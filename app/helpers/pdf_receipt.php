<?php

declare(strict_types=1);

function receipt_pdf_normalize(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    if (function_exists('transliterator_transliterate')) {
        $latin = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        if (is_string($latin)) {
            $value = $latin;
        }
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted)) {
            $value = $converted;
        }
    } else {
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
    }

    $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

    return $value;
}

function receipt_pdf_escape_encoded(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function receipt_pdf_text(string $value): string
{
    return receipt_pdf_escape_encoded(receipt_pdf_normalize($value));
}

function receipt_pdf_fit(string $value, int $length): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value) > $length) {
        return rtrim(mb_substr($value, 0, max(1, $length - 3))) . '...';
    }

    return $value;
}

function receipt_pdf_money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2, '.', ',');
}

function receipt_pdf_number(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function receipt_print_layout(
    array $order,
    array $items,
    array $pricing,
    array $settings,
    ?array $transaction = null,
    ?array $delivery = null
): array {
    $businessName = trim((string) ($settings['business_name'] ?? app_settings()['brand_name'] ?? APP_NAME));
    $businessAddress = trim((string) ($settings['business_address'] ?? ''));
    $contact = trim(implode(' | ', array_filter([
        (string) ($settings['contact_number'] ?? ''),
        (string) ($settings['contact_email'] ?? ''),
    ])));
    $reference = (string) ($order['reference_no'] ?? ('ORDER-' . (int) ($order['id'] ?? 0)));
    $createdAt = format_display_datetime($order['created_at'] ?? 'now');
    $paymentMethod = strtoupper((string) ($transaction['payment_method'] ?? $order['payment_method'] ?? ''));
    $paymentState = (string) ($transaction['transaction_status'] ?? $order['payment_status'] ?? 'pending');
    $paymentStatus = in_array($paymentState, ['accepted', 'completed', 'paid'], true)
        ? 'Paid'
        : (in_array($paymentState, ['failed', 'cancelled'], true) ? ucfirst($paymentState) : 'Pending');
    $orderStatus = ucwords(str_replace('_', ' ', (string) ($order['order_status'] ?? 'pending')));
    $deliveryStatus = ucwords(str_replace('_', ' ', (string) ($delivery['delivery_status'] ?? $order['delivery_status'] ?? 'pending')));

    $paperWidth = ($settings['paper_width'] ?? '58mm') === '80mm' ? '80mm' : '58mm';
    // Leave safe side margins on a 58mm roll; the printable area varies by
    // printer and driver, so text must fit without relying on a model name.
    $pageWidth = $paperWidth === '58mm' ? 136.06 : 226.77;
    $pageMargin = $paperWidth === '58mm' ? 4.0 : 10.0;
    // Courier glyphs are 0.6em wide. Derive capacity from printable points,
    // not a fixed character count that can overrun both thermal paper sizes.
    $capacity = static fn (int $size): int => max(1, (int) floor(($pageWidth - 2 * $pageMargin - 1) / ($size * 0.6)));
    $lineHeight = $paperWidth === '58mm' ? 11.5 : 12.0;
    $lines = [];
    $append = static function (string $text = '', bool $bold = false, int $size = 10, bool $encoded = false) use (&$lines, $lineHeight, $paperWidth): void {
        $lines[] = [
            'text' => $encoded ? $text : receipt_pdf_normalize($text),
            // Physical HTML print tests showed no reliable clarity benefit
            // from bold on 58mm; keep a uniform normal-weight layout.
            'bold' => $paperWidth === '80mm' && $bold,
            'size' => $size,
            'advance' => max($lineHeight, $size * 1.25),
        ];
    };
    $appendWrapped = static function (string $text, bool $bold = false, int $size = 10) use ($append, $capacity): void {
        $text = receipt_pdf_normalize($text);
        foreach (explode("\n", wordwrap($text, $capacity($size), "\n", true)) as $line) {
            $append($line, $bold, $size, true);
        }
    };
    $appendLabeled = static function (string $label, string $value, bool $bold = false, int $size = 9) use ($append, $appendWrapped, $capacity): void {
        $label = receipt_pdf_normalize($label);
        $value = receipt_pdf_normalize($value);
        if (strlen($label) + 1 + strlen($value) <= $capacity($size)) {
            $append($label . ' ' . $value, $bold, $size, true);
            return;
        }
        $append($label, $bold, $size, true);
        $appendWrapped($value, false, $size);
    };
    $appendPair = static function (string $label, string $value, bool $bold = false, int $size = 9) use ($append, $appendWrapped, $capacity): void {
        $label = receipt_pdf_normalize($label);
        $value = receipt_pdf_normalize($value);
        $width = $capacity($size);
        if (strlen($label) + strlen($value) + 1 <= $width) {
            $append($label . str_repeat(' ', $width - strlen($label) - strlen($value)) . $value, $bold, $size, true);
            return;
        }
        $appendWrapped($label, $bold, $size);
        foreach (explode("\n", wordwrap($value, $width, "\n", true)) as $line) {
            $append(str_pad($line, $width, ' ', STR_PAD_LEFT), $bold, $size, true);
        }
    };

    $appendWrapped($businessName, true, $paperWidth === '58mm' ? 12 : 13);
    if ($businessAddress !== '') {
        $appendWrapped($businessAddress, false, 9);
    }
    if ($contact !== '') {
        $appendWrapped($contact, false, 9);
    }
    $append();
    $appendWrapped('OFFICIAL ORDER RECEIPT', true, 11);
    $appendLabeled('Reference:', $reference, true, 10);
    $appendLabeled('Created:', $createdAt, false, 9);
    $appendLabeled('Customer:', (string) ($order['customer_name'] ?? 'Customer'), false, 9);
    if (!empty($order['prepared_by_name'])) {
        $appendLabeled('Cashier/Staff:', (string) $order['prepared_by_name'], false, 9);
    }
    if (!empty($delivery['rider_name'])) {
        $appendLabeled('Rider:', (string) $delivery['rider_name'], false, 9);
    }
    $appendLabeled('Payment:', $paymentMethod . ' - ' . $paymentStatus, true, 9);
    $appendLabeled('Order:', $orderStatus, true, 9);
    if ($delivery !== null) {
        $appendLabeled('Delivery:', $deliveryStatus, true, 9);
    }
    $deliveryAddress = trim((string) ($delivery['delivery_address'] ?? $order['customer_address'] ?? ''));
    if ($deliveryAddress !== '') {
        $appendLabeled('Deliver to:', $deliveryAddress, false, 9);
    }
    if (!empty($delivery['delivery_landmark'])) {
        $appendLabeled('Landmark:', (string) $delivery['delivery_landmark'], false, 9);
    }
    $append();
    $append('ITEMS', true, 10);
    $append(str_repeat('-', $capacity(9)), false, 9);

    foreach ($items as $item) {
        $name = (string) ($item['product_name'] ?? $item['name'] ?? 'Item');
        $quantity = (int) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $lineTotal = (float) ($item['subtotal'] ?? ($unitPrice * $quantity));
        $appendWrapped($name, true, 10);
        $appendPair($quantity . ' x ' . receipt_pdf_money($unitPrice), receipt_pdf_money($lineTotal), false, 9);
    }

    $append(str_repeat('-', $capacity(9)), false, 9);
    $summaryLine = static function (string $label, float $amount, bool $negative = false) use ($appendPair): void {
        $formatted = ($negative ? '-' : '') . receipt_pdf_money($amount);
        $appendPair($label, $formatted, false, 9);
    };
    $summaryLine('Subtotal', (float) ($pricing['subtotal'] ?? 0));
    if ((float) ($pricing['discount_amount'] ?? 0) > 0) {
        $summaryLine('Bulk discount', (float) $pricing['discount_amount'], true);
    }
    if ((float) ($pricing['promo_discount_amount'] ?? 0) > 0) {
        $summaryLine('Promo ' . (string) ($pricing['promo_code'] ?? ''), (float) $pricing['promo_discount_amount'], true);
    }
    if ((float) ($pricing['service_fee'] ?? 0) > 0) {
        $summaryLine('Service fee', (float) $pricing['service_fee']);
    }
    if ((float) ($pricing['delivery_fee'] ?? 0) > 0) {
        $summaryLine('Delivery fee', (float) $pricing['delivery_fee']);
    }
    if ((float) ($pricing['tax_rate'] ?? 0) > 0) {
        $summaryLine('VAT (' . rtrim(rtrim(number_format((float) $pricing['tax_rate'], 2), '0'), '.') . '%, included)', (float) ($pricing['vat_amount'] ?? 0));
    }
    $appendPair('GRAND TOTAL', receipt_pdf_money((float) ($pricing['total'] ?? 0)), true, 10);
    $append();
    if ($paymentMethod === 'GCASH') {
        $gcashReference = trim((string) ($transaction['gcash_reference'] ?? $order['gcash_reference'] ?? ''));
        if ($gcashReference !== '') {
            $appendLabeled('GCash ref:', $gcashReference, false, 9);
        }
    }
    $append();
    if ($paymentMethod === 'CASH' && $paymentStatus !== 'Paid') {
        $appendWrapped(
            $delivery !== null
                ? 'Cash payment remains pending until the assigned rider records delivery and cash received.'
                : 'Cash payment remains pending until staff validates it.',
            false,
            9
        );
    }
    $appendWrapped((string) ($settings['footer_message'] ?? 'Thank you for your order!'), false, 9);

    return [$lines, $pageWidth, $pageMargin];
}

function build_receipt_text_lines(
    array $order,
    array $items,
    array $pricing,
    array $settings,
    ?array $transaction = null,
    ?array $delivery = null
): array {
    [$lines] = receipt_print_layout($order, $items, $pricing, $settings, $transaction, $delivery);

    return array_column($lines, 'text');
}

function build_receipt_pdf(
    array $order,
    array $items,
    array $pricing,
    array $settings,
    ?array $transaction = null,
    ?array $delivery = null,
    ?array &$textLines = null
): string {
    [$lines, $pageWidth, $pageMargin] = receipt_print_layout($order, $items, $pricing, $settings, $transaction, $delivery);
    $textLines = array_column($lines, 'text');

    $pages = [$lines];
    $advances = array_slice(array_column($lines, 'advance'), 0, -1);
    $pageHeight = max(72.0, ($pageMargin * 2) + (float) ($lines[0]['size'] ?? 10) + array_sum($advances));

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>';
    $pageObjectIds = [];
    $nextObjectId = 5;

    foreach ($pages as $index => $pageLines) {
        $pageId = $nextObjectId++;
        $contentId = $nextObjectId++;
        $pageObjectIds[] = $pageId;
        $commands = [];
        $baseline = $pageHeight - $pageMargin - (float) ($pageLines[0]['size'] ?? 10);
        foreach ($pageLines as $line) {
            $font = $line['bold'] ? 'F2' : 'F1';
            $size = max(8, min(18, (int) $line['size']));
            $commands[] = 'BT';
            $commands[] = '/' . $font . ' ' . $size . ' Tf';
            $commands[] = receipt_pdf_number($pageMargin) . ' ' . receipt_pdf_number($baseline) . ' Td';
            $commands[] = '(' . receipt_pdf_escape_encoded((string) $line['text']) . ') Tj';
            $commands[] = 'ET';
            $baseline -= (float) $line['advance'];
        }
        $stream = implode("\n", $commands) . "\n";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . receipt_pdf_number($pageWidth) . ' ' . receipt_pdf_number($pageHeight) . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }

    $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageObjectIds));
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageObjectIds) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $objectCount = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 " . $objectCount . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id < $objectCount; $id++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
    }
    $pdf .= 'trailer << /Size ' . $objectCount . ' /Root 1 0 R >>' . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}
