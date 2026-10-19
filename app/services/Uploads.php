<?php

declare(strict_types=1);

function uploaded_file_mime(array $uploadedFile): string
{
    if (!is_readable((string) ($uploadedFile['tmp_name'] ?? ''))) {
        return '';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    return (string) $finfo->file((string) $uploadedFile['tmp_name']);
}

function assert_uploaded_file_type(array $uploadedFile, array $allowedMimeTypes, string $message): void
{
    $mimeType = uploaded_file_mime($uploadedFile);

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new InvalidArgumentException($message);
    }
}

function private_upload_storage_root(): string
{
    return defined('PRIVATE_UPLOAD_ROOT')
        ? rtrim((string) PRIVATE_UPLOAD_ROOT, '/\\')
        : APP_ROOT . DIRECTORY_SEPARATOR . 'private-uploads';
}

function private_upload_directory(string $directory): string
{
    $directory = trim(str_replace('\\', '/', $directory), '/');

    if ($directory === '' || str_contains($directory, '..') || !preg_match('/^[a-z0-9\/-]+$/', $directory)) {
        throw new InvalidArgumentException('Invalid private upload directory.');
    }

    $target = private_upload_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to prepare the private upload folder.');
    }

    return $target;
}

function delete_private_upload(?string $storedPath): void
{
    $storedPath = trim(str_replace('\\', '/', (string) $storedPath));
    if (!str_starts_with($storedPath, 'private-uploads/') || str_contains($storedPath, '..')) {
        return;
    }

    $relative = substr($storedPath, strlen('private-uploads/'));
    if ($relative === '' || !preg_match('~^[A-Za-z0-9_./-]+$~', $relative)) {
        return;
    }

    $root = realpath(private_upload_storage_root());
    $target = realpath(private_upload_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($root === false || $target === false || !is_file($target)) {
        return;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalizedTarget = str_replace('\\', '/', $target);
    if (str_starts_with($normalizedTarget, $rootPrefix)) {
        @unlink($target);
    }
}

function save_uploaded_product_image(?array $uploadedImage, string $currentImageUrl = ''): string
{
    if (is_array($uploadedImage)) {
        foreach (['name', 'tmp_name', 'error', 'size'] as $field) {
            if (isset($uploadedImage[$field]) && !is_scalar($uploadedImage[$field])) {
                throw new InvalidArgumentException('Unable to upload the selected image. Please choose a valid image file.');
            }
        }
    }

    $hasUploadedImage = is_array($uploadedImage)
        && ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && !empty($uploadedImage['name']);

    if (!$hasUploadedImage) {
        return $currentImageUrl;
    }

    if (($uploadedImage['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($uploadedImage['tmp_name'])) {
        throw new InvalidArgumentException('Unable to upload the selected image. Please try again.');
    }

    $maxBytes = 5 * 1024 * 1024;

    if ((int) ($uploadedImage['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('Product image must be 5MB or smaller.');
    }

    $extension = strtolower((string) pathinfo((string) $uploadedImage['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new InvalidArgumentException('Product image must be a JPG, JPEG, PNG, GIF, or WEBP file.');
    }

    assert_uploaded_file_type($uploadedImage, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'Product image content must be a valid JPG, PNG, GIF, or WEBP file.');

    $targetDirectory = UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'products';

    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        throw new InvalidArgumentException('Unable to prepare the product upload folder.');
    }

    $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($uploadedImage['tmp_name'], $targetPath)) {
        throw new InvalidArgumentException('Unable to save the uploaded product image.');
    }

    return 'uploads/products/' . $filename;
}

function delete_local_product_image(?string $storedPath): void
{
    $storedPath = trim(str_replace('\\', '/', (string) $storedPath));
    if (!str_starts_with($storedPath, 'uploads/products/') || str_contains($storedPath, '..')) {
        return;
    }

    $relative = substr($storedPath, strlen('uploads/products/'));
    if ($relative === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $relative)) {
        return;
    }

    $root = realpath(UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'products');
    $target = realpath(UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $relative);
    if ($root === false || $target === false || !is_file($target)) {
        return;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    if (str_starts_with(str_replace('\\', '/', $target), $rootPrefix)) {
        @unlink($target);
    }
}

function save_uploaded_payment_proof(?array $uploadedImage): ?string
{
    $hasUploadedImage = is_array($uploadedImage)
        && ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && !empty($uploadedImage['name']);

    if (!$hasUploadedImage) {
        return null;
    }

    if (($uploadedImage['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($uploadedImage['tmp_name'])) {
        throw new InvalidArgumentException('Unable to upload the proof of payment. Please try again.');
    }

    $maxBytes = 5 * 1024 * 1024;

    if ((int) ($uploadedImage['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('Proof of payment must be 5MB or smaller.');
    }

    $extension = strtolower((string) pathinfo((string) $uploadedImage['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new InvalidArgumentException('Proof of payment must be a picture or screenshot file.');
    }

    assert_uploaded_file_type($uploadedImage, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'Proof of payment content must be a valid image file.');

    $targetDirectory = private_upload_directory('payment-proofs');
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($uploadedImage['tmp_name'], $targetPath)) {
        throw new InvalidArgumentException('Unable to save the proof of payment.');
    }

    return 'private-uploads/payment-proofs/' . $filename;
}

function save_uploaded_delivery_proof(?array $uploadedImage): ?string
{
    $hasUploadedImage = is_array($uploadedImage)
        && ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && !empty($uploadedImage['name']);

    if (!$hasUploadedImage) {
        return null;
    }

    if (($uploadedImage['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($uploadedImage['tmp_name'])) {
        throw new InvalidArgumentException('Unable to upload the proof of delivery. Please try again.');
    }

    $maxBytes = 5 * 1024 * 1024;

    if ((int) ($uploadedImage['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('Proof of delivery must be 5MB or smaller.');
    }

    $extension = strtolower((string) pathinfo((string) $uploadedImage['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new InvalidArgumentException('Proof of delivery must be a JPG, JPEG, PNG, GIF, or WEBP file.');
    }

    assert_uploaded_file_type($uploadedImage, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'Proof of delivery content must be a valid image file.');

    $targetDirectory = private_upload_directory('delivery-proofs');
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($uploadedImage['tmp_name'], $targetPath)) {
        throw new InvalidArgumentException('Unable to save the proof of delivery.');
    }

    return 'private-uploads/delivery-proofs/' . $filename;
}

function save_uploaded_valid_id(?array $uploadedFile, bool $required = true): ?string
{
    $hasUploadedFile = is_array($uploadedFile)
        && ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && !empty($uploadedFile['name']);

    if (!$hasUploadedFile) {
        if ($required) {
            throw new InvalidArgumentException('Please upload a valid ID or photo before submitting your registration.');
        }

        return null;
    }

    if (($uploadedFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($uploadedFile['tmp_name'])) {
        throw new InvalidArgumentException('Unable to upload the valid ID. Please try again.');
    }

    $maxBytes = 5 * 1024 * 1024;

    if ((int) ($uploadedFile['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('Valid ID file must be 5MB or smaller.');
    }

    $extension = strtolower((string) pathinfo((string) $uploadedFile['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new InvalidArgumentException('Valid ID must be a JPG, PNG, GIF, WEBP, or PDF file.');
    }

    assert_uploaded_file_type($uploadedFile, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], 'Valid ID content must be a valid image or PDF file.');

    $targetDirectory = private_upload_directory('ids');
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
        throw new InvalidArgumentException('Unable to save the uploaded valid ID.');
    }

    return 'private-uploads/ids/' . $filename;
}

