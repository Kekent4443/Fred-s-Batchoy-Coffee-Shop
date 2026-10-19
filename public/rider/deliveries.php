<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login('rider');

$riderId = (int) current_user()['id'];

if (is_post()) {
    $proofPhotoPath = null;
    try {
        $orderId = (int) request('order_id');
        $nextStatus = (string) request('workflow_status');
        $recipientName = trim((string) request('recipient_name'));
        $proofPhotoPath = save_uploaded_delivery_proof($_FILES['proof_photo'] ?? null);

        if (in_array($nextStatus, ['issue_reported', 'failed_delivery'], true)) {
            update_delivery_status(
                $orderId,
                $nextStatus,
                $riderId,
                trim((string) request('issue_reason')),
                null,
                $proofPhotoPath
            );
        } else {
            update_order_workflow_status(
                $orderId,
                $nextStatus,
                $riderId,
                '',
                null,
                $proofPhotoPath,
                $recipientName
            );
        }

        $message = match ($nextStatus) {
            'delivered' => 'Delivery completed. For COD, the cash payment was recorded as paid atomically.',
            'issue_reported' => 'Delivery issue reported for manager review.',
            'failed_delivery' => 'Failed delivery attempt recorded for manager review.',
            default => 'Order marked Out for Delivery.',
        };
        flash('deliveries', $message, in_array($nextStatus, ['issue_reported', 'failed_delivery'], true) ? 'warning' : 'success');
    } catch (InvalidArgumentException $exception) {
        if ($proofPhotoPath && function_exists('delete_uploaded_evidence')) {
            delete_uploaded_evidence($proofPhotoPath);
        }
        flash('deliveries', $exception->getMessage(), 'danger');
    }

    redirect('rider/deliveries.php');
}

$search = table_search_value();
$allDeliveries = fetch_deliveries('rider', $riderId);
$deliveries = array_values(array_filter($allDeliveries, static function (array $delivery) use ($search): bool {
    if ($search === '') {
        return true;
    }

    return str_contains(strtolower(implode(' ', [
        $delivery['reference_no'] ?? '',
        $delivery['customer_name'] ?? '',
        $delivery['delivery_address'] ?? '',
        $delivery['delivery_landmark'] ?? '',
        $delivery['delivery_status'] ?? '',
    ])), strtolower($search));
}));

$pageTitle = 'Assigned Deliveries';
$bodyClass = 'rider-deliveries-page';
$pageStyles = ['css/rider-deliveries.css'];
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section rider-deliveries">
            <?php render_page_header('Assigned Deliveries', 'Update only the next delivery step for orders assigned to you.', 'delivery', 'Rider Portal', null, 'rider-deliveries__heading'); ?>
            <div class="page-panel rider-deliveries__panel">
                <form method="get" class="toolbar rider-deliveries__toolbar" role="search">
                    <input type="search" name="search" placeholder="Search reference, customer, or address" value="<?= e($search) ?>">
                    <button class="btn btn-primary" type="submit">Search</button>
                    <a class="btn btn-ghost" href="<?= e(url('rider/deliveries.php')) ?>">Reset</a>
                </form>
                <div class="table-responsive rider-deliveries__list">
                    <table class="data-table rider-deliveries-table">
                        <thead><tr><th>Reference</th><th>Customer</th><th>Address</th><th>Status</th><th>Payment</th><th>Delivery Record</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($deliveries as $delivery): ?>
                            <?php $workflowStatus = order_workflow_current_status($delivery); ?>
                            <tr class="rider-delivery-card <?= priority_item_class($delivery) ?>" <?= priority_data_attr($delivery) ?>>
                                <td class="rider-delivery-card__reference" data-label="Reference">
                                    <strong class="rider-delivery-card__reference-number"><?= e($delivery['reference_no']) ?></strong>
                                    <?= priority_badge($delivery, 'delivery') ?>
                                </td>
                                <td class="rider-delivery-card__customer" data-label="Customer">
                                    <strong><?= e($delivery['customer_name']) ?></strong>
                                    <small><?= e(($delivery['customer_phone'] ?? '') ?: 'No phone recorded') ?></small>
                                </td>
                                <td class="rider-delivery-card__address" data-label="Address">
                                    <span><?= e($delivery['delivery_address']) ?></span>
                                    <?php if (!empty($delivery['delivery_landmark'])): ?><small>Landmark: <?= e((string) $delivery['delivery_landmark']) ?></small><?php endif; ?>
                                </td>
                                <td class="rider-delivery-card__status" data-label="Status">
                                    <div class="rider-delivery-card__badges"><?= order_workflow_badge($delivery) ?></div>
                                </td>
                                <td class="rider-delivery-card__payment" data-label="Payment">
                                    <?= order_payment_summary($delivery) ?>
                                </td>
                                <td class="rider-delivery-card__record" data-label="Delivery Record">
                                    <dl class="rider-delivery-record">
                                        <div><dt>Recipient</dt><dd><?= e($delivery['recipient_name'] ?: 'Not recorded') ?></dd></div>
                                        <div><dt>Date and time</dt><dd><?= e(format_display_datetime($delivery['delivered_at'] ?? null, 'Not recorded')) ?></dd></div>
                                    </dl>
                                    <?php if (!empty($delivery['proof_photo_path'])): ?>
                                        <a class="rider-delivery-record__photo" href="<?= e(protected_upload_url('delivery_proof', (int) $delivery['id'])) ?>" target="_blank" rel="noopener noreferrer">View delivery photo</a>
                                    <?php endif; ?>
                                </td>
                                <td class="rider-delivery-card__actions status-action-controls" data-label="Action" data-status-action-controls>
                                    <?php if ($workflowStatus === 'ready_for_delivery'): ?>
                                        <section class="rider-delivery-action-panel rider-delivery-action-panel--ready" aria-label="Delivery action">
                                            <div>
                                                <h2>Ready for delivery</h2>
                                                <p>Start this delivery when you are ready to leave.</p>
                                            </div>
                                            <form method="post" class="inline-form rider-delivery-action-form" data-status-action-form>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="order_id" value="<?= e((string) $delivery['order_id']) ?>">
                                                <input type="hidden" name="workflow_status" value="out_for_delivery">
                                                <button class="btn btn-primary btn-sm status-action-button" type="submit"><?= status_action_button_content('delivery', 'Start Delivery', 'Starting...') ?></button>
                                            </form>
                                        </section>
                                    <?php elseif ($workflowStatus === 'out_for_delivery'): ?>
                                        <div class="rider-delivery-actions-grid">
                                            <section class="rider-delivery-action-panel" aria-labelledby="complete-delivery-<?= e((string) $delivery['id']) ?>">
                                                <div class="rider-delivery-action-panel__heading">
                                                    <h2 id="complete-delivery-<?= e((string) $delivery['id']) ?>">Complete delivery</h2>
                                                    <p>Record who received the order and add an optional delivery photo.</p>
                                                </div>
                                                <form method="post" class="stack-form rider-delivery-action-form" enctype="multipart/form-data" data-status-action-form data-confirm-message="Mark this order as delivered and close the rider delivery action?" data-confirm-title="Confirm Delivery" data-confirm-label="Mark as Delivered" data-confirm-variant="warning">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="order_id" value="<?= e((string) $delivery['order_id']) ?>">
                                                    <input type="hidden" name="workflow_status" value="delivered">
                                                    <label>Recipient name
                                                        <input type="text" name="recipient_name" placeholder="Enter recipient name" autocomplete="name" required maxlength="150">
                                                    </label>
                                                    <label>Delivery photo <span class="rider-form-optional">Optional</span>
                                                        <input type="file" name="proof_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                                    </label>
                                                    <?php $deliveryActionLabel = ($delivery['payment_method'] ?? '') === 'cash' ? 'Delivered and Cash Received' : 'Mark as Delivered'; ?>
                                                    <button class="btn btn-primary btn-sm status-action-button" type="submit"><?= status_action_button_content('check-circle', $deliveryActionLabel, 'Completing...') ?></button>
                                                </form>
                                            </section>
                                            <details class="rider-delivery-problem">
                                                <summary>Report a delivery problem</summary>
                                                <div class="rider-delivery-problem__body">
                                                    <p>Send the issue to a manager for review. Include a clear reason and optional photo evidence.</p>
                                                    <form method="post" class="stack-form rider-delivery-action-form" enctype="multipart/form-data" data-status-action-form data-confirm-message="Submit this delivery problem for manager review?" data-confirm-title="Report Delivery Problem" data-confirm-label="Submit Problem" data-confirm-variant="warning">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="order_id" value="<?= e((string) $delivery['order_id']) ?>">
                                                        <label>Problem type
                                                            <select name="workflow_status" required>
                                                                <option value="issue_reported">Issue Reported</option>
                                                                <option value="failed_delivery">Failed Delivery Attempt</option>
                                                            </select>
                                                        </label>
                                                        <label>Required reason
                                                            <textarea name="issue_reason" rows="4" minlength="5" maxlength="1000" required placeholder="Describe what prevented delivery or requires manager review."></textarea>
                                                        </label>
                                                        <label>Photo evidence <span class="rider-form-optional">Optional</span>
                                                            <input type="file" name="proof_photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                                        </label>
                                                        <button class="btn btn-danger btn-sm status-action-button" type="submit"><?= status_action_button_content('alert-triangle', 'Submit Delivery Problem', 'Submitting...') ?></button>
                                                    </form>
                                                </div>
                                            </details>
                                        </div>
                                    <?php else: ?>
                                        <div class="rider-delivery-action-panel rider-delivery-action-panel--complete">
                                            <span class="badge badge-muted">No action available</span>
                                            <p>This delivery has no remaining rider action.</p>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($deliveries === []): ?><tr class="rider-delivery-card rider-delivery-card--empty"><td colspan="7"><strong>No assigned deliveries found.</strong><span>Try another search or check back when a delivery is assigned to you.</span></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
