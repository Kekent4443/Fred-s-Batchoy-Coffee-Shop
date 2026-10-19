<?php

declare(strict_types=1);

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section discount-management">
            <?php $discountManagementTab = 'bulk'; $discountManagementBaseFolder = role_folder((string) (current_user()['role'] ?? '')); require APP_ROOT . '/app/includes/discounts_promotions_tabs.php'; ?>

            <div class="content-grid bulk-discount-layout">
                <div class="page-panel">
                    <div class="card-head"><h2><?= $editRule ? 'Edit Rule' : 'New Rule' ?></h2></div>
                    <form method="post" class="stack-form" data-bulk-discount-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_rule">
                        <input type="hidden" name="rule_id" value="<?= e((string) ($editRule['id'] ?? 0)) ?>">
                        <label>Label
                            <input type="text" name="label" maxlength="120" required value="<?= e((string) ($editRule['label'] ?? '')) ?>"<?= form_error_attributes($discountRuleErrors, 'label', 'discount-rule') ?>>
                            <?= form_field_error($discountRuleErrors, 'label', 'discount-rule') ?>
                        </label>
                        <div class="form-grid">
                            <label>Minimum Quantity
                                <input type="number" name="min_quantity" min="1" required value="<?= e((string) ($editRule['min_quantity'] ?? '')) ?>"<?= form_error_attributes($discountRuleErrors, 'min_quantity', 'discount-rule') ?>>
                                <?= form_field_error($discountRuleErrors, 'min_quantity', 'discount-rule') ?>
                            </label>
                            <label>Maximum Quantity
                                <input type="number" name="max_quantity" min="2" required value="<?= e((string) ($editRule['max_quantity'] ?? '')) ?>"<?= form_error_attributes($discountRuleErrors, 'max_quantity', 'discount-rule') ?>>
                                <small>Must be greater than Minimum Quantity.</small>
                                <?= form_field_error($discountRuleErrors, 'max_quantity', 'discount-rule') ?>
                            </label>
                            <label class="form-grid__full">Discount Rate (%)
                                <input type="number" name="discount_rate" min="0" max="100" step="0.01" required value="<?= e((string) ($editRule['discount_rate'] ?? '')) ?>"<?= form_error_attributes($discountRuleErrors, 'discount_rate', 'discount-rule') ?>>
                                <?= form_field_error($discountRuleErrors, 'discount_rate', 'discount-rule') ?>
                            </label>
                            <?php if (!empty($editRule['id'])): ?>
                                <label class="form-grid__full">Status
                                    <select name="status">
                                        <option value="active" <?= ($editRule['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= ($editRule['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </label>
                            <?php endif; ?>
                        </div>
                        <div class="bulk-discount-form-actions">
                            <button class="btn btn-primary" type="submit"><?= $editRule ? 'Save Changes' : 'Save Rule' ?></button>
                            <?php if (!empty($editRule['id'])): ?>
                                <a class="btn btn-secondary" href="<?= e(url($discountRulesBasePath)) ?>">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="page-panel">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rule</th>
                                    <th>Quantity</th>
                                    <th>Discount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $rule): ?>
                                    <tr>
                                        <td data-label="Rule"><?= e((string) $rule['label']) ?></td>
                                        <td data-label="Quantity"><?= e((string) $rule['min_quantity']) ?> - <?= $rule['max_quantity'] !== null ? e((string) $rule['max_quantity']) : 'Maximum missing' ?><?php if (isset($overlappingRuleIds[(int) $rule['id']])): ?><small class="discount-management__overlap-note">Overlaps an active rule</small><?php endif; ?></td>
                                        <td data-label="Discount"><?= e(rtrim(rtrim(number_format((float) $rule['discount_rate'], 2), '0'), '.')) ?>%</td>
                                        <td data-label="Status"><?= status_badge((string) $rule['status']) ?></td>
                                        <td data-label="Action">
                                            <div class="table-actions bulk-discount-actions">
                                                <a class="btn btn-icon btn-edit-icon" href="<?= e(url($discountRulesBasePath . '?edit=' . $rule['id'])) ?>" aria-label="Edit <?= e((string) $rule['label']) ?>" title="Edit Rule">
                                                    <?= edit_icon_svg() ?>
                                                    <span class="sr-only">Edit Rule</span>
                                                </a>
                                                <form method="post" class="inline-form inline-form--icon" data-confirm-message="Delete this bulk discount rule? It will no longer apply to future orders. This action cannot be undone." data-confirm-title="Delete Bulk Discount Rule" data-confirm-label="Delete Rule" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_rule">
                                                    <input type="hidden" name="rule_id" value="<?= e((string) $rule['id']) ?>">
                                                    <button class="btn btn-icon btn-delete-icon" type="submit" aria-label="Delete <?= e((string) $rule['label']) ?>" title="Delete Rule">
                                                        <?= trash_icon_svg() ?>
                                                        <span class="sr-only">Delete Rule</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($rules === []): ?>
                                    <tr><td colspan="5">No discount rules found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
