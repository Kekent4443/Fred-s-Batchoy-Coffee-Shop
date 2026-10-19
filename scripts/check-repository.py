#!/usr/bin/env python3
"""Check upload candidates and the Git index without printing secret values."""

import argparse
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
MAX_FILE_BYTES = 100 * 1024 * 1024
PATTERNS = {
    "provider credential": re.compile(
        r"(?:xkeysib-|xsmtpsib-)[A-Za-z0-9_-]{20,}"
        r"|AIzaSy[A-Za-z0-9_-]{25,}"
        r"|(?:sk_live_|sk_test_)[A-Za-z0-9]{16,}"
        r"|sk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{32,}"
        r"|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}"
        r"|(?:AKIA|ASIA)[A-Z0-9]{16}"
        r"|xox[baprs]-[A-Za-z0-9-]{20,}"
    ),
    "private signing key": re.compile(r"-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----"),
    "credential in URL": re.compile(r"[a-z][a-z0-9+.-]*://[^\s/'\":]+:[^\s/'\"]+@", re.I),
}
SENSITIVE_FIELD = (
    r"api[_-]?key|password|passwd|secret|webhook_secret|email_code_hmac_key|"
    r"smtp_password|db_pass|access_token|auth_token|client_secret"
)
ASSIGNMENT = re.compile(
    r"(?<![\w])(?:(?i:['\"](?:" + SENSITIVE_FIELD + r")[\"']|\$(?:"
    + SENSITIVE_FIELD + r"))|API_KEY|SMTP_PASSWORD|DB_PASS|CLIENT_SECRET|ACCESS_TOKEN)"
    r"\s*(?:=>|=(?!=)|:)\s*(['\"])([^'\"\r\n]+)\1"
)
PLACEHOLDER = re.compile(r"^(?:your[-_]|replace[-_]|example(?:[._-]|$)|<[^>]+>$)", re.I)
SQL_PASSWORD_HASH = re.compile(r"\$2[aby]\$\d\d\$[./A-Za-z0-9]{53}|\$argon2(?:id|i)\$")


def git(*args, input_data=None):
    result = subprocess.run(
        ["git", "-C", str(ROOT), *args], input=input_data, capture_output=True, check=False
    )
    if result.returncode:
        raise RuntimeError("Git inventory failed. Run git init in the project first.")
    return result.stdout


def forbidden_path(name):
    path = PurePosixPath(name)
    parts = path.parts
    if path.is_absolute() or ".." in parts or "\\" in name:
        return True
    if path.name == ".env" or (path.name.startswith(".env.") and path.name != ".env.example"):
        return True
    if path.name in {"auth.json", ".netrc", ".npmrc"} or path.name.startswith(("id_rsa", "id_ed25519")):
        return True
    if path.name.endswith(".local.php") or path.suffix.lower() in {
        ".pem", ".key", ".p12", ".pfx", ".p8", ".jks", ".keystore", ".log", ".bak",
        ".zip", ".7z", ".tar", ".gz",
    }:
        return True
    if parts and parts[0] in {"vendor", "tmp", "temp", "output", "coverage", "backups"}:
        return True
    if any(part in {"node_modules", ".idea", ".vscode", "__pycache__"} for part in parts):
        return True
    if name.startswith("database/backups/"):
        return True
    if name.startswith(("public/uploads/", "private/uploads/")):
        return path.name != ".htaccess"
    if parts and parts[0] == "private":
        return name not in {"private/.htaccess", "private/admin-access.php"} and not (
            len(parts) == 2 and path.name.endswith(".example.php")
        )
    return False


def content_findings(name, data, source):
    text = data.decode("utf-8", errors="replace")
    findings = []
    patterns = dict(PATTERNS)
    if name.endswith(".sql"):
        patterns["password hash in SQL"] = SQL_PASSWORD_HASH
    for label, pattern in patterns.items():
        for match in pattern.finditer(text):
            line = text.count("\n", 0, match.start()) + 1
            findings.append(f"{source} {name}:{line}: {label}")
    # Test passwords belong only to disposable fixtures. Provider tokens and
    # private keys above are rejected everywhere, including tests/templates.
    if not name.startswith(("scripts/test-", "scripts/fixtures/")):
        for match in ASSIGNMENT.finditer(text):
            value = match.group(2)
            if value.strip() and not PLACEHOLDER.search(value):
                line = text.count("\n", 0, match.start()) + 1
                findings.append(f"{source} {name}:{line}: hardcoded credential")
    return findings


def inventory():
    names = sorted(set(filter(None, git("ls-files", "--cached", "--others", "--exclude-standard", "-z").decode("utf-8").split("\0"))))
    entries = []
    for entry in filter(None, git("ls-files", "--stage", "-z").split(b"\0")):
        metadata, raw_name = entry.split(b"\t", 1)
        mode, object_id, stage = metadata.decode("ascii").split()
        if stage != "0" or mode not in {"100644", "100755"}:
            raise RuntimeError("Resolve merge conflicts and remove symlinks/submodules before exporting.")
        entries.append((raw_name.decode("utf-8"), object_id))
    # Read staged blobs too: an edited working file can otherwise conceal a
    # credential still staged for the next commit. No blob contents are logged.
    objects = {}
    ids = list(dict.fromkeys(object_id for _, object_id in entries))
    if ids:
        output = git("cat-file", "--batch", input_data=("\n".join(ids) + "\n").encode("ascii"))
        offset = 0
        for object_id in ids:
            end = output.index(b"\n", offset)
            header = output[offset:end].split()
            if len(header) != 3 or header[1] != b"blob":
                raise RuntimeError("Unable to inspect a staged file.")
            size = int(header[2])
            offset = end + 1
            objects[object_id] = output[offset:offset + size]
            offset += size + 1
    return names, [(name, objects[object_id]) for name, object_id in entries]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--export", action="store_true", help="Create tmp/github-upload.zip after a passing scan")
    args = parser.parse_args()
    try:
        names, staged = inventory()
        findings = []
        files = {}
        for name in names:
            if forbidden_path(name):
                findings.append(f"{name}: private/generated file is eligible for upload")
                continue
            path = ROOT / name
            if path.is_symlink() or not path.resolve().is_relative_to(ROOT):
                findings.append(f"{name}: symlink or external path")
                continue
            if not path.is_file():
                findings.append(f"{name}: missing working file; stage its deletion first")
                continue
            if path.stat().st_size > MAX_FILE_BYTES:
                findings.append(f"{name}: exceeds the upload size limit")
                continue
            files[name] = path.read_bytes()
            findings.extend(content_findings(name, files[name], "working"))
        for name, data in staged:
            if len(data) > MAX_FILE_BYTES:
                findings.append(f"staged {name}: exceeds the upload size limit")
            elif files.get(name) != data:
                findings.extend(content_findings(name, data, "staged"))
        if findings:
            print("FAIL: upload blocked; matched values are withheld.")
            print("\n".join(sorted(set(findings))))
            return 1
        print(f"PASS: {len(files)} upload files and {len(staged)} staged files checked.")
        print("Private paths and known credential patterns checked; Git history is not scanned.")
        if args.export:
            destination = ROOT / "tmp" / "github-upload.zip"
            destination.parent.mkdir(exist_ok=True)
            if destination.is_symlink() or not destination.resolve().is_relative_to(ROOT):
                raise RuntimeError("Export destination must stay inside this project.")
            with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED) as archive:
                for name, data in files.items():
                    archive.writestr(name, data)
            print("Created tmp/github-upload.zip using only the scanned working files.")
        return 0
    except (OSError, RuntimeError, ValueError) as error:
        # Avoid including file content or subprocess stderr in diagnostics.
        print(f"FAIL: {type(error).__name__}; inventory/export could not be completed.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
