<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/config/database.php';

function tracking_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tracking_test_render(int $orderId, int $customerId): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['id' => (string) $orderId];
    $_POST = [];
    $_SESSION['user_id'] = $customerId;
    clear_current_user_cache();
    ob_start();
    try {
        include APP_ROOT . '/public/customer/order-detail.php';
        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

function tracking_test_render_role_page(string $page, int $userId, array $query = []): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $query;
    $_POST = [];
    $_SESSION['user_id'] = $userId;
    clear_current_user_cache();
    ob_start();
    try {
        include APP_ROOT . '/public/' . $page;
        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

function tracking_test_render_receipt(string $page, int $userId, int $orderId, string $paper): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [$page === 'staff/receipt.php' ? 'order_id' : 'id' => (string) $orderId, 'paper' => $paper];
    $_POST = [];
    $_SESSION['user_id'] = $userId;
    clear_current_user_cache();
    ob_start();
    try {
        include APP_ROOT . '/public/' . $page;
        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

function tracking_test_receipt_preview(string $html, string $paper): string
{
    $matched = preg_match('/<pre data-thermal-text-preview="' . preg_quote($paper, '/') . '"[^>]*>(.*?)<\/pre>/s', $html, $parts);
    tracking_test_assert($matched === 1, $paper . ' thermal preview is missing.');
    return html_entity_decode($parts[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tracking_test_render_dual_thermal_controls(int $orderId, string $paper): string
{
    $order = fetch_order_by_id($orderId);
    $items = fetch_order_items($orderId);
    $transaction = fetch_transaction_by_order($orderId);
    $delivery = null;
    $pricing = order_pricing_summary($items, $order);
    $receiptSettings = receipt_settings();
    $receiptSettings['paper_width'] = $paper;
    $thermalPrintLocalUrl = url('receipt-local-print.php');
    $thermalBrowserPrintUrl = url('customer/receipt-print.php?id=' . $orderId);
    ob_start();
    try {
        require APP_ROOT . '/app/includes/thermal-receipt.php';
        return (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
}

$host = strtolower(database_setting('DB_HOST', 'host', '127.0.0.1'));
tracking_test_assert(in_array($host, ['localhost', '127.0.0.1', '::1'], true), 'Tests require local MySQL.');
$databaseName = 'freds_tracking_test_' . bin2hex(random_bytes(5));
tracking_test_assert(preg_match('/^freds_tracking_test_[a-f0-9]{10}$/', $databaseName) === 1, 'Unsafe disposable database name.');
$admin = new mysqli($host, database_setting('DB_USER', 'user', 'root'), database_setting('DB_PASS', 'password'), '', (int) database_setting('DB_PORT', 'port', '3306'));
$admin->query('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    putenv('DB_NAME=' . $databaseName);
    putenv('APP_ENV=testing');
    putenv('APP_AUTO_MIGRATE=0');
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    $db = get_db();
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $schema = explode('DELIMITER $$', $schema, 2)[0] . "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    $db->multi_query($schema);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());

    $ids = [];
    foreach (['customer', 'other_customer', 'staff', 'manager', 'administrator', 'rider', 'other_rider'] as $fixture) {
        $role = match ($fixture) {
            'other_customer' => 'customer',
            'other_rider' => 'rider',
            default => $fixture,
        };
        db_execute(
            'INSERT INTO users (full_name, email, password, phone, address, role, status) VALUES (?, ?, ?, ?, ?, ?, "active")',
            'ssssss',
            [ucwords(str_replace('_', ' ', $fixture)), $fixture . '@tracking.example.test', password_hash('TestPassword123!', PASSWORD_DEFAULT), '09170000001', 'Test address', $role]
        );
        $ids[$fixture] = db_last_id();
    }

    db_execute('INSERT INTO orders (reference_no, customer_id, subtotal_amount, total_amount) VALUES (?, ?, 60, 60)', 'si', ['TRACKING-TEST', $ids['customer']]);
    $orderId = db_last_id();
    db_execute('INSERT INTO categories (name) VALUES (?)', 's', ['Tracking Test Food']);
    $categoryId = db_last_id();
    db_execute('INSERT INTO products (category_id, name, price, stock_quantity) VALUES (?, ?, 60, 10)', 'is', [$categoryId, 'Tracking Test Batchoy']);
    $productId = db_last_id();
    db_execute('INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, 1, 60, 60)', 'ii', [$orderId, $productId]);
    db_execute('INSERT INTO deliveries (order_id, delivery_address) VALUES (?, ?)', 'is', [$orderId, 'Test Street, Test City']);
    db_execute('INSERT INTO transactions (order_id, payment_method, amount, transaction_status) VALUES (?, "cash", 60, "pending")', 'i', [$orderId]);

    $pendingHtml = tracking_test_render($orderId, $ids['customer']);
    tracking_test_assert(str_contains($pendingHtml, 'Pending Confirmation') && str_contains($pendingHtml, 'Order placed') && str_contains($pendingHtml, 'Refresh status'), 'Pending delivery tracking did not render.');
    tracking_test_assert(!str_contains($pendingHtml, 'Sent after every workflow update'), 'Unverified notification claim remains.');
    tracking_test_assert(str_contains($pendingHtml, 'css/customer-order-detail.css'), 'Responsive tracking stylesheet was not loaded.');
    $trackingCss = (string) file_get_contents(APP_ROOT . '/assets/css/customer-order-detail.css');
    tracking_test_assert(str_contains($trackingCss, '@media (max-width: 900px)')
        && str_contains($trackingCss, '@media (max-width: 480px)')
        && str_contains($trackingCss, 'overflow-wrap: anywhere'), 'Tablet/phone tracking rules are missing.');

    update_order_workflow_status($orderId, 'confirmed', $ids['staff']);
    update_order_workflow_status($orderId, 'preparing', $ids['staff']);
    update_order_workflow_status($orderId, 'ready_for_delivery', $ids['staff']);
    foreach (['admin/orders.php' => $ids['administrator'], 'manager/orders.php' => $ids['manager']] as $page => $viewerId) {
        $beforeAssignment = tracking_test_render_role_page($page, $viewerId);
        tracking_test_assert(str_contains($beforeAssignment, 'TRACKING-TEST')
            && str_contains($beforeAssignment, 'Select rider')
            && str_contains($beforeAssignment, 'Assign Rider'), $page . ' did not offer assignment for a ready order.');
    }
    $invalidRiderRejected = false;
    try {
        assign_delivery_to_staff($orderId, $ids['other_customer'], $ids['manager']);
    } catch (InvalidArgumentException $exception) {
        $invalidRiderRejected = true;
    }
    tracking_test_assert($invalidRiderRejected
        && (int) (db_find('SELECT assigned_staff_id FROM deliveries WHERE order_id = ?', 'i', [$orderId])['assigned_staff_id'] ?? 0) === 0,
        'Failed rider assignment changed the delivery.');
    assign_delivery_to_staff($orderId, $ids['rider'], $ids['manager']);
    $historyAfterAssignment = count(fetch_delivery_history($orderId));
    foreach ([$ids['rider'], $ids['other_rider']] as $repeatedRiderId) {
        $repeatRejected = false;
        try {
            assign_delivery_to_staff($orderId, $repeatedRiderId, $ids['administrator']);
        } catch (InvalidArgumentException $exception) {
            $repeatRejected = str_contains($exception->getMessage(), 'already has an assigned rider');
        }
        tracking_test_assert($repeatRejected, 'Repeated or forged assignment was accepted.');
    }
    tracking_test_assert(count(fetch_delivery_history($orderId)) === $historyAfterAssignment
        && (int) db_find('SELECT assigned_staff_id FROM deliveries WHERE order_id = ?', 'i', [$orderId])['assigned_staff_id'] === $ids['rider']
        && (int) db_find('SELECT assigned_staff_id FROM orders WHERE id = ?', 'i', [$orderId])['assigned_staff_id'] === $ids['rider'],
        'Repeated assignment changed the rider or created duplicate history.');
    foreach (['admin/orders.php' => $ids['administrator'], 'manager/orders.php' => $ids['manager'],
        'admin/deliveries.php' => $ids['administrator'], 'manager/deliveries.php' => $ids['manager']] as $page => $viewerId) {
        $assignedPage = tracking_test_render_role_page($page, $viewerId);
        tracking_test_assert(str_contains($assignedPage, 'TRACKING-TEST')
            && str_contains($assignedPage, 'Rider')
            && str_contains($assignedPage, 'aria-label="Assigned rider" disabled')
            && str_contains($assignedPage, 'data-ready-label="Assigned"')
            && str_contains($assignedPage, 'type="button" disabled'), $page . ' did not lock the assigned rider controls.');
    }
    $earlyCompletionRejected = false;
    try {
        update_delivery_status($orderId, 'delivered', $ids['rider'], '', null, null, 'Customer');
    } catch (InvalidArgumentException $exception) {
        $earlyCompletionRejected = true;
    }
    tracking_test_assert($earlyCompletionRejected, 'Rider skipped Out for Delivery.');
    update_delivery_status($orderId, 'out_for_delivery', $ids['rider']);
    $wrongRiderRejected = false;
    try {
        update_delivery_status($orderId, 'delivered', $ids['other_rider'], '', null, null, 'Customer');
    } catch (InvalidArgumentException $exception) {
        $wrongRiderRejected = true;
    }
    tracking_test_assert($wrongRiderRejected, 'An unassigned rider changed the delivery.');
    update_delivery_status($orderId, 'issue_reported', $ids['rider'], 'Customer not available.');
    $issueHtml = tracking_test_render($orderId, $ids['customer']);
    tracking_test_assert(str_contains($issueHtml, 'Issue Reported') && str_contains($issueHtml, 'Customer not available.'), 'Issue tracking did not render.');
    tracking_test_assert(!str_contains($issueHtml, 'Sent after every workflow update'), 'Issue tracking retained a false notification claim.');
    $adminIssueHtml = tracking_test_render_role_page('admin/deliveries.php', $ids['administrator']);
    tracking_test_assert(str_contains($adminIssueHtml, 'TRACKING-TEST') && str_contains($adminIssueHtml, 'Resolve Issue'), 'Administrator issue action was not visible.');
    $managerIssueHtml = tracking_test_render_role_page('manager/deliveries.php', $ids['manager']);
    tracking_test_assert(str_contains($managerIssueHtml, 'TRACKING-TEST') && str_contains($managerIssueHtml, 'manager-deliveries__issue') && str_contains($managerIssueHtml, 'Resolution reason'), 'Manager issue panel did not render correctly.');
    $riderIssueHtml = tracking_test_render_role_page('rider/deliveries.php', $ids['rider']);
    tracking_test_assert(str_contains($riderIssueHtml, 'TRACKING-TEST') && str_contains($riderIssueHtml, 'No action available'), 'Rider issue state was not read-only.');

    resolve_delivery_exception($orderId, 'return_to_ready', $ids['manager'], null, '', 'Review complete.');
    assign_delivery_to_staff($orderId, $ids['rider'], $ids['manager']);
    update_delivery_status($orderId, 'out_for_delivery', $ids['rider']);
    update_delivery_status($orderId, 'delivered', $ids['rider'], '', null, null, 'Customer');
    $deliveredHtml = tracking_test_render($orderId, $ids['customer']);
    tracking_test_assert(str_contains($deliveredHtml, 'Your delivery has been completed.') && str_contains($deliveredHtml, 'Recorded Updates'), 'Delivered tracking did not render.');
    tracking_test_assert(str_contains($deliveredHtml, 'data-label="Product"') && str_contains($deliveredHtml, 'data-label="Subtotal"'), 'Order items lack mobile labels.');
    tracking_test_assert(str_contains($deliveredHtml, 'Rider assigned') && str_contains($deliveredHtml, 'Issue Reported'), 'History events were lost after resolution.');
    $managerDeliveredHtml = tracking_test_render_role_page('manager/deliveries.php', $ids['manager']);
    tracking_test_assert(str_contains($managerDeliveredHtml, 'Delivered') && str_contains($managerDeliveredHtml, 'data-label="Delivery address"'), 'Manager delivered status or mobile labels are missing.');
    $order = fetch_order_by_id($orderId);
    tracking_test_assert(($order['payment_status'] ?? '') === 'paid', 'COD payment did not complete with delivery.');
    $delivery = db_find('SELECT delivery_status, delivered_at, recipient_name FROM deliveries WHERE order_id = ?', 'i', [$orderId]);
    tracking_test_assert(($delivery['delivery_status'] ?? '') === 'delivered' && !empty($delivery['delivered_at']) && ($delivery['recipient_name'] ?? '') === 'Customer', 'Delivery record was not completed.');
    $history = fetch_delivery_history($orderId);
    tracking_test_assert(count($history) >= 9, 'Delivery history is incomplete.');
    foreach (['administrator', 'manager', 'rider', 'customer'] as $role) {
        $visible = fetch_deliveries($role, $ids[$role]);
        tracking_test_assert(count($visible) === 1 && ($visible[0]['delivery_status'] ?? '') === 'delivered', 'Delivery status was inconsistent for ' . $role . '.');
    }
    tracking_test_assert(fetch_deliveries('customer', $ids['other_customer']) === [], 'Another customer could see this delivery.');

    tracking_test_assert((receipt_settings()['paper_width'] ?? '') === '58mm', 'New receipt settings did not default to 58mm.');
    $defaultStaffReceipt = tracking_test_render_receipt('staff/receipt.php', $ids['staff'], $orderId, '');
    $defaultCustomerReceipt = tracking_test_render_receipt('customer/receipt-print.php', $ids['customer'], $orderId, '');
    tracking_test_assert(str_contains($defaultStaffReceipt, 'data-thermal-size="58"'), 'Staff default receipt width is not 58mm.');
    tracking_test_assert(str_contains($defaultCustomerReceipt, 'data-thermal-size="58"'), 'Customer default receipt width is not 58mm.');
    db_execute('UPDATE receipt_settings SET paper_width = "80mm", updated_by = NULL, updated_at = created_at WHERE id = 1');
    tracking_test_assert((receipt_settings()['paper_width'] ?? '') === '58mm', 'Untouched legacy 80mm default was not normalized to 58mm.');
    db_execute('UPDATE receipt_settings SET paper_width = "80mm", updated_by = ? WHERE id = 1', 'i', [$ids['administrator']]);
    $configuredStaffReceipt = tracking_test_render_receipt('staff/receipt.php', $ids['staff'], $orderId, '');
    $configuredCustomerReceipt = tracking_test_render_receipt('customer/receipt-print.php', $ids['customer'], $orderId, '');
    tracking_test_assert(str_contains($configuredStaffReceipt, 'data-thermal-size="58"')
        && str_contains($configuredCustomerReceipt, 'data-thermal-size="58"'), 'Saved 80mm setting changed Customer or Staff printing.');
    db_execute('UPDATE receipt_settings SET paper_width = "58mm", updated_by = NULL WHERE id = 1');

    foreach (['58mm', '80mm'] as $paper) {
        $customerReceipt = tracking_test_render_receipt('customer/receipt-print.php', $ids['customer'], $orderId, $paper);
        $staffReceipt = tracking_test_render_receipt('staff/receipt.php', $ids['staff'], $orderId, $paper);
        tracking_test_assert(str_contains($customerReceipt, 'data-thermal-size="58"')
            && str_contains($staffReceipt, 'data-thermal-size="58"')
            && !str_contains($customerReceipt, 'data-thermal-text-preview="80mm"')
            && !str_contains($staffReceipt, 'data-thermal-text-preview="80mm"'), 'A paper query changed the locked 58mm receipt.');
        tracking_test_assert(tracking_test_receipt_preview($customerReceipt, '58mm') === tracking_test_receipt_preview($staffReceipt, '58mm'),
            'Staff receipt differs from Customer 58mm output.');
        tracking_test_assert(str_contains($customerReceipt, 'data-qz-auto-print')
            && !str_contains($customerReceipt, 'id="receiptSize"')
            && str_contains($customerReceipt, 'data-qz-receipt-print'), 'Customer print page has extra settings or no QZ control.');
        tracking_test_assert(str_contains($staffReceipt, 'data-qz-auto-print')
            && !str_contains($staffReceipt, 'data-thermal-paper-select')
            && str_contains($staffReceipt, 'data-qz-receipt-print'), 'Staff print page did not start the QZ 58mm flow.');
    }
    $customerDetailReceipt = tracking_test_render($orderId, $ids['customer']);
    tracking_test_assert(str_contains($customerDetailReceipt, 'data-paper-width="58mm"')
        && !str_contains($customerDetailReceipt, 'data-thermal-paper-select')
        && !str_contains($customerDetailReceipt, 'data-thermal-text-preview="80mm"'), 'Customer order preview still offers 80mm.');
    $customerConfirmationReceipt = tracking_test_render_role_page('customer/order-confirmation.php', $ids['customer'], ['id' => (string) $orderId]);
    tracking_test_assert(str_contains($customerConfirmationReceipt, 'data-paper-width="58mm"')
        && !str_contains($customerConfirmationReceipt, 'data-thermal-paper-select'), 'Customer confirmation preview still offers 80mm.');
    $longReceiptOrder = fetch_order_by_id($orderId);
    $longReceiptOrder['customer_name'] = 'Customer With An Extended Name For Thermal Width Testing';
    $longReceiptItems = fetch_order_items($orderId);
    $longReceiptItems[0]['product_name'] = 'Extra Long Product Description That Must Wrap On Narrow Thermal Paper';
    $receiptSettings = receipt_settings();
    $receiptPricing = order_pricing_summary($longReceiptItems, $longReceiptOrder);
    $wrappedLineCounts = [];
    foreach (['58mm' => 24, '80mm' => 40] as $paper => $maxCharacters) {
        $receiptSettings['paper_width'] = $paper;
        $lines = build_receipt_text_lines($longReceiptOrder, $longReceiptItems, $receiptPricing, $receiptSettings);
        tracking_test_assert(max(array_map('strlen', $lines)) <= $maxCharacters, $paper . ' receipt has an overwide line.');
        $wrappedLineCounts[$paper] = count($lines);
    }
    tracking_test_assert($wrappedLineCounts['58mm'] > $wrappedLineCounts['80mm'], '58mm receipt did not wrap more than 80mm.');
    $local58Controls = tracking_test_render_dual_thermal_controls($orderId, '58mm');
    $local80Controls = tracking_test_render_dual_thermal_controls($orderId, '80mm');
    tracking_test_assert(str_contains($local58Controls, 'data-qz-receipt-print')
        && !str_contains($local58Controls, 'data-thermal-browser-print-link')
        && !str_contains($local58Controls, 'data-thermal-local-form'), 'Local 58mm preview did not select QZ printing.');
    tracking_test_assert(preg_match('/data-qz-receipt-print[^>]*\bdisabled\b/', $local80Controls) === 1
        && !str_contains($local80Controls, 'data-thermal-browser-print-link'), 'Optional 80mm preview still links to browser printing.');
    $staffTransactions = tracking_test_render_role_page('staff/transactions.php', $ids['staff']);
    tracking_test_assert(str_contains($staffTransactions, 'Print Receipt')
        && str_contains($staffTransactions, 'data-qz-receipt-print data-order-id="' . $orderId . '"')
        && str_contains($staffTransactions, 'data-action-icon="print"')
        && str_contains($staffTransactions, 'data-action-label="Print Receipt"')
        && str_contains($staffTransactions, 'title="Print Receipt"')
        && !str_contains($staffTransactions, 'receipt-local-print.php')
        && !str_contains($staffTransactions, 'data-confirm-message="Print'), 'Staff transaction does not use QZ printing.');
    $transactionId = (int) (fetch_transaction_by_order($orderId)['id'] ?? 0);
    $staffTransactionDetail = tracking_test_render_role_page('staff/transactions.php', $ids['staff'], ['view' => (string) $transactionId]);
    tracking_test_assert(substr_count($staffTransactionDetail, 'data-action-icon="print"') === 2,
        'Print Receipt icon is not present in both Payment Validation action locations.');
    ob_start();
    $receiptOrderId = $orderId;
    require APP_ROOT . '/app/includes/staff-receipt-print-action.php';
    $localStaffAction = (string) ob_get_clean();
    tracking_test_assert(str_contains($localStaffAction, 'data-qz-receipt-print')
        && !str_contains($localStaffAction, 'receipt-local-print.php')
        && !str_contains($localStaffAction, 'data-confirm-message'), 'Local Staff action is not a single QZ print button.');
    foreach (['admin/transactions.php' => $ids['administrator'], 'manager/transactions.php' => $ids['manager']] as $page => $viewerId) {
        $transactionsPage = tracking_test_render_role_page($page, $viewerId);
        tracking_test_assert(!str_contains($transactionsPage, 'Print Receipt'), $page . ' unexpectedly exposes a receipt action.');
    }

    db_execute('INSERT INTO orders (reference_no, customer_id, subtotal_amount, total_amount) VALUES (?, ?, 60, 60)', 'si', ['TRACKING-ADMIN', $ids['customer']]);
    $adminOrderId = db_last_id();
    db_execute('INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, 1, 60, 60)', 'ii', [$adminOrderId, $productId]);
    db_execute('INSERT INTO deliveries (order_id, delivery_address) VALUES (?, ?)', 'is', [$adminOrderId, 'Test Street, Test City']);
    db_execute('INSERT INTO transactions (order_id, payment_method, amount, transaction_status) VALUES (?, "cash", 60, "pending")', 'i', [$adminOrderId]);
    update_order_workflow_status($adminOrderId, 'confirmed', $ids['staff']);
    update_order_workflow_status($adminOrderId, 'preparing', $ids['staff']);
    update_order_workflow_status($adminOrderId, 'ready_for_delivery', $ids['staff']);
    assign_order_to_staff($adminOrderId, $ids['other_rider'], $ids['administrator']);
    tracking_test_assert((int) db_find('SELECT assigned_staff_id FROM deliveries WHERE order_id = ?', 'i', [$adminOrderId])['assigned_staff_id'] === $ids['other_rider'], 'Administrator assignment did not persist.');
    foreach (['admin/orders.php' => $ids['administrator'], 'manager/orders.php' => $ids['manager']] as $page => $viewerId) {
        $adminAssignedPage = tracking_test_render_role_page($page, $viewerId);
        tracking_test_assert(str_contains($adminAssignedPage, 'TRACKING-ADMIN')
            && str_contains($adminAssignedPage, 'Other Rider')
            && str_contains($adminAssignedPage, 'data-ready-label="Assigned"'), $page . ' did not show the Administrator-assigned rider as locked.');
    }

    echo "Customer tracking, receipt print markup and parity, staff/manager/rider workflow, exceptions, authorization, COD completion, history, and responsive markup passed in a disposable database.\n";
} finally {
    $admin->query('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    $admin->close();
}
