<?php

declare(strict_types=1);

$contactMessagesActionViewer = current_user();
$contactMessagesActionRole = (string) ($contactMessagesActionViewer['role'] ?? '');

if (!is_post()) {
    return;
}

$contactMessagesAction = (string) request('action', 'update_message');

try {
    if ($contactMessagesAction === 'save_contact_settings') {
        save_contact_settings($contactMessagesActionViewer ?? [], contact_settings_payload_from_request());
        flash('contact_messages', 'Contact Us details updated.', 'success');
        redirect(role_folder($contactMessagesActionRole) . '/contact-messages.php');
    }

    if ($contactMessagesAction === 'mark_read') {
        $messageId = (int) request('message_id', 0);
        $message = fetch_contact_message_for_viewer($messageId, $contactMessagesActionViewer ?? []);
        if (!$message) {
            throw new InvalidArgumentException('Contact message not found.');
        }
        db_execute('UPDATE contact_messages SET is_read = 1, updated_at = NOW() WHERE id = ? AND is_read = 0', 'i', [$messageId]);
        log_audit((int) ($contactMessagesActionViewer['id'] ?? 0), 'contact_message_read', 'Marked contact message #' . $messageId . ' as read.');
        flash('contact_messages', 'Contact message marked as read.', 'success');
        redirect(role_folder($contactMessagesActionRole) . '/contact-messages.php?id=' . $messageId);
    }

    update_contact_message(
        (int) request('message_id', 0),
        $contactMessagesActionViewer ?? [],
        (string) request('status', 'new'),
        trim((string) request('internal_note', '')),
        trim((string) request('response_message', '')),
        request('send_reply') === '1'
    );
    flash('contact_messages', 'Contact message updated.', 'success');
} catch (RuntimeException|InvalidArgumentException $exception) {
    flash('contact_messages', $exception->getMessage(), 'danger');
} catch (Throwable $exception) {
    error_log('Contact panel update failed: ' . $exception->getMessage());
    flash('contact_messages', 'Unable to update Contact Us information.', 'danger');
}

redirect(role_folder($contactMessagesActionRole) . '/contact-messages.php?id=' . (int) request('message_id', 0));
