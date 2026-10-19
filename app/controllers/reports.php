<?php

declare(strict_types=1);

// $reportsRole, $reportsBasePath, $reportsPageTitle, $reportsSubtitle,
// $reportsFilterPrefix, $reportsTypeLabel, and $reportsShowSummary are set by
// a role-guarded route wrapper, never by request parameters.
$user = current_user();
$reportTypes = report_type_options($reportsShowSummary);
$categories = fetch_categories();
$selectedType = (string) query_scalar('report_type', 'sales');
$selectedType = array_key_exists($selectedType, $reportTypes) ? $selectedType : 'sales';
$statusOptions = report_status_options($selectedType);
$filterErrors = date_filter_errors($_GET);
$filters = normalize_report_filters($_GET);
if (!array_key_exists($filters['status'], $statusOptions)) {
    $filters['status'] = '';
}
if (!in_array($selectedType, ['sales', 'products'], true)) {
    $filters['category_id'] = 0;
}
$report = build_report_data($selectedType, $filters);
if ($filterErrors !== []) {
    $report['rows'] = [];
}

if (request('export') === '1' && $filterErrors === []) {
    generate_report_log($report['title'], $reportsRole, (int) $user['id']);
    $filePrefix = $reportsRole === 'manager' ? 'manager-' : '';
    export_csv($filePrefix . strtolower(str_replace(' ', '-', $report['title'])) . '.csv', $report['headers'], $report['rows']);
}

$exportQuery = http_build_query(array_merge($filters, ['report_type' => $selectedType, 'export' => '1']));
$stats = $reportsShowSummary ? fetch_dashboard_stats($reportsRole, (int) $user['id']) : [];
$summaryUserCount = $reportsShowSummary ? count(fetch_users()) : 0;
$summaryProductCount = $reportsShowSummary ? count(fetch_products()) : 0;
$pageTitle = $reportsPageTitle;
