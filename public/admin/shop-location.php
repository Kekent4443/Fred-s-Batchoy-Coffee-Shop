<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('administrator');

$viewer = current_user();
$locations = fetch_shop_locations(true);
$selectedShopId = (int) request('shop_id', (int) ($locations[0]['id'] ?? 0));
$shopLocationErrors = [];
$shopFormInput = null;
$addFormInput = null;
$addFormErrors = [];

if ($selectedShopId <= 0 && $locations !== []) {
    $selectedShopId = (int) $locations[0]['id'];
}

if (is_post()) {
    $action = (string) post_scalar('action', 'save_location');

    if ($action === 'delete_location') {
        try {
            delete_shop_location((int) post_scalar('shop_id', 0), $viewer ?? []);
            flash('shop_location', 'Shop location deleted successfully.', 'success');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            flash('shop_location', $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            error_log('Shop location delete failed: ' . $exception->getMessage());
            flash('shop_location', 'Unable to delete this shop location.', 'danger');
        }
        redirect('admin/shop-location.php');
    }

    if ($action === 'add_location') {
        $addFormInput = [
            'shop_name' => request('shop_name', ''),
            'complete_address' => request('complete_address', ''),
            'google_maps_url' => request('google_maps_url', ''),
            'google_maps_embed_url' => request('google_maps_embed_url', ''),
            'latitude' => request('latitude', ''),
            'longitude' => request('longitude', ''),
            'contact_number' => request('contact_number', ''),
            'landmark' => request('landmark', ''),
            'status' => 'active',
        ];
        try {
            $payload = shop_location_payload_from_request(false);
            $newId = add_shop_location($viewer ?? [], $payload);
            flash('shop_location', 'New shop location added successfully.', 'success');
            redirect('admin/shop-location.php?shop_id=' . $newId);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            flash('shop_location', $exception->getMessage(), 'danger');
            $addFormErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Shop location add failed: ' . $exception->getMessage());
            flash('shop_location', 'Unable to add shop location.', 'danger');
            $addFormErrors['form'] = 'Unable to add shop location.';
        }
    }

    if ($action === 'save_location') {
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
            $selectedShopId = (int) post_scalar('shop_id', 0);
            save_shop_location($selectedShopId, $viewer ?? [], shop_location_payload_from_request());
            flash('shop_location', 'Shop location updated successfully.', 'success');
            redirect('admin/shop-location.php?shop_id=' . $selectedShopId);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            flash('shop_location', $exception->getMessage(), 'danger');
            $shopLocationErrors[validation_error_field($exception->getMessage())] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Shop location admin update failed: ' . $exception->getMessage());
            flash('shop_location', 'Unable to update shop location settings.', 'danger');
            $shopLocationErrors['form'] = 'Unable to update shop location settings.';
        }
    }

    if ($action === 'manager_permission') {
        try {
            $managerId = (int) post_scalar('manager_id', 0);
            $assignedShopId = (int) post_scalar('assigned_shop_id', 0);
            $canEdit = post_scalar('can_edit_shop_location') === '1';
            update_manager_location_permission($managerId, $assignedShopId, $canEdit, $viewer ?? []);
            flash('shop_location', 'Manager location permission updated.', 'success');
            redirect('admin/shop-location.php?shop_id=' . $selectedShopId);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            flash('shop_location', $exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            error_log('Shop location permission update failed: ' . $exception->getMessage());
            flash('shop_location', 'Unable to update shop location settings.', 'danger');
        }
    }
}

$locations = fetch_shop_locations(true);
$shop = fetch_shop_location_by_id($selectedShopId) ?? current_shop_location();
if ($shopFormInput !== null && $shopLocationErrors !== []) {
    $shop = array_merge($shop, $shopFormInput);
}
$selectedShopId = (int) ($shop['id'] ?? $selectedShopId);
$mapPreview = shop_location_embed_src($shop);
$publicMapUrl = shop_location_public_url($shop);
$managers = db_select(
    'SELECT u.id, u.full_name, u.email, u.can_edit_shop_location, u.assigned_shop_id, sl.shop_name AS assigned_shop_name
     FROM users u
     LEFT JOIN shop_locations sl ON sl.id = u.assigned_shop_id
     WHERE u.role = "manager"
     ORDER BY u.full_name ASC'
);
$assignedShopCounts = [];
foreach (db_select('SELECT assigned_shop_id, COUNT(*) AS total FROM users WHERE assigned_shop_id IS NOT NULL GROUP BY assigned_shop_id') as $assignment) {
    $assignedShopCounts[(int) $assignment['assigned_shop_id']] = (int) $assignment['total'];
}
$activeLocations = count(array_filter($locations, static fn (array $loc): bool => ($loc['status'] ?? '') === 'active'));
$inactiveLocations = count($locations) - $activeLocations;

$pageTitle = 'Shop Location Management';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Shop Location Management', 'View, add, and manage shop locations with Google Maps integration.', 'location', 'System Settings', static function (): void { ?><a class="btn btn-primary" href="#add-shop">Add New Shop</a><?php }); ?>

            <div class="metric-grid">
                <article class="metric-box"><div class="metric-box__icon blue">📍</div><strong><?= e((string) count($locations)) ?></strong><span>Total Locations</span></article>
                <article class="metric-box"><div class="metric-box__icon green">✓</div><strong><?= e((string) $activeLocations) ?></strong><span>Active</span></article>
                <article class="metric-box"><div class="metric-box__icon red">×</div><strong><?= e((string) $inactiveLocations) ?></strong><span>Inactive</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">👤</div><strong><?= e((string) count($managers)) ?></strong><span>Managers</span></article>
            </div>

            <!-- Shop Location List -->
            <div class="page-panel">
                <div class="card-head"><h2>All Shop Locations</h2></div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Shop Name</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Coordinates</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $location): ?>
                                <?php $deleteReason = shop_location_deletion_reason($location, $locations, $assignedShopCounts[(int) $location['id']] ?? 0); ?>
                                <tr<?= (int) $location['id'] === $selectedShopId ? ' class="is-highlighted"' : '' ?>>
                                    <td><strong><?= e((string) $location['shop_name']) ?></strong></td>
                                    <td><?= e(mb_strimwidth((string) $location['complete_address'], 0, 60, '...')) ?></td>
                                    <td><?= e((string) $location['contact_number']) ?></td>
                                    <td>
                                        <?php if (!empty($location['latitude']) && !empty($location['longitude'])): ?>
                                            <?= e((string) $location['latitude']) ?>, <?= e((string) $location['longitude']) ?>
                                        <?php else: ?>
                                            <span class="badge badge-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= status_badge((string) $location['status']) ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/shop-location.php?shop_id=' . $location['id'] . '#edit-shop')) ?>">Edit</a>
                                            <form method="post" class="inline-form inline-form--icon" data-confirm-message="Permanently delete <?= e((string) $location['shop_name']) ?>? This cannot be undone." data-confirm-title="Delete Shop Location" data-confirm-label="Delete Permanently" data-confirm-variant="danger" data-confirm-sensitive="true">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_location">
                                                <input type="hidden" name="shop_id" value="<?= e((string) $location['id']) ?>">
                                                <button class="btn btn-icon btn-delete-icon" type="submit" aria-label="Delete <?= e((string) $location['shop_name']) ?>" title="<?= e($deleteReason ?? 'Delete Shop Location') ?>"<?= $deleteReason !== null ? ' disabled' : '' ?>>
                                                    <?= trash_icon_svg() ?>
                                                    <span class="sr-only">Delete Shop Location</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($locations === []): ?>
                                <tr><td colspan="6">No shop locations found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add New Shop Location -->
            <div class="page-panel page-panel--spaced" id="add-shop">
                <div class="card-head"><h2>Add New Shop Location</h2></div>
                <form method="post" class="stack-form shop-location-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_location">

                    <label>Shop Name
                        <input type="text" name="shop_name" maxlength="180" required value="<?= e((string) ($addFormInput['shop_name'] ?? '')) ?>"<?= form_error_attributes($addFormErrors, 'name', 'add-shop-location') ?>>
                        <?= form_field_error($addFormErrors, 'name', 'add-shop-location') ?>
                    </label>
                    <label>Complete Address
                        <textarea name="complete_address" rows="3" required<?= form_error_attributes($addFormErrors, 'address', 'add-shop-location') ?>><?= e((string) ($addFormInput['complete_address'] ?? '')) ?></textarea>
                        <?= form_field_error($addFormErrors, 'address', 'add-shop-location') ?>
                    </label>
                    <label>Contact Number
                        <input type="text" name="contact_number" maxlength="40" required value="<?= e((string) ($addFormInput['contact_number'] ?? '')) ?>"<?= form_error_attributes($addFormErrors, 'contact_number', 'add-shop-location') ?>>
                        <?= form_field_error($addFormErrors, 'contact_number', 'add-shop-location') ?>
                    </label>
                    <div class="form-grid">
                        <label>Latitude
                            <input type="number" step="0.0000001" min="-90" max="90" name="latitude" value="<?= e((string) ($addFormInput['latitude'] ?? '')) ?>"<?= form_error_attributes($addFormErrors, 'latitude', 'add-shop-location') ?>>
                            <?= form_field_error($addFormErrors, 'latitude', 'add-shop-location') ?>
                        </label>
                        <label>Longitude
                            <input type="number" step="0.0000001" min="-180" max="180" name="longitude" value="<?= e((string) ($addFormInput['longitude'] ?? '')) ?>"<?= form_error_attributes($addFormErrors, 'longitude', 'add-shop-location') ?>>
                            <?= form_field_error($addFormErrors, 'longitude', 'add-shop-location') ?>
                        </label>
                    </div>
                    <label>Google Maps Public Link
                        <input type="url" name="google_maps_url" required value="<?= e((string) ($addFormInput['google_maps_url'] ?? '')) ?>" placeholder="https://www.google.com/maps/..."<?= form_error_attributes($addFormErrors, 'google_maps_url', 'add-shop-location') ?>>
                        <?= form_field_error($addFormErrors, 'google_maps_url', 'add-shop-location') ?>
                    </label>
                    <label>Google Maps Embed URL
                        <input type="url" name="google_maps_embed_url" value="<?= e((string) ($addFormInput['google_maps_embed_url'] ?? '')) ?>" placeholder="https://www.google.com/maps?q=...&output=embed"<?= form_error_attributes($addFormErrors, 'google_maps_embed_url', 'add-shop-location') ?>>
                        <small>Leave blank to auto-generate from latitude and longitude.</small>
                        <?= form_field_error($addFormErrors, 'google_maps_embed_url', 'add-shop-location') ?>
                    </label>
                    <label>Landmark or Location Description
                        <textarea name="landmark" rows="2"><?= e((string) ($addFormInput['landmark'] ?? '')) ?></textarea>
                    </label>
                    <button class="btn btn-primary" type="submit">Add Shop Location</button>
                </form>
            </div>

            <!-- Edit Selected Shop Location -->
            <div class="content-grid" id="edit-shop">
                <div class="page-panel">
                    <div class="card-head"><h2>Edit Location Details</h2></div>
                    <?php if (count($locations) > 1): ?>
                        <form method="get" class="toolbar shop-location-selector">
                            <select name="shop_id" aria-label="Select shop">
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?= e((string) $location['id']) ?>" <?= $selectedShopId === (int) $location['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $location['shop_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-secondary" type="submit">Load Shop</button>
                        </form>
                    <?php endif; ?>

                    <form method="post" class="stack-form shop-location-form" data-shop-location-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_location">
                        <input type="hidden" name="shop_id" value="<?= e((string) $selectedShopId) ?>">

                        <label>Shop Name
                            <input type="text" name="shop_name" maxlength="180" required value="<?= e((string) $shop['shop_name']) ?>"<?= form_error_attributes($shopLocationErrors, 'name', 'shop-location') ?>>
                            <?= form_field_error($shopLocationErrors, 'name', 'shop-location') ?>
                        </label>
                        <label>Complete Address
                            <textarea name="complete_address" rows="3" required<?= form_error_attributes($shopLocationErrors, 'address', 'shop-location') ?>><?= e((string) $shop['complete_address']) ?></textarea>
                            <?= form_field_error($shopLocationErrors, 'address', 'shop-location') ?>
                        </label>
                        <label>Google Maps Public Link
                            <input type="url" name="google_maps_url" required value="<?= e((string) $shop['google_maps_url']) ?>" placeholder="https://www.google.com/maps/..."<?= form_error_attributes($shopLocationErrors, 'google_maps_url', 'shop-location') ?>>
                            <?= form_field_error($shopLocationErrors, 'google_maps_url', 'shop-location') ?>
                        </label>
                        <label>Google Maps Embed URL
                            <input type="url" name="google_maps_embed_url" value="<?= e((string) ($shop['google_maps_embed_url'] ?? '')) ?>" placeholder="https://www.google.com/maps?q=...&output=embed" data-shop-embed-url<?= form_error_attributes($shopLocationErrors, 'google_maps_embed_url', 'shop-location') ?>>
                            <?= form_field_error($shopLocationErrors, 'google_maps_embed_url', 'shop-location') ?>
                        </label>
                        <div class="form-grid">
                            <label>Latitude
                                <input type="number" step="0.0000001" min="-90" max="90" name="latitude" value="<?= e((string) ($shop['latitude'] ?? '')) ?>" data-shop-latitude<?= form_error_attributes($shopLocationErrors, 'latitude', 'shop-location') ?>>
                                <?= form_field_error($shopLocationErrors, 'latitude', 'shop-location') ?>
                            </label>
                            <label>Longitude
                                <input type="number" step="0.0000001" min="-180" max="180" name="longitude" value="<?= e((string) ($shop['longitude'] ?? '')) ?>" data-shop-longitude<?= form_error_attributes($shopLocationErrors, 'longitude', 'shop-location') ?>>
                                <?= form_field_error($shopLocationErrors, 'longitude', 'shop-location') ?>
                            </label>
                        </div>
                        <label>Contact Number
                            <input type="text" name="contact_number" maxlength="40" required value="<?= e((string) $shop['contact_number']) ?>"<?= form_error_attributes($shopLocationErrors, 'contact_number', 'shop-location') ?>>
                            <?= form_field_error($shopLocationErrors, 'contact_number', 'shop-location') ?>
                        </label>
                        <label>Landmark or Location Description
                            <textarea name="landmark" rows="3"><?= e((string) ($shop['landmark'] ?? '')) ?></textarea>
                        </label>
                        <label>Status
                            <select name="status">
                                <option value="active" <?= ($shop['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($shop['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </label>
                        <button class="btn btn-primary" type="submit">Save Location</button>
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
                        <dt>Coordinates</dt>
                        <dd><?= e(trim((string) ($shop['latitude'] ?? '') . ', ' . (string) ($shop['longitude'] ?? ''), ', ')) ?></dd>
                        <dt>Google Maps Link</dt>
                        <dd><a href="<?= e($publicMapUrl) ?>" target="_blank" rel="noopener noreferrer">View in Google Maps</a></dd>
                    </dl>
                </div>
            </div>

            <!-- Manager Location Permissions -->
            <div class="page-panel page-panel--spaced">
                <div class="card-head"><h2>Manager Location Permissions</h2></div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Manager</th>
                                <th>Assigned Shop</th>
                                <th>Can Edit</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($managers as $manager): ?>
                                <?php $permissionFormId = 'manager-location-permission-' . (int) $manager['id']; ?>
                                <tr>
                                    <td><strong><?= e((string) $manager['full_name']) ?></strong><br><small><?= e((string) $manager['email']) ?></small></td>
                                    <td>
                                        <select name="assigned_shop_id" form="<?= e($permissionFormId) ?>" aria-label="Assigned shop for <?= e((string) $manager['full_name']) ?>">
                                            <option value="0">Default single shop</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?= e((string) $location['id']) ?>" <?= (int) ($manager['assigned_shop_id'] ?? 0) === (int) $location['id'] ? 'selected' : '' ?>>
                                                    <?= e((string) $location['shop_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <label class="toggle-row">
                                            <input type="checkbox" name="can_edit_shop_location" value="1" form="<?= e($permissionFormId) ?>" <?= (int) ($manager['can_edit_shop_location'] ?? 0) === 1 ? 'checked' : '' ?>>
                                            <span><?= (int) ($manager['can_edit_shop_location'] ?? 0) === 1 ? 'Allowed' : 'Read only' ?></span>
                                        </label>
                                    </td>
                                    <td>
                                        <form method="post" id="<?= e($permissionFormId) ?>" class="inline-form shop-location-permission-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="manager_permission">
                                            <input type="hidden" name="manager_id" value="<?= e((string) $manager['id']) ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit">Save Permission</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($managers === []): ?>
                                <tr><td colspan="4">No manager accounts found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
