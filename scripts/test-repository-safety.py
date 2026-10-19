#!/usr/bin/env python3
"""Exercise the upload guard in disposable repositories, without real secrets."""

from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]


class RepositorySafetyTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="capstone-upload-test-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        (self.root / "scripts").mkdir()
        shutil.copyfile(ROOT / "scripts/check-repository.py", self.root / "scripts/check-repository.py")
        shutil.copyfile(ROOT / ".gitignore", self.root / ".gitignore")
        self.git("init", "-q")

    def git(self, *args):
        return subprocess.run(["git", "-C", str(self.root), *args], capture_output=True, check=True)

    def write(self, name, content):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def scan(self, *args):
        return subprocess.run(
            [sys.executable, str(self.root / "scripts/check-repository.py"), *args],
            capture_output=True, text=True, check=False,
        )

    def test_export_excludes_private_data_and_preserves_access_controls(self):
        for name in ["private/mail.local.php", "private/backups/admin.json",
                     "private/uploads/ids/id.png", "public/uploads/gcash-qr/qr.png",
                     "tmp/browser/Preferences", "vendor/autoload.php", "nested/.env.local"]:
            self.write(name, "local data")
        for name in [".htaccess", "private/.htaccess", "private/uploads/.htaccess",
                     "public/uploads/.htaccess", "public/uploads/ids/.htaccess"]:
            self.write(name, "Require all denied")
        self.write("private/database.local.example.php", "<?php return ['password' => ''];")
        result = self.scan("--export")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        with zipfile.ZipFile(self.root / "tmp/github-upload.zip") as archive:
            names = archive.namelist()
        self.assertIn(".gitignore", names)
        self.assertIn("public/uploads/ids/.htaccess", names)
        self.assertIn("private/database.local.example.php", names)
        self.assertNotIn("private/mail.local.php", names)
        self.assertFalse(any(name.startswith(("tmp/", "vendor/", "private/backups/")) for name in names))

    def test_force_added_local_config_blocks_export(self):
        self.write("private/mail.local.php", "<?php return [];")
        self.git("add", "-f", "private/mail.local.php")
        result = self.scan("--export")
        self.assertEqual(result.returncode, 1)
        self.assertIn("private/generated file", result.stdout)
        self.assertFalse((self.root / "tmp/github-upload.zip").exists())

    def test_staged_credential_is_checked_even_after_working_copy_is_clean(self):
        token = "ghp_" + "A" * 36
        self.write("config.php", "<?php $api_key = '" + token + "';")
        self.git("add", "config.php")
        self.write("config.php", "<?php $api_key = '';")
        result = self.scan()
        self.assertEqual(result.returncode, 1)
        self.assertIn("staged config.php", result.stdout)
        self.assertNotIn(token, result.stdout + result.stderr)

    def test_literal_password_and_sql_hash_are_rejected(self):
        self.write("config.php", "<?php return ['password' => 'fixture-password'];")
        self.write("database/seed.sql", "SELECT '$2y$10$" + "a" * 53 + "';")
        result = self.scan()
        self.assertEqual(result.returncode, 1)
        self.assertIn("hardcoded credential", result.stdout)
        self.assertIn("password hash in SQL", result.stdout)
        self.assertNotIn("fixture-password", result.stdout + result.stderr)

    def test_test_fixtures_cannot_conceal_provider_tokens_or_keys(self):
        self.write("scripts/test-example.php", "<?php // " + "xkeysib-" + "a" * 40)
        self.write("signing.txt", "-----BEGIN " + "PRIVATE KEY-----")
        result = self.scan()
        self.assertEqual(result.returncode, 1)
        self.assertIn("provider credential", result.stdout)
        self.assertIn("private signing key", result.stdout)


if __name__ == "__main__":
    unittest.main()
