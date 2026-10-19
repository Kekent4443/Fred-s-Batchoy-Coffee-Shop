<?php

declare(strict_types=1);

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/contact_messages_management.php'; ?>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
