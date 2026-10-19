<?php

declare(strict_types=1);

const SITE_LOGO_DEFAULT_PATH = 'uploads/Freds.jpg';
const SITE_LOGO_UPLOAD_DIRECTORY = 'uploads/branding';
const SITE_LOGO_MAX_BYTES = 2 * 1024 * 1024;

function ensure_branding_schema(): void
{
    if (function_exists('schema_maintenance_enabled') && !schema_maintenance_enabled()) {
        return;
    }

    if (!database_column_exists('receipt_settings', 'site_logo')) {
        db_execute('ALTER TABLE receipt_settings ADD COLUMN site_logo VARCHAR(255) NULL AFTER social_media');
    }
}

function site_logo_stored_path(): string
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    if (!database_column_exists('receipt_settings', 'site_logo')) {
        return $path = '';
    }

    $settings = db_find('SELECT site_logo FROM receipt_settings WHERE id = 1 LIMIT 1');

    return $path = trim((string) ($settings['site_logo'] ?? ''));
}

function site_logo_file_path(string $storedPath): ?string
{
    $storedPath = str_replace('\\', '/', trim($storedPath));
    if (preg_match('#^uploads/branding/logo_[a-f0-9]{32}\.(?:png|jpe?g|webp)$#', $storedPath) !== 1) {
        return null;
    }

    $brandingRoot = realpath(UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'branding');
    $candidate = realpath(PUBLIC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath));
    if ($brandingRoot === false || $candidate === false || !is_file($candidate)) {
        return null;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $brandingRoot), '/') . '/';
    $normalizedCandidate = str_replace('\\', '/', $candidate);

    return str_starts_with($normalizedCandidate, $rootPrefix) ? $candidate : null;
}

function get_site_logo_url(): string
{
    $storedPath = site_logo_stored_path();
    if ($storedPath !== '' && site_logo_file_path($storedPath) !== null) {
        return url($storedPath);
    }

    $defaultFile = PUBLIC_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'Freds.jpg';
    $defaultUrl = url(SITE_LOGO_DEFAULT_PATH);
    if (is_file($defaultFile)) {
        $defaultUrl .= '?v=' . rawurlencode((string) filemtime($defaultFile));
    }

    return $defaultUrl;
}

function has_custom_site_logo(): bool
{
    $storedPath = site_logo_stored_path();

    return $storedPath !== '' && site_logo_file_path($storedPath) !== null;
}

function delete_custom_site_logo(string $storedPath): void
{
    $file = site_logo_file_path($storedPath);
    if ($file !== null) {
        @unlink($file);
    }
}

function validate_site_logo_upload(?array $upload): array
{
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a PNG, JPG, or WebP logo to upload.');
    }

    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Logo image must be 2 MB or smaller.'
            : 'Unable to receive the logo upload. Please try again.';
        throw new InvalidArgumentException($message);
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > SITE_LOGO_MAX_BYTES) {
        throw new InvalidArgumentException('Logo image must be a non-empty file no larger than 2 MB.');
    }

    $mime = uploaded_file_mime($upload);
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException('Logo content must be a valid PNG, JPG, or WebP image.');
    }

    $imageInfo = @getimagesize((string) $upload['tmp_name']);
    if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== $mime) {
        throw new InvalidArgumentException('The uploaded logo is not a valid image.');
    }

    return ['tmp_name' => (string) $upload['tmp_name'], 'extension' => $extensions[$mime]];
}

function save_site_logo(array $viewer, ?array $upload): void
{
    if (($viewer['role'] ?? '') !== 'administrator' || empty($viewer['id'])) {
        throw new RuntimeException('Only the Administrator can update system branding.');
    }
    if (!database_column_exists('receipt_settings', 'site_logo')) {
        throw new RuntimeException('Branding storage is not installed. Import the latest database migration first.');
    }

    $validated = validate_site_logo_upload($upload);
    $directory = UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'branding';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare the branding upload folder.');
    }

    $filename = 'logo_' . bin2hex(random_bytes(16)) . '.' . $validated['extension'];
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($validated['tmp_name'], $target)) {
        throw new RuntimeException('Unable to save the logo image.');
    }
    $newPath = SITE_LOGO_UPLOAD_DIRECTORY . '/' . $filename;

    $db = get_db();
    $db->begin_transaction();
    try {
        $current = db_find('SELECT site_logo FROM receipt_settings WHERE id = 1 LIMIT 1 FOR UPDATE') ?? [];
        $oldPath = trim((string) ($current['site_logo'] ?? ''));
        db_execute(
            'UPDATE receipt_settings SET site_logo = ?, updated_by = ?, updated_at = NOW() WHERE id = 1',
            'si',
            [$newPath, (int) $viewer['id']]
        );
        log_audit((int) $viewer['id'], 'site_logo_updated', 'Updated the global site logo.');
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        @unlink($target);
        throw $exception;
    }

    if ($oldPath !== '' && $oldPath !== $newPath) {
        delete_custom_site_logo($oldPath);
    }
}

function reset_site_logo(array $viewer): void
{
    if (($viewer['role'] ?? '') !== 'administrator' || empty($viewer['id'])) {
        throw new RuntimeException('Only the Administrator can reset system branding.');
    }
    if (!database_column_exists('receipt_settings', 'site_logo')) {
        throw new RuntimeException('Branding storage is not installed. Import the latest database migration first.');
    }

    $db = get_db();
    $db->begin_transaction();
    try {
        $current = db_find('SELECT site_logo FROM receipt_settings WHERE id = 1 LIMIT 1 FOR UPDATE') ?? [];
        $oldPath = trim((string) ($current['site_logo'] ?? ''));
        db_execute(
            'UPDATE receipt_settings SET site_logo = NULL, updated_by = ?, updated_at = NOW() WHERE id = 1',
            'i',
            [(int) $viewer['id']]
        );
        log_audit((int) $viewer['id'], 'site_logo_reset', 'Reset the global site logo to the built-in default.');
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    if ($oldPath !== '') {
        delete_custom_site_logo($oldPath);
    }
}
