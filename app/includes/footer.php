<footer class="site-policy-footer no-print">
    <nav aria-label="Site policies">
        <a href="<?= e(url('privacy-policy.php')) ?>">Privacy Policy</a>
        <a href="<?= e(url('terms-of-service.php')) ?>">Terms and Conditions</a>
        <a href="<?= e(url('cookie-policy.php')) ?>">Cookie Policy</a>
        <a href="<?= e(url('refund-policy.php')) ?>">Refund and Remedies</a>
        <a href="<?= e(url('contact.php')) ?>">Contact Us</a>
    </nav>
</footer>
<script src="<?= e(asset('js/system-modal.js')) ?>"></script>
<script src="<?= e(asset('js/notifications.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($useQzReceipt)): ?>
<?php require APP_ROOT . '/app/includes/qz-receipt-config.php'; ?>
<script src="<?= e(asset('js/vendor/qz-tray.js')) ?>"></script>
<script src="<?= e(asset('js/qz-receipt.js')) ?>"></script>
<?php endif; ?>
<?php if (!empty($isDashboardPage)): ?>
<script src="<?= e(asset('js/dashboard.js')) ?>"></script>
<?php endif; ?>
<?php foreach (($pageScripts ?? []) as $pageScript): ?>
<script src="<?= e(asset((string) $pageScript)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
