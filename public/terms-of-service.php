<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pageTitle = 'Terms and Conditions';
$bodyClass = 'guest-body';
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page legal-page">
    <div class="legal-page__inner">
        <article class="auth-card legal-card">
            <header class="legal-card__header">
                <span class="eyebrow">Ordering</span>
                <h1>Terms and Conditions</h1>
                <p>Account and ordering terms for Fred's Batchoy &amp; Coffee Shop.</p>
            </header>

            <section>
                <h2>Registration and account use</h2>
                <p>Customer registration requires contact and delivery details, a password, and a valid-ID upload for review by authorized staff. A new account remains pending until staff approves it. Email verification is offered separately. Provide accurate information and take reasonable care of your sign-in credentials. Use only an account you are authorized to access.</p>
            </section>
            <section>
                <h2>Account responsibility and recovery limitations</h2>
                <p>Keep your registered email address accurate, valid, and accessible. Customer password recovery requires a code sent to that address. An authorized administrator may initiate a reset for a customer with a verified registered email, but this sends a single-use link to that address; the administrator does not choose or directly change the customer's password.</p>
                <p>The system does not automatically recover forgotten registered email addresses or use SMS verification. Account email addresses and access are not disclosed or granted on an unverified recovery request. If you no longer know or can access the registered email and cannot complete email verification, access to that account may not be restored. These login limits do not restrict requests to access, correct, or otherwise exercise rights concerning personal data under Republic Act No. 10173. Such requests are handled separately through <a href="<?= e(url('contact.php')) ?>">Contact Us</a>, subject to reasonable identity verification and applicable law. Nothing here removes statutory consumer or privacy rights or the shop's legal responsibilities.</p>
            </section>
            <section>
                <h2>Orders and payment</h2>
                <p>Review your cart, delivery address, and instructions before submitting an order. The checkout shows products, discounts, fees, VAT information, and total; stock and prices are checked again when the order is placed. The website creates a pending order for delivery, then staff confirm and prepare it through the recorded workflow. The website does not offer a pickup checkout option.</p>
                <p>Cash payment is recorded as pending until collection on delivery. GCash is available only while the shop has an active recipient account configured. A GCash order requires a reference number and uploaded proof. Staff, managers, or administrators review that evidence and may verify or reject it; a rejected submission can be resubmitted in the customer account. Uploading proof does not itself verify payment. The website does not process a GCash transfer or refund through a payment gateway.</p>
            </section>
            <section>
                <h2>Delivery, cancellation, and concerns</h2>
                <p>Provide a complete delivery address and a reachable contact number. The customer account permits cancellation only for an eligible pending order before preparation, rider assignment, or protected payment review. GCash proof submitted with an order normally prevents self-service cancellation. Some GCash orders with no recorded payment evidence may be cancelled by the site's expiry process. If the account action is unavailable, contact staff for review; a payment return is not automatic. Riders record delivery progress and can report a failed delivery or issue for manager or administrator review. Delivery estimates are estimates.</p>
                <p>For missing, incorrect, defective, unsafe, or unfulfilled food orders, use <a href="<?= e(url('contact.php')) ?>">Contact Us</a> or the customer support conversation feature. Include the order reference if available. The <a href="<?= e(url('refund-policy.php')) ?>">Refund and Remedies Policy</a> explains how requests enter manual review. The shop remains responsible for applicable legal remedies. Do not impersonate others, submit false payment evidence, or interfere with the website.</p>
            </section>

            <footer class="legal-card__footer">
                <a class="btn btn-secondary" href="<?= e(url('index.php')) ?>">Return to Home</a>
                <a class="auth-link" href="<?= e(url('contact.php')) ?>">Contact Us</a>
            </footer>
        </article>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
