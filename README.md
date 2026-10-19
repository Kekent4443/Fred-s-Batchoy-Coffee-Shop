<<<<<<< HEAD
# Fred's Multi-Product Sales Ordering System

PHP/MySQL ordering system for localhost use with Laragon on Windows. GitHub
stores the source; run the application locally with PHP and MySQL. GitHub
Pages cannot run this PHP application.

## What the system includes

- Administrator, manager, staff, rider, and customer accounts.
- Product categories, catalog, cart, checkout, and order tracking.
- Cash and GCash payment workflows with proof review.
- Delivery assignments, status updates, and delivery proofs.
- Discounts, promo codes, reports, notifications, and support conversations.
- Customer verification, password recovery, and optional receipt printing.

## Start here

| Your situation | Follow these instructions |
| --- | --- |
| The system already works on your computer | Keep your database/local files and follow [GitHub upload](#prepare-a-github-upload). |
| You downloaded/cloned the project on a new computer | Follow [localhost setup](#localhost-setup), then [administrator setup](#create-the-first-administrator). |
| You only need to open the system again | Follow [daily use](#daily-use). |
| Something is not working | Check [troubleshooting](#troubleshooting). |

## Requirements

- PHP 8.1 to 8.3 with the extensions listed in `composer.json`.
- MySQL/MariaDB and Apache with `mod_rewrite` and `.htaccess` overrides enabled.
- Composer and Git. Python 3.9+ is used only by the repository safety/export tool.

Run setup commands from the project root in PowerShell. For this installation:

```powershell
Set-Location 'D:\laragon\www\capstone2'
php --version
composer --version
git --version
```

On another computer, use that computer's Laragon project path instead.

## Localhost setup

1. Clone/extract into your Laragon `www/capstone2` folder and start Apache/MySQL.
2. Run `composer install` from the project folder. Keep `composer.lock` in Git.
3. Copy `private/database.local.example.php` to `private/database.local.php`
   **only if the local file does not exist**, then edit the local MySQL settings.
   This file is ignored by Git. Process variables `DB_HOST`, `DB_PORT`, `DB_NAME`,
   `DB_USER`, and `DB_PASS` override its values.
4. For a **new empty database only**, create `freds_sales_system` in phpMyAdmin
   with `utf8mb4_unicode_ci`, then import `database/schema.sql`. This SQL drops
   existing tables; keep your current database if the application already runs.
5. For a fresh install, create the administrator as described below, then import
   `database/seed.sql` into that new database for sample shop/discount/FAQ settings.
   The seed contains no administrator login or password hash.
6. Open `http://localhost/capstone2/`. Loopback Apache requests automatically
   select the local environment. For a Laragon `.test` hostname, explicitly set
   `APP_ENV=local` in Apache's environment and restart Apache.

For existing databases, review [the migration guide](database/MIGRATION_AUDIT.md) before any schema
changes. Web requests do not automatically run migrations.

### Daily use

1. Open Laragon and start Apache and MySQL.
2. Open `http://localhost/capstone2/` in your browser.
3. Sign in with an existing account. Use the administrator account to manage
   users, products, shop details, payment settings, and receipt settings.
4. Stop the Laragon services when you finish using the system.

Composer installation and database import are setup steps; they are not needed
every time you open the application. A new clone includes source and example
settings; your existing accounts, orders, and uploaded images stay in your local
database/upload folders.

### Create the first administrator

Skip this on an existing installation unless you intend to change its
administrator. The command can update the sole existing administrator and writes
a private rollback snapshot when doing so. There is no shared default login.

Run in PowerShell from the project folder; enter your own details when prompted:

```powershell
$env:APP_ENV = 'local'
$env:ADMIN_FULL_NAME = Read-Host 'Administrator full name'
$env:ADMIN_EMAIL = Read-Host 'Administrator email'
$env:ADMIN_PHONE = Read-Host 'Administrator phone'
$env:ADMIN_ADDRESS = Read-Host 'Administrator address'
$adminCredential = Get-Credential -UserName $env:ADMIN_EMAIL -Message 'Choose an administrator password (8 to 72 characters)'
try {
    $env:ADMIN_TEMP_PASSWORD = $adminCredential.GetNetworkCredential().Password
    php scripts/set-primary-admin.php
} finally {
    Remove-Item Env:ADMIN_TEMP_PASSWORD -ErrorAction SilentlyContinue
    Remove-Variable adminCredential -ErrorAction SilentlyContinue
}
```

Existing administrator passwords are unaffected by repository preparation.
If you previously used the old seeded password, change it through the local
profile page before sharing the project. To deliberately rotate via the setup
script, set `ADMIN_FORCE_PASSWORD_RESET=1` for that invocation and clear it afterward.

### Optional email and receipt printing

Copy `private/mail.local.example.php` to `private/mail.local.php` only if it does
not already exist. Put Brevo SMTP credentials in the local copy, or supply the
documented `SMTP_*` process variables. Empty credentials disable email delivery;
registration verification and password recovery need working SMTP settings.
The application does **not** automatically read `.env` files.

For the provided Brevo transport, use `smtp-relay.brevo.com`, port `587`, and
`starttls`, plus your SMTP username/key and verified sender address. Configure
these values in the ignored local file. PHP process environment values take
precedence over the local file. PowerShell `$env:` settings affect commands
launched from that shell; Apache has its own environment.

Receipt printing is optional. See [the receipt printing guide](docs/qz-receipt-printing.md) for QZ Tray;
private signing keys must stay outside this entire project directory.

## Project folders

| Path | Purpose |
| --- | --- |
| `app/` | Application configuration, helpers, services, controllers, and shared views. |
| `public/` | Browser pages and request endpoints for each account role. |
| `assets/` | Styles, JavaScript, and shared static images. |
| `database/` | Fresh-install schema, sample settings, and migration history. |
| `private/` | Local configuration, protected uploads, and private backups. |
| `scripts/` | Administrator setup/restore, migrations, email queue, private URL checks, upload safety/export, and the local receipt spooler. |
| `docs/` | Receipt setup, repository review, and supporting documentation. |
| `vendor/` | Composer-installed dependencies, recreated by `composer install`. |
| `tmp/` | Local temporary files and the generated clean upload ZIP. |

## Prepare a GitHub upload

The repository excludes local credentials, uploaded IDs/payment/delivery proofs,
GCash QR images, backups, browser profiles, logs, temporary reports, and installed
dependencies. Keep source SQL (`schema.sql`, `seed.sql`, migrations) in Git;
keep actual database exports in the ignored `private/backups/` folder.
Public business contact defaults in the application/SQL remain visible source.

Run from the project folder:

```powershell
python scripts/check-repository.py
git add .
python scripts/check-repository.py
git diff --cached --stat
git commit -m "Prepare localhost application for GitHub"
```

The checker inspects eligible working files and staged blobs, including files
force-added despite `.gitignore`. It reports locations without printing matched
values. It checks known patterns and private paths; review new files too. It does
not scan past commits. This prepared folder started without Git history.

Create an empty GitHub repository, then use its URL:

```powershell
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
git push -u origin main
```

Replace both URL placeholders with your GitHub account and repository name.
If Git reports that your author identity is missing, configure your preferred
name and GitHub-associated email locally with `git config user.name` and
`git config user.email`, then repeat the commit. Keep login tokens out of remote
URLs and source files; use Git's authentication prompt or credential manager.

For a manual browser upload, generate a clean archive:

```powershell
python scripts/check-repository.py --export
```

Extract `tmp/github-upload.zip` and upload **only its extracted contents**,
including `.gitignore` and `.htaccess` files. Do not upload the original project
folder: browser uploads and ordinary ZIP tools do not honor `.gitignore`.
The archive excludes local data and `vendor`; run `composer install` on a fresh
local clone. Neither these commands nor the export tool publishes a live site.

If a real key was previously shared or committed elsewhere, revoke/replace it
with its provider. Ignoring the file does not invalidate the old key or remove
it from another repository's history.

## Backup and transfer to another computer

Use phpMyAdmin's **Export** function to save your current database into the
ignored `private/backups/` folder. Keep a separate private copy of that export,
your `private/*.local.php` configuration files, and both upload folders:
`private/uploads/` and `public/uploads/`. These files are not in the clean ZIP.

On the destination computer, install the source and Composer dependencies,
create an empty database, import your private database export, restore your
upload folders, and configure that computer's local database/mail settings.
Use the database export as the restore source; importing the fresh-install
`schema.sql` afterward would drop the restored data.

## Optional verification

Run these from the project root after changing source or configuration:

```powershell
composer validate --no-check-publish
composer check-platform-reqs
python scripts/check-repository.py
```

After these checks, verify login, ordering, payments, delivery, and receipt
printing in your local application. The local Windows receipt spooler is
`scripts/print-xprinter-raw.ps1`; keep it with the application because PHP calls
it when sending receipts to the attached XP-58 printer.

Development test suites, test fixtures, generated PDF samples, temporary browser
profiles, and obsolete refactor notes have been removed from this distribution.
The earlier review and its testing limits are recorded in
[the repository review](docs/repository-safety.md).

## Troubleshooting

| Problem | What to check |
| --- | --- |
| `php`, `composer`, `git`, or `python` is not recognized | Use Laragon's terminal or add the installed tool to your Windows `PATH`; reopen PowerShell afterward. |
| Browser cannot connect to localhost | Start Apache in Laragon and check its configured port. Adjust the URL if Apache uses a port other than 80. |
| Database error or service unavailable | Start MySQL, confirm the database exists, and check host/port/name/user/password in `private/database.local.php`. Check local PHP/Apache logs for details. |
| `vendor/autoload.php` is missing | Run `composer install` from the project root. |
| Database setup fails for a CLI command | Set `$env:APP_ENV = 'local'` in the PowerShell session and check your local database file. |
| No administrator login works after a fresh install | There is no default password. Complete the administrator setup above using your own email and password. |
| Verification or password recovery email does not arrive | Check the Brevo SMTP key, username, verified sender, port 587, STARTTLS, internet connection, and recipient spam folder. |
| Product images or uploaded proofs are missing on another computer | Uploads are excluded from Git. Restore them privately or add replacement images through the application. |
| Opening `private/`, `app/`, `database/`, or `scripts/` returns 403 | These paths are protected. Use the application entry URL; run maintenance scripts from PowerShell. |
| The upload checker reports a staged credential after you edited the file | Remove the credential, run `git add` on the corrected source file, and run the checker again. Private local files must be removed from Git's index with `git rm --cached -- path/to/file`; the local copy remains. |
| A cloned repository has no upload ZIP | Generate one with `python scripts/check-repository.py --export`. The ZIP is local output and is excluded from Git. |
=======
# Fred-s-Batchoy-Coffee-Shop
Welcome to Fred's Batchoy &amp; Coffee Shop! Browse our delicious menu, discover your favorite meals and drinks, and enjoy a convenient ordering experience. You may explore our products as a visitor and sign in only when you are ready to checkout or manage your account.
>>>>>>> 918fcc61a19519862f6ec29f46cbbbd684147b93
