<?php

declare(strict_types=1);

function protected_upload_url(string $type, int $recordId): string
{
    $allowedTypes = ['valid_id', 'payment_proof', 'delivery_proof'];

    if (!in_array($type, $allowedTypes, true) || $recordId < 1) {
        return '';
    }

    return url('secure-upload.php?' . http_build_query(['type' => $type, 'id' => $recordId]));
}

function evidence_upload_subdirectory(string $type): string
{
    return match ($type) {
        'valid_id' => 'ids',
        'payment_proof' => 'payment-proofs',
        'delivery_proof' => 'delivery-proofs',
        default => '',
    };
}

/**
 * Resolve a stored evidence token while supporting legacy public upload paths.
 * Every candidate is constrained to the evidence-specific directory before it
 * can be read or deleted.
 */
function resolve_uploaded_evidence_path(string $storedPath, string $type = ''): ?string
{
    $storedPath = str_replace('\\', '/', trim($storedPath));

    if ($storedPath === '' || str_contains($storedPath, "\0") || str_contains($storedPath, '../') || str_starts_with($storedPath, '/')) {
        return null;
    }

    $types = $type !== '' ? [$type] : ['valid_id', 'payment_proof', 'delivery_proof'];

    foreach ($types as $candidateType) {
        $subdirectory = evidence_upload_subdirectory($candidateType);
        if ($subdirectory === '') {
            continue;
        }

        $privateRoot = defined('PRIVATE_UPLOAD_ROOT')
            ? PRIVATE_UPLOAD_ROOT . DIRECTORY_SEPARATOR . $subdirectory
            : APP_ROOT . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subdirectory;
        $legacyRoot = PUBLIC_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $subdirectory;
        $basename = basename($storedPath);
        $candidates = [];

        if (str_starts_with($storedPath, 'private-uploads/' . $subdirectory . '/')) {
            $candidates[] = $privateRoot . DIRECTORY_SEPARATOR . $basename;
        }
        if (str_starts_with($storedPath, 'private/uploads/' . $subdirectory . '/')) {
            $candidates[] = $privateRoot . DIRECTORY_SEPARATOR . $basename;
        }
        if (str_starts_with($storedPath, $subdirectory . '/')) {
            $candidates[] = $privateRoot . DIRECTORY_SEPARATOR . $basename;
        }
        if (str_starts_with($storedPath, 'uploads/' . $subdirectory . '/')) {
            $candidates[] = $legacyRoot . DIRECTORY_SEPARATOR . $basename;
            // Deployment migrations may move legacy files without rewriting
            // every historical database path in the same maintenance window.
            $candidates[] = $privateRoot . DIRECTORY_SEPARATOR . $basename;
        }

        foreach (array_unique($candidates) as $candidate) {
            $realFile = realpath($candidate);
            $realPrivateRoot = realpath($privateRoot);
            $realLegacyRoot = realpath($legacyRoot);

            if ($realFile === false || !is_file($realFile)) {
                continue;
            }

            $insidePrivate = $realPrivateRoot !== false && str_starts_with($realFile, $realPrivateRoot . DIRECTORY_SEPARATOR);
            $insideLegacy = $realLegacyRoot !== false && str_starts_with($realFile, $realLegacyRoot . DIRECTORY_SEPARATOR);

            if ($insidePrivate || $insideLegacy) {
                return $realFile;
            }
        }
    }

    return null;
}

function delete_uploaded_evidence(?string $storedPath): bool
{
    $resolved = resolve_uploaded_evidence_path((string) $storedPath);

    return $resolved !== null && is_file($resolved) ? @unlink($resolved) : false;
}
