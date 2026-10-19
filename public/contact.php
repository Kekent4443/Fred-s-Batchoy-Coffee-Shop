<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$viewer = current_user();
$business = contact_business_info();
$categories = contact_categories();
$errors = [];
$privacyAcknowledged = false;
$customerOrders = ($viewer && ($viewer['role'] ?? '') === 'customer') ? fetch_orders('customer', (int) $viewer['id']) : [];

$formDefaults = [
    'full_name' => (string) ($viewer['full_name'] ?? ''),
    'email' => (string) ($viewer['email'] ?? ''),
    'contact_number' => (string) ($viewer['phone'] ?? ''),
    'subject' => '',
    'category' => 'general_inquiry',
    'message' => '',
    'related_order_id' => '',
];

if (is_post()) {
    $privacyAcknowledged = post_scalar('privacy_acknowledged') === '1';
    foreach ($formDefaults as $key => $value) {
        $formDefaults[$key] = (string) request($key, $value);
    }

    try {
        if (!$privacyAcknowledged) {
            $errors['privacy_acknowledged'] = 'Please review the privacy notice before sending your message.';
            throw new InvalidArgumentException($errors['privacy_acknowledged']);
        }

        $identifier = $viewer ? 'user:' . (string) $viewer['id'] : (string) request('email', '');

        if (trim((string) request('contact_website', '')) !== '') {
            throw new InvalidArgumentException('Unable to submit this message.');
        }

        if (!rate_limit_allow('contact_message_submit', $identifier, 3, 10 * 60)) {
            throw new InvalidArgumentException('Please wait before sending another message.');
        }

        $messageId = submit_contact_message(contact_payload_from_request($viewer));
        flash('contact', 'Your message was sent successfully. Reference #' . $messageId . '.', 'success');
        redirect('contact.php');
    } catch (InvalidArgumentException $exception) {
        $errors['form'] = $exception->getMessage();
        flash('contact_form', $errors['form'], 'danger');
    } catch (Throwable $exception) {
        error_log('Contact form submit failed: ' . $exception->getMessage());
        $errors['form'] = 'Unable to send your message right now. Please try again later.';
        flash('contact_form', $errors['form'], 'danger');
    }
}

$pageTitle = 'Contact Us';
$bodyClass = ($viewer && ($viewer['role'] ?? '') === 'customer' ? 'customer-body ' : '') . 'contact-page-body';
require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';

$renderContactContent = function () use ($business, $categories, $formDefaults, $customerOrders, $errors, $privacyAcknowledged): void {
    ?>
    <?php require APP_ROOT . '/app/includes/alerts.php'; ?>

    <section class="section contact-page">
        <?php render_page_header('Contact Us', 'Send us your concern or visit the shop using the latest saved business location.', 'phone', 'Contact'); ?>

        <div class="contact-layout">
            <div class="page-panel contact-info-panel">
                <div class="card-head"><h2><?= e($business['shop_name']) ?></h2></div>
                <dl class="contact-info-list">
                    <div><dt>Address</dt><dd><?= e($business['complete_address']) ?></dd></div>
                    <?php if ($business['contact_number'] !== ''): ?><div><dt>Contact Number</dt><dd><a href="tel:<?= e($business['contact_number']) ?>"><?= e($business['contact_number']) ?></a></dd></div><?php endif; ?>
                    <?php if ($business['email_address'] !== ''): ?><div><dt>Email Address</dt><dd><a href="mailto:<?= e($business['email_address']) ?>"><?= e($business['email_address']) ?></a></dd></div><?php endif; ?>
                    <div><dt>Operating Hours</dt><dd><?= e($business['operating_hours']) ?></dd></div>
                    <?php if (trim((string) $business['landmark']) !== ''): ?>
                        <div><dt>Landmark</dt><dd><?= e($business['landmark']) ?></dd></div>
                    <?php endif; ?>
                </dl>
                <a class="btn btn-secondary" href="<?= e($business['map_url']) ?>" target="_blank" rel="noopener noreferrer">View in Google Maps</a>
            </div>

            <div class="page-panel contact-map-panel">
                <div class="card-head"><h2>Shop Location</h2></div>
                <div class="contact-map-frame">
                    <?php render_external_map((string) $business['shop_name'] . ' Google Maps location', (string) $business['map_embed_url'], (string) $business['map_url']); ?>
                </div>
            </div>

            <form method="post" class="page-panel contact-form-panel">
                <?= csrf_field() ?>
                <input type="text" name="contact_website" tabindex="-1" autocomplete="off" class="contact-honeypot" aria-hidden="true">
                <div class="card-head"><h2>Send a Message</h2></div>
                <div class="form-grid">
                    <label>Full Name
                        <input type="text" name="full_name" maxlength="150" required value="<?= e($formDefaults['full_name']) ?>">
                    </label>
                    <label>Email Address
                        <input type="email" name="email" maxlength="150" required value="<?= e($formDefaults['email']) ?>">
                    </label>
                    <label>Contact Number
                        <input type="text" name="contact_number" maxlength="40" required value="<?= e($formDefaults['contact_number']) ?>">
                    </label>
                    <label>Concern Category
                        <select name="category" required>
                            <?php foreach ($categories as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $formDefaults['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label>Subject
                    <input type="text" name="subject" maxlength="180" required value="<?= e($formDefaults['subject']) ?>">
                </label>
                <?php if ($customerOrders !== []): ?>
                    <label>Related Order Number
                        <select name="related_order_id">
                            <option value="">Not related to an order</option>
                            <?php foreach ($customerOrders as $order): ?>
                                <option value="<?= e((string) $order['id']) ?>" <?= (int) $formDefaults['related_order_id'] === (int) $order['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $order['reference_no']) ?> · <?= e(ucwords(str_replace('_', ' ', (string) $order['order_status']))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label>Message
                    <textarea name="message" rows="6" maxlength="3000" required><?= e($formDefaults['message']) ?></textarea>
                </label>
                <label class="terms-agreement" for="contact-privacy-acknowledged">
                    <input type="checkbox" id="contact-privacy-acknowledged" name="privacy_acknowledged" value="1" required <?= $privacyAcknowledged ? 'checked' : '' ?> <?= isset($errors['privacy_acknowledged']) ? 'aria-invalid="true" aria-describedby="contact-privacy-error"' : '' ?>>
                    <span class="terms-agreement__text">I have read the <a href="<?= e(url('privacy-policy.php')) ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a> and understand that my details and message will be used to respond to this inquiry.</span>
                </label>
                <?php if (isset($errors['privacy_acknowledged'])): ?><small id="contact-privacy-error" class="field-error" role="alert"><?= e($errors['privacy_acknowledged']) ?></small><?php endif; ?>
                <button class="btn btn-primary" type="submit">Submit Message</button>
            </form>
        </div>
    </section>
    <?php
};
?>
<?php if ($viewer && ($viewer['role'] ?? '') !== 'customer'): ?>
    <div class="app-shell">
        <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
        <main class="app-main">
            <?php $renderContactContent(); ?>
        </main>
    </div>
<?php else: ?>
    <main class="<?= $viewer ? 'customer-shell' : 'contact-public-shell' ?>">
        <?php $renderContactContent(); ?>
    </main>
<?php endif; ?>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
