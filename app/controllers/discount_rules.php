<?php

declare(strict_types=1);

// Included only after the role wrapper has authenticated the real user.
$viewer = current_user();
$editId = (int) request('edit', 0);
$editRule = $editId > 0 ? db_find('SELECT * FROM bulk_discount_rules WHERE id = ? LIMIT 1', 'i', [$editId]) : null;
$discountRuleErrors = [];

if (is_post() && post_scalar('action') === 'delete_rule') {
    try {
        delete_bulk_discount_rule($viewer, (int) request('rule_id', 0));
        flash('discount_rules', 'Bulk discount rule deleted.', 'success');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('discount_rules', $exception->getMessage(), 'danger');
    } catch (Throwable $exception) {
        error_log('Bulk discount rule delete failed: ' . $exception->getMessage());
        flash('discount_rules', 'Unable to delete discount rule.', 'danger');
    }
    redirect($discountRulesBasePath);
}

if (is_post() && post_scalar('action') === 'save_rule') {
    $submittedRule = [
        'id' => (int) post_scalar('rule_id', 0),
        'label' => post_scalar('label', ''),
        'min_quantity' => post_scalar('min_quantity', ''),
        'max_quantity' => post_scalar('max_quantity', ''),
        'discount_rate' => post_scalar('discount_rate', ''),
        'status' => (int) post_scalar('rule_id', 0) > 0 ? post_scalar('status', '') : 'active',
    ];
    try {
        save_bulk_discount_rule($viewer, (int) $submittedRule['id'], bulk_discount_rule_payload_from_request((int) $submittedRule['id']));
        flash('discount_rules', 'Bulk discount rule saved.', 'success');
        redirect($discountRulesBasePath);
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('discount_rules', $exception->getMessage(), 'danger');
        $discountRuleErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        $editRule = $submittedRule;
    } catch (Throwable $exception) {
        error_log('Bulk discount rule save failed: ' . $exception->getMessage());
        flash('discount_rules', 'Unable to save discount rule.', 'danger');
        $discountRuleErrors['form'] = 'Unable to save discount rule.';
        $editRule = $submittedRule;
    }
}

$rules = fetch_bulk_discount_rules(true);
$overlappingRuleIds = [];
foreach ($rules as $index => $rule) {
    if ($rule['status'] !== 'active' || (int) ($rule['max_quantity'] ?? 0) <= (int) $rule['min_quantity']) {
        continue;
    }
    foreach ($rules as $otherIndex => $otherRule) {
        if ($otherIndex <= $index || $otherRule['status'] !== 'active'
            || (int) ($otherRule['max_quantity'] ?? 0) <= (int) $otherRule['min_quantity']) {
            continue;
        }
        if ((int) $rule['min_quantity'] <= (int) $otherRule['max_quantity']
            && (int) $otherRule['min_quantity'] <= (int) $rule['max_quantity']) {
            $overlappingRuleIds[(int) $rule['id']] = true;
            $overlappingRuleIds[(int) $otherRule['id']] = true;
        }
    }
}
$pageTitle = 'Discounts & Promotions';
