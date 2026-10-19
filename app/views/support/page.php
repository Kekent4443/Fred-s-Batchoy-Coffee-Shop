<?php

declare(strict_types=1);

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section">
            <?php render_page_header('Support Chat', $supportSubtitle, 'chat', 'Support', $supportFaqUrl === null ? null : static function () use ($supportFaqUrl): void { ?><a class="btn btn-secondary" href="<?= e(url($supportFaqUrl)) ?>">Manage FAQs</a><?php }); ?>
            <?php require APP_ROOT . '/app/includes/support_console.php'; ?>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
