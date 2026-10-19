<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pageTitle = 'Cookie and Browser Storage Policy';
$bodyClass = 'guest-body';
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page legal-page">
    <div class="legal-page__inner">
        <article class="auth-card legal-card">
            <header class="legal-card__header">
                <span class="eyebrow">Privacy</span>
                <h1>Cookie Policy</h1>
                <p>Browser storage used by the Fred's Batchoy &amp; Coffee Shop website.</p>
            </header>
            <section>
                <h2>Session and sign-in cookies</h2>
                <p>A PHP session cookie links your browser to server-side sign-in, cart, checkout, and form-protection state. It is configured as a browser-session cookie. If you select <strong>Remember me</strong> at login, a separate authentication cookie can keep you signed in for up to 30 days. Remember me is optional. Both cookies are set as HttpOnly and SameSite=Lax; the Secure flag is used when the request is HTTPS.</p>
            </section>
            <section>
                <h2>Local and session storage</h2>
                <p>The light or dark theme preference is saved in localStorage. The sidebar scroll position on account pages is saved in sessionStorage for that browser tab. These are interface preferences, not advertising cookies. The inspected scripts do not store a notification display preference in your browser.</p>
            </section>
            <section>
                <h2>Analytics and external content</h2>
                <p>Production pages load a Cloudflare Web Analytics script added outside the application code. It sends page usage and performance measurements to Cloudflare. Cloudflare states that this beacon does not itself store or read cookies, localStorage, or sessionStorage. The website has no analytics preference control. The Google Maps frame remains off until you select <strong>Load Google Maps</strong>. You can keep it off or hide it again on that page. Hiding it cannot recall information already sent to Google. Opening a Google Maps or Facebook link takes you to that provider's site, where its own storage practices may apply.</p>
            </section>
            <section>
                <h2>Managing storage</h2>
                <p>You can clear cookies and storage in your browser settings or sign out to end the current login. Clearing the session cookie will also clear access to the cart and checkout state held in that session. This website has no separate cookie-preference or analytics-consent panel. See the <a href="<?= e(url('privacy-policy.php')) ?>">Privacy Policy</a> for personal-data handling.</p>
            </section>
            <footer class="legal-card__footer">
                <a class="btn btn-secondary" href="<?= e(url('index.php')) ?>">Return to Home</a>
                <a class="auth-link" href="<?= e(url('contact.php')) ?>">Contact Us</a>
            </footer>
        </article>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
