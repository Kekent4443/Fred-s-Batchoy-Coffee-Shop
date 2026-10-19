<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('manager');

$viewer = current_user();
$managerSettings = $viewer ? shop_location_user_settings((int) $viewer['id']) : [];
$assignedShopId = (int) ($managerSettings['assigned_shop_id'] ?? 0);
$locations = $assignedShopId > 0
    ? array_values(array_filter([fetch_shop_location_by_id($assignedShopId)]))
    : fetch_shop_locations(false);
$shop = $locations[0] ?? current_shop_location();
$shopId = (int) ($shop['id'] ?? 0);
$canEdit = $viewer ? user_can_edit_shop_location($viewer, $shopId) : false;
$shopLocationErrors = [];
$shopFormInput = null;

if (is_post()) {
    $shopFormInput = [
        'shop_name' => request('shop_name', ''),
        'complete_address' => request('complete_address', ''),
        'google_maps_url' => request('google_maps_url', ''),
        'google_maps_embed_url' => request('google_maps_embed_url', ''),
        'latitude' => request('latitude', ''),
        'longitude' => request('longitude', ''),
        'contact_number' => request('contact_number', ''),
        'landmark' => request('landmark', ''),
        'status' => request('status', 'active'),
    ];
    try {
        save_shop_location((int) post_scalar('shop_id', 0), $viewer ?? [], shop_location_payload_from_request());
        flash('shop_location', 'Shop location updated successfully.', 'success');
        redirect('manager/shop-location.php');
    } catch (InvalidArgumentException|RuntimeException $exception) {
        flash('shop_location', $exception->getMessage(), 'danger');
        $shopLocationErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Shop location manager update failed: ' . $exception->getMessage());
        flash('shop_location', 'Unable to update shop location.', 'danger');
        $shopLocationErrors['form'] = 'Unable to update shop location.';
    }
}

$shop = fetch_shop_location_by_id($shopId) ?? current_shop_location();
if ($shopFormInput !== null && $shopLocationErrors !== []) {
    $shop = array_merge($shop, $shopFormInput);
}
$mapPreview = shop_location_embed_src($shop);
$publicMapUrl = shop_location_public_url($shop);

$pageTitle = 'Shop Location';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Shop Location', $canEdit ? 'Update your assigned shop location shown to customers.' : 'View the current shop location. Editing requires administrator permission.', 'location', 'Location Management', static function () use ($publicMapUrl): void { ?><a class="btn btn-secondary" href="<?= e($publicMapUrl) ?>" target="_blank" rel="noopener noreferrer">View in Google Maps</a><?php }); ?>

            <div class="content-grid">
                <div class="page-panel">
                    <div class="card-head"><h2><?= $canEdit ? 'Update Location' : 'Current Location' ?></h2></div>
                    <form method="post" class="stack-form shop-location-form" data-shop-location-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="shop_id" value="<?= e((string) $shopId) ?>">

                        <label>Shop Name
                            <input type="text" name="shop_name" maxlength="180" required value="<?= e((string) $shop['shop_name']) ?>"<?= form_error_attributes($shopLocationErrors, 'name', 'shop-location') ?> <?= $canEdit ? '' : 'disabled' ?>>
                            <?= form_field_error($shopLocationErrors, 'name', 'shop-location') ?>
                        </label>
                        <label>Complete Address
                            <textarea name="complete_address" rows="3" required<?= form_error_attributes($shopLocationErrors, 'address', 'shop-location') ?> <?= $canEdit ? '' : 'disabled' ?>><?= e((string) $shop['complete_address']) ?></textarea>
                            <?= form_field_error($shopLocationErrors, 'address', 'shop-location') ?>
                        </label>
                        <label>Google Maps Public Link
                            <input type="url" name="google_maps_url" required value="<?= e((string) $shop['google_maps_url']) ?>"<?= form_error_attributes($shopLocationErrors, 'google_maps_url', 'shop-location') ?> <?= $canEdit ? '' : 'disabled' ?>>
                            <?= form_field_error($shopLocationErrors, 'google_maps_url', 'shop-location') ?>
                        </label>
                        <label>Google Maps Embed URL
                            <input type="url" name="google_maps_embed_url" value="<?= e((string) ($shop['google_maps_embed_url'] ?? '')) ?>" data-shop-embed-url<?= form_error_attributes($shopLocationErrors, 'google_maps_embed_url', 'shop-location') ?> <?= $canEdit ? '' : 'disabled' ?>>
                            <?= form_field_error($shopLocationErrors, 'google_maps_embed_url', 'shop-location') ?>
                        </label>
                        <div class="form-grid">
                            <label>Latitude
                                <input type="number" step="0.0000001" min="-90" max="90" name="latitude" value="<?= e((string) ($shop['latitude'] ?? '')) ?>" data-shop-latitude<?= form_error_attributes($shopLocationErrors, 'latitude', 'shop-location') ?> <?= $canEdit ? '' : 'disabled' ?>>
                                <?= form_field_error($shopLocationErrors, 'latitude', 'shop-location') ?>
                            </label>
                            <label>Longitude
                                <input type="number" step="0.0000001" min="-180" max="180" name="longitude" value="<?= e((string) ($shop['longitude'] ?? '')) ?>" data-shop-longitude<?= form_error_attributes($shopLocationErrors, 'longitude', 'shop-location') ?> <?= $canEdit ? '' : 'disabled' ?>>
                                <?= form_field_error($shopLocationErrors, 'longitude', 'shop-location') ?>
                            </label>
                        </div>
                        <label>Contact Number
                            <input type="text" name="contact_number" maxlength="40" required value="<?= e((string) $shop['contact_number']) ?>"<?= form_error_attributes($shopLocationErrors, 'contact_number', 'shop-location') ?> <?= $canEdit ? '' : 'disabled' ?>>
                            <?= form_field_error($shopLocationErrors, 'contact_number', 'shop-location') ?>
                        </label>
                        <label>Landmark or Location Description
                            <textarea name="landmark" rows="3" <?= $canEdit ? '' : 'disabled' ?>><?= e((string) ($shop['landmark'] ?? '')) ?></textarea>
                        </label>
                        <input type="hidden" name="status" value="<?= e((string) ($shop['status'] ?? 'active')) ?>">
                        <?php if ($canEdit): ?>
                            <button class="btn btn-primary" type="submit">Save Location</button>
                        <?php else: ?>
                            <p class="muted">Your account has read-only access to shop location details.</p>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="page-panel">
                    <div class="card-head"><h2>Map Preview</h2></div>
                    <div class="shop-location-preview">
                        <?php render_external_map((string) $shop['shop_name'] . ' map preview', $mapPreview, $publicMapUrl, true); ?>
                    </div>
                    <dl class="shop-location-readout">
                        <dt>Address</dt>
                        <dd><?= e((string) $shop['complete_address']) ?></dd>
                        <dt>Contact</dt>
                        <dd><?= e((string) $shop['contact_number']) ?></dd>
                        <dt>Landmark</dt>
                        <dd><?= e(trim((string) ($shop['landmark'] ?? '')) !== '' ? (string) $shop['landmark'] : 'No landmark saved.') ?></dd>
                    </dl>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
