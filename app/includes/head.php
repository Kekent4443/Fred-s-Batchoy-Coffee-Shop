<?php

$pageTitle = $pageTitle ?? APP_NAME;
$bodyClass = $bodyClass ?? '';
$htmlClass = str_contains($bodyClass, 'landing-portal-body') ? 'portal-page' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= e($htmlClass) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($metaReferrer)): ?><meta name="referrer" content="<?= e((string) $metaReferrer) ?>"><?php endif; ?>
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/notifications.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/system-modal.css')) ?>">
    <?php if (!empty($useQzReceipt)): ?>
        <link rel="stylesheet" href="<?= e(asset('css/qz-receipt.css')) ?>">
    <?php endif; ?>
    <?php if (!empty($isDashboardPage)): ?>
        <link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">
    <?php endif; ?>
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?>
        <link rel="stylesheet" href="<?= e(asset((string) $pageStyle)) ?>">
    <?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>">
<button class="system-theme-toggle system-theme-toggle--standalone" type="button" data-system-theme-toggle aria-label="Toggle dark mode" aria-pressed="false" title="Switch to dark mode">
    <?= system_theme_toggle_markup() ?>
</button>
