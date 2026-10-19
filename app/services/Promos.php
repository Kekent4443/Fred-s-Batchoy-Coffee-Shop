<?php

declare(strict_types=1);

function fetch_bulk_discount_rules(bool $includeInactive = true): array
{
    ensure_pricing_schema();

    $sql = 'SELECT * FROM bulk_discount_rules';
    if (!$includeInactive) {
        $sql .= ' WHERE status = "active"';
    }

    return db_select($sql . ' ORDER BY min_quantity ASC, COALESCE(max_quantity, 999999) ASC, id ASC');
}

function normalize_promo_code_value(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code !== '' && !preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
        throw new InvalidArgumentException('Promo codes may contain only letters, numbers, underscores, and hyphens.');
    }

    return $code;
}

function cart_applied_promo_code(): string
{
    return normalize_promo_code_value((string) ($_SESSION['promo_code'] ?? ''));
}

function promo_effective_status(array $promo, ?int $now = null): string
{
    $now ??= time();
    if (($promo['status'] ?? '') !== 'active') {
        return 'inactive';
    }

    $startsAt = !empty($promo['starts_at']) ? strtotime((string) $promo['starts_at']) : null;
    $endsAt = !empty($promo['ends_at']) ? strtotime((string) $promo['ends_at']) : null;
    if ($startsAt === false || $endsAt === false) {
        return 'inactive';
    }
    if ($startsAt !== null && $now < $startsAt) {
        return 'scheduled';
    }
    if ($endsAt !== null && $now > $endsAt) {
        return 'expired';
    }
    if ((int) ($promo['usage_limit'] ?? 0) > 0
        && (int) ($promo['used_count'] ?? 0) >= (int) $promo['usage_limit']) {
        return 'usage_limit_reached';
    }

    return 'active';
}

function promo_discount_description(array $promo): string
{
    $value = (float) ($promo['discount_value'] ?? 0);
    $label = ($promo['discount_type'] ?? '') === 'percent'
        ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% discount'
        : currency($value) . ' discount';
    if (($promo['maximum_discount'] ?? null) !== null && (float) $promo['maximum_discount'] > 0) {
        $label .= ', up to ' . currency((float) $promo['maximum_discount']) . ' maximum discount';
    }

    return $label;
}

function validated_promo_discount(array $promo, float $subtotal, float $existingDiscount = 0.0, ?int $now = null): float
{
    if (promo_effective_status($promo, $now) !== 'active') {
        throw new InvalidArgumentException('This promo code is unavailable or does not apply to your cart.');
    }

    $subtotal = round(max(0.0, $subtotal), 2);
    $minimumSubtotal = (float) ($promo['minimum_subtotal'] ?? 0);
    $discountType = (string) ($promo['discount_type'] ?? '');
    $discountValue = (float) ($promo['discount_value'] ?? 0);
    $maximumDiscount = ($promo['maximum_discount'] ?? null) === null ? null : (float) $promo['maximum_discount'];
    if (!is_finite($minimumSubtotal) || $minimumSubtotal < 0 || $subtotal < $minimumSubtotal
        || !in_array($discountType, ['percent', 'fixed'], true)
        || !is_finite($discountValue) || $discountValue <= 0
        || ($discountType === 'percent' && $discountValue > 100)
        || ($maximumDiscount !== null && (!is_finite($maximumDiscount) || $maximumDiscount <= 0))) {
        throw new InvalidArgumentException('This promo code is unavailable or does not apply to your cart.');
    }

    $eligible = max(0.0, $subtotal - max(0.0, $existingDiscount));
    $discount = $discountType === 'percent'
        ? round($eligible * $discountValue / 100, 2)
        : round($discountValue, 2);
    if ($maximumDiscount !== null) {
        $discount = min($discount, $maximumDiscount);
    }

    return round(min($subtotal, $eligible, $discount), 2);
}

function promo_code_preview(string $code, float $subtotal, float $existingDiscount = 0.0): array
{
    $code = normalize_promo_code_value($code);
    if ($code === '') {
        return ['code' => '', 'discount_amount' => 0.0, 'promo' => null];
    }

    $promo = db_find('SELECT * FROM promo_codes WHERE code = ? LIMIT 1', 's', [$code]);
    if (!$promo) {
        throw new InvalidArgumentException('This promo code is unavailable or does not apply to your cart.');
    }

    return ['code' => $code, 'discount_amount' => validated_promo_discount($promo, $subtotal, $existingDiscount), 'promo' => $promo];
}

function apply_cart_promo_code(string $code): array
{
    $base = cart_pricing_summary();
    if ((int) ($base['quantity'] ?? 0) < 1) {
        throw new InvalidArgumentException('Add an item to your cart before applying a promo code.');
    }

    $preview = promo_code_preview($code, (float) $base['subtotal'], (float) $base['discount_amount']);
    $_SESSION['promo_code'] = $preview['code'];

    return cart_pricing_summary_with_promo();
}

function clear_cart_promo_code(): void
{
    unset($_SESSION['promo_code']);
}

function cart_pricing_summary_with_promo(): array
{
    $base = cart_pricing_summary();
    $code = cart_applied_promo_code();
    $preview = $code !== ''
        ? promo_code_preview($code, (float) $base['subtotal'], (float) $base['discount_amount'])
        : ['code' => '', 'discount_amount' => 0.0, 'promo' => null];
    $promoDiscount = round((float) $preview['discount_amount'], 2);
    $total = round(max(0.0, (float) $base['total'] - $promoDiscount), 2);
    $vat = vat_inclusive_breakdown($total, (float) $base['tax_rate']);
    $base['promo_code'] = $preview['code'] !== '' ? $preview['code'] : null;
    $base['promo_discount_amount'] = $promoDiscount;
    $base['vatable_sales'] = $vat['vatable_sales'];
    $base['vat_amount'] = $vat['vat_amount'];
    $base['total'] = $total;

    return $base;
}

function fetch_promo_codes(bool $includeInactive = true, string $search = ''): array
{
    $sql = 'SELECT * FROM promo_codes WHERE 1=1';
    $types = '';
    $params = [];
    if (!$includeInactive) {
        $sql .= ' AND status = "active"';
    }
    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND (code LIKE ? OR description LIKE ?)';
        $types .= 'ss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like);
    }
    $sql .= ' ORDER BY status = "active" DESC, COALESCE(ends_at, "9999-12-31") ASC, code ASC';

    return db_select($sql, $types, $params);
}

function promo_datetime_value(mixed $value, string $label): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function save_promo_code(array $viewer, int $promoId, array $payload): int
{
    if (!in_array((string) ($viewer['role'] ?? ''), ['administrator', 'manager'], true)
        || ($viewer['status'] ?? '') !== 'active' || empty($viewer['id'])) {
        throw new RuntimeException('Only an active administrator or manager can manage promo codes.');
    }

    $code = normalize_promo_code_value(require_non_empty_string((string) ($payload['code'] ?? ''), 'Promo code is required.'));
    $description = trim((string) ($payload['description'] ?? ''));
    $discountType = require_allowed_value((string) ($payload['discount_type'] ?? ''), ['percent', 'fixed'], 'Select a valid discount type.');
    $discountValue = round((float) ($payload['discount_value'] ?? 0), 2);
    $minimumSubtotal = round((float) ($payload['minimum_subtotal'] ?? 0), 2);
    $maximumInput = trim((string) ($payload['maximum_discount'] ?? ''));
    $maximumDiscount = $maximumInput === '' ? null : round((float) $maximumInput, 2);
    $limitInput = trim((string) ($payload['usage_limit'] ?? ''));
    $usageLimit = $limitInput === '' ? null : (int) $limitInput;
    $startsAt = promo_datetime_value($payload['starts_at'] ?? '', 'promo start date');
    $endsAt = promo_datetime_value($payload['ends_at'] ?? '', 'promo end date');
    $status = $promoId > 0
        ? require_allowed_value((string) ($payload['status'] ?? 'active'), ['active', 'inactive'], 'Select a valid promo status.')
        : 'active';

    if (strlen($description) > 255) {
        throw new InvalidArgumentException('Promo description must be 255 characters or fewer.');
    }
    if ($discountValue <= 0 || $discountValue > 99999999.99 || ($discountType === 'percent' && $discountValue > 100)) {
        throw new InvalidArgumentException($discountType === 'percent' ? 'Percent discounts must be greater than 0 and at most 100.' : 'Enter a valid fixed discount amount.');
    }
    if ($minimumSubtotal < 0 || $minimumSubtotal > 99999999.99 || ($maximumDiscount !== null && ($maximumDiscount <= 0 || $maximumDiscount > 99999999.99))) {
        throw new InvalidArgumentException('Minimum subtotal and maximum discount must be valid non-negative amounts.');
    }
    if ($usageLimit !== null && $usageLimit < 1) {
        throw new InvalidArgumentException('Usage limit must be empty or at least 1.');
    }
    if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
        throw new InvalidArgumentException('Promo end date must be later than its start date.');
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $actor = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $viewer['id']]);
        if (!$actor || ($actor['status'] ?? '') !== 'active' || !empty($actor['deleted_at'])
            || !in_array((string) ($actor['role'] ?? ''), ['administrator', 'manager'], true)) {
            throw new RuntimeException('You are no longer authorized to manage promo codes.');
        }

        $existing = $promoId > 0 ? db_find('SELECT * FROM promo_codes WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$promoId]) : null;
        if ($promoId > 0 && !$existing) {
            throw new InvalidArgumentException('Promo code does not exist.');
        }
        if ($usageLimit !== null && $existing && $usageLimit < (int) $existing['used_count']) {
            throw new InvalidArgumentException('Usage limit cannot be lower than the number of completed redemptions.');
        }
        $duplicate = db_find('SELECT id FROM promo_codes WHERE code = ? AND id <> ? LIMIT 1 FOR UPDATE', 'si', [$code, $promoId]);
        if ($duplicate) {
            throw new InvalidArgumentException('That promo code already exists.');
        }

        if ($existing) {
            db_execute(
                'UPDATE promo_codes
                 SET code = ?, description = ?, discount_type = ?, discount_value = ?, minimum_subtotal = ?,
                     maximum_discount = ?, usage_limit = ?, starts_at = ?, ends_at = ?, status = ?, updated_at = NOW()
                 WHERE id = ?',
                'sssdddisssi',
                [$code, $description !== '' ? $description : null, $discountType, $discountValue, $minimumSubtotal, $maximumDiscount, $usageLimit, $startsAt, $endsAt, $status, $promoId]
            );
        } else {
            db_execute(
                'INSERT INTO promo_codes
                    (code, description, discount_type, discount_value, minimum_subtotal, maximum_discount, usage_limit, starts_at, ends_at, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                'sssdddisss',
                [$code, $description !== '' ? $description : null, $discountType, $discountValue, $minimumSubtotal, $maximumDiscount, $usageLimit, $startsAt, $endsAt, $status]
            );
            $promoId = db_last_id();
        }

        log_audit((int) $viewer['id'], 'promo_code_saved', 'Saved promo code #' . $promoId . ' (' . $code . ') with status ' . $status . '.');
        $database->commit();
    } catch (mysqli_sql_exception $exception) {
        $database->rollback();
        if ((int) $exception->getCode() === 1062) {
            throw new InvalidArgumentException('That promo code already exists.', 0, $exception);
        }
        throw $exception;
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }

    return $promoId;
}

function delete_promo_code(array $viewer, int $promoId): void
{
    if (!in_array((string) ($viewer['role'] ?? ''), ['administrator', 'manager'], true)
        || ($viewer['status'] ?? '') !== 'active' || empty($viewer['id'])) {
        throw new RuntimeException('Only an active administrator or manager can manage promo codes.');
    }
    if ($promoId < 1) {
        throw new InvalidArgumentException('Please select a valid promo code.');
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $actor = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $viewer['id']]);
        if (!$actor || ($actor['status'] ?? '') !== 'active' || !empty($actor['deleted_at'])
            || !in_array((string) ($actor['role'] ?? ''), ['administrator', 'manager'], true)) {
            throw new RuntimeException('You are no longer authorized to manage promo codes.');
        }

        $promo = db_find('SELECT id, code, used_count FROM promo_codes WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$promoId]);
        if (!$promo) {
            throw new InvalidArgumentException('Promo code does not exist.');
        }
        if ((int) $promo['used_count'] > 0
            || db_find('SELECT id FROM orders WHERE promo_code = ? LIMIT 1', 's', [(string) $promo['code']])) {
            throw new InvalidArgumentException('This promo code has order history. Set its status to Inactive instead of deleting it.');
        }

        db_execute('DELETE FROM promo_codes WHERE id = ?', 'i', [$promoId]);
        log_audit((int) $viewer['id'], 'promo_code_deleted', 'Deleted unused promo code #' . $promoId . ' (' . $promo['code'] . ').');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function validate_bulk_discount_quantity_range(mixed $minimum, mixed $maximum): array
{
    $minQuantity = filter_var($minimum, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 4294967295],
    ]);
    if ($minQuantity === false) {
        throw new InvalidArgumentException('Minimum Quantity must be a positive whole number.');
    }

    if ($maximum === null || trim((string) $maximum) === '') {
        throw new InvalidArgumentException('Maximum Quantity is required.');
    }
    $maxQuantity = filter_var($maximum, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 4294967295],
    ]);
    if ($maxQuantity === false) {
        throw new InvalidArgumentException('Maximum Quantity must be a positive whole number.');
    }
    if ($minQuantity >= $maxQuantity) {
        throw new InvalidArgumentException('Maximum Quantity must be greater than Minimum Quantity.');
    }

    return [(int) $minQuantity, (int) $maxQuantity];
}

function validate_bulk_discount_rate(mixed $value): float
{
    if (!is_scalar($value) || !is_numeric((string) $value)) {
        throw new InvalidArgumentException('Discount rate must be between 0 and 100.');
    }
    $rate = (float) $value;
    if (!is_finite($rate) || $rate < 0 || $rate > 100) {
        throw new InvalidArgumentException('Discount rate must be between 0 and 100.');
    }

    return round($rate, 2);
}

function bulk_discount_rule_payload_from_request(int $ruleId = 0): array
{
    $label = require_non_empty_string((string) post_scalar('label', ''), 'Rule label is required.');
    [$minQuantity, $maxQuantity] = validate_bulk_discount_quantity_range(
        post_scalar('min_quantity', ''),
        post_scalar('max_quantity', '')
    );
    if (strlen($label) > 120) {
        throw new InvalidArgumentException('Rule label must be 120 characters or fewer.');
    }
    $discountRate = validate_bulk_discount_rate(post_scalar('discount_rate', ''));
    $status = $ruleId > 0
        ? require_allowed_value((string) post_scalar('status', ''), ['active', 'inactive'], 'Please select a valid rule status.')
        : 'active';

    return [
        'label' => $label,
        'min_quantity' => $minQuantity,
        'max_quantity' => $maxQuantity,
        'discount_rate' => $discountRate,
        'status' => $status,
    ];
}

function save_bulk_discount_rule(array $viewer, int $ruleId, array $payload): void
{
    ensure_pricing_schema();

    if (!in_array((string) ($viewer['role'] ?? ''), ['administrator', 'manager'], true)
        || (int) ($viewer['id'] ?? 0) < 1) {
        throw new RuntimeException('You do not have permission to update discount rules.');
    }

    [$payload['min_quantity'], $payload['max_quantity']] = validate_bulk_discount_quantity_range(
        $payload['min_quantity'] ?? null,
        $payload['max_quantity'] ?? null
    );
    $label = require_non_empty_string((string) ($payload['label'] ?? ''), 'Rule label is required.');
    if (strlen($label) > 120) {
        throw new InvalidArgumentException('Rule label must be 120 characters or fewer.');
    }
    $discountRate = validate_bulk_discount_rate($payload['discount_rate'] ?? null);
    $status = $ruleId > 0
        ? require_allowed_value((string) ($payload['status'] ?? ''), ['active', 'inactive'], 'Please select a valid rule status.')
        : 'active';

    $database = get_db();
    $database->begin_transaction();
    try {
        $actor = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $viewer['id']]);
        if (!$actor || ($actor['status'] ?? '') !== 'active' || !empty($actor['deleted_at'])
            || !in_array((string) ($actor['role'] ?? ''), ['administrator', 'manager'], true)) {
            throw new RuntimeException('You do not have permission to update discount rules.');
        }

        if ($ruleId > 0) {
            if (!db_find('SELECT id FROM bulk_discount_rules WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$ruleId])) {
                throw new InvalidArgumentException('Discount rule does not exist.');
            }
            db_execute(
                'UPDATE bulk_discount_rules
                 SET label = ?, min_quantity = ?, max_quantity = ?, discount_rate = ?, status = ?, updated_at = NOW()
                 WHERE id = ?',
                'siidsi',
                [$label, $payload['min_quantity'], $payload['max_quantity'], $discountRate, $status, $ruleId]
            );
        } else {
            db_execute(
                'INSERT INTO bulk_discount_rules (label, min_quantity, max_quantity, discount_rate, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, "active", NOW(), NOW())',
                'siid',
                [$label, $payload['min_quantity'], $payload['max_quantity'], $discountRate]
            );
            $ruleId = db_last_id();
        }

        log_audit((int) $viewer['id'], 'bulk_discount_rule_saved', 'Saved bulk discount rule #' . $ruleId . ' (' . $label . ')');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function delete_bulk_discount_rule(array $viewer, int $ruleId): void
{
    if (!in_array((string) ($viewer['role'] ?? ''), ['administrator', 'manager'], true)) {
        throw new RuntimeException('You do not have permission to delete discount rules.');
    }
    if ((int) ($viewer['id'] ?? 0) < 1) {
        throw new RuntimeException('You do not have permission to delete discount rules.');
    }
    if ($ruleId < 1) {
        throw new InvalidArgumentException('Please select a valid discount rule.');
    }

    $database = get_db();
    $database->begin_transaction();
    try {
        $actor = db_find('SELECT id, role, status, deleted_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [(int) $viewer['id']]);
        if (!$actor || ($actor['status'] ?? '') !== 'active' || !empty($actor['deleted_at'])
            || !in_array((string) ($actor['role'] ?? ''), ['administrator', 'manager'], true)) {
            throw new RuntimeException('You do not have permission to delete discount rules.');
        }

        $rule = db_find('SELECT id, label FROM bulk_discount_rules WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$ruleId]);
        if (!$rule) {
            throw new InvalidArgumentException('Discount rule does not exist.');
        }

        db_execute('DELETE FROM bulk_discount_rules WHERE id = ?', 'i', [$ruleId]);
        log_audit((int) $viewer['id'], 'bulk_discount_rule_deleted', 'Deleted bulk discount rule #' . $ruleId . ' (' . $rule['label'] . ')');
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

