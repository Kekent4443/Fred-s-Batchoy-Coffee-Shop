# Migration audit and deployment procedure

`database/migrations/` is the sole migration directory. All historical SQL
files are present there, together with the tracking-table migration and the
current repair migrations.
The former singular directory was empty and has been removed. The two
September 28 files formerly kept there were merged into the plural directory
in the prior upgrade. No historical migration has been deleted or rewritten
by this refactor; the restored July/August files match their pre-refactor
SHA-256 checksums.

## Runtime schema-helper audit

Every schema-changing `ensure_*` helper is gated by
`schema_maintenance_enabled()`, which is false for HTTP requests and in
production. The explicit SQL replacement is as follows; these are migration
families, not permission to rerun a historical file whose effects are already
present. `ensure_unique_user_contacts()` is validation, not a schema helper.

| Helper(s) | Explicit production schema source |
| --- | --- |
| `ensure_database_schema()` | `database/schema.sql` for **empty fresh installs**; dated feature migrations and `2026_09_29_runtime_schema_alignment.sql` for existing installs |
| `ensure_rate_limit_schema()`, `ensure_remember_tokens_table()` | `2026_09_29_runtime_schema_alignment.sql` |
| `ensure_auth_history_schema()` | `2026_09_28_authentication_activity.sql` |
| `ensure_email_queue_schema()`, `ensure_password_reset_schema()` | `2026_07_15_password_reset_verification_codes.sql`, `2026_07_16_password_reset_email_queue.sql`, `2026_07_19_integrity_workflow_repair.sql`, `2026_09_28_customer_email_verification_codes.sql` |
| `ensure_pricing_schema()` | `2026_07_06_vat_inclusive_bulk_discounts.sql`, `2026_07_19_integrity_workflow_repair.sql`, `2026_09_28_customer_email_verification_codes.sql` |
| `ensure_customer_verification_schema()`, `ensure_registration_verification_schema()` | `2026_08_01_customer_verification_ui.sql`, `2026_07_19_integrity_workflow_repair.sql`, `2026_09_28_customer_email_verification_codes.sql`, `2026_09_29_runtime_schema_alignment.sql` |
| `ensure_operational_feature_columns()`, `ensure_record_management_tables()` | `2026_07_06_delivery_workflow_improvements.sql`, `2026_07_19_integrity_workflow_repair.sql`, `2026_09_29_runtime_schema_alignment.sql` |
| `ensure_notification_schema()`, `ensure_conditional_user_deletion_schema()` | `2026_07_14_notification_system.sql`, `2026_07_14_conditional_user_deletion.sql`, `2026_07_19_integrity_workflow_repair.sql` |
| `ensure_support_schema()`, `ensure_shop_location_schema()`, `ensure_contact_schema()` | Respective `2026_07_05_*` feature migrations, with shop locations before contact inquiries |
| `ensure_gcash_account_schema()`, `ensure_receipt_settings_schema()`, `ensure_branding_schema()` | `2026_07_14_gcash_payment_accounts.sql`, `2026_07_14_thermal_receipt_settings.sql` plus later receipt defaults, and `2026_09_22_global_site_logo.sql`, respectively |
| `ensure_customer_delivery_address_schema()`, `ensure_performance_indexes()` | `2026_09_21_structured_customer_delivery_address.sql`, `2026_07_19_integrity_workflow_repair.sql`, and `2026_09_29_runtime_schema_alignment.sql` |

This mapping is based on repository SQL, not a claim that any particular
production database already has those changes. Verify effects on a database
copy before recording the baseline.

The fresh-install `database/schema.sql` contains the current structural end
state, but is destructive and must **never** be imported over an existing
database. A live database's schema, seed data, and historical repairs cannot
be inferred from an empty `schema_migrations` table. Review a backup/copy
before baselining or running any file.

## Inventory and dependency order

All paths below are relative to `database/migrations/`. For every existing
installation, the decision is **verify effects and baseline if already
applied; otherwise run deliberately**. The “current schema” column describes
the fresh-install schema, not a verified production database.

| Migration (`.sql`) | Purpose / dependency | Overlap and current-schema status |
| --- | --- | --- |
| `2026_07_05_shop_location_management` | Shops and user assignments; core users required | Current fresh schema has shop structures; **run before contact** |
| `2026_07_05_contact_us_inquiry_management` | Shop contact fields and inquiries; needs shops | Current fresh schema has fields/table; data updates need review |
| `2026_07_05_support_chat` | Support conversations, messages, FAQs and seeds; needs users | Current fresh schema has tables; seeds need review |
| `2026_07_06_delivery_workflow_improvements` | Delivery/payment fields and history; needs core tables | Later integrity repair overlaps columns |
| `2026_07_06_vat_inclusive_bulk_discounts` | Discount rules, pricing columns and backfill | Current schema has fields; historical totals need review |
| `2026_07_14_conditional_user_deletion` | Soft-delete fields/index/constraint | Later integrity repair overlaps; old SQL is not repeatable |
| `2026_07_14_gcash_payment_accounts` | GCash account table | Later integrity repair also safeguards table |
| `2026_07_14_notification_system` | Notification ownership/read/link fields and indexes | Later integrity repair overlaps; old SQL is not repeatable |
| `2026_07_14_notification_url_repair` | Legacy notification-link data repair | Needs notification-system fields; data-only |
| `2026_07_14_thermal_receipt_settings` | Receipt settings and order fields | Later defaults intentionally supersede its default |
| `2026_07_15_password_reset_verification_codes` | Earlier reset-code fields | Later integrity/email upgrades overlap |
| `2026_07_16_password_reset_email_queue` | Email queue leases/links; needs reset and queue tables | Later integrity repair overlaps; old SQL is not repeatable |
| `2026_07_19_integrity_workflow_repair` | Consolidated schema, data and index repairs; after earlier July files | Intentional overlap, not a replacement for every earlier data step |
| `2026_08_01_customer_verification_ui` | Verification-review cleanup | Data effect not inferable from fresh schema |
| `2026_08_07_default_58mm_receipt` | Historical 58mm default | Superseded by September 80mm default, then restored by October upgrade |
| `2026_08_07_single_administrator_policy` | One-admin assertion and protective triggers | Fresh schema has triggers; requires one existing admin when run |
| `2026_09_21_structured_customer_delivery_address` | Address columns and safe backfill | Fresh schema has columns; historical data needs review |
| `2026_09_22_global_site_logo` | Optional branding logo field | Fresh schema has field |
| `2026_09_24_default_80mm_receipt` | Historical 80mm default, preserving configured choices | Superseded by October 58mm default; retained for migration history |
| `2026_09_28_authentication_activity` | Successful/failed login history tables | Fresh schema has tables |
| `2026_09_28_customer_email_verification_codes` | Email/reset code fields and discount-range repair | Requires earlier auth/pricing tables; data backfill needs review |
| `2026_09_29_runtime_schema_alignment` | Explicit replacement for former request-time schema repairs | Fresh schema has end state; repeatable safeguards |
| `2026_09_29_schema_migrations` | Tracking table only | Also included in fresh schema; install deliberately before baseline |
| `2026_10_05_manager_product_shop_scope` | Adds product-to-shop ownership for Manager product authorization; needs products and shop locations | Fresh schema has the column/index/foreign key; existing NULL products are preserved and assigned to the first active shop |
| `2026_10_06_password_recovery_repair` | Repeatable alignment for password reset, registration verification, email queue, rate limit, remember-token, and login-history structures | Additive only; safe for populated databases and does not replace `schema.sql` |
| `2026_10_07_default_58mm_thermal_print` | 58mm receipt default and untouched 80mm seed normalization | Preserves saved 80mm choices, receipt history, and the width column |

The runner sorts filenames deterministically and explicitly puts the
same-day shop-location file before the contact file, whose historical name
would otherwise sort first. Other files retain chronological filename order.
The July integrity repair and earlier feature migrations overlap by design;
the 58mm and 80mm receipt migrations are a sequence, not duplicates. No file
was removed simply because a later file has a similar purpose.

## Safe existing-installation procedure

Production web/API requests never execute migration SQL. `APP_ENV=production`
forces `APP_AUTO_MIGRATE=false`; `scripts/migrate.php` is CLI-only and outside
`public/`. Use a deployment account with DDL privileges, separate from the
normal runtime account when hosting permits it. This repository does not
change live grants.

1. Back up the database. Confirm that the original core schema exists. The
   runner checks essential core tables but cannot prove that every historical
   data repair or trigger is present. Review each migration's actual effect
   on a copy of the database. If the database is empty, use the fresh-install
   schema deliberately instead; do not run dated upgrades against no core.
2. Run `php scripts/migrate.php --status`. It reads the canonical files and
   prints their SHA-256 values. If tracking is absent, all files appear pending
   even if their effects already exist; do **not** run them yet.
3. Run `php scripts/migrate.php --install-tracker`, or import
   `2026_09_29_schema_migrations.sql` through phpMyAdmin. The CLI records the
   tracker migration only after successful installation.
4. For each file whose effects are **already present**, create a local
   reviewed manifest line: `exact_filename.sql SHA256_FROM_STATUS APPLIED`.
   Include the tracker file; omit genuinely pending files. Run
   `php scripts/migrate.php --baseline=path/to/reviewed-manifest.txt`. This
   verifies filenames/checksums, records reviewed files with batch 0, and
   records a baseline-review marker. The manifest must not be empty.
5. Run `php scripts/migrate.php --run`. It skips recorded files, checks their
   checksums, and executes pending files in the order above. It records a file
   only after its entire SQL succeeds. It stops on failure and does **not**
   record that file. A database advisory lock prevents two runner processes
   from racing. MySQL DDL can partially commit; inspect/repair a failed
   migration manually before retrying. Recheck `--status` and smoke-test the
   application.

For phpMyAdmin-only hosting, import only genuinely pending files one at a
time, in the table order. **After** each successful import, record its exact
filename and SHA-256 in `schema_migrations` (batch 1). Baseline a previously
applied file with batch 0 only after verifying its actual effect. Record the
tracker file too. Compute SHA-256 locally from the exact uploaded SQL if the
host has no CLI. Replace both placeholders below before executing:

```sql
INSERT INTO schema_migrations (migration, checksum, batch)
VALUES ('REPLACE_WITH_EXACT_FILENAME.sql', 'REPLACE_WITH_64_CHARACTER_SHA256', 0);
```

Use batch 0 for a reviewed prior effect and batch 1 (or a later deployment
batch) for a successful new import. Once the historical review is complete,
the operator may unlock later CLI runs with:

```sql
INSERT INTO schema_migrations (migration, checksum, batch)
VALUES ('__baseline_reviewed__', SHA2('reviewed existing schema', 256), 0);
```

Never record a failed migration as applied. The table and marker do not prove
production is correct; keep the schema/data review with the backup and change
record. Do not import `database/schema.sql` into a populated database.
