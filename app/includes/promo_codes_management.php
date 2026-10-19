<?php

declare(strict_types=1);

$promoViewer = current_user();
$promoRoleFolder = role_folder((string) ($promoViewer['role'] ?? ''));
$promoRoute = $promoRoleFolder . '/promo-codes.php';
$promoSearch = table_search_value();
$promoEditId = (int) request('edit', 0);
$editPromo = $promoEditId > 0
    ? db_find('SELECT * FROM promo_codes WHERE id = ? LIMIT 1', 'i', [$promoEditId])
    : null;
$promoErrors = [];

if (is_post() && request('action') === 'delete_promo') {
    try {
        delete_promo_code($promoViewer, (int) request('promo_id', 0));
        flash('promo_codes', 'Promo code deleted successfully.', 'success');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('promo_codes', $exception->getMessage(), 'danger');
    } catch (Throwable $exception) {
        error_log('Promo code delete failed: ' . $exception->getMessage());
        flash('promo_codes', 'Unable to delete the promo code right now.', 'danger');
    }
    redirect($promoRoute);
}

if (is_post() && request('action', 'save_promo') === 'save_promo') {
    $submittedPromo = [
        'id' => (int) request('promo_id', 0),
        'code' => request_string('code', '', 40),
        'description' => request_string('description', '', 255),
        'discount_type' => request_string('discount_type', 'percent', 20),
        'discount_value' => request('discount_value', ''),
        'minimum_subtotal' => request('minimum_subtotal', '0'),
        'maximum_discount' => request('maximum_discount', ''),
        'usage_limit' => request('usage_limit', ''),
        'used_count' => (int) ($editPromo['used_count'] ?? 0),
        'starts_at' => request_string('starts_at', '', 30),
        'ends_at' => request_string('ends_at', '', 30),
        'status' => (int) request('promo_id', 0) > 0 ? request_string('status', 'active', 20) : 'active',
    ];
    try {
        $savedPromoId = save_promo_code($promoViewer, (int) $submittedPromo['id'], $submittedPromo);
        flash('promo_codes', 'Promo code saved successfully.', 'success');
        redirect($promoRoute . '?edit=' . $savedPromoId);
    } catch (mysqli_sql_exception $exception) {
        $message = (int) $exception->getCode() === 1062
            ? 'That promo code already exists.'
            : 'Unable to save the promo code right now.';
        flash('promo_codes', $message, 'danger');
        $promoErrors[(int) $exception->getCode() === 1062 ? 'code' : 'form'] = $message;
        if ((int) $exception->getCode() === 1062) {
            $submittedPromo['code'] = '';
        }
        $editPromo = $submittedPromo;
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('promo_codes', $exception->getMessage(), 'danger');
        $promoErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        if (($promoErrors['code'] ?? '') !== '' && str_contains(strtolower($exception->getMessage()), 'already exists')) {
            $submittedPromo['code'] = '';
        }
        $editPromo = $submittedPromo;
    } catch (Throwable $exception) {
        error_log('Promo code save failed: ' . $exception->getMessage());
        flash('promo_codes', 'Unable to save the promo code right now.', 'danger');
        $promoErrors['form'] = 'Unable to save the promo code right now.';
        $editPromo = $submittedPromo;
    }
}

$promoCodes = fetch_promo_codes(true, $promoSearch);
$pageTitle = 'Discounts & Promotions';
require APP_ROOT . '/app/includes/head.php';
require APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section discount-management">
            <?php $discountManagementTab = 'promo'; $discountManagementBaseFolder = $promoRoleFolder; require APP_ROOT . '/app/includes/discounts_promotions_tabs.php'; ?>
            <div class="content-grid">
                <div class="page-panel">
                    <div class="card-head"><h2><?= !empty($editPromo['id']) ? 'Edit Promo Code' : 'New Promo Code' ?></h2></div>
                    <form method="post" class="stack-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_promo">
                        <input type="hidden" name="promo_id" value="<?= e((string) ($editPromo['id'] ?? 0)) ?>">
                        <label>Code<input type="text" name="code" minlength="3" maxlength="40" pattern="[A-Za-z0-9_-]{3,40}" required value="<?= e((string) ($editPromo['code'] ?? '')) ?>"<?= form_error_attributes($promoErrors, 'code', 'promo') ?>><?= form_field_error($promoErrors, 'code', 'promo') ?></label>
                        <label>Description<input type="text" name="description" maxlength="255" value="<?= e((string) ($editPromo['description'] ?? '')) ?>"<?= form_error_attributes($promoErrors, 'description', 'promo') ?>><?= form_field_error($promoErrors, 'description', 'promo') ?></label>
                        <div class="form-grid">
                            <label>Discount Type
                                <select name="discount_type" required>
                                    <option value="percent" <?= ($editPromo['discount_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percentage</option>
                                    <option value="fixed" <?= ($editPromo['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Amount</option>
                                </select>
                            </label>
                            <label>Discount Value<input type="number" name="discount_value" min="0.01" step="0.01" required value="<?= e((string) ($editPromo['discount_value'] ?? '')) ?>"<?= form_error_attributes($promoErrors, 'discount_value', 'promo') ?>><?= form_field_error($promoErrors, 'discount_value', 'promo') ?></label>
                            <label>Minimum Subtotal<input type="number" name="minimum_subtotal" min="0" step="0.01" required value="<?= e((string) ($editPromo['minimum_subtotal'] ?? '0')) ?>"<?= form_error_attributes($promoErrors, 'minimum_subtotal', 'promo') ?>><small>Checked before bulk or promo discounts; excludes fees.</small><?= form_field_error($promoErrors, 'minimum_subtotal', 'promo') ?></label>
                            <label>Maximum Discount<input type="number" name="maximum_discount" min="0.01" step="0.01" value="<?= e((string) ($editPromo['maximum_discount'] ?? '')) ?>" placeholder="No cap"<?= form_error_attributes($promoErrors, 'maximum_discount', 'promo') ?>><?= form_field_error($promoErrors, 'maximum_discount', 'promo') ?></label>
                            <label>Usage Limit<input type="number" name="usage_limit" min="1" step="1" value="<?= e((string) ($editPromo['usage_limit'] ?? '')) ?>" placeholder="Unlimited"<?= form_error_attributes($promoErrors, 'usage_limit', 'promo') ?>><?= form_field_error($promoErrors, 'usage_limit', 'promo') ?></label>
                            <?php if (!empty($editPromo['id'])): ?>
                                <label>Status
                                    <select name="status" required>
                                        <option value="active" <?= ($editPromo['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= ($editPromo['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <div><?= datetime_input_field('starts_at', $editPromo['starts_at'] ?? '', 'Starts At', false, 'promo') ?><?= form_field_error($promoErrors, 'starts_at', 'promo') ?></div>
                            <div><?= datetime_input_field('ends_at', $editPromo['ends_at'] ?? '', 'Ends At', false, 'promo') ?><?= form_field_error($promoErrors, 'ends_at', 'promo') ?></div>
                        </div>
                        <?php if (!empty($editPromo['id'])): ?><small>Used <?= e((string) ($editPromo['used_count'] ?? 0)) ?> time(s). Usage counters cannot be reset by editing.</small><?php endif; ?>
                        <div class="inline-form"><button class="btn btn-primary" type="submit">Save Promo Code</button><?php if (!empty($editPromo['id'])): ?><a class="btn btn-ghost" href="<?= e(url($promoRoute)) ?>">New Code</a><?php endif; ?></div>
                    </form>
                </div>
                <div class="page-panel">
                    <form method="get" class="toolbar">
                        <input type="search" name="search" maxlength="120" placeholder="Search code or description" value="<?= e($promoSearch) ?>">
                        <button class="btn btn-secondary" type="submit">Search</button>
                        <a class="btn btn-ghost" href="<?= e(url($promoRoute)) ?>">Reset</a>
                    </form>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Code</th><th>Discount</th><th>Minimum</th><th>Usage</th><th>Window</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($promoCodes as $promo): ?>
                                <tr>
                                    <td><strong><?= e((string) $promo['code']) ?></strong><br><small><?= e((string) ($promo['description'] ?? '')) ?></small></td>
                                    <td><?= e(promo_discount_description($promo)) ?></td>
                                    <td><?= e(currency((float) $promo['minimum_subtotal'])) ?></td>
                                    <td><?= e((string) $promo['used_count']) ?> / <?= $promo['usage_limit'] !== null ? e((string) $promo['usage_limit']) : 'Unlimited' ?></td>
                                    <td><small><?= e(format_display_datetime($promo['starts_at'] ?? null, 'Immediately')) ?><br>to <?= e(format_display_datetime($promo['ends_at'] ?? null, 'No expiry')) ?></small></td>
                                    <td><?= status_badge(promo_effective_status($promo)) ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <a class="btn btn-icon btn-edit-icon" href="<?= e(url($promoRoute . '?edit=' . (int) $promo['id'])) ?>" aria-label="Edit <?= e((string) $promo['code']) ?>" title="Edit Promo Code">
                                                <?= edit_icon_svg() ?>
                                                <span class="sr-only">Edit Promo Code</span>
                                            </a>
                                            <form method="post" class="inline-form inline-form--icon" data-confirm-message="Delete this promo code permanently? It will no longer apply to future orders. Codes with order history cannot be deleted." data-confirm-title="Delete Promo Code" data-confirm-label="Delete Code" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_promo">
                                                <input type="hidden" name="promo_id" value="<?= e((string) $promo['id']) ?>">
                                                <button class="btn btn-icon btn-delete-icon" type="submit" aria-label="<?= (int) $promo['used_count'] > 0 ? 'Cannot delete used promo code ' : 'Delete promo code ' ?><?= e((string) $promo['code']) ?>" title="<?= (int) $promo['used_count'] > 0 ? 'Set this used code Inactive instead' : 'Delete Promo Code' ?>"<?= (int) $promo['used_count'] > 0 ? ' disabled' : '' ?>>
                                                    <?= trash_icon_svg() ?>
                                                    <span class="sr-only"><?= (int) $promo['used_count'] > 0 ? 'Cannot delete used promo code' : 'Delete Promo Code' ?></span>
                                                </button>
                                            </form>
                                        </div>
                                        <?php if ((int) $promo['used_count'] > 0): ?><small class="muted">Set used codes Inactive.</small><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($promoCodes === []): ?><tr><td colspan="7">No promo codes found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require APP_ROOT . '/app/includes/footer.php'; ?>
