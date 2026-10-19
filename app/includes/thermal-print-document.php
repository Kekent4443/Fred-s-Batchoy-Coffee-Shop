<?php

declare(strict_types=1);

$thermalPrintAuto = (bool) ($thermalPrintAuto ?? false);
$thermalPrintBackUrl = (string) ($thermalPrintBackUrl ?? url('index.php'));
$thermalSize = $paperWidth === '80mm' ? '80' : '58';
?>
<!DOCTYPE html>
<html lang="en" class="thermal-print-document" data-thermal-size="<?= e($thermalSize) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Thermal Receipt</title>
    <link rel="stylesheet" href="<?= e(asset('css/thermal-print.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/qz-receipt.css')) ?>">
</head>
<body class="thermal-print-document">
    <div class="thermal-print-page">
        <div class="thermal-print-controls no-print" aria-label="Thermal receipt print controls">
            <div class="thermal-print-actions">
                <button id="printReceiptButton" type="button" data-qz-receipt-print data-order-id="<?= (int) ($order['id'] ?? 0) ?>"<?= $thermalPrintAuto ? ' data-qz-auto-print' : '' ?>>Print Receipt</button>
                <a href="<?= e($thermalPrintBackUrl) ?>">Back</a>
            </div>
        </div>

        <main class="thermal-print-sheet" aria-label="Thermal receipt print document">
            <?php require APP_ROOT . '/app/includes/thermal-receipt.php'; ?>
        </main>
    </div>
    <?php require APP_ROOT . '/app/includes/qz-receipt-config.php'; ?>
    <script src="<?= e(asset('js/vendor/qz-tray.js')) ?>"></script>
    <script src="<?= e(asset('js/qz-receipt.js')) ?>"></script>
</body>
</html>
