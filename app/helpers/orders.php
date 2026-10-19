<?php

declare(strict_types=1);

function order_reference(): string
{
    return 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
}

function normalize_gcash_reference(string $reference): string
{
    $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($reference)));

    if (strlen($normalized) < 6 || strlen($normalized) > 40) {
        throw new InvalidArgumentException('Enter a valid GCash reference number (6 to 40 letters or digits).');
    }

    return $normalized;
}

function gcash_reference_hash(string $reference): string
{
    return hash('sha256', normalize_gcash_reference($reference));
}

function checkout_idempotency_key(int $customerId, array $payload, string $fingerprint): string
{
    $submitted = trim((string) ($payload['idempotency_key'] ?? $payload['checkout_idempotency_key'] ?? ''));

    if ($submitted !== '' && !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $submitted)) {
        throw new InvalidArgumentException('The checkout request token is invalid. Refresh checkout and try again.');
    }

    if ($submitted === '') {
        if (!isset($_SESSION['checkout_idempotency_key'])
            || !hash_equals((string) ($_SESSION['checkout_idempotency_fingerprint'] ?? ''), $fingerprint)) {
            $_SESSION['checkout_idempotency_key'] = bin2hex(random_bytes(24));
            $_SESSION['checkout_idempotency_fingerprint'] = $fingerprint;
        }
        $submitted = (string) $_SESSION['checkout_idempotency_key'];
    }

    return hash('sha256', $customerId . ':' . $submitted);
}

function checkout_locked_products(array $items): array
{
    $ids = array_values(array_unique(array_map(
        static fn (array $item): int => (int) ($item['product_id'] ?? 0),
        $items
    )));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    sort($ids, SORT_NUMERIC);

    if ($ids === []) {
        return [];
    }

    $products = db_select(
        'SELECT p.id, p.name, p.price, p.status, p.is_available, p.stock_quantity,
                c.status AS category_status
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
         WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
         ORDER BY p.id ASC
         FOR UPDATE',
        str_repeat('i', count($ids)),
        $ids
    );

    $indexed = [];
    foreach ($products as $product) {
        $indexed[(int) $product['id']] = $product;
    }

    return $indexed;
}

function checkout_locked_pricing(float $subtotal, int $quantity, string $promoCode = ''): array
{
    $discountRule = db_find(
        'SELECT * FROM bulk_discount_rules
         WHERE status = "active" AND min_quantity <= ?
           AND max_quantity IS NOT NULL AND max_quantity > min_quantity AND max_quantity >= ?
         ORDER BY min_quantity DESC, discount_rate DESC, id ASC
         LIMIT 1 FOR UPDATE',
        'ii',
        [$quantity, $quantity]
    );
    $settings = db_find('SELECT tax_rate, service_fee, delivery_fee FROM receipt_settings WHERE id = 1 LIMIT 1 FOR UPDATE') ?? [];
    $discountRate = max(0.0, min(100.0, (float) ($discountRule['discount_rate'] ?? 0)));
    $discountAmount = round(min($subtotal, $subtotal * ($discountRate / 100)), 2);
    $promoCode = strtoupper(trim($promoCode));
    $promoDiscount = 0.0;
    $promo = null;

    if ($promoCode !== '') {
        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $promoCode)) {
            throw new InvalidArgumentException('Enter a valid promo code.');
        }

        $promo = db_find('SELECT * FROM promo_codes WHERE code = ? LIMIT 1 FOR UPDATE', 's', [$promoCode]);
        if (!$promo) {
            throw new InvalidArgumentException('This promo code is unavailable or does not apply to this order.');
        }
        try {
            $promoDiscount = validated_promo_discount($promo, $subtotal, $discountAmount);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('This promo code is unavailable or does not apply to this order.', 0, $exception);
        }
    }

    $taxRate = max(0.0, min(100.0, (float) ($settings['tax_rate'] ?? 12)));
    $serviceFee = round(max(0.0, (float) ($settings['service_fee'] ?? 0)), 2);
    $deliveryFee = round(max(0.0, (float) ($settings['delivery_fee'] ?? 0)), 2);
    $total = round(max(0.0, $subtotal - $discountAmount - $promoDiscount + $serviceFee + $deliveryFee), 2);
    $taxRateDecimal = $taxRate / 100;
    $vatableSales = $taxRateDecimal > 0 ? round($total / (1 + $taxRateDecimal), 2) : $total;

    return [
        'subtotal' => round($subtotal, 2),
        'discount_rate' => $discountRate,
        'discount_amount' => $discountAmount,
        'promo_code' => $promoCode !== '' ? $promoCode : null,
        'promo_discount_amount' => $promoDiscount,
        'promo_id' => $promo ? (int) $promo['id'] : null,
        'tax_rate' => $taxRate,
        'vat_amount' => round($total - $vatableSales, 2),
        'service_fee' => $serviceFee,
        'delivery_fee' => $deliveryFee,
        'total' => $total,
    ];
}

function order_status_notification_title(string $status): string
{
    return match ($status) {
        'confirmed' => 'Order Confirmed',
        'processing' => 'Order Preparation Started',
        'completed' => 'Order Completed',
        'cancelled' => 'Order Rejected',
        default => 'Order Status Updated',
    };
}

function order_status_notification_type(string $status): string
{
    return match ($status) {
        'completed', 'confirmed' => 'success',
        'cancelled' => 'warning',
        default => 'info',
    };
}

function notify_order_operations(array $order, string $title, string $message, string $type = 'info', array $excludeUserIds = []): void
{
    notify_active_roles(['administrator', 'manager'], $title, $message, $type, $excludeUserIds);

    if (!empty($order['assigned_staff_id'])) {
        $assignedStaffId = (int) $order['assigned_staff_id'];

        if (!in_array($assignedStaffId, array_map('intval', $excludeUserIds), true)) {
            create_notification($assignedStaffId, $title, $message, $type);
        }
    }
}

function notify_payment_reviewers(array $order, string $title, string $message, string $type = 'info', array $excludeUserIds = []): void
{
    notify_active_roles(['administrator', 'manager', 'staff'], $title, $message, $type, $excludeUserIds);
}

function create_order_from_cart(int $customerId, array $payload): ?int
{
    ensure_operational_feature_columns();
    ensure_pricing_schema();

    $submittedPromoCode = (string) ($payload['promo_code'] ?? cart_applied_promo_code());
    $fingerprint = hash('sha256', (string) json_encode([
        'customer_id' => $customerId,
        'cart' => cart_items(),
        'checkout' => $payload,
        'promo_code' => $submittedPromoCode,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $idempotencyKey = checkout_idempotency_key($customerId, $payload, $fingerprint);
    $existingCheckout = db_find(
        'SELECT id FROM orders WHERE customer_id = ? AND checkout_idempotency_key = ? LIMIT 1',
        'is',
        [$customerId, $idempotencyKey]
    );

    if ($existingCheckout) {
        $incomingProof = (string) ($payload['proof_of_payment_path'] ?? '');
        $persistedProof = (string) (db_find('SELECT proof_of_payment_path FROM transactions WHERE order_id = ? LIMIT 1', 'i', [(int) $existingCheckout['id']])['proof_of_payment_path'] ?? '');
        if ($incomingProof !== '' && $incomingProof !== $persistedProof) {
            delete_private_upload($incomingProof);
        }
        clear_cart();
        clear_cart_promo_code();
        $_SESSION['last_order_fingerprint'] = $fingerprint;
        $_SESSION['last_order_id'] = (int) $existingCheckout['id'];
        return (int) $existingCheckout['id'];
    }

    $previousFingerprint = (string) ($_SESSION['last_order_fingerprint'] ?? '');
    $previousOrderId = (int) ($_SESSION['last_order_id'] ?? 0);

    if ($previousOrderId > 0 && $previousFingerprint !== '' && hash_equals($previousFingerprint, $fingerprint)) {
        $previousOrder = db_find(
            'SELECT id FROM orders WHERE id = ? AND customer_id = ? LIMIT 1',
            'ii',
            [$previousOrderId, $customerId]
        );

        if ($previousOrder) {
            return (int) $previousOrder['id'];
        }

        unset($_SESSION['last_order_fingerprint'], $_SESSION['last_order_id']);
    }

    $cartMessages = sync_cart_with_inventory();

    if ($cartMessages !== []) {
        flash_many($cartMessages, 'warning', 'order_stock');
    }

    $items = cart_items();

    if ($items === []) {
        flash('order', 'Your cart is empty. Please review your cart before placing the order.', 'warning');
        return null;
    }

    $paymentMethod = require_allowed_value((string) ($payload['payment_method'] ?? 'cash'), ['cash', 'gcash'], 'Please select a valid payment method.');
    $gcashReference = null;
    $gcashReferenceHash = null;
    $gcashAccountId = 0;
    if ($paymentMethod === 'gcash') {
        $gcashAccountId = (int) ($payload['gcash_account_id'] ?? 0);
        if ($gcashAccountId <= 0) {
            throw new InvalidArgumentException('GCash payment details changed. Review the current recipient account before submitting your order.');
        }
        $gcashReference = normalize_gcash_reference(require_non_empty_string((string) ($payload['gcash_reference'] ?? ''), 'Please enter the GCash reference number.'));
        $gcashReferenceHash = hash('sha256', $gcashReference);
        require_non_empty_string((string) ($payload['proof_of_payment_path'] ?? ''), 'Please upload proof of payment before placing a GCash order.');
    }
    $structuredDeliveryParts = customer_delivery_address_parts($payload);
    $hasStructuredDeliveryAddress = array_filter(
        array_intersect_key($structuredDeliveryParts, array_flip(['street', 'barangay', 'city', 'province'])),
        static fn (string $value): bool => $value !== ''
    ) !== [];

    if ($hasStructuredDeliveryAddress) {
        $addressErrors = customer_delivery_address_errors($structuredDeliveryParts);
        if ($addressErrors !== []) {
            throw new InvalidArgumentException(reset($addressErrors) ?: 'Please complete your delivery address.');
        }

        $deliveryAddress = validate_delivery_address(compose_customer_delivery_address($structuredDeliveryParts), 'customer');
        $deliveryLandmark = $structuredDeliveryParts['landmark'];
    } else {
        // Retain valid checkout sessions created before structured addresses
        // were introduced. New checkouts always use the branch above.
        $deliveryAddress = validate_delivery_address((string) ($payload['delivery_address'] ?? ''), 'customer');
        $deliveryLandmark = normalize_customer_delivery_address_value((string) ($payload['delivery_landmark'] ?? ''));
        if (mb_strlen($deliveryLandmark) > 255) {
            throw new InvalidArgumentException('Landmark / Additional Address Info must be 255 characters or fewer.');
        }
    }
    $notes = trim((string) ($payload['notes'] ?? ''));

    $db = get_db();
    $db->begin_transaction();

    try {
        $customer = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$customerId]);
        if (!$customer || ($customer['role'] ?? '') !== 'customer' || ($customer['status'] ?? '') !== 'active' || !empty($customer['deleted_at'])) {
            throw new InvalidArgumentException('Only an active customer account can place an order.');
        }

        $duplicateCheckout = db_find(
            'SELECT id FROM orders WHERE customer_id = ? AND checkout_idempotency_key = ? LIMIT 1 FOR UPDATE',
            'is',
            [$customerId, $idempotencyKey]
        );
        if ($duplicateCheckout) {
            $db->commit();
            $incomingProof = (string) ($payload['proof_of_payment_path'] ?? '');
            $persistedProof = (string) (db_find('SELECT proof_of_payment_path FROM transactions WHERE order_id = ? LIMIT 1', 'i', [(int) $duplicateCheckout['id']])['proof_of_payment_path'] ?? '');
            if ($incomingProof !== '' && $incomingProof !== $persistedProof) {
                delete_private_upload($incomingProof);
            }
            clear_cart();
            clear_cart_promo_code();
            $_SESSION['last_order_fingerprint'] = $fingerprint;
            $_SESSION['last_order_id'] = (int) $duplicateCheckout['id'];
            return (int) $duplicateCheckout['id'];
        }

        if ($paymentMethod === 'gcash' && !db_find(
            'SELECT id FROM gcash_payment_accounts WHERE id = ? AND status = "active" LIMIT 1 FOR UPDATE',
            'i',
            [$gcashAccountId]
        )) {
            throw new InvalidArgumentException('GCash payment is temporarily unavailable. Please select Cash or contact support.');
        }

        if ($gcashReferenceHash !== null) {
            $duplicateReference = db_find('SELECT id FROM transactions WHERE gcash_reference_hash = ? LIMIT 1 FOR UPDATE', 's', [$gcashReferenceHash]);
            if ($duplicateReference) {
                throw new InvalidArgumentException('This GCash reference number has already been submitted.');
            }
        }

        $lockedProducts = checkout_locked_products($items);
        $validatedItems = [];
        $subtotal = 0.0;
        $quantity = 0;

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $product = $lockedProducts[$productId] ?? null;
            $orderedQuantity = (int) ($item['quantity'] ?? 0);

            if ($orderedQuantity < 1 || !product_can_be_ordered($product) || ($product['category_status'] ?? '') !== 'active') {
                throw new InvalidArgumentException(($item['name'] ?? 'A product') . ' is unavailable. Please review your cart.');
            }

            if ($orderedQuantity > product_stock_quantity($product)) {
                throw new InvalidArgumentException('Only ' . product_stock_quantity($product) . ' item(s) of ' . $product['name'] . ' are available.');
            }

            $unitPrice = round((float) $product['price'], 2);
            $lineSubtotal = round($unitPrice * $orderedQuantity, 2);
            $subtotal += $lineSubtotal;
            $quantity += $orderedQuantity;
            $validatedItems[] = [$product, $orderedQuantity, $unitPrice, $lineSubtotal];
        }

        $subtotal = round($subtotal, 2);
        $pricing = checkout_locked_pricing($subtotal, $quantity, $submittedPromoCode);
        $total = $pricing['total'];
        $reference = order_reference();

        db_execute(
            'INSERT INTO orders (reference_no, customer_id, checkout_idempotency_key, order_status, payment_method, payment_status, delivery_status, notes, subtotal_amount, discount_rate, discount_amount, promo_code, promo_discount_amount, tax_rate, tax_amount, service_fee, delivery_fee, total_amount, created_at, updated_at)
             VALUES (?, ?, ?, "pending", ?, "pending", "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            'sisssdddsdddddd',
            [$reference, $customerId, $idempotencyKey, $paymentMethod, $notes, $subtotal, $pricing['discount_rate'], $pricing['discount_amount'], $pricing['promo_code'], $pricing['promo_discount_amount'], $pricing['tax_rate'], $pricing['vat_amount'], $pricing['service_fee'], $pricing['delivery_fee'], $total]
        );

        $orderId = db_last_id();

        $inventoryProductIds = [];

        foreach ($validatedItems as [$product, $orderedQuantity, $unitPrice, $lineSubtotal]) {
            db_execute(
                'INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                'iiidd',
                [
                    $orderId,
                    (int) $product['id'],
                    $orderedQuantity,
                    $unitPrice,
                    $lineSubtotal,
                ]
            );

            db_execute(
                'UPDATE products
                 SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                 WHERE id = ?',
                'ii',
                [$orderedQuantity, (int) $product['id']]
            );
            $inventoryProductIds[] = (int) $product['id'];
        }

        if (!empty($pricing['promo_id'])) {
            // The promo row is already locked. Recheck time at the redemption point
            // so a checkout crossing Ends At cannot consume an expired code.
            $finalPromo = db_find('SELECT * FROM promo_codes WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $pricing['promo_id']]);
            if (!$finalPromo) {
                throw new InvalidArgumentException('This promo code is unavailable or does not apply to this order.');
            }
            try {
                validated_promo_discount($finalPromo, $subtotal, (float) $pricing['discount_amount']);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('This promo code is unavailable or does not apply to this order.', 0, $exception);
            }
            db_execute('UPDATE promo_codes SET used_count = used_count + 1, updated_at = NOW() WHERE id = ?', 'i', [(int) $pricing['promo_id']]);
        }

        db_execute(
            'INSERT INTO transactions (order_id, payment_method, amount, subtotal_amount, discount_rate, discount_amount, promo_code, promo_discount_amount, transaction_status, gcash_reference, gcash_reference_hash, proof_of_payment_path, recorded_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, ?, ?, NULL, NOW(), NOW())',
            'isddddsdsss',
            [
                $orderId,
                $paymentMethod,
                $total,
                $subtotal,
                $pricing['discount_rate'],
                $pricing['discount_amount'],
                $pricing['promo_code'],
                $pricing['promo_discount_amount'],
                $gcashReference,
                $gcashReferenceHash,
                $payload['proof_of_payment_path'] ?? null,
            ]
        );
        $transactionId = db_last_id();

        if ($paymentMethod === 'gcash') {
            db_execute(
                'INSERT INTO payment_review_history (transaction_id, order_id, action, reference_no, proof_path, reviewed_by, reason, created_at)
                 VALUES (?, ?, "submitted", ?, ?, NULL, NULL, NOW())',
                'iiss',
                [$transactionId, $orderId, $gcashReference, (string) $payload['proof_of_payment_path']]
            );
        }

        db_execute(
            'INSERT INTO deliveries (order_id, assigned_staff_id, delivery_status, delivery_address, delivery_landmark, notes, created_at, updated_at)
             VALUES (?, NULL, "pending", ?, ?, ?, NOW(), NOW())',
            'isss',
            [$orderId, $deliveryAddress, $deliveryLandmark !== '' ? $deliveryLandmark : null, $notes]
        );

        $orderForNotice = [
            'id' => $orderId,
            'reference_no' => $reference,
            'customer_id' => $customerId,
            'assigned_staff_id' => null,
        ];

        create_notification($customerId, 'Order Submitted', 'Your order ' . $reference . ' has been placed successfully and is waiting for confirmation.', 'success', null, 'order-submitted:' . $orderId);
        notify_active_roles(
            ['administrator', 'manager', 'staff'],
            'New Customer Order ' . $reference,
            'A new customer order ' . $reference . ' is waiting for review and confirmation.',
            'info',
            [$customerId]
        );

        if ($paymentMethod === 'gcash') {
            notify_payment_reviewers(
                $orderForNotice,
                'Payment Verification Needed ' . $reference,
                'GCash payment proof for order ' . $reference . ' is ready for verification.',
                'info',
                [$customerId]
            );
        }

        log_audit($customerId, 'order_created', 'Placed order ' . $reference);

        $db->commit();
        clear_cart();
        clear_cart_promo_code();
        $_SESSION['last_order_fingerprint'] = $fingerprint;
        $_SESSION['last_order_id'] = $orderId;

        // The order is already committed at this point. Inventory alerts are
        // best-effort follow-up work and must never make a successful checkout
        // look failed (or remove its uploaded payment proof).
        foreach (array_unique($inventoryProductIds) as $productId) {
            try {
                notify_inventory_threshold($productId);
            } catch (Throwable $notificationException) {
                error_log('Unable to create inventory threshold notification for product #'
                    . $productId . ': ' . $notificationException->getMessage());
            }
        }

        return $orderId;
    } catch (InvalidArgumentException $exception) {
        $db->rollback();

        delete_private_upload((string) ($payload['proof_of_payment_path'] ?? ''));
        throw $exception;
    } catch (Throwable $exception) {
        $db->rollback();

        if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
            $existing = db_find('SELECT id FROM orders WHERE customer_id = ? AND checkout_idempotency_key = ? LIMIT 1', 'is', [$customerId, $idempotencyKey]);
            if ($existing) {
                $incomingProof = (string) ($payload['proof_of_payment_path'] ?? '');
                $persistedProof = (string) (db_find('SELECT proof_of_payment_path FROM transactions WHERE order_id = ? LIMIT 1', 'i', [(int) $existing['id']])['proof_of_payment_path'] ?? '');
                if ($incomingProof !== '' && $incomingProof !== $persistedProof) {
                    delete_private_upload($incomingProof);
                }
                clear_cart();
                clear_cart_promo_code();
                $_SESSION['last_order_fingerprint'] = $fingerprint;
                $_SESSION['last_order_id'] = (int) $existing['id'];
                return (int) $existing['id'];
            }
        }

        delete_private_upload((string) ($payload['proof_of_payment_path'] ?? ''));

        flash('order', 'Unable to process the order right now. Please try again.', 'danger');

        if (APP_DEBUG) {
            throw $exception;
        }

        return null;
    }
}

function delivery_terminal_statuses(): array
{
    return ['delivered', 'failed_delivery', 'cancelled'];
}

function delivery_active_limit(): int
{
    return 5;
}

function delivery_status_labels(): array
{
    return [
        'pending' => 'Pending',
        'assigned' => 'Ready for Delivery',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'issue_reported' => 'Issue Reported',
        'failed_delivery' => 'Failed Delivery',
        'cancelled' => 'Cancelled',
    ];
}

/**
 * These are the customer-facing stages. The stored statuses remain unchanged
 * so existing reports and historical records continue to work.
 */
function order_workflow_labels(): array
{
    return [
        'pending' => 'Pending Confirmation',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'ready_for_delivery' => 'Ready for Delivery',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'issue_reported' => 'Issue Reported',
        'failed_delivery' => 'Failed Delivery',
        'cancelled' => 'Cancelled',
    ];
}

function order_workflow_current_status(array $record): string
{
    $deliveryStatus = (string) ($record['delivery_status'] ?? $record['order_delivery_status'] ?? 'pending');
    $orderStatus = (string) ($record['order_status'] ?? 'pending');

    if (in_array($deliveryStatus, ['delivered', 'cancelled', 'issue_reported', 'failed_delivery'], true)) {
        return $deliveryStatus;
    }

    if ($deliveryStatus === 'out_for_delivery' || $orderStatus === 'out_for_delivery') {
        return 'out_for_delivery';
    }

    if ($deliveryStatus === 'assigned' || $orderStatus === 'assigned') {
        return 'ready_for_delivery';
    }

    return match ($orderStatus) {
        'completed' => 'delivered',
        'processing' => 'preparing',
        'confirmed' => 'confirmed',
        'cancelled' => 'cancelled',
        default => 'pending',
    };
}

function order_workflow_badge(array $record): string
{
    $status = order_workflow_current_status($record);
    $labels = order_workflow_labels();
    $class = match ($status) {
        'delivered' => 'success',
        'out_for_delivery' => 'accent',
        'issue_reported' => 'warning',
        'failed_delivery', 'cancelled' => 'danger',
        'pending' => 'warning',
        default => 'info',
    };

    return '<span class="badge badge-' . e($class) . '">' . e($labels[$status] ?? ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function order_payment_summary(array $record): string
{
    $method = (string) ($record['payment_method'] ?? 'cash');
    $paymentStatus = (string) ($record['payment_status'] ?? 'pending');
    $transactionStatus = (string) ($record['transaction_status'] ?? $record['payment_verification_status'] ?? $paymentStatus);
    $methodLabel = $method === 'gcash' ? 'GCash' : 'Cash on Delivery';

    $html = '<div class="order-payment-summary">';
    $paymentBadge = $method === 'gcash' && $paymentStatus === 'pending'
        ? '<span class="badge badge-warning">Pending Verification</span>'
        : status_badge($paymentStatus);
    $html .= '<div class="order-payment-summary__line"><strong>' . e($methodLabel) . '</strong>' . $paymentBadge . '</div>';

    if ($method === 'gcash') {
        $reference = trim((string) ($record['gcash_reference'] ?? $record['payment_reference'] ?? ''));
        $html .= '<div class="order-payment-summary__detail"><span>Reference</span><strong>' . e($reference !== '' ? $reference : 'Not provided') . '</strong></div>';
        $html .= '<div class="order-payment-summary__detail"><span>Verification</span>' . status_badge($transactionStatus) . '</div>';
    }

    $html .= '</div>';

    return $html;
}

function transaction_payment_badge(array $transaction): string
{
    $status = (string) ($transaction['transaction_status'] ?? 'pending');
    if (($transaction['payment_method'] ?? '') === 'gcash' && $status === 'pending') {
        return '<span class="badge badge-warning">Pending Verification</span>';
    }
    if (($transaction['payment_method'] ?? '') === 'gcash' && $status === 'failed') {
        return '<span class="badge badge-danger">Rejected</span>';
    }
    return status_badge($status);
}

function order_workflow_next_statuses(array $record): array
{
    $status = order_workflow_current_status($record);
    $next = match ($status) {
        'pending' => ['confirmed'],
        'confirmed' => ['preparing'],
        'preparing' => ['ready_for_delivery'],
        'ready_for_delivery' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered'],
        default => [],
    };
    $labels = order_workflow_labels();

    return array_combine($next, array_map(static fn (string $item): string => $labels[$item], $next)) ?: [];
}

function staff_can_update_order_workflow(array $record, int $staffId): bool
{
    $status = order_workflow_current_status($record);
    $preparedBy = (int) ($record['prepared_by_staff_id'] ?? 0);

    return in_array($status, ['pending', 'confirmed', 'preparing'], true)
        && ($preparedBy === 0 || $preparedBy === $staffId);
}

function update_order_workflow_status(
    int $orderId,
    string $status,
    int $userId,
    string $issue = '',
    ?string $deliveredAt = null,
    ?string $proofPhotoPath = null,
    string $recipientName = '',
    string $overrideReason = ''
): void {
    $user = fetch_user_by_id($userId);
    $role = (string) ($user['role'] ?? '');

    if (!$user || ($user['status'] ?? '') !== 'active' || !empty($user['deleted_at']) || !in_array($role, ['staff', 'rider'], true)) {
        throw new InvalidArgumentException('You are not allowed to update this order workflow.');
    }

    if ($role === 'rider') {
        update_delivery_status($orderId, $status, $userId, $issue, $deliveredAt, $proofPhotoPath, $recipientName, $overrideReason);
        return;
    }

    if (!in_array($status, ['confirmed', 'preparing', 'ready_for_delivery'], true)) {
        throw new InvalidArgumentException('Staff can only confirm, prepare, and mark orders ready for delivery.');
    }

    $storedOrderStatus = match ($status) {
        'confirmed' => 'confirmed',
        'preparing' => 'processing',
        'ready_for_delivery' => 'assigned',
        default => throw new InvalidArgumentException('Invalid workflow status.'),
    };
    $storedDeliveryStatus = $status === 'ready_for_delivery' ? 'assigned' : 'pending';

    $db = get_db();
    $db->begin_transaction();

    try {
        $lockedUser = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$userId]);
        $delivery = fetch_delivery_workflow_record($orderId, true);

        if (!$lockedUser || ($lockedUser['role'] ?? '') !== 'staff' || ($lockedUser['status'] ?? '') !== 'active' || !empty($lockedUser['deleted_at'])) {
            throw new InvalidArgumentException('Only an active staff account can update preparation.');
        }
        if (!$delivery) {
            throw new InvalidArgumentException('Order delivery record does not exist.');
        }

        $currentStatus = order_workflow_current_status($delivery);
        if (!in_array($status, array_keys(order_workflow_next_statuses($delivery)), true)) {
            throw new InvalidArgumentException('Order status must follow the workflow sequence without skipping steps.');
        }
        if (!delivery_payment_ready($delivery)) {
            throw new InvalidArgumentException('GCash payment must be verified before staff can confirm or prepare this order.');
        }
        if (!empty($delivery['prepared_by_staff_id']) && (int) $delivery['prepared_by_staff_id'] !== $userId) {
            throw new InvalidArgumentException('Only the staff member who confirmed this order can continue its preparation.');
        }

        db_execute(
            'UPDATE deliveries SET delivery_status = ?, updated_at = NOW() WHERE order_id = ?',
            'si',
            [$storedDeliveryStatus, $orderId]
        );
        db_execute(
            'UPDATE orders
             SET order_status = ?, delivery_status = ?, prepared_by_staff_id = COALESCE(prepared_by_staff_id, ?), updated_at = NOW()
             WHERE id = ?',
            'ssii',
            [$storedOrderStatus, $storedDeliveryStatus, $userId, $orderId]
        );

        $labels = order_workflow_labels();
        $statusLabel = $labels[$status];
        record_delivery_history((int) $delivery['id'], $orderId, $currentStatus, $status, $userId, 'Workflow updated to ' . $statusLabel . '.');

        create_notification((int) $delivery['customer_id'], 'Order ' . $statusLabel, 'Your order ' . $delivery['reference_no'] . ' is now ' . $statusLabel . '.', $status === 'confirmed' ? 'success' : 'info');
        notify_order_operations(
            array_merge($delivery, ['assigned_staff_id' => $delivery['assigned_staff_id'] ?? null]),
            'Order ' . $statusLabel . ' ' . $delivery['reference_no'],
            'Order ' . $delivery['reference_no'] . ' moved from ' . ($labels[$currentStatus] ?? $currentStatus) . ' to ' . $statusLabel . '.',
            $status === 'confirmed' ? 'success' : 'info',
            [$userId, (int) $delivery['customer_id']]
        );
        log_audit($userId, 'order_workflow_updated', 'Updated order #' . $orderId . ' from ' . $currentStatus . ' to ' . $status . '.');
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function delivery_allowed_next_statuses(string $currentStatus): array
{
    return match ($currentStatus) {
        'assigned' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered', 'failed_delivery', 'issue_reported'],
        default => [],
    };
}

function delivery_normalize_datetime(?string $value): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        throw new InvalidArgumentException('Please enter a valid delivery date and time.');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function fetch_delivery_workflow_record(int $orderId, bool $forUpdate = false): ?array
{
    ensure_operational_feature_columns();

    return db_find(
        'SELECT d.*, o.reference_no, o.customer_id, o.order_status, o.assigned_staff_id AS order_assigned_staff_id,
                o.payment_method, o.payment_status,
                o.prepared_by_staff_id,
                t.transaction_status
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         LEFT JOIN transactions t ON t.order_id = o.id
         WHERE d.order_id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
        'i',
        [$orderId]
    );
}

function delivery_payment_ready(array $delivery): bool
{
    if (($delivery['payment_method'] ?? '') !== 'gcash') {
        return ($delivery['payment_status'] ?? '') !== 'cancelled';
    }

    return in_array((string) ($delivery['payment_status'] ?? ''), ['accepted', 'paid'], true)
        && in_array((string) ($delivery['transaction_status'] ?? ''), ['accepted', 'completed'], true);
}

function delivery_active_count_for_staff(int $staffId, ?int $excludeDeliveryId = null): int
{
    $sql = 'SELECT COUNT(*) AS total
            FROM deliveries
            WHERE assigned_staff_id = ?
              AND delivery_status IN ("assigned", "out_for_delivery", "issue_reported")';
    $types = 'i';
    $params = [$staffId];

    if ($excludeDeliveryId !== null) {
        $sql .= ' AND id <> ?';
        $types .= 'i';
        $params[] = $excludeDeliveryId;
    }

    return (int) (db_find($sql, $types, $params)['total'] ?? 0);
}

function record_delivery_history(int $deliveryId, int $orderId, ?string $previousStatus, string $newStatus, ?int $userId, string $note = ''): void
{
    db_execute(
        'INSERT INTO delivery_history (delivery_id, order_id, previous_status, new_status, changed_by, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())',
        'iissis',
        [$deliveryId, $orderId, $previousStatus, $newStatus, $userId, $note !== '' ? $note : null]
    );
}

function fetch_delivery_history(int $orderId): array
{
    ensure_operational_feature_columns();

    return db_select(
        'SELECT h.*, u.full_name AS changed_by_name, u.role AS changed_by_role
         FROM delivery_history h
         LEFT JOIN users u ON u.id = h.changed_by
         WHERE h.order_id = ?
         ORDER BY h.created_at ASC, h.id ASC',
        'i',
        [$orderId]
    );
}

function assign_order_to_staff(int $orderId, int $staffId, int $managerId): void
{
    assign_delivery_to_staff($orderId, $staffId, $managerId);
}

function assign_delivery_to_staff(int $orderId, int $staffId, int $assignedById, ?string $estimatedDeliveryAt = null): void
{
    ensure_operational_feature_columns();

    $estimatedDeliveryAt = delivery_normalize_datetime($estimatedDeliveryAt);

    $db = get_db();
    $db->begin_transaction();

    try {
        $assignedBy = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$assignedById]);
        $staff = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$staffId]);
        $delivery = fetch_delivery_workflow_record($orderId, true);

        if (!$assignedBy || ($assignedBy['status'] ?? '') !== 'active' || !empty($assignedBy['deleted_at'])
            || !in_array((string) ($assignedBy['role'] ?? ''), ['administrator', 'manager'], true)) {
            throw new InvalidArgumentException('Only an active administrator or manager can assign riders.');
        }
        if (!$delivery) {
            throw new InvalidArgumentException('Delivery record does not exist.');
        }
        if (!$staff || ($staff['role'] ?? '') !== 'rider' || ($staff['status'] ?? '') !== 'active' || !empty($staff['deleted_at'])) {
            throw new InvalidArgumentException('Please select an active rider account.');
        }

        // The normal Assign Rider action is a one-time transition. The
        // separately audited exception-resolution workflow handles any later
        // reassignment after a reported/failed delivery.
        if ((int) ($delivery['assigned_staff_id'] ?? 0) > 0
            || (int) ($delivery['order_assigned_staff_id'] ?? 0) > 0) {
            throw new InvalidArgumentException('This order already has an assigned rider.');
        }

        $currentStatus = (string) $delivery['delivery_status'];
        if (in_array($currentStatus, delivery_terminal_statuses(), true)) {
            throw new InvalidArgumentException('Delivered, failed, or cancelled deliveries cannot be assigned through the normal workflow.');
        }
        if (order_workflow_current_status($delivery) !== 'ready_for_delivery' || $currentStatus !== 'assigned') {
            throw new InvalidArgumentException('An order can be assigned only after staff marks it ready for delivery.');
        }
        if (!delivery_payment_ready($delivery)) {
            throw new InvalidArgumentException('GCash orders must be paid before rider assignment.');
        }

        $activeDeliveries = db_select(
            'SELECT id FROM deliveries
             WHERE assigned_staff_id = ? AND delivery_status IN ("assigned", "out_for_delivery", "issue_reported") AND id <> ?
             ORDER BY id FOR UPDATE',
            'ii',
            [$staffId, (int) $delivery['id']]
        );
        if (count($activeDeliveries) >= delivery_active_limit()) {
            throw new InvalidArgumentException('Selected rider has reached the active delivery limit.');
        }

        db_execute(
            'UPDATE deliveries
             SET assigned_staff_id = ?, delivery_status = "assigned", estimated_delivery_at = ?, delivered_at = NULL, updated_at = NOW()
             WHERE order_id = ?',
            'isi',
            [$staffId, $estimatedDeliveryAt, $orderId]
        );

        db_execute(
            'UPDATE orders
             SET assigned_staff_id = ?, order_status = "assigned", delivery_status = "assigned", updated_at = NOW()
             WHERE id = ?',
            'ii',
            [$staffId, $orderId]
        );

        record_delivery_history((int) $delivery['id'], $orderId, 'ready_for_delivery', 'ready_for_delivery', $assignedById, 'Assigned rider #' . $staffId);
        create_notification($staffId, 'Delivery Assignment', 'You have been assigned to delivery ' . $delivery['reference_no'] . '.', 'info');
        create_notification((int) $delivery['customer_id'], 'Delivery Assigned', 'A rider has been assigned to your order ' . $delivery['reference_no'] . '.', 'info');
        notify_active_roles(
            ['administrator', 'manager'],
            'Rider Assigned',
            'Delivery for order ' . $delivery['reference_no'] . ' was assigned to ' . (string) $staff['full_name'] . '.',
            'success',
            [$assignedById, $staffId, (int) $delivery['customer_id']]
        );
        log_audit($assignedById, 'delivery_assigned', 'Assigned rider #' . $staffId . ' to order #' . $orderId . '.');

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function staff_can_update_delivery(int $orderId, int $staffId): bool
{
    return db_find(
        'SELECT d.id
         FROM deliveries d
         INNER JOIN orders o ON o.id = d.order_id
         WHERE d.order_id = ? AND d.assigned_staff_id = ? AND o.assigned_staff_id = ?
         LIMIT 1',
        'iii',
        [$orderId, $staffId, $staffId]
    ) !== null;
}

function update_order_status(int $orderId, string $status, int $userId): void
{
    $workflowStatus = match ($status) {
        'confirmed' => 'confirmed',
        'processing' => 'preparing',
        'assigned' => 'ready_for_delivery',
        'out_for_delivery' => 'out_for_delivery',
        'completed' => 'delivered',
        default => throw new InvalidArgumentException('Use the unified order workflow to update this status.'),
    };

    update_order_workflow_status($orderId, $workflowStatus, $userId);
}

function customer_order_has_protected_gcash_payment(array $order): bool
{
    $orderPaymentMethod = (string) ($order['payment_method'] ?? '');
    $transactionPaymentMethod = (string) ($order['transaction_payment_method'] ?? $orderPaymentMethod);
    if ($orderPaymentMethod !== 'gcash' && $transactionPaymentMethod !== 'gcash') {
        return false;
    }

    return trim((string) ($order['gcash_reference'] ?? '')) !== ''
        || trim((string) ($order['proof_of_payment_path'] ?? '')) !== ''
        || (int) ($order['payment_reviewed_by'] ?? 0) > 0
        || !empty($order['payment_reviewed_at'])
        || trim((string) ($order['payment_review_action'] ?? '')) !== '';
}

function customer_order_cancellation_block_reason(array $order, int $customerId): ?string
{
    if ($customerId <= 0 || (int) ($order['customer_id'] ?? 0) !== $customerId) {
        return 'Order does not exist.';
    }

    if ((string) ($order['order_status'] ?? '') !== 'pending') {
        return 'This order can no longer be cancelled because staff or delivery processing has already started.';
    }

    if ((int) ($order['prepared_by_staff_id'] ?? 0) > 0) {
        return 'This order can no longer be cancelled because staff processing has already started.';
    }

    if ((int) ($order['assigned_staff_id'] ?? 0) > 0 || (int) ($order['delivery_assigned_staff_id'] ?? 0) > 0) {
        return 'This order can no longer be cancelled because a rider has already been assigned.';
    }

    $orderDeliveryStatus = (string) ($order['delivery_status'] ?? '');
    $deliveryRecordStatus = (string) ($order['delivery_record_status'] ?? $orderDeliveryStatus);
    if ($orderDeliveryStatus !== 'pending' || $deliveryRecordStatus !== 'pending') {
        return 'This order can no longer be cancelled because delivery processing has already started.';
    }

    $paymentStatus = (string) ($order['payment_status'] ?? '');
    $transactionStatus = (string) ($order['transaction_status'] ?? '');
    $orderPaymentMethod = (string) ($order['payment_method'] ?? '');
    $transactionPaymentMethod = (string) ($order['transaction_payment_method'] ?? '');

    if (!in_array($orderPaymentMethod, ['cash', 'gcash'], true)
        || $transactionPaymentMethod !== $orderPaymentMethod) {
        return 'This order cannot be cancelled because its payment record is unavailable or inconsistent.';
    }

    if ($orderPaymentMethod === 'cash'
        && ($paymentStatus !== 'pending'
            || $transactionStatus !== 'pending'
            || (int) ($order['payment_reviewed_by'] ?? 0) > 0
            || !empty($order['payment_reviewed_at']))) {
        return 'This Cash on Delivery order can no longer be cancelled because its payment was collected or its payment state has changed.';
    }

    if (in_array($paymentStatus, ['accepted', 'paid'], true)
        || in_array($transactionStatus, ['accepted', 'completed'], true)) {
        return 'A verified or paid order cannot be cancelled. Contact support for assistance.';
    }

    if (customer_order_has_protected_gcash_payment($order)) {
        return 'Your GCash payment is already under review. Please contact support or wait for the payment review before requesting cancellation.';
    }

    return null;
}

function customer_can_cancel_order(array $order, int $customerId): bool
{
    return customer_order_cancellation_block_reason($order, $customerId) === null;
}

function cancel_order(int $orderId, int $customerId): void
{
    $db = get_db();
    $db->begin_transaction();

    try {
        $order = db_find(
            'SELECT o.*, d.id AS delivery_id, d.assigned_staff_id AS delivery_assigned_staff_id,
                    d.delivery_status AS delivery_record_status,
                    t.id AS transaction_id, t.payment_method AS transaction_payment_method,
                    t.transaction_status, t.gcash_reference, t.proof_of_payment_path,
                    t.recorded_by AS payment_reviewed_by, t.reviewed_at AS payment_reviewed_at,
                    (SELECT prh.action FROM payment_review_history prh
                     WHERE prh.order_id = o.id ORDER BY prh.created_at DESC, prh.id DESC LIMIT 1) AS payment_review_action
             FROM orders o
             LEFT JOIN deliveries d ON d.order_id = o.id
             LEFT JOIN transactions t ON t.order_id = o.id
             WHERE o.id = ? AND o.customer_id = ? LIMIT 1 FOR UPDATE',
            'ii',
            [$orderId, $customerId]
        );

        if (!$order) {
            throw new InvalidArgumentException('Order does not exist.');
        }
        $blockReason = customer_order_cancellation_block_reason($order, $customerId);
        if ($blockReason !== null) {
            throw new InvalidArgumentException($blockReason);
        }

        $stmt = $db->prepare(
            'UPDATE orders SET order_status = "cancelled", payment_status = "cancelled", delivery_status = "cancelled", updated_at = NOW()
             WHERE id = ? AND customer_id = ?
               AND order_status = "pending" AND delivery_status = "pending"
               AND prepared_by_staff_id IS NULL AND assigned_staff_id IS NULL
               AND payment_method IN ("cash", "gcash") AND payment_status = "pending"
               AND NOT EXISTS (
                   SELECT 1 FROM deliveries d
                   WHERE d.order_id = orders.id
                     AND (d.delivery_status <> "pending" OR d.assigned_staff_id IS NOT NULL)
               )
               AND EXISTS (
                   SELECT 1 FROM transactions current_payment
                   WHERE current_payment.order_id = orders.id
                     AND current_payment.payment_method = orders.payment_method
               )
               AND NOT EXISTS (
                   SELECT 1 FROM transactions t
                   WHERE t.order_id = orders.id
                      AND (
                         t.payment_method <> orders.payment_method
                         OR t.transaction_status IN ("accepted", "completed")
                         OR (
                             t.payment_method = "cash"
                             AND (
                                 t.transaction_status <> "pending"
                                 OR t.recorded_by IS NOT NULL
                                 OR t.reviewed_at IS NOT NULL
                             )
                         )
                         OR (
                             t.payment_method = "gcash"
                             AND (
                                 NULLIF(TRIM(t.gcash_reference), "") IS NOT NULL
                                 OR NULLIF(TRIM(t.proof_of_payment_path), "") IS NOT NULL
                                 OR t.recorded_by IS NOT NULL
                                 OR t.reviewed_at IS NOT NULL
                                 OR EXISTS (SELECT 1 FROM payment_review_history prh WHERE prh.order_id = orders.id)
                             )
                         )
                     )
               )'
        );
        $stmt->bind_param('ii', $orderId, $customerId);
        $stmt->execute();

        if ($stmt->affected_rows <= 0) {
            throw new InvalidArgumentException('The order changed before it could be cancelled. Please refresh and review its current status.');
        }

        restore_order_stock($orderId);

        db_execute(
            'UPDATE transactions SET transaction_status = "cancelled", updated_at = NOW()
             WHERE order_id = ? AND transaction_status NOT IN ("accepted", "completed")',
            'i',
            [$orderId]
        );

        db_execute(
            'UPDATE deliveries SET delivery_status = "cancelled", delivered_at = NULL, updated_at = NOW()
             WHERE order_id = ? AND delivery_status = "pending" AND assigned_staff_id IS NULL',
            'i',
            [$orderId]
        );

        create_notification($customerId, 'Order Cancelled', 'Your order has been cancelled.', 'warning');
        notify_order_operations(
            $order,
            'Order Cancelled ' . $order['reference_no'],
            'Customer cancelled order ' . $order['reference_no'] . '.',
            'warning',
            [$customerId]
        );

        log_audit($customerId, 'order_cancelled', 'Cancelled order #' . $orderId);
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function restore_order_stock(int $orderId): void
{
    db_execute(
        'UPDATE products p
         INNER JOIN order_items oi ON oi.product_id = p.id
         SET p.stock_quantity = p.stock_quantity + oi.quantity, p.updated_at = NOW()
         WHERE oi.order_id = ?',
        'i',
        [$orderId]
    );
}

function auto_cancel_expired_orders(?int $minutes = null): int
{
    static $checked = false;

    if ($checked) {
        return 0;
    }

    $checked = true;
    $minutes = max(1, (int) ($minutes ?? ORDER_PAYMENT_TIMEOUT_MINUTES));
    ensure_operational_feature_columns();

    $expiredOrders = db_select(
        'SELECT DISTINCT o.id, o.customer_id, o.reference_no, o.assigned_staff_id
         FROM orders o
         LEFT JOIN transactions t ON t.order_id = o.id
         WHERE o.order_status NOT IN ("completed", "cancelled")
           AND o.payment_method = "gcash"
           AND o.payment_status NOT IN ("accepted", "paid", "cancelled")
           AND (t.id IS NULL OR t.transaction_status NOT IN ("accepted", "completed", "cancelled"))
           AND (t.id IS NULL OR (NULLIF(TRIM(t.gcash_reference), "") IS NULL AND NULLIF(TRIM(t.proof_of_payment_path), "") IS NULL))
           AND o.created_at <= DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)'
    );

    if ($expiredOrders === []) {
        return 0;
    }

    $db = get_db();
    $cancelled = 0;
    $db->begin_transaction();

    try {
        $cancelOrderStmt = $db->prepare(
            'UPDATE orders
             SET order_status = "cancelled", payment_status = "cancelled", delivery_status = "cancelled", updated_at = NOW()
             WHERE id = ?
               AND payment_method = "gcash"
               AND order_status NOT IN ("completed", "cancelled")
               AND payment_status NOT IN ("accepted", "paid", "cancelled")
               AND NOT EXISTS (
                   SELECT 1 FROM transactions t
                   WHERE t.order_id = orders.id
                     AND (NULLIF(TRIM(t.gcash_reference), "") IS NOT NULL OR NULLIF(TRIM(t.proof_of_payment_path), "") IS NOT NULL)
               )'
        );

        foreach ($expiredOrders as $order) {
            $orderId = (int) $order['id'];

            $cancelOrderStmt->bind_param('i', $orderId);
            $cancelOrderStmt->execute();

            if ($cancelOrderStmt->affected_rows <= 0) {
                continue;
            }

            restore_order_stock($orderId);

            db_execute(
                'UPDATE transactions
                 SET transaction_status = "cancelled", updated_at = NOW()
                 WHERE order_id = ? AND transaction_status NOT IN ("accepted", "completed", "cancelled")',
                'i',
                [$orderId]
            );

            db_execute(
                'UPDATE deliveries
                 SET delivery_status = "cancelled", delivered_at = NULL, updated_at = NOW()
                 WHERE order_id = ? AND delivery_status <> "delivered"',
                'i',
                [$orderId]
            );

            create_notification(
                (int) $order['customer_id'],
                'Order Automatically Cancelled',
                'Your order ' . $order['reference_no'] . ' was cancelled because payment was not completed or accepted by staff within ' . $minutes . ' minutes.',
                'warning'
            );

            if (!empty($order['assigned_staff_id'])) {
                create_notification(
                    (int) $order['assigned_staff_id'],
                    'Assigned Order Cancelled',
                    'Assigned order ' . $order['reference_no'] . ' was automatically cancelled because payment was not completed or accepted within ' . $minutes . ' minutes.',
                    'warning'
                );
            }

            log_audit(null, 'order_auto_cancelled', 'Automatically cancelled order #' . $orderId . ' after payment timeout.');
            $cancelled++;
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();

        if (APP_DEBUG) {
            throw $exception;
        }
    }

    return $cancelled;
}

function update_transaction_status(int $orderId, string $status, int $recordedBy): void
{
    ensure_operational_feature_columns();
    $transaction = fetch_transaction_by_order($orderId);
    if (!$transaction) {
        throw new InvalidArgumentException('Transaction does not exist.');
    }
    if ((string) $transaction['transaction_status'] === $status) {
        return;
    }
    if (($transaction['payment_method'] ?? '') === 'gcash' && in_array($status, ['accepted', 'completed'], true)) {
        review_gcash_payment((int) $transaction['id'], 'verify', $recordedBy);
        return;
    }

    throw new InvalidArgumentException('Use GCash review or the assigned-rider delivery workflow to change payment status safely.');
}

function accept_staff_payment(int $transactionId, int $staffId): void
{
    $transaction = fetch_transaction_by_id($transactionId);

    if (!$transaction) {
        throw new InvalidArgumentException('Transaction does not exist.');
    }

    if (($transaction['order_status'] ?? '') === 'cancelled' || ($transaction['payment_status'] ?? '') === 'cancelled') {
        throw new InvalidArgumentException('This order has already been cancelled and can no longer be validated.');
    }

    if (($transaction['transaction_status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('Only pending payments can be accepted.');
    }

    if (($transaction['payment_method'] ?? '') === 'cash') {
        throw new InvalidArgumentException('COD payments must be confirmed by the assigned rider using Delivered and Cash Received.');
    }

    if (($transaction['payment_method'] ?? '') === 'gcash' && empty($transaction['proof_of_payment_path'])) {
        throw new InvalidArgumentException('This GCash payment has no uploaded proof of payment yet.');
    }

    review_gcash_payment($transactionId, 'verify', $staffId);
}

function review_gcash_payment(int $transactionId, string $decision, int $reviewerId, string $rejectionReason = ''): void
{
    ensure_operational_feature_columns();
    $decision = require_allowed_value($decision, ['verify', 'reject'], 'Please select a valid payment review decision.');

    $rejectionReason = trim($rejectionReason);
    if ($decision === 'reject' && $rejectionReason === '') {
        throw new InvalidArgumentException('Please enter a rejection reason.');
    }

    $db = get_db();
    $db->begin_transaction();
    try {
        $reviewer = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$reviewerId]);
        if (!$reviewer || ($reviewer['status'] ?? '') !== 'active' || !empty($reviewer['deleted_at'])
            || !in_array((string) ($reviewer['role'] ?? ''), ['staff', 'manager', 'administrator'], true)) {
            throw new InvalidArgumentException('Only active staff, managers, or administrators can review GCash payments.');
        }

        $transaction = db_find(
            'SELECT t.*, o.customer_id, o.reference_no, o.order_status, o.payment_status
             FROM transactions t INNER JOIN orders o ON o.id=t.order_id
             WHERE t.id=? LIMIT 1 FOR UPDATE',
            'i',
            [$transactionId]
        );

        if (!$transaction || ($transaction['payment_method'] ?? '') !== 'gcash') {
            throw new InvalidArgumentException('GCash payment transaction does not exist.');
        }
        if (($transaction['transaction_status'] ?? '') !== 'pending' || ($transaction['payment_status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('This GCash payment has already been reviewed.');
        }
        if (empty($transaction['gcash_reference']) || empty($transaction['proof_of_payment_path'])) {
            throw new InvalidArgumentException('A GCash reference number and payment proof are required before review.');
        }
        if (($transaction['order_status'] ?? '') === 'cancelled') {
            throw new InvalidArgumentException('Cancelled orders cannot have payments reviewed.');
        }

        $normalizedReference = normalize_gcash_reference((string) $transaction['gcash_reference']);
        $referenceHash = hash('sha256', $normalizedReference);
        $duplicateReference = db_find(
            'SELECT id FROM transactions WHERE gcash_reference_hash = ? AND id <> ? LIMIT 1 FOR UPDATE',
            'si',
            [$referenceHash, $transactionId]
        );
        if ($duplicateReference) {
            throw new InvalidArgumentException('This GCash reference number was already used for another payment.');
        }

        $transactionStatus = $decision === 'verify' ? 'completed' : 'failed';
        $paymentStatus = $decision === 'verify' ? 'paid' : 'failed';
        db_execute(
            'UPDATE transactions
             SET transaction_status = ?, gcash_reference = ?, gcash_reference_hash = ?, recorded_by = ?, reviewed_at = NOW(), rejection_reason = ?, updated_at = NOW()
             WHERE id = ? AND transaction_status = "pending"',
            'sssisi',
            [$transactionStatus, $normalizedReference, $referenceHash, $reviewerId, $decision === 'reject' ? $rejectionReason : null, $transactionId]
        );
        db_execute('UPDATE orders SET payment_status=?, updated_at=NOW() WHERE id=?', 'si', [$paymentStatus, (int) $transaction['order_id']]);
        db_execute(
            'INSERT INTO payment_review_history (transaction_id, order_id, action, reference_no, proof_path, reviewed_by, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            'iisssis',
            [$transactionId, (int) $transaction['order_id'], $decision === 'verify' ? 'verified' : 'rejected', $normalizedReference, (string) $transaction['proof_of_payment_path'], $reviewerId, $decision === 'reject' ? $rejectionReason : null]
        );

        if ($decision === 'verify') {
            create_notification((int) $transaction['customer_id'], 'GCash Payment Verified', 'Your GCash payment for ' . $transaction['reference_no'] . ' was verified and is now Paid.', 'success');
            log_audit($reviewerId, 'gcash_payment_verified', 'Verified GCash payment for order #' . $transaction['order_id'] . '.');
        } else {
            create_notification((int) $transaction['customer_id'], 'GCash Payment Rejected', 'Your GCash payment for ' . $transaction['reference_no'] . ' was rejected. Reason: ' . $rejectionReason, 'danger');
            log_audit($reviewerId, 'gcash_payment_rejected', 'Rejected GCash payment for order #' . $transaction['order_id'] . '. Reason: ' . $rejectionReason);
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function resubmit_gcash_payment(int $orderId, int $customerId, string $reference, string $proofPath): void
{
    ensure_operational_feature_columns();
    $reference = normalize_gcash_reference($reference);
    $referenceHash = hash('sha256', $reference);
    $proofPath = require_non_empty_string($proofPath, 'Please upload a new proof of payment.');
    $database = get_db();
    $database->begin_transaction();
    $oldProofPath = '';

    try {
        $transaction = db_find(
            'SELECT t.*, o.customer_id, o.reference_no, o.order_status, o.payment_status
             FROM transactions t
             INNER JOIN orders o ON o.id = t.order_id
             WHERE t.order_id = ? AND o.customer_id = ? LIMIT 1 FOR UPDATE',
            'ii',
            [$orderId, $customerId]
        );

        if (!$transaction || ($transaction['payment_method'] ?? '') !== 'gcash') {
            throw new InvalidArgumentException('GCash payment transaction does not exist.');
        }
        if (($transaction['order_status'] ?? '') === 'cancelled') {
            throw new InvalidArgumentException('A cancelled order cannot receive another payment submission.');
        }
        if (($transaction['transaction_status'] ?? '') !== 'failed' || ($transaction['payment_status'] ?? '') !== 'failed') {
            throw new InvalidArgumentException('Only a rejected GCash payment can be resubmitted.');
        }

        $duplicate = db_find(
            'SELECT id FROM transactions WHERE gcash_reference_hash = ? AND id <> ? LIMIT 1 FOR UPDATE',
            'si',
            [$referenceHash, (int) $transaction['id']]
        );
        if ($duplicate) {
            throw new InvalidArgumentException('This GCash reference number has already been submitted for another order.');
        }

        $oldProofPath = (string) ($transaction['proof_of_payment_path'] ?? '');
        $statement = $database->prepare(
            'UPDATE transactions
             SET transaction_status = "pending", gcash_reference = ?, gcash_reference_hash = ?, proof_of_payment_path = ?,
                 recorded_by = NULL, reviewed_at = NULL, rejection_reason = NULL, updated_at = NOW()
             WHERE id = ? AND transaction_status = "failed"'
        );
        $transactionId = (int) $transaction['id'];
        $statement->bind_param('sssi', $reference, $referenceHash, $proofPath, $transactionId);
        $statement->execute();
        if ($statement->affected_rows !== 1) {
            throw new RuntimeException('The payment changed before it could be resubmitted.');
        }

        db_execute('UPDATE orders SET payment_status = "pending", updated_at = NOW() WHERE id = ? AND payment_status = "failed"', 'i', [$orderId]);
        db_execute(
            'INSERT INTO payment_review_history (transaction_id, order_id, action, reference_no, proof_path, reviewed_by, reason, created_at)
             VALUES (?, ?, "resubmitted", ?, ?, NULL, NULL, NOW())',
            'iiss',
            [$transactionId, $orderId, $reference, $proofPath]
        );
        create_notification($customerId, 'GCash Payment Resubmitted', 'Your new payment proof for ' . $transaction['reference_no'] . ' is awaiting review.', 'info');
        notify_payment_reviewers(
            $transaction,
            'GCash Payment Resubmitted ' . $transaction['reference_no'],
            'New GCash proof for order ' . $transaction['reference_no'] . ' is ready for verification.',
            'info',
            [$customerId]
        );
        log_audit($customerId, 'gcash_payment_resubmitted', 'Resubmitted GCash payment proof for order #' . $orderId . '.');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        delete_private_upload($proofPath);
        throw $exception;
    }

    if ($oldProofPath !== '' && $oldProofPath !== $proofPath) {
        delete_private_upload($oldProofPath);
    }
}

function update_delivery_status(
    int $orderId,
    string $status,
    int $userId,
    string $issue = '',
    ?string $deliveredAt = null,
    ?string $proofPhotoPath = null,
    string $recipientName = '',
    string $overrideReason = ''
): void {
    ensure_operational_feature_columns();

    $allowedStatuses = array_keys(delivery_status_labels());

    if (!in_array($status, $allowedStatuses, true)) {
        throw new InvalidArgumentException('Invalid delivery status.');
    }

    $user = fetch_user_by_id($userId);
    $role = (string) ($user['role'] ?? '');

    if (!$user || $role !== 'rider' || ($user['status'] ?? '') !== 'active' || !empty($user['deleted_at'])) {
        throw new InvalidArgumentException('Only the assigned rider can update delivery progress.');
    }

    if (!in_array($status, ['out_for_delivery', 'delivered', 'issue_reported', 'failed_delivery'], true)) {
        throw new InvalidArgumentException('Riders can only start delivery, complete delivery, or report a failed delivery issue.');
    }

    $db = get_db();
    $db->begin_transaction();

    try {
        $delivery = db_find(
            'SELECT d.*, o.reference_no, o.customer_id, o.order_status, o.payment_method, o.payment_status,
                    t.id AS transaction_id, t.transaction_status
             FROM deliveries d
             INNER JOIN orders o ON o.id = d.order_id
             LEFT JOIN transactions t ON t.order_id = o.id
             WHERE d.order_id = ? LIMIT 1 FOR UPDATE',
            'i',
            [$orderId]
        );

        if (!$delivery) {
            throw new InvalidArgumentException('Delivery record does not exist.');
        }

        if ((int) ($delivery['assigned_staff_id'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('Only the assigned rider can update this delivery.');
        }

    $currentStatus = (string) $delivery['delivery_status'];

    if ($currentStatus === $status) {
        if ($proofPhotoPath !== null) {
            delete_private_upload($proofPhotoPath);
        }
        $db->commit();
        return;
    }

    if (in_array($currentStatus, delivery_terminal_statuses(), true)) {
        throw new InvalidArgumentException('Delivered or cancelled deliveries can no longer be changed.');
    }

    if (!in_array($status, delivery_allowed_next_statuses($currentStatus), true)) {
        throw new InvalidArgumentException('Delivery status must follow the correct workflow sequence.');
    }

    if (!delivery_payment_ready($delivery)) {
        throw new InvalidArgumentException('GCash orders must be verified or paid before delivery can continue.');
    }

    $issue = trim($issue);
    $recipientName = trim($recipientName);
    $overrideReason = trim($overrideReason);
    $isCodDeliveryCompletion = ($delivery['payment_method'] ?? '') === 'cash' && $status === 'delivered';

    if (in_array($status, ['failed_delivery', 'issue_reported'], true) && $issue === '') {
        throw new InvalidArgumentException('Please provide a reason for Failed Delivery or Issue Reported.');
    }

    $deliveredAt = $status === 'delivered' ? (delivery_normalize_datetime($deliveredAt) ?? date('Y-m-d H:i:s')) : null;

    if ($status === 'delivered' && $recipientName === '') {
        throw new InvalidArgumentException('Please enter the recipient name before completing the delivery.');
    }

    if ($isCodDeliveryCompletion && (empty($delivery['transaction_id']) || ($delivery['transaction_status'] ?? '') !== 'pending')) {
        throw new InvalidArgumentException('COD payment was already completed, cancelled, or is not available for collection.');
    }

    $orderStatus = match ($status) {
        'out_for_delivery' => 'out_for_delivery',
        'delivered' => 'completed',
        'failed_delivery', 'issue_reported' => 'assigned',
        'cancelled' => 'cancelled',
        default => 'assigned',
    };

    db_execute(
        'UPDATE deliveries
         SET delivery_status = ?,
             issue_notes = ?,
             delivered_at = ?,
             proof_photo_path = COALESCE(?, proof_photo_path),
             recipient_name = CASE WHEN ? <> "" THEN ? ELSE recipient_name END,
             updated_at = NOW()
         WHERE order_id = ?',
        'ssssssi',
        [$status, $issue, $deliveredAt, $proofPhotoPath, $recipientName, $recipientName, $orderId]
    );

    db_execute(
        'UPDATE orders
         SET order_status = ?, delivery_status = ?, updated_at = NOW()
         WHERE id = ?',
        'ssi',
        [$orderStatus, $status, $orderId]
    );

    if ($isCodDeliveryCompletion) {
        $paymentStatement = $db->prepare(
            'UPDATE transactions
             SET transaction_status = "completed", recorded_by = ?, reviewed_at = NOW(), updated_at = NOW()
             WHERE order_id = ? AND payment_method = "cash" AND transaction_status = "pending"'
        );
        $paymentStatement->bind_param('ii', $userId, $orderId);
        $paymentStatement->execute();
        if ($paymentStatement->affected_rows !== 1) {
            throw new RuntimeException('COD payment completion conflicted with another update.');
        }
        db_execute('UPDATE orders SET payment_status = "paid", updated_at = NOW() WHERE id = ?', 'i', [$orderId]);
    }

    $historyNote = $issue;

    if ($isCodDeliveryCompletion) {
        $historyNote = 'COD payment received by assigned rider.';
    }

    record_delivery_history((int) $delivery['id'], $orderId, $currentStatus, $status, $userId, $historyNote);
    $statusLabel = ucwords(str_replace('_', ' ', $status));
    $deliveryType = $status === 'delivered' ? 'success' : (in_array($status, ['failed_delivery', 'issue_reported'], true) ? 'warning' : 'info');

    $customerTitle = $isCodDeliveryCompletion ? 'Delivered and Paid' : 'Delivery Update';
    $customerMessage = $isCodDeliveryCompletion
        ? 'Your order ' . $delivery['reference_no'] . ' was delivered to ' . $recipientName . '; cash was received and payment is now Paid.'
        : 'Delivery status for ' . $delivery['reference_no'] . ' is now ' . $statusLabel . '.';
    create_notification((int) $delivery['customer_id'], $customerTitle, $customerMessage, $deliveryType);
    notify_active_roles(
        ['administrator', 'manager'],
        'Delivery Status Updated ' . $delivery['reference_no'],
        'Delivery for order ' . $delivery['reference_no'] . ' is now ' . $statusLabel . '.',
        $deliveryType,
        [$userId, (int) $delivery['customer_id']]
    );

    log_audit($userId, 'delivery_status_updated', 'Updated delivery for order #' . $orderId . ' to ' . $status);

    if ($isCodDeliveryCompletion) {
        $auditDescription = 'Recorded COD payment as completed for order #' . $orderId . ' with recipient "' . $recipientName . '"';

        log_audit($userId, 'cod_payment_completed', $auditDescription);
    }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        delete_private_upload($proofPhotoPath);
        throw $exception;
    }
}

/**
 * A manager/administrator may explicitly resolve a rider-reported exception.
 * This is the only supported path from issue_reported/failed_delivery back to
 * the ready queue; it is audited and never changes completed deliveries.
 */
function resolve_delivery_exception(
    int $orderId,
    string $action,
    int $resolverId,
    ?int $riderId = null,
    ?string $estimatedDeliveryAt = null,
    string $reason = ''
): void {
    $action = require_allowed_value($action, ['return_to_ready', 'reassign'], 'Select a valid delivery resolution.');
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('Enter a reason for resolving the delivery exception.');
    }
    if ($action === 'reassign' && (!$riderId || $riderId < 1)) {
        throw new InvalidArgumentException('Select an active rider for reassignment.');
    }
    $estimatedDeliveryAt = delivery_normalize_datetime($estimatedDeliveryAt);
    $database = get_db();
    $database->begin_transaction();

    try {
        $resolver = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$resolverId]);
        if (!$resolver || ($resolver['status'] ?? '') !== 'active' || !empty($resolver['deleted_at'])
            || !in_array((string) ($resolver['role'] ?? ''), ['manager', 'administrator'], true)) {
            throw new InvalidArgumentException('Only an active manager or administrator can resolve delivery exceptions.');
        }

        $delivery = fetch_delivery_workflow_record($orderId, true);
        if (!$delivery || !in_array((string) $delivery['delivery_status'], ['issue_reported', 'failed_delivery'], true)) {
            throw new InvalidArgumentException('Only a reported or failed delivery can be resolved.');
        }
        if (($delivery['order_status'] ?? '') === 'completed' || ($delivery['delivery_status'] ?? '') === 'delivered') {
            throw new InvalidArgumentException('A delivered order cannot return to the ready queue.');
        }

        $assignedRiderId = null;
        $rider = null;
        if ($action === 'reassign') {
            $rider = db_find('SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $riderId]);
            if (!$rider || ($rider['role'] ?? '') !== 'rider' || ($rider['status'] ?? '') !== 'active' || !empty($rider['deleted_at'])) {
                throw new InvalidArgumentException('Select an active rider account.');
            }
            $active = db_select(
                'SELECT id FROM deliveries
                 WHERE assigned_staff_id = ? AND delivery_status IN ("assigned", "out_for_delivery", "issue_reported") AND id <> ?
                 ORDER BY id FOR UPDATE',
                'ii',
                [(int) $riderId, (int) $delivery['id']]
            );
            if (count($active) >= delivery_active_limit()) {
                throw new InvalidArgumentException('Selected rider has reached the active delivery limit.');
            }
            $assignedRiderId = (int) $riderId;
        }

        db_execute(
            'UPDATE deliveries
             SET assigned_staff_id = ?, delivery_status = "assigned", issue_notes = NULL,
                 estimated_delivery_at = ?, delivered_at = NULL, updated_at = NOW()
             WHERE id = ?',
            'isi',
            [$assignedRiderId, $estimatedDeliveryAt, (int) $delivery['id']]
        );
        db_execute(
            'UPDATE orders SET assigned_staff_id = ?, order_status = "assigned", delivery_status = "assigned", updated_at = NOW() WHERE id = ?',
            'ii',
            [$assignedRiderId, $orderId]
        );
        record_delivery_history(
            (int) $delivery['id'],
            $orderId,
            (string) $delivery['delivery_status'],
            'ready_for_delivery',
            $resolverId,
            $reason . ($assignedRiderId ? ' Reassigned to rider #' . $assignedRiderId . '.' : ' Returned to the unassigned ready queue.')
        );

        if ($assignedRiderId && $rider) {
            create_notification($assignedRiderId, 'Delivery Reassignment', 'You have been assigned to order ' . $delivery['reference_no'] . ' after an exception review.', 'warning');
        }
        create_notification((int) $delivery['customer_id'], 'Delivery Rescheduled', 'Delivery for ' . $delivery['reference_no'] . ' was returned to the ready queue after review.', 'info');
        log_audit($resolverId, 'delivery_exception_resolved', 'Resolved ' . $delivery['delivery_status'] . ' for order #' . $orderId . ': ' . $reason);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}
