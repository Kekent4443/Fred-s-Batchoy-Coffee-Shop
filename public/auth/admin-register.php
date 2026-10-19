<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

http_response_code(404);

$pageTitle = 'Page Not Found';
$bodyClass = 'guest-body';
require_once APP_ROOT . '/app/includes/head.php';
?>
<main class="auth-page">
    <div class="auth-page__inner">
        <div class="auth-card">
            <h1>Page Not Found</h1>
            <p>The requested page is not available.</p>
            <a class="btn btn-primary" href="<?= e(url('index.php')) ?>">Back to Website</a>
        </div>
    </div>
</main>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
