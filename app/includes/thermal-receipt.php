<?php
require_once APP_ROOT . '/app/helpers/pdf_receipt.php';

$thermalSettings = $receiptSettings ?? receipt_settings();
$thermalPaperLocked = (bool) ($thermalPaperLocked ?? false);
$thermalOnly58mm = (bool) ($thermalOnly58mm ?? false);
$thermalShowControls = (bool) ($thermalShowControls ?? true);
$thermalTransaction = $transaction ?? [];
$thermalDelivery = $delivery ?? [];
$thermalPricing = $pricing ?? order_pricing_summary($items ?? [], $order ?? []);
$thermalPaperWidth = $thermalOnly58mm ? '58mm' : (in_array(($thermalSettings['paper_width'] ?? '58mm'), ['58mm', '80mm'], true)
    ? (string) $thermalSettings['paper_width']
    : '58mm');
$thermalPreviewLines = [];
foreach ($thermalOnly58mm ? ['58mm'] : ['58mm', '80mm'] as $thermalPreviewWidth) {
    $thermalPreviewSettings = $thermalSettings;
    $thermalPreviewSettings['paper_width'] = $thermalPreviewWidth;
    $thermalPreviewLines[$thermalPreviewWidth] = build_receipt_text_lines(
        $order ?? [],
        $items ?? [],
        $thermalPricing,
        $thermalPreviewSettings,
        $thermalTransaction,
        $thermalDelivery
    );
}
?>
<style data-thermal-page-style media="print">
    @page { margin: 0; }
</style>
<?php if ($thermalShowControls): ?>
    <div class="thermal-receipt-controls no-print">
        <button class="btn btn-primary" type="button" data-qz-receipt-print data-order-id="<?= (int) ($order['id'] ?? 0) ?>" <?= $thermalPaperWidth === '80mm' ? 'disabled' : '' ?>>Print Receipt</button>
        <small class="thermal-receipt-controls__hint"><?= $thermalPaperWidth === '80mm' ? 'The XP-58 uses 58mm paper. Select 58mm before printing.' : 'Prints on 58mm paper through QZ Tray and the Xprinter XP-58.' ?></small>
    </div>
<?php endif; ?>

<article class="thermal-receipt thermal-receipt--text <?= $thermalPaperWidth === '80mm' ? 'paper-80' : 'paper-58' ?>" data-thermal-receipt data-paper-width="<?= e($thermalPaperWidth) ?>" aria-label="Thermal receipt preview for order <?= e((string) ($order['reference_no'] ?? '')) ?>">
    <?php foreach ($thermalPreviewLines as $thermalPreviewWidth => $thermalLines): ?>
        <?php $thermalPreviewActive = $thermalPreviewWidth === $thermalPaperWidth; ?>
        <pre data-thermal-text-preview="<?= e($thermalPreviewWidth) ?>" <?= $thermalPreviewActive ? '' : 'hidden' ?> aria-hidden="<?= $thermalPreviewActive ? 'false' : 'true' ?>"><?= e(implode("\n", $thermalLines)) ?></pre>
    <?php endforeach; ?>
</article>
