<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$viewer = current_user();

if (is_post()) {
    try {
        $action = request_string('action', 'save_receipt_settings');
        if ($action === 'save_site_logo') {
            save_site_logo($viewer ?? [], $_FILES['site_logo'] ?? null);
            flash('receipt_settings', 'System logo updated successfully.', 'success');
        } elseif ($action === 'reset_site_logo') {
            reset_site_logo($viewer ?? []);
            flash('receipt_settings', 'System logo reset to the default Fred\'s logo.', 'success');
        } else {
            save_receipt_settings($viewer ?? [], [
                'business_name' => request('business_name', ''),
                'business_address' => request('business_address', ''),
                'contact_number' => request('contact_number', ''),
                'contact_email' => request('contact_email', ''),
                'social_media' => request('social_media', ''),
                'tax_rate' => request('tax_rate', 0),
                'service_fee' => request('service_fee', 0),
                'delivery_fee' => request('delivery_fee', 0),
                'footer_message' => request('footer_message', ''),
                'paper_width' => request('paper_width', '58mm'),
            ]);
            flash('receipt_settings', 'Receipt settings saved successfully.', 'success');
        }
        redirect('admin/receipt-settings.php');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('receipt_settings', $exception->getMessage(), 'danger');
    } catch (Throwable $exception) {
        error_log('Receipt settings update failed: ' . $exception->getMessage());
        flash('receipt_settings', 'Unable to save receipt settings.', 'danger');
    }
}

$settings = receipt_settings();
if (is_post()) {
    foreach (['business_name', 'business_address', 'contact_number', 'contact_email', 'social_media', 'tax_rate', 'service_fee', 'delivery_fee', 'footer_message', 'paper_width'] as $field) {
        $settings[$field] = request($field, $settings[$field] ?? '');
    }
}

$pageTitle = 'Receipt Settings';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Thermal Receipt Settings', 'Configure business information, pricing fees, VAT, receipt footer, and the default printer width.', 'receipt', 'System Settings'); ?>

            <div class="content-grid">
                <div class="page-panel">
                    <div class="card-head"><h2>Branding</h2></div>
                    <div class="branding-settings">
                        <div class="branding-settings__preview">
                            <span class="eyebrow">Current Logo</span>
                            <div class="branding-settings__logo">
                                <img src="<?= e(get_site_logo_url()) ?>" alt="Fred's Batchoy &amp; Coffee Shop logo">
                            </div>
                            <p><?= has_custom_site_logo() ? 'Custom logo is active system-wide.' : 'The built-in Fred\'s logo is active.' ?></p>
                        </div>
                        <div class="branding-settings__actions">
                            <form method="post" enctype="multipart/form-data" class="stack-form branding-upload-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_site_logo">
                                <label for="site-logo-upload">Upload New Logo
                                    <input id="site-logo-upload" type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp" required>
                                    <small>PNG, JPG, or WebP. Maximum file size: 2 MB.</small>
                                </label>
                                <button class="btn btn-primary" type="submit">Save Logo</button>
                            </form>
                            <form method="post" class="inline-form" data-confirm-message="Reset the system logo to the built-in Fred's logo?" data-confirm-title="Reset System Logo" data-confirm-label="Reset to Default" data-confirm-variant="warning">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_site_logo">
                                <button class="btn btn-secondary" type="submit" <?= has_custom_site_logo() ? '' : 'disabled' ?>>Reset to Default Logo</button>
                            </form>
                        </div>
                    </div>

                    <div class="settings-section-divider" aria-hidden="true"></div>
                    <div class="card-head"><h2>Business Information</h2></div>
                    <form method="post" class="stack-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_receipt_settings">
                        <label>Business Name
                            <input type="text" name="business_name" maxlength="150" required value="<?= e((string) ($settings['business_name'] ?? '')) ?>">
                        </label>
                        <label>Address
                            <textarea name="business_address" maxlength="255" required><?= e((string) ($settings['business_address'] ?? '')) ?></textarea>
                        </label>
                        <label>Contact Number / Information
                            <input type="text" name="contact_number" maxlength="60" required value="<?= e((string) ($settings['contact_number'] ?? '')) ?>">
                        </label>
                        <label>Email <small>(optional)</small>
                            <input type="email" name="contact_email" maxlength="150" value="<?= e((string) ($settings['contact_email'] ?? '')) ?>">
                        </label>
                        <label>Social Media <small>(optional)</small>
                            <input type="text" name="social_media" maxlength="150" value="<?= e((string) ($settings['social_media'] ?? '')) ?>">
                        </label>

                        <div class="form-grid">
                            <label>Tax / VAT Percentage
                                <input type="number" name="tax_rate" min="0" max="100" step="0.01" required value="<?= e((string) ($settings['tax_rate'] ?? 12)) ?>">
                                <small>VAT remains inclusive in the displayed grand total.</small>
                            </label>
                            <label>Service Fee
                                <input type="number" name="service_fee" min="0" max="999999.99" step="0.01" required value="<?= e((string) ($settings['service_fee'] ?? 0)) ?>">
                            </label>
                            <label>Delivery Fee
                                <input type="number" name="delivery_fee" min="0" max="999999.99" step="0.01" required value="<?= e((string) ($settings['delivery_fee'] ?? 0)) ?>">
                            </label>
                            <label>Default Paper Width
                                <select name="paper_width" required>
                                    <option value="58mm" <?= ($settings['paper_width'] ?? '58mm') === '58mm' ? 'selected' : '' ?>>58mm</option>
                                    <option value="80mm" <?= ($settings['paper_width'] ?? '58mm') === '80mm' ? 'selected' : '' ?>>80mm</option>
                                </select>
                            </label>
                        </div>

                        <label>Receipt Footer Message
                            <textarea name="footer_message" maxlength="500" required><?= e((string) ($settings['footer_message'] ?? '')) ?></textarea>
                        </label>
                        <button class="btn btn-primary" type="submit">Save Receipt Settings</button>
                    </form>
                </div>

                <aside class="info-card">
                    <h2>Pricing behavior</h2>
                    <p>New orders store a snapshot of the active VAT and fee values, so changing settings later does not alter historical receipts.</p>
                    <p>Existing orders retain zero fees and their original totals.</p>
                    <p>Choose the matching paper size in the browser print dialog for edge-to-edge thermal output.</p>
                </aside>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
