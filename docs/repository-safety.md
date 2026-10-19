# Repository preparation review

Reviewed on October 20, 2026 for a source upload to GitHub and continued
localhost use. No Git repository/history existed in this folder when the
review started. A local `main` branch was initialized; nothing was published.

## Changes

- Excluded private local configuration, all private runtime files except
  reviewed templates/access controls, uploads, backups, browser profiles,
  temporary output, dependencies, logs and signing-key files from Git.
- Preserved nested upload `.htaccess` controls in the source inventory.
- Preserved migration SQL bytes with `.gitattributes`, so Git line-ending
  conversion does not change the migration runner's stored checksums.
- Removed the seeded administrator email/password hash and the administrator
  setup script's hardcoded identity. Setup now requires `ADMIN_FULL_NAME` and
  `ADMIN_EMAIL`, with the password supplied locally through `ADMIN_TEMP_PASSWORD`.
- Updated the database example for Laragon localhost and documented fresh
  installation, optional SMTP, administrator setup, Git upload and safe export.
- Blocked temporary/internal directories and signing-key file types in the root
  Apache access rules. Added a local access-denial file to the existing ignored
  temporary directory.
- Added `scripts/check-repository.py` to inspect working files and staged blobs
  for private paths and known credential patterns, with values withheld from
  diagnostic output. Its export option packages only scanned working files.

No provider credentials were detected in the final eligible upload files by
the pattern checks. Existing local configuration, administrator rollback data,
uploaded identification/payment/delivery images and browser files remain on the
computer, excluded from the upload. Existing database accounts/passwords were
not modified. Fixed dummy hashes used for authentication timing and synthetic
test credentials are source fixtures, not seeded user accounts. Existing public
business contact defaults remain in application/SQL source.

## Verification

- PHP syntax: 200 application/configuration/script files passed.
- Composer manifest validation and platform requirements passed.
- Composer locked-dependency audit: no security vulnerability advisories found.
- Five upload-guard integration tests passed in disposable Git repositories.
  These cover clean archive contents, force-added private files, a secret still
  staged after cleaning the working file, literal passwords/SQL hashes, and
  provider tokens/private keys in test fixtures.
- Missing and invalid administrator inputs are rejected before database access.
- Existing PHP and JavaScript QZ receipt tests passed.

Local MySQL on `127.0.0.1:3306` was unreachable during verification. Database
integration and complete browser workflows were not exercised. Apache's new
access rules require the documented modules/overrides; they were not tested
against a running Apache instance in this review.

The checker covers known patterns and upload paths, not all possible secret
formats or historical commits. A separate existing GitHub repository, provider
credential rotation and any earlier published history were outside this folder
review. Run the checker again after adding or changing files.
