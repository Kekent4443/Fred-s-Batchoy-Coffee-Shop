<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/mail.php';

function email_last_error(): string
{
    return (string) ($GLOBALS['email_last_error'] ?? '');
}

function send_email_with_result(string $toEmail, string $toName, string $subject, string $body, ?int $queueId = null): array
{
    $GLOBALS['email_last_error'] = '';
    if (defined('TESTING_MODE') && TESTING_MODE && is_callable($GLOBALS['freds_test_mail_transport'] ?? null)) {
        return ($GLOBALS['freds_test_mail_transport'])($toEmail, $toName, $subject, $body, $queueId);
    }
    $settings = mail_settings();
    $mail = new PHPMailer(true);

    try {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The recipient email address is invalid.');
        }

        if (!transactional_mail_is_configured()) {
            throw new RuntimeException('Brevo SMTP credentials are not configured correctly.');
        }

        $mail->isSMTP();
        $mail->Host = $settings['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];
        $mail->SMTPSecure = $settings['encryption'] === 'smtps'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $settings['port'];
        $mail->Timeout = 20;
        $mail->getSMTPInstance()->Timelimit = 20;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($settings['from_address'], $settings['from_name']);
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], PHP_EOL, $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($queueId !== null && $queueId > 0) {
            $mail->addCustomHeader('X-Mailin-custom', 'queue_id:' . $queueId);
        }
        $mail->send();

        return [
            'success' => true,
            'temporary' => false,
            'message_id' => trim($mail->getLastMessageID(), '<>'),
            'error' => '',
        ];
    } catch (Throwable $exception) {
        $error = trim($mail->ErrorInfo) !== '' ? trim($mail->ErrorInfo) : $exception->getMessage();
        $smtpCode = method_exists($mail, 'getSMTPInstance') ? (int) ($mail->getSMTPInstance()->getError()['smtp_code'] ?? 0) : 0;
        if ($smtpCode >= 400 && $smtpCode < 500) {
            $temporary = true;
        } elseif ($smtpCode >= 500 && $smtpCode < 600) {
            $temporary = false;
        } else {
            $temporary = preg_match('/timeout|timed out|temporar|connect|connection|network|try again|rate limit/i', $error) === 1;
        }

        $safeError = 'SMTP delivery failed' . ($smtpCode > 0 ? ' (SMTP ' . $smtpCode . ')' : '') . '.';
        $GLOBALS['email_last_error'] = $safeError;
        error_log(
            'Brevo SMTP delivery failed (' . $exception::class . ', code ' . (int) $exception->getCode()
            . ', SMTP ' . $smtpCode . '): ' . mail_log_message($error)
        );

        return [
            'success' => false,
            'temporary' => $temporary,
            'message_id' => '',
            'error' => $safeError,
            'smtp_code' => $smtpCode,
        ];
    }
}

function sendEmail(string $toEmail, string $toName, string $subject, string $body): bool
{
    return (bool) send_email_with_result($toEmail, $toName, $subject, $body)['success'];
}
