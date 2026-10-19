<?php

declare(strict_types=1);

function ensure_gcash_account_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    static $checked = false;

    if ($checked) {
        return;
    }

    db_execute('CREATE TABLE IF NOT EXISTS gcash_payment_accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_name VARCHAR(150) NOT NULL,
        account_number VARCHAR(20) NOT NULL,
        qr_code_path VARCHAR(255) NULL,
        status ENUM("active", "inactive") NOT NULL DEFAULT "active",
        updated_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_gcash_accounts_status_updated (status, updated_at, id),
        INDEX idx_gcash_accounts_updated_by (updated_by),
        CONSTRAINT fk_gcash_accounts_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $checked = true;
}

function normalize_gcash_number(string $number): string
{
    $number = preg_replace('/[\s().-]+/', '', trim($number)) ?? '';
    if (preg_match('/^\+639\d{9}$/', $number)) {
        $number = '0' . substr($number, 3);
    } elseif (preg_match('/^639\d{9}$/', $number)) {
        $number = '0' . substr($number, 2);
    }

    if (!preg_match('/^09\d{9}$/', $number)) {
        throw new InvalidArgumentException('GCash number must be a valid Philippine mobile number, such as 09171234567 or +639171234567.');
    }

    return $number;
}

function format_gcash_number(string $number): string
{
    $number = normalize_gcash_number($number);

    return substr($number, 0, 4) . ' ' . substr($number, 4, 3) . ' ' . substr($number, 7, 4);
}

function save_uploaded_gcash_qr(?array $uploadedImage, string $currentPath = ''): string
{
    $hasUpload = is_array($uploadedImage)
        && ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && !empty($uploadedImage['name']);

    if (!$hasUpload) {
        return $currentPath;
    }

    if (($uploadedImage['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($uploadedImage['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('Unable to upload the QR code image. Please try again.');
    }

    if ((int) ($uploadedImage['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('QR code image must be 5MB or smaller.');
    }

    $extension = strtolower((string) pathinfo((string) $uploadedImage['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new InvalidArgumentException('QR code must be a JPG, JPEG, PNG, or WEBP image.');
    }

    assert_uploaded_file_type($uploadedImage, ['image/jpeg', 'image/png', 'image/webp'], 'QR code content must be a valid JPG, PNG, or WEBP image.');
    $directory = UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'gcash-qr';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare the QR code upload folder.');
    }

    $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    if (!move_uploaded_file((string) $uploadedImage['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Unable to save the QR code image.');
    }

    return 'uploads/gcash-qr/' . $filename;
}

function delete_local_gcash_qr(?string $storedPath): void
{
    $storedPath = trim(str_replace('\\', '/', (string) $storedPath));
    if (!str_starts_with($storedPath, 'uploads/gcash-qr/') || str_contains($storedPath, '..')) {
        return;
    }
    $relative = substr($storedPath, strlen('uploads/gcash-qr/'));
    if ($relative === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $relative)) {
        return;
    }
    $root = realpath(UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'gcash-qr');
    $target = realpath(UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'gcash-qr' . DIRECTORY_SEPARATOR . $relative);
    if ($root === false || $target === false || !is_file($target)) {
        return;
    }
    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    if (str_starts_with(str_replace('\\', '/', $target), $rootPrefix)) {
        @unlink($target);
    }
}

function fetch_gcash_accounts(): array
{
    ensure_gcash_account_schema();

    return db_select(
        'SELECT g.*, u.full_name AS updated_by_name
         FROM gcash_payment_accounts g
         INNER JOIN users u ON u.id = g.updated_by
         ORDER BY g.status = "active" DESC, g.updated_at DESC, g.id DESC'
    );
}

function fetch_gcash_account(int $id): ?array
{
    ensure_gcash_account_schema();

    return db_find('SELECT * FROM gcash_payment_accounts WHERE id = ? LIMIT 1', 'i', [$id]);
}

function active_gcash_account(): ?array
{
    ensure_gcash_account_schema();

    return db_find(
        'SELECT * FROM gcash_payment_accounts
         WHERE status = "active"
         ORDER BY updated_at DESC, id DESC
         LIMIT 1'
    );
}

function gcash_account_deletion_assessment(array $account, array $accounts): array
{
    $accountId = (int) ($account['id'] ?? 0);
    $matchingAccount = null;
    $activeCount = 0;

    foreach ($accounts as $candidate) {
        if (($candidate['status'] ?? '') === 'active') {
            $activeCount++;
        }
        if ((int) ($candidate['id'] ?? 0) === $accountId) {
            $matchingAccount = $candidate;
        }
    }

    if ($accountId <= 0 || $matchingAccount === null) {
        return ['allowed' => false, 'reason' => 'The selected GCash account does not exist.'];
    }

    if (count($accounts) <= 1) {
        return ['allowed' => false, 'reason' => 'The last GCash account cannot be deleted. Deactivate it instead.'];
    }

    if (($matchingAccount['status'] ?? '') === 'active' && $activeCount <= 1) {
        return ['allowed' => false, 'reason' => 'The only active GCash account cannot be deleted. Activate another account first.'];
    }

    return ['allowed' => true, 'reason' => ''];
}

function delete_gcash_account(array $viewer, int $accountId): void
{
    ensure_gcash_account_schema();

    if (($viewer['role'] ?? '') !== 'administrator' || empty($viewer['id'])) {
        throw new RuntimeException('Only the Administrator can delete GCash payment settings.');
    }
    if ($accountId <= 0) {
        throw new InvalidArgumentException('Please select a valid GCash account.');
    }

    $db = get_db();
    $qrCodePath = '';
    $db->begin_transaction();

    try {
        $accounts = db_select('SELECT * FROM gcash_payment_accounts ORDER BY id FOR UPDATE');
        $account = null;
        foreach ($accounts as $candidate) {
            if ((int) $candidate['id'] === $accountId) {
                $account = $candidate;
                break;
            }
        }

        if ($account === null) {
            throw new InvalidArgumentException('The selected GCash account no longer exists.');
        }

        $assessment = gcash_account_deletion_assessment($account, $accounts);
        if (!$assessment['allowed']) {
            throw new InvalidArgumentException((string) $assessment['reason']);
        }

        $qrCodePath = (string) ($account['qr_code_path'] ?? '');
        db_execute('DELETE FROM gcash_payment_accounts WHERE id = ?', 'i', [$accountId]);
        log_audit(
            (int) $viewer['id'],
            'gcash_account_deleted',
            'Deleted GCash payment account #' . $accountId . ' (' . (string) $account['account_name'] . ').'
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    delete_local_gcash_qr($qrCodePath);
}

function save_gcash_account(array $viewer, array $payload, ?array $uploadedQr = null): int
{
    ensure_gcash_account_schema();

    if (($viewer['role'] ?? '') !== 'administrator' || empty($viewer['id'])) {
        throw new RuntimeException('Only the Administrator can update GCash payment settings.');
    }

    $id = max(0, (int) ($payload['id'] ?? 0));
    $accountName = trim((string) ($payload['account_name'] ?? ''));
    $accountNumber = normalize_gcash_number((string) ($payload['account_number'] ?? ''));
    $status = $id > 0
        ? require_allowed_value((string) ($payload['status'] ?? 'active'), ['active', 'inactive'], 'Please select a valid GCash account status.')
        : 'active';
    if ($accountName === '' || strlen($accountName) > 150) {
        throw new InvalidArgumentException('GCash account name is required and must be 150 characters or fewer.');
    }

    $current = $id > 0 ? fetch_gcash_account($id) : null;
    if ($id > 0 && !$current) {
        throw new InvalidArgumentException('The selected GCash account does not exist.');
    }

    $currentQr = (string) ($current['qr_code_path'] ?? '');
    $originalQr = $currentQr;
    $removeQr = ($payload['remove_qr'] ?? '') === '1';
    $qrCodePath = save_uploaded_gcash_qr($uploadedQr, $removeQr ? '' : $currentQr);
    $newQrStaged = $qrCodePath !== '' && $qrCodePath !== $currentQr;
    $db = get_db();
    $db->begin_transaction();

    try {
        // Serialize account changes with deletion checks so concurrent requests
        // cannot invalidate the last-account and last-active-account rules.
        db_select('SELECT id FROM gcash_payment_accounts ORDER BY id FOR UPDATE');
        $current = $id > 0 ? db_find('SELECT * FROM gcash_payment_accounts WHERE id = ? LIMIT 1', 'i', [$id]) : null;
        if ($id > 0 && !$current) {
            throw new InvalidArgumentException('The selected GCash account no longer exists.');
        }
        $currentQr = (string) ($current['qr_code_path'] ?? '');
        if ($id > 0 && $currentQr !== $originalQr) {
            throw new InvalidArgumentException('The GCash QR code changed while you were editing. Reload the account and try again.');
        }

        if ($current) {
            db_execute(
                'UPDATE gcash_payment_accounts
                 SET account_name = ?, account_number = ?, qr_code_path = ?, status = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = ?',
                'ssssii',
                [$accountName, $accountNumber, $qrCodePath !== '' ? $qrCodePath : null, $status, (int) $viewer['id'], $id]
            );
            $accountId = $id;
            $action = 'gcash_account_updated';
        } else {
            db_execute(
                'INSERT INTO gcash_payment_accounts (account_name, account_number, qr_code_path, status, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                'ssssi',
                [$accountName, $accountNumber, $qrCodePath !== '' ? $qrCodePath : null, $status, (int) $viewer['id']]
            );
            $accountId = db_last_id();
            $action = 'gcash_account_created';
        }

        log_audit((int) $viewer['id'], $action, 'Saved GCash payment account #' . $accountId . ' with status ' . $status . '.');
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        if ($newQrStaged) {
            delete_local_gcash_qr($qrCodePath);
        }
        throw $exception;
    }

    if (($removeQr || ($newQrStaged && $currentQr !== $qrCodePath)) && $currentQr !== '') {
        delete_local_gcash_qr($currentQr);
    }

    return $accountId;
}
