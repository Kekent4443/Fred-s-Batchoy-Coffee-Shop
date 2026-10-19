<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pageTitle = 'Refund and Remedies Policy';
$bodyClass = 'guest-body';
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page legal-page">
    <div class="legal-page__inner">
        <article class="auth-card legal-card">
            <header class="legal-card__header">
                <span class="eyebrow">Orders</span>
                <h1>Refund and Remedies Policy</h1>
                <p>How to raise an order problem with Fred's Batchoy &amp; Coffee Shop.</p>
            </header>
            <section>
                <h2>Cancelling an order</h2>
                <p>The customer account offers cancellation only while an order is pending and has no preparation, rider assignment, or protected payment review. GCash proof submitted with an order normally prevents self-service cancellation. The site's expiry process can cancel a GCash order without recorded payment evidence after its configured window. If the Cancel action is unavailable, contact staff for review. Cancelling an order in the website does not automatically return a payment.</p>
            </section>
            <section>
                <h2>Problems with food or delivery</h2>
                <p>Tell us if an item is missing, incorrect, defective, unsafe, or different from its description, or if an order is unfulfilled or delivery fails. Preparation and perishability can affect a change-of-mind cancellation, but do not remove remedies available under Philippine consumer law. A replacement, refund, or another lawful remedy may be appropriate after the facts are reviewed. No automatic refund, GCash reversal, or cash refund function exists in this website.</p>
            </section>
            <section>
                <h2>Request a review</h2>
                <p>Use the <a href="<?= e(url('contact.php')) ?>">Contact Us form</a> or, if signed in, open a customer support conversation and select the relevant order when available. Describe the problem and include the order reference and any relevant evidence you have. Staff can review the order, payment, and delivery records and respond through the available support channels. The website records refund requests for staff review but cannot pay a refund; any required return of money must be arranged outside its transaction flow. Do not send a payment password, PIN, or one-time code. You may pursue remedies provided by law even if the website has no self-service refund button.</p>
            </section>
            <footer class="legal-card__footer">
                <a class="btn btn-secondary" href="<?= e(url('index.php')) ?>">Return to Home</a>
                <a class="auth-link" href="<?= e(url('terms-of-service.php')) ?>">Terms and Conditions</a>
            </footer>
        </article>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
