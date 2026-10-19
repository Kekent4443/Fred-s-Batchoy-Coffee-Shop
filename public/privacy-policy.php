<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pageTitle = 'Privacy Policy';
$bodyClass = 'guest-body';
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page legal-page">
    <div class="legal-page__inner">
        <article class="auth-card legal-card">
            <header class="legal-card__header">
                <span class="eyebrow">Privacy</span>
                <h1>Privacy Policy</h1>
                <p>How Fred's Batchoy &amp; Coffee Shop uses information through this ordering website.</p>
            </header>

            <section>
                <h2>Information handled by the website</h2>
                <p>Customer registration collects your name, email address, phone number, delivery address, password, and an uploaded valid ID for staff review. The database stores a password hash. Optional email verification and password recovery create verification and delivery records. Your profile may also contain an image and an updated delivery address.</p>
                <p>Orders record products, quantities, prices, discounts, fees, payment and delivery status, delivery address, notes, and status history. GCash orders record the reference number and uploaded payment proof; cash orders record payment status. Delivery records may include the recipient's name, issue notes, and proof of delivery. Contact forms and customer support conversations store the details and messages submitted, related order references, staff responses, and internal handling records. Sign-in and security records can include attempted email addresses, IP addresses, browser information, access times, and audit events.</p>
            </section>
            <section>
                <h2>Why the information is used</h2>
                <p>The website uses these records to review registrations, maintain accounts, process and deliver orders, check payment evidence, respond to concerns, send service emails and notifications, and investigate security or transaction issues. Registration email verification is optional for ordinary account use. Password recovery uses the registered email address.</p>
            </section>
            <section>
                <h2>People and services that may receive information</h2>
                <p>Administrators, managers, and staff can access records needed for their assigned functions. An assigned rider can access delivery information for an assigned order. The application sends transactional email details, such as a recipient's name, address, and service message, through Brevo SMTP and can record Brevo delivery events. The website's hosting provider processes information stored or transmitted by the website. Production pages also load a Cloudflare Web Analytics beacon that sends page usage and performance measurements to Cloudflare.</p>
                <p>For GCash, the website displays the shop's configured recipient details and stores the reference and proof you submit. The inspected code does not send customer order data to a GCash payment API. Google Maps is loaded only if you choose to load a map panel; that request can expose your IP address and browser information to Google. Opening Google Maps or Facebook links takes you to those services.</p>
            </section>
            <section>
                <h2>Security and keeping records</h2>
                <p>The application uses password hashing, role checks, session and form protections, and controlled access to uploaded IDs and payment and delivery evidence. These measures do not guarantee absolute security. The code does not set a fixed deletion schedule for order, support, or authentication history. An administrator's account deletion action may retain an inactive account when linked operational records exist.</p>
            </section>
            <section>
                <h2>Your data privacy rights</h2>
                <p>Under Republic Act No. 10173, you may seek information about processing, access to your personal data, correction of inaccurate data, objection where applicable, erasure or blocking where the legal conditions are met, data portability where applicable, damages where legally warranted, and redress or a complaint before the National Privacy Commission. To make a request, use the <a href="<?= e(url('contact.php')) ?>">Contact Us form</a>. Reasonable identity verification may be needed before account information is disclosed or changed. A data privacy request can be made separately from password recovery, even if you can no longer use your registered email address. See the <a href="<?= e(url('cookie-policy.php')) ?>">Cookie Policy</a> for browser storage.</p>
            </section>

            <footer class="legal-card__footer">
                <a class="btn btn-secondary" href="<?= e(url('index.php')) ?>">Return to Home</a>
                <a class="auth-link" href="<?= e(url('contact.php')) ?>">Contact Us</a>
            </footer>
        </article>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
