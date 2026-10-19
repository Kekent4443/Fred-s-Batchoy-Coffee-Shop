<?php

declare(strict_types=1);

require_once APP_ROOT . '/app/includes/head.php';
require_once APP_ROOT . '/app/includes/topbar.php';
?>
<div class="app-shell">
    <?php require APP_ROOT . '/app/includes/sidebar.php'; ?>
    <main class="app-main">
        <?php require APP_ROOT . '/app/includes/alerts.php'; ?>
        <section class="section report-print-area">
            <?php render_page_header($reportsPageTitle, $reportsSubtitle, 'reports', 'Reporting', static function () use ($exportQuery, $filterErrors, $reportsBasePath): void { ?><div class="section__actions no-print"><?php if ($filterErrors === []): ?><a class="btn btn-secondary" href="<?= e(url($reportsBasePath . '?' . $exportQuery)) ?>">Export CSV</a><?php endif; ?><button class="btn btn-primary" type="button" data-print-report>Print Report</button></div><?php }); ?>

            <div class="page-panel no-print">
                <form method="get" class="report-filter-form" novalidate>
                    <div class="report-filter-grid">
                        <div class="report-filter-field">
                            <label for="<?= e($reportsFilterPrefix) ?>-type"><?= e($reportsTypeLabel) ?></label>
                            <select id="<?= e($reportsFilterPrefix) ?>-type" name="report_type">
                            <?php foreach ($reportTypes as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <?= date_input_field('date_from', query_scalar('date_from', $filters['date_from']), 'Start Date', $filterErrors, $reportsFilterPrefix) ?>
                        <?= date_input_field('date_to', query_scalar('date_to', $filters['date_to']), 'End Date', $filterErrors, $reportsFilterPrefix) ?>
                        <?php if ($statusOptions !== []): ?>
                        <div class="report-filter-field">
                            <label for="<?= e($reportsFilterPrefix) ?>-status"><?= in_array($selectedType, ['sales', 'orders'], true) ? 'Order Status' : ($selectedType === 'transactions' ? 'Transaction Status' : ($selectedType === 'deliveries' ? 'Delivery Status' : 'Product Status')) ?></label>
                            <select id="<?= e($reportsFilterPrefix) ?>-status" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if (in_array($selectedType, ['sales', 'products'], true)): ?>
                        <div class="report-filter-field">
                            <label for="<?= e($reportsFilterPrefix) ?>-category">Category</label>
                            <select id="<?= e($reportsFilterPrefix) ?>-category" name="category_id">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e((string) $category['id']) ?>" <?= $filters['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="report-filter-actions">
                        <button class="btn btn-primary" type="submit">Generate Report</button>
                        <a class="btn btn-ghost" href="<?= e(url($reportsBasePath)) ?>">Reset</a>
                    </div>
                </form>
            </div>

            <?php if ($reportsShowSummary): ?>
            <div class="metric-grid page-panel--spaced">
                <article class="metric-box"><div class="metric-box__icon green">PHP</div><strong><?= e(currency($stats['sales_total'])) ?></strong><span>Total Revenue</span></article>
                <article class="metric-box"><div class="metric-box__icon blue">O</div><strong><?= e((string) $stats['orders_total']) ?></strong><span>Total Orders</span></article>
                <article class="metric-box"><div class="metric-box__icon purple">U</div><strong><?= e((string) $summaryUserCount) ?></strong><span>Total Users</span></article>
                <article class="metric-box"><div class="metric-box__icon orange">P</div><strong><?= e((string) $summaryProductCount) ?></strong><span>Total Products</span></article>
            </div>
            <?php endif; ?>

            <div class="page-panel page-panel--spaced">
                <div class="card-head"><h2><?= e($report['title']) ?></h2></div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php foreach ($report['headers'] as $header): ?>
                                    <th><?= e($header) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['rows'] as $row): ?>
                                <tr>
                                    <?php foreach ($row as $index => $cell): ?>
                                        <td data-label="<?= e((string) ($report['headers'][$index] ?? 'Value')) ?>"><?= e((string) $cell) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($report['rows'] === []): ?>
                                <tr><td colspan="<?= e((string) count($report['headers'])) ?>">No report records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>
<?php require_once APP_ROOT . '/app/includes/footer.php'; ?>
