<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Disposable print fixtures must not depend on a live database or session.
require_once dirname(__DIR__) . '/app/config/app.php';
require_once APP_ROOT . '/app/helpers/functions.php';
require_once APP_ROOT . '/app/helpers/pdf_receipt.php';
require_once APP_ROOT . '/app/helpers/csrf.php';

$fixtureDirectory = APP_ROOT . '/tmp/pdfs';
if (!is_dir($fixtureDirectory) && !mkdir($fixtureDirectory, 0700, true) && !is_dir($fixtureDirectory)) {
    throw new RuntimeException('Could not create the disposable receipt fixture directory.');
}

function fixture_file_url(string $path): string
{
    return 'file:///' . ltrim(str_replace('\\', '/', $path), '/');
}

function assert_pdf_fits_roll(string $pdf, float $expectedWidth): void
{
    if (!preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $pdf, $page)) {
        throw new RuntimeException('Receipt PDF is missing its paper dimensions.');
    }
    $width = (float) $page[1];
    $height = (float) $page[2];
    if (abs($width - $expectedWidth) > 0.1) {
        throw new RuntimeException('Receipt PDF has the wrong paper width.');
    }

    preg_match_all('/\/F[12] (\d+) Tf\n([\d.]+) ([\d.]+) Td\n\(([^\n]*)\) Tj\nET/', $pdf, $draws, PREG_SET_ORDER);
    if (count($draws) < 10) {
        throw new RuntimeException('Receipt PDF is missing expected text rows.');
    }
    $lowestBaseline = $height;
    foreach ($draws as $draw) {
        $size = (int) $draw[1];
        $x = (float) $draw[2];
        $y = (float) $draw[3];
        $lowestBaseline = min($lowestBaseline, $y);
        $plain = preg_replace('/\\\\([\\\\()])/', '$1', $draw[4]) ?? $draw[4];
        if ($x < 0 || $y < 0 || $y + $size > $height || $x + strlen($plain) * $size * 0.6 > $width - 1) {
            throw new RuntimeException('Receipt text extends beyond the paper bounds.');
        }
    }
    if ($lowestBaseline > 12.5) {
        throw new RuntimeException('Receipt PDF leaves unnecessary blank paper after its final line.');
    }
}

$order = [
    'reference_no' => 'TEST-RECEIPT-20261004-ABCDEFGH1234567890',
    'created_at' => '2026-10-04 13:45:00',
    'customer_name' => 'Sample Customer With A Longer Name',
    'prepared_by_name' => 'Sample Staff',
    'payment_method' => 'gcash',
    'payment_status' => 'paid',
    'order_status' => 'completed',
    'delivery_status' => 'delivered',
];
$items = [
    ['product_name' => 'Batchoy Regular With Extra Noodles And Egg', 'quantity' => 2, 'unit_price' => 110.00, 'subtotal' => 220.00],
    ['product_name' => 'Iced Coffee', 'quantity' => 1, 'unit_price' => 54.00, 'subtotal' => 54.00],
];
$pricing = [
    'subtotal' => 274.00,
    'discount_rate' => 3.65,
    'discount_amount' => 10.00,
    'promo_code' => 'TESTPROMO',
    'promo_discount_amount' => 5.00,
    'service_fee' => 0.00,
    'delivery_fee' => 20.00,
    'tax_rate' => 12.00,
    'vat_amount' => 29.89,
    'total' => 279.00,
];
$transaction = [
    'payment_method' => 'gcash',
    'transaction_status' => 'accepted',
    'gcash_reference' => '12345678901234567890ABCDEF',
];
$delivery = [
    'delivery_status' => 'delivered',
    'delivery_address' => 'Sample Street, Poblacion, Guihulngan City, Negros Oriental, Philippines',
    'delivery_landmark' => 'Across the public market near the main entrance',
    'rider_name' => 'Sample Rider',
];

foreach (['58mm', '80mm'] as $paperWidth) {
    $receiptSettings = [
        'business_name' => "Fred's Batchoy - TEST RECEIPT",
        'business_address' => 'Sample address only - not a real shop location',
        'contact_number' => 'TEST CONTACT',
        'contact_email' => 'sample@example.test',
        'social_media' => '',
        'footer_message' => 'Thank you for your order! This is test data only.',
        'paper_width' => $paperWidth,
    ];
    $thermalShowControls = false;
    $thermalPaperLocked = true;
    ob_start();
    require APP_ROOT . '/app/includes/thermal-receipt.php';
    $receiptHtml = (string) ob_get_clean();
    $size = $paperWidth === '58mm' ? '58' : '80';
    $style = htmlspecialchars(fixture_file_url(APP_ROOT . '/assets/css/thermal-print.css'), ENT_QUOTES, 'UTF-8');
    $script = htmlspecialchars(fixture_file_url(APP_ROOT . '/assets/js/thermal-print.js'), ENT_QUOTES, 'UTF-8');
    $standalone = '<!doctype html><html lang="en" class="thermal-print-document" data-thermal-size="' . $size . '"><head><meta charset="utf-8"><title>Disposable receipt fixture</title><link rel="stylesheet" href="' . $style . '"></head><body class="thermal-print-document"><div class="thermal-print-page"><div class="thermal-print-controls no-print"><label for="receiptSize">Paper size</label><select id="receiptSize"><option value="' . $paperWidth . '" selected>' . $paperWidth . '</option></select><button id="printReceiptButton" type="button">Print</button></div><main class="thermal-print-sheet">' . $receiptHtml . '</main></div><script src="' . $script . '"></script></body></html>';
    file_put_contents($fixtureDirectory . '/thermal-' . $size . '-standalone.html', $standalone);
    if ($size === '58') {
        $automaticPrintFixture = str_replace('data-thermal-size="58"', 'data-thermal-size="58" data-thermal-auto-print', $standalone);
        file_put_contents($fixtureDirectory . '/thermal-58-final-auto-test.html', $automaticPrintFixture);
        foreach (['consolas' => 'Consolas', 'lucida-console' => 'Lucida Console'] as $fontVariant => $fontFamily) {
            $fontFixture = str_replace('</head>', '<style>.thermal-receipt--text,.thermal-receipt--text > pre{font-family:"' . $fontFamily . '",monospace!important;font-size:9pt!important;font-weight:400!important}</style></head>', $automaticPrintFixture);
            file_put_contents($fixtureDirectory . '/thermal-58-' . $fontVariant . '-auto-test.html', $fontFixture);
            file_put_contents($fixtureDirectory . '/thermal-58-' . $fontVariant . '-manual-test.html', str_replace(' data-thermal-auto-print', '', $fontFixture));
        }
        $economyPrintFixture = str_replace('</head>', '<style>@media print { .thermal-receipt, .thermal-receipt * { print-color-adjust: economy !important; -webkit-print-color-adjust: economy !important; } }</style></head>', $automaticPrintFixture);
        file_put_contents($fixtureDirectory . '/thermal-58-economy-print-test.html', $economyPrintFixture);
        $driverWidthStyle = '<style>@media print { :root[data-thermal-size="58"] { --thermal-paper-width: 48mm; --thermal-receipt-width: 48mm; } :root[data-thermal-size="58"] .thermal-receipt--text { width: 48mm !important; max-width: 48mm !important; padding: 0 1mm !important; margin: 0 !important; } }</style>';
        $driverWidthScript = '<script>window.addEventListener("beforeprint", () => { const receipt = document.querySelector("[data-thermal-receipt]"); const pageStyle = document.querySelector("[data-thermal-page-style]"); if (!receipt || !pageStyle) return; const mm = Math.ceil((Math.max(receipt.scrollHeight, receipt.getBoundingClientRect().height) * 25.4 / 96 + 1) * 10) / 10; pageStyle.textContent = `@page { size: 48mm ${mm}mm; margin: 0; }`; });</script>';
        $driverWidthFixture = str_replace('</head>', $driverWidthStyle . '</head>', $automaticPrintFixture);
        $driverWidthFixture = str_replace('</body>', $driverWidthScript . '</body>', $driverWidthFixture);
        file_put_contents($fixtureDirectory . '/thermal-48-driver-width-auto-test.html', $driverWidthFixture);
        file_put_contents($fixtureDirectory . '/thermal-48-driver-width-manual-test.html', str_replace(' data-thermal-auto-print', '', $driverWidthFixture));
        // Same receipt and printer path, varying only the font weight for a
        // manual XP-58 comparison. These files contain disposable test data.
        foreach (['normal' => '400', 'bold' => '700'] as $variant => $weight) {
            $printFixture = $automaticPrintFixture;
            $printFixture = str_replace('</head>', '<style>.thermal-receipt--text > pre, .thermal-receipt--text > pre span { font-weight: ' . $weight . ' !important; }</style></head>', $printFixture);
            file_put_contents($fixtureDirectory . '/thermal-58-' . $variant . '-physical-test.html', $printFixture);
        }
    }

    $style = htmlspecialchars(fixture_file_url(APP_ROOT . '/assets/css/style.css'), ENT_QUOTES, 'UTF-8');
    $script = htmlspecialchars(fixture_file_url(APP_ROOT . '/assets/js/app.js'), ENT_QUOTES, 'UTF-8');
    $embedded = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Disposable embedded receipt fixture</title><link rel="stylesheet" href="' . $style . '"></head><body><main><h1 class="no-print">Receipt fixture</h1>' . $receiptHtml . '</main><script src="' . $script . '"></script></body></html>';
    file_put_contents($fixtureDirectory . '/thermal-' . $size . '-embedded.html', $embedded);

    $textLines = null;
    $pdf = build_receipt_pdf($order, $items, $pricing, $receiptSettings, $transaction, $delivery, $textLines);
    $directLines = build_receipt_text_lines($order, $items, $pricing, $receiptSettings, $transaction, $delivery);
    if ($paperWidth === '58mm') {
        $minimalText = htmlspecialchars(implode("\n", $directLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $minimalHtml = '<!doctype html><html><head><meta charset="utf-8"><title>Minimal 58mm HTML print diagnostic</title><style>@page{size:58mm 240mm;margin:0}*{box-sizing:border-box}html,body{width:58mm;margin:0;padding:0;background:#fff;color:#000}body{padding:2mm 5mm}pre{margin:0;font:400 9pt/1.2 "Courier New",Courier,monospace;white-space:pre;letter-spacing:0}</style></head><body><pre>' . $minimalText . '</pre><script>window.addEventListener("load",()=>{const h=Math.ceil((document.body.scrollHeight*25.4/96+1)*10)/10;document.head.appendChild(Object.assign(document.createElement("style"),{textContent:`@page{size:58mm ${h}mm;margin:0}`}));requestAnimationFrame(()=>window.print())},{once:true})</script></body></html>';
        file_put_contents($fixtureDirectory . '/thermal-58-minimal-html-test.html', $minimalHtml);
    }
    if (!preg_match(
        '/<pre data-thermal-text-preview="' . preg_quote($paperWidth, '/') . '"[^>]*>(.*?)<\/pre>/s',
        $receiptHtml,
        $previewMatch
    )) {
        throw new RuntimeException('The selected thermal preview is missing.');
    }
    $previewText = html_entity_decode(strip_tags($previewMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $maximumPreviewColumns = $paperWidth === '58mm' ? 32 : 48;
    $longestPreviewLine = max(array_map('strlen', $directLines));
    if (!is_array($textLines) || count($textLines) < 10
        || $textLines !== $directLines
        || $previewText !== implode("\n", $directLines)
        || str_contains($previewMatch[1], 'thermal-receipt__emphasis')
        || $longestPreviewLine > $maximumPreviewColumns
        || !str_contains($receiptHtml, 'data-thermal-text-preview="58mm"')
        || !str_contains($receiptHtml, 'data-thermal-text-preview="80mm"')
        || !in_array('ITEMS', $textLines, true)
        || !array_filter($textLines, static fn (string $line): bool => str_starts_with($line, 'GRAND TOTAL'))) {
        throw new RuntimeException('The preview and direct-print receipt layouts do not match.');
    }
    assert_pdf_fits_roll($pdf, $paperWidth === '58mm' ? 136.06 : 226.77);
    file_put_contents($fixtureDirectory . '/thermal-' . $size . '-download.pdf', $pdf);

    $shortOrder = [
        'reference_no' => 'TEST-XP58-001',
        'created_at' => '2026-10-04 13:45:00',
        'customer_name' => 'Sample Customer',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'order_status' => 'completed',
    ];
    $shortItems = [['product_name' => 'Batchoy Regular', 'quantity' => 1, 'unit_price' => 60.00, 'subtotal' => 60.00]];
    $shortPricing = ['subtotal' => 60.00, 'tax_rate' => 0.00, 'total' => 60.00];
    $shortSettings = [
        'business_name' => "Fred's Batchoy - TEST",
        'business_address' => '',
        'contact_number' => '',
        'contact_email' => '',
        'footer_message' => 'TEST ONLY - no real order or payment.',
        'paper_width' => $paperWidth,
    ];
    $shortPdf = build_receipt_pdf($shortOrder, $shortItems, $shortPricing, $shortSettings);
    assert_pdf_fits_roll($shortPdf, $paperWidth === '58mm' ? 136.06 : 226.77);
    $shortLines = build_receipt_text_lines($shortOrder, $shortItems, $shortPricing, $shortSettings);
    if (count($shortLines) < 10 || max(array_map('strlen', $shortLines)) > $maximumPreviewColumns) {
        throw new RuntimeException('The short receipt exceeds the printable text width.');
    }
    file_put_contents($fixtureDirectory . '/thermal-' . $size . '-short-test.pdf', $shortPdf);

    $longItems = array_fill(0, 18, [
        'product_name' => 'Batchoy Special With Extra Noodles And A Very Long Item Name',
        'quantity' => 12,
        'unit_price' => 12345.67,
        'subtotal' => 148148.04,
    ]);
    $longOrder = $order;
    $longOrder['reference_no'] = 'TEST-LONG-RECEIPT-20261004-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $longOrder['customer_name'] = 'Jose Nino With A Very Long Customer Name For Thermal Receipt Testing';
    $longOrder['payment_method'] = 'cash';
    $longOrder['payment_status'] = 'pending';
    $longPricing = $pricing;
    $longPricing['subtotal'] = 2666664.72;
    $longPricing['discount_amount'] = 0;
    $longPricing['promo_discount_amount'] = 0;
    $longPricing['delivery_fee'] = 0;
    $longPricing['total'] = 2666664.72;
    $longDelivery = $delivery;
    $longDelivery['delivery_address'] = 'Very Long Delivery Address, Second Floor, Across The Public Market, Poblacion, Guihulngan City, Negros Oriental, Philippines';
    $longPdf = build_receipt_pdf($longOrder, $longItems, $longPricing, $receiptSettings, null, $longDelivery);
    assert_pdf_fits_roll($longPdf, $paperWidth === '58mm' ? 136.06 : 226.77);
    $longLines = build_receipt_text_lines($longOrder, $longItems, $longPricing, $receiptSettings, null, $longDelivery);
    if (count($longLines) < 70
        || max(array_map('strlen', $longLines)) > $maximumPreviewColumns
        || preg_match('/[^\x20-\x7E\n]/', implode("\n", $longLines))
        || !array_filter($longLines, static fn (string $line): bool => str_starts_with($line, 'VAT '))
        || !array_filter($longLines, static fn (string $line): bool => str_starts_with($line, 'GRAND TOTAL'))) {
        throw new RuntimeException('Long receipt wrapping or totals are incomplete.');
    }
    file_put_contents($fixtureDirectory . '/thermal-' . $size . '-long-optional.pdf', $longPdf);
}

$receiptSettings['paper_width'] = '58mm';
$thermalShowControls = true;
$thermalPaperLocked = false;
$_SESSION['csrf_token'] = str_repeat('a', 64);
ob_start();
require APP_ROOT . '/app/includes/thermal-receipt.php';
$localControls = (string) ob_get_clean();
if (!str_contains($localControls, 'data-qz-receipt-print')
    || str_contains($localControls, 'data-thermal-browser-print-link')
    || str_contains($localControls, 'data-thermal-local-form')) {
    throw new RuntimeException('The receipt must use the QZ Tray print control.');
}

$receiptSettings['paper_width'] = '80mm';
ob_start();
require APP_ROOT . '/app/includes/thermal-receipt.php';
$local80Controls = (string) ob_get_clean();
if (!preg_match('/data-qz-receipt-print[^>]*\bdisabled\b/', $local80Controls)) {
    throw new RuntimeException('The XP-58 QZ button must be disabled for 80mm preview.');
}

$testStyle = htmlspecialchars(fixture_file_url(APP_ROOT . '/assets/css/style.css'), ENT_QUOTES, 'UTF-8');
$testScript = htmlspecialchars(fixture_file_url(APP_ROOT . '/assets/js/app.js'), ENT_QUOTES, 'UTF-8');
$controlsTest = '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="' . $testStyle . '"></head><body>'
    . '<section class="section">' . $localControls . '</section>'
    . '<script src="' . $testScript . '"></script>'
    . '</body></html>';
file_put_contents($fixtureDirectory . '/thermal-controls-test.html', $controlsTest);

echo "Generated disposable receipt fixtures; verified shared QZ Tray controls.\n";
