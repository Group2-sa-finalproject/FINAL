<?php
// =====================================================
// REVIEW SALES - ADMIN DASHBOARD MODULE
// Included inside admin/dashboard.php
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// ADMIN AUTHENTICATION
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('Review Sales: database connection unavailable.');
    http_response_code(500);
    exit('Unable to connect to the database.');
}

$conn->set_charset('utf8mb4');

// =====================================================
// HELPER FUNCTIONS (unique names avoid dashboard conflicts)
// =====================================================
function salesReportEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function salesReportRunQuery(mysqli $conn, string $sql, string $types, array $params): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Review Sales prepare failed: ' . $conn->error);
        exit('Unable to load the sales report.');
    }

    if ($types !== '') {
        $bindArgs = [$types];
        foreach ($params as $key => $value) {
            $bindArgs[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindArgs);
    }

    if (!$stmt->execute()) {
        error_log('Review Sales execute failed: ' . $stmt->error);
        $stmt->close();
        exit('Unable to load the sales report.');
    }

    return $stmt;
}

function salesReportBadge(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'paid') return 'green';
    if ($status === 'unpaid') return 'red';
    if (strpos($status, 'pending') !== false) return 'yellow';
    return 'gray';
}

function salesReportPeso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

// =====================================================
// DATE FILTERS
// =====================================================
$today = new DateTimeImmutable('today');
$timeframe = $_GET['timeframe'] ?? 'month';
if (!is_string($timeframe) || !in_array($timeframe, ['week', 'month', 'custom'], true)) {
    $timeframe = 'month';
}

$startDate = $today->modify('first day of this month')->format('Y-m-d');
$endDate = $today->format('Y-m-d');

if ($timeframe === 'week') {
    $startDate = $today->modify('monday this week')->format('Y-m-d');
    $endDate = $today->modify('sunday this week')->format('Y-m-d');
} elseif ($timeframe === 'custom') {
    $requestedStart = isset($_GET['start_date']) && is_string($_GET['start_date']) ? $_GET['start_date'] : '';
    $requestedEnd = isset($_GET['end_date']) && is_string($_GET['end_date']) ? $_GET['end_date'] : '';
    $startObj = DateTime::createFromFormat('!Y-m-d', $requestedStart);
    $endObj = DateTime::createFromFormat('!Y-m-d', $requestedEnd);

    if ($startObj && $startObj->format('Y-m-d') === $requestedStart &&
        $endObj && $endObj->format('Y-m-d') === $requestedEnd &&
        $requestedStart <= $requestedEnd) {
        $startDate = $requestedStart;
        $endDate = $requestedEnd;
    } else {
        $timeframe = 'month';
    }
}

// =====================================================
// SEARCH AND STATUS FILTERS
// =====================================================
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';

$statusOptions = [];
$statusResult = $conn->query("SELECT DISTINCT payment_status FROM subscriptions WHERE payment_status IS NOT NULL AND payment_status <> '' ORDER BY payment_status");
if (!$statusResult) {
    error_log('Review Sales status query failed: ' . $conn->error);
    exit('Unable to load payment statuses.');
}
while ($statusRow = $statusResult->fetch_assoc()) {
    $statusOptions[] = $statusRow['payment_status'];
}
$statusResult->free();

if ($statusFilter !== '' && !in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

// =====================================================
// BUILD QUERY CONDITIONS
// =====================================================
$where = ['DATE(s.created_at) BETWEEN ? AND ?'];
$params = [$startDate, $endDate];
$types = 'ss';

if ($search !== '') {
    $where[] = '(u.fullname LIKE ? OR u.users LIKE ? OR CAST(s.id AS CHAR) LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($statusFilter !== '') {
    $where[] = 's.payment_status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// =====================================================
// SUMMARY INFORMATION
// =====================================================
$summarySql = "
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(s.payment_status) = 'paid' THEN s.amount_paid ELSE 0 END), 0) AS revenue_collected,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(CASE WHEN LOWER(s.payment_status) = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
        COALESCE(SUM(CASE WHEN LOWER(s.payment_status) = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count,
        COALESCE(SUM(CASE WHEN LOWER(s.payment_status) LIKE '%pending%' THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(GREATEST(s.balance_due, 0)), 0) AS total_balance
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    $whereSql
";
$summaryStmt = salesReportRunQuery($conn, $summarySql, $types, $params);
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$revenueCollected = (float)($summary['revenue_collected'] ?? 0);
$transactionCount = (int)($summary['transaction_count'] ?? 0);
$paidCount = (int)($summary['paid_count'] ?? 0);
$unpaidCount = (int)($summary['unpaid_count'] ?? 0);
$pendingCount = (int)($summary['pending_count'] ?? 0);
$totalBalance = (float)($summary['total_balance'] ?? 0);


// =====================================================
// TRANSACTION RECORDS - PAID SUBSCRIPTIONS ONLY
// =====================================================
$listWhere = $where;
$listWhere[] = "LOWER(s.payment_status) = 'paid'";
$listWhereSql = 'WHERE ' . implode(' AND ', $listWhere);

$listSql = "
    SELECT
        s.id AS subscription_id, s.service_fee, s.months, s.original_amount,
        s.points_redeemed, s.discount_amount, s.total_amount, s.amount_paid,
        s.balance_due, s.subscription_status, s.payment_status, s.created_at,
        u.fullname, u.users AS username
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    $listWhereSql
    ORDER BY s.created_at DESC, s.id DESC
";

$listStmt = salesReportRunQuery($conn, $listSql, $types, $params);
$listResult = $listStmt->get_result();

$transactions = [];

while ($row = $listResult->fetch_assoc()) {
    $transactions[] = $row;
}

$listStmt->close();


// Preserve dashboard routing parameters when resetting filters.
$routeParams = $_GET;
unset($routeParams['timeframe'], $routeParams['start_date'], $routeParams['end_date'],
      $routeParams['search'], $routeParams['status'], $routeParams['export']);
$resetUrl = $_SERVER['PHP_SELF'];
if (!empty($routeParams)) {
    $resetUrl .= '?' . http_build_query($routeParams);
}

$reportLabel = $timeframe === 'week' ? 'Weekly Report' : ($timeframe === 'custom' ? 'Custom Report' : 'Monthly Report');
?>

<style>
/* REVIEW SALES - MATCHING MANAGE SUBSCRIPTIONS DESIGN */
.sales-report {
    --sales-green: #198754;
    --sales-dark: #146c43;
    --sales-forest: #183d2b;
    --sales-muted: #78877e;
    --sales-line: #e5ece7;
    width: 100%;
    min-width: 0;
    color: #263b30;
    font-family: Arial, Helvetica, sans-serif;
    line-height: 1.5;
}
.sales-report, .sales-report * { box-sizing: border-box; }
.sales-report h2, .sales-report h3, .sales-report h4, .sales-report p { margin: 0; }

/* Header */
.sales-report .sales-header {
    display: flex; align-items: center; gap: 16px;
    padding: 26px 28px; margin-bottom: 20px;
    color: #fff; border-radius: 15px;
    background: linear-gradient(135deg, #146c43, #198754);
    box-shadow: 0 4px 15px rgba(25,135,84,.13);
}
.sales-report .sales-header-icon {
    width: 52px; height: 52px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    border-radius: 14px; background: rgba(255,255,255,.16); font-size: 23px;
}
.sales-report .sales-title { color: #fff; font-size: 26px; line-height: 1.2; font-weight: 800; margin-bottom: 6px; }
.sales-report .sales-subtitle { color: rgba(255,255,255,.86); font-size: 14px; }
.sales-report .sales-date {
    margin-left: auto; flex-shrink: 0; padding: 9px 13px;
    border: 1px solid rgba(255,255,255,.25); border-radius: 8px;
    background: rgba(255,255,255,.13); color: #fff; font-size: 12px; font-weight: 700;
}

/* Summary cards */
.sales-report .sales-summary { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 16px; margin-bottom: 20px; }
.sales-report .sales-stat {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    min-width: 0; padding: 20px 22px; background: #fff;
    border: 1px solid #edf1ee; border-radius: 13px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04); transition: .2s ease;
}
.sales-report .sales-stat:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,.07); }
.sales-report .sales-stat-label { color: var(--sales-muted); font-size: 12px; font-weight: 600; margin-bottom: 7px; }
.sales-report .sales-stat-value { color: var(--sales-forest); font-size: 24px; line-height: 1.2; font-weight: 800; overflow-wrap: anywhere; }
.sales-report .sales-stat-icon {
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    width: 48px; height: 48px; border-radius: 12px; font-size: 20px;
}
.sales-report .green { background: #e4f4eb; color: #198754; }
.sales-report .blue { background: #e5efff; color: #2764b5; }
.sales-report .yellow { background: #fff4d6; color: #b78103; }
.sales-report .red { background: #fde7e7; color: #a43e3e; }

/* Cards and headings */
.sales-report .sales-card { background: #fff; border: 1px solid #edf1ee; border-radius: 14px; box-shadow: 0 3px 14px rgba(0,0,0,.045); overflow: hidden; margin-bottom: 20px; }
.sales-report .sales-card-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; padding: 20px 24px; border-bottom: 1px solid #edf1ee; }
.sales-report .sales-card-body { padding: 24px; }
.sales-report .sales-card-title { display: flex; align-items: center; gap: 9px; color: var(--sales-forest); font-size: 17px; font-weight: 800; margin-bottom: 4px; }
.sales-report .sales-card-title i { color: var(--sales-green); }
.sales-report .sales-card-caption { color: var(--sales-muted); font-size: 12px; }
.sales-report .sales-section-title { display: flex; align-items: center; gap: 8px; color: var(--sales-forest); font-size: 15px; font-weight: 800; }
.sales-report .sales-section-title i { color: var(--sales-green); }

/* Filter panel */
.sales-report .sales-filter { padding: 22px; margin-bottom: 24px; border: 1px solid #e1ece5; border-radius: 13px; background: #f8fbf9; }
.sales-report .sales-filter-head { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.sales-report .sales-filter-icon { width: 38px; height: 38px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 10px; background: #e2f2e9; color: var(--sales-green); }
.sales-report .sales-filter-heading { color: #185c39; font-size: 15px; font-weight: 800; margin-bottom: 2px; }
.sales-report .sales-filter-caption { color: var(--sales-muted); font-size: 12px; }
.sales-report .sales-filter-grid { display: grid; grid-template-columns: repeat(12,minmax(0,1fr)); gap: 16px; align-items: end; }
.sales-report .sales-field { min-width: 0; }
.sales-report .sales-field.span-3 { grid-column: span 3; }
.sales-report .sales-field.span-4 { grid-column: span 4; }
.sales-report .sales-field.span-2 { grid-column: span 2; }
.sales-report .sales-label { display: block; margin-bottom: 7px; color: #41594a; font-size: 12px; font-weight: 700; }
.sales-report .sales-input, .sales-report .sales-select {
    display: block; width: 100%; height: 42px; padding: 0 12px;
    border: 1px solid #dce7df; border-radius: 8px; background: #fff;
    color: #263b30; font-family: inherit; font-size: 13px; outline: none; transition: .15s ease;
}
.sales-report .sales-select { padding-right: 30px; cursor: pointer; }
.sales-report .sales-input:focus, .sales-report .sales-select:focus { border-color: var(--sales-green); box-shadow: 0 0 0 3px rgba(25,135,84,.12); }
.sales-report .sales-search { position: relative; }
.sales-report .sales-search i { position: absolute; top: 50%; left: 14px; transform: translateY(-50%); color: var(--sales-green); pointer-events: none; }
.sales-report .sales-search .sales-input { padding-left: 38px; }
.sales-report .sales-filter-actions { grid-column: 1 / -1; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding-top: 16px; border-top: 1px solid #e6eee8; }
.sales-report .sales-filter-actions .sales-push-right { display: flex; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.sales-report .sales-custom-date { display: none; }
.sales-report .sales-custom-date.open { display: block; }

/* Buttons */
.sales-report .sales-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    min-height: 40px; padding: 0 15px; border: 1px solid transparent;
    border-radius: 8px; font-family: inherit; font-size: 12px; font-weight: 700;
    line-height: 1; text-decoration: none; white-space: nowrap; cursor: pointer; transition: .18s ease;
}
.sales-report .sales-btn:hover { transform: translateY(-1px); }
.sales-report .sales-btn.primary { background: var(--sales-green); border-color: var(--sales-green); color: #fff; }
.sales-report .sales-btn.primary:hover { background: var(--sales-dark); border-color: var(--sales-dark); }
.sales-report .sales-btn.outline { background: #fff; border-color: var(--sales-green); color: var(--sales-green); }
.sales-report .sales-btn.outline:hover { background: var(--sales-green); color: #fff; }
.sales-report .sales-btn.neutral { background: #fff; border-color: #d5dfd8; color: #5a6c61; }
.sales-report .sales-btn.neutral:hover { background: #f1f5f2; color: #2f4538; }
.sales-report .sales-btn.dark { background: #263b32; border-color: #263b32; color: #fff; }
.sales-report .sales-btn.dark:hover { background: #182b21; }

/* Table */
.sales-report .sales-table-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.sales-report .sales-count { display: inline-flex; align-items: center; gap: 7px; padding: 8px 12px; border-radius: 8px; background: #e8f4ec; color: #17683e; font-size: 11px; font-weight: 700; }
.sales-report .sales-table-wrap { width: 100%; overflow-x: auto; border: 1px solid var(--sales-line); border-radius: 10px; }
.sales-report .sales-table { width: 100%; min-width: 1050px; border-collapse: collapse; font-size: 12px; }
.sales-report .sales-table th { padding: 14px; background: #f1f6f3; border-bottom: 1px solid #e0e9e3; color: #53665a; font-size: 11px; font-weight: 800; letter-spacing: .3px; text-align: left; text-transform: uppercase; white-space: nowrap; }
.sales-report .sales-table td { padding: 14px; border-bottom: 1px solid #eef2ef; color: #405246; vertical-align: middle; }
.sales-report .sales-table tbody tr:last-child td { border-bottom: 0; }
.sales-report .sales-table tbody tr:nth-child(even) { background: #fafcfb; }
.sales-report .sales-table tbody tr:hover { background: #f4faf6; }
.sales-report .resident-name { color: #234b34; font-size: 13px; font-weight: 700; }
.sales-report .username { display: block; margin-top: 3px; color: #87948a; font-size: 11px; }
.sales-report .transaction-id { color: #198754; font-weight: 800; white-space: nowrap; }
.sales-report .fee-breakdown { min-width: 175px; line-height: 1.8; }
.sales-report .fee-breakdown strong { color: #244b34; }
.sales-report .money { color: #234b34; font-weight: 700; white-space: nowrap; }
.sales-report .sales-badge { display: inline-block; padding: 6px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; white-space: nowrap; }
.sales-report .sales-badge.green { background: #dff3e5; color: #1d6e42; }
.sales-report .sales-badge.yellow { background: #fff1d6; color: #8a5d0a; }
.sales-report .sales-badge.red { background: #fde7e7; color: #a43e3e; }
.sales-report .sales-badge.gray { background: #eceff0; color: #5f6b66; }
.sales-report .sales-empty { padding: 46px 20px; color: #829087; text-align: center; }
.sales-report .sales-empty i { display: block; margin-bottom: 14px; color: #a8cbb5; font-size: 40px; }
.sales-report .sales-empty h4 { color: #385342; font-size: 15px; font-weight: 800; margin-bottom: 6px; }
.sales-report .sales-empty p { font-size: 12px; }
.sales-report .sales-report-foot { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 16px; color: #78877e; font-size: 12px; }
.sales-report .sales-balance { color: #a43e3e; font-weight: 800; }
.sales-report .sales-note { margin-top: 10px; color: #87948a; font-size: 11px; }

@media (max-width: 1100px) {
    .sales-report .sales-summary { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .sales-report .sales-field.span-4, .sales-report .sales-field.span-3 { grid-column: span 6; }
    .sales-report .sales-field.span-2 { grid-column: span 6; }
}
@media (max-width: 700px) {
    .sales-report .sales-header { padding: 20px; flex-wrap: wrap; }
    .sales-report .sales-header-icon { display: none; }
    .sales-report .sales-title { font-size: 21px; }
    .sales-report .sales-date { margin-left: 0; }
    .sales-report .sales-summary { grid-template-columns: 1fr; }
    .sales-report .sales-field.span-4, .sales-report .sales-field.span-3, .sales-report .sales-field.span-2 { grid-column: span 12; }
    .sales-report .sales-filter, .sales-report .sales-card-body { padding: 16px; }
    .sales-report .sales-filter-actions .sales-push-right { margin-left: 0; width: 100%; }
    .sales-report .sales-filter-actions .sales-btn, .sales-report .sales-filter-actions .sales-push-right .sales-btn { flex: 1; }
}
@media print {
    .sales-report .sales-filter, .sales-report .no-print { display: none !important; }
    .sales-report .sales-card, .sales-report .sales-stat { box-shadow: none !important; }
    .sales-report .sales-table { min-width: 0; font-size: 9px; }
    .sales-report .sales-table th { background: #f1f6f3 !important; color: #263b30 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<div class="sales-report">

    <!-- PAGE HEADER -->
    <div class="sales-header">
        <div class="sales-header-icon"><i class="fas fa-chart-line"></i></div>
        <div>
            <h2 class="sales-title">Review Sales</h2>
            <p class="sales-subtitle">Monitor subscription payments, collected revenue, and outstanding balances.</p>
        </div>
        <div class="sales-date">
            <i class="fas fa-calendar-days"></i>
            <?= salesReportEscape(date('M d, Y', strtotime($startDate))) ?> –
            <?= salesReportEscape(date('M d, Y', strtotime($endDate))) ?>
        </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="sales-summary">
        <div class="sales-stat">
            <div><div class="sales-stat-label">Total Revenue Collected</div><div class="sales-stat-value"><?= salesReportPeso($revenueCollected) ?></div></div>
            <div class="sales-stat-icon green"><i class="fas fa-coins"></i></div>
        </div>
        <div class="sales-stat">
            <div><div class="sales-stat-label">Subscription Transactions</div><div class="sales-stat-value"><?= number_format($transactionCount) ?></div></div>
            <div class="sales-stat-icon blue"><i class="fas fa-receipt"></i></div>
        </div>
        <div class="sales-stat">
            <div><div class="sales-stat-label">Paid Transactions</div><div class="sales-stat-value"><?= number_format($paidCount) ?></div></div>
            <div class="sales-stat-icon green"><i class="fas fa-circle-check"></i></div>
        </div>
        <div class="sales-stat">
            <div><div class="sales-stat-label">Unpaid / Pending</div><div class="sales-stat-value"><?= number_format($unpaidCount + $pendingCount) ?></div></div>
            <div class="sales-stat-icon yellow"><i class="fas fa-hourglass-half"></i></div>
        </div>
    </div>

    <!-- SALES REPORT CARD -->
    <div class="sales-card">
        <div class="sales-card-head">
            <div>
                <h3 class="sales-card-title"><i class="fas fa-filter"></i> Search and Filter</h3>
                <p class="sales-card-caption">Find sales records by date, resident details, or payment status.</p>
            </div>
            <span class="sales-count"><i class="fas fa-calendar-check"></i> <?= salesReportEscape($reportLabel) ?></span>
        </div>

        <div class="sales-card-body">
            <div class="sales-filter">
                <div class="sales-filter-head">
                    <div class="sales-filter-icon"><i class="fas fa-sliders"></i></div>
                    <div>
                        <h4 class="sales-filter-heading">Report Filters</h4>
                        <p class="sales-filter-caption">Choose a reporting period and narrow down the transaction list.</p>
                    </div>
                </div>

                <form method="GET" action="<?= salesReportEscape($_SERVER['PHP_SELF']) ?>">
                    <?php foreach ($routeParams as $key => $value): ?>
                        <?php if (is_string($value) || is_numeric($value)): ?>
                            <input type="hidden" name="<?= salesReportEscape($key) ?>" value="<?= salesReportEscape($value) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <div class="sales-filter-grid">
                        <div class="sales-field span-2">
                            <label class="sales-label" for="timeframe">Timeframe</label>
                            <select name="timeframe" id="timeframe" class="sales-select">
                                <option value="week" <?= $timeframe === 'week' ? 'selected' : '' ?>>This Week</option>
                                <option value="month" <?= $timeframe === 'month' ? 'selected' : '' ?>>This Month</option>
                                <option value="custom" <?= $timeframe === 'custom' ? 'selected' : '' ?>>Custom Date Range</option>
                            </select>
                        </div>

                        <div class="sales-field span-2 sales-custom-date <?= $timeframe === 'custom' ? 'open' : '' ?>" id="salesStartField">
                            <label class="sales-label" for="start_date">Start Date</label>
                            <input type="date" name="start_date" id="start_date" class="sales-input" value="<?= salesReportEscape($startDate) ?>">
                        </div>

                        <div class="sales-field span-2 sales-custom-date <?= $timeframe === 'custom' ? 'open' : '' ?>" id="salesEndField">
                            <label class="sales-label" for="end_date">End Date</label>
                            <input type="date" name="end_date" id="end_date" class="sales-input" value="<?= salesReportEscape($endDate) ?>">
                        </div>

                        <div class="sales-field span-3">
                            <label class="sales-label" for="salesSearch">Search Resident / ID</label>
                            <div class="sales-search">
                                <i class="fas fa-magnifying-glass"></i>
                                <input type="search" name="search" id="salesSearch" class="sales-input" placeholder="Name, username, or subscription ID" value="<?= salesReportEscape($search) ?>">
                            </div>
                        </div>

                        <div class="sales-field span-3">
                            <label class="sales-label" for="status">Payment Status</label>
                            <select name="status" id="status" class="sales-select">
                                <option value="">All Statuses</option>
                                <?php foreach ($statusOptions as $option): ?>
                                    <option value="<?= salesReportEscape($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= salesReportEscape($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <!-- TRANSACTION TABLE -->
            <div class="sales-table-top">
                <div>
                    <h3 class="sales-section-title"><i class="fas fa-receipt"></i> Subscription Transactions</h3>
                    <p class="sales-card-caption" style="margin-top:4px;">Review subscription amounts, payments, and balances.</p>
                </div>
                <span class="sales-count"><i class="fas fa-list"></i> <?= number_format(count($transactions)) ?> record(s) found</span>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="sales-empty">
                    <i class="fas fa-inbox"></i>
                    <h4>No Transactions Found</h4>
                    <p>Try changing your search terms or selected filters.</p>
                </div>
            <?php else: ?>
                <div class="sales-table-wrap">
                    <table class="sales-table">
                        <thead>
                            <tr>
                                <th>Subscription ID</th>
                                <th>Resident</th>
                                <th>Package / Fee Breakdown</th>
                                <th>Amount Paid</th>
                                <th>Balance Due</th>
                                <th>Payment Status</th>
                                <th>Date Created</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <?php $status = (string)($transaction['payment_status'] ?? ''); ?>
                            <tr>
                                <td class="transaction-id">#<?= (int)$transaction['subscription_id'] ?></td>
                                <td>
                                    <div class="resident-name"><?= salesReportEscape($transaction['fullname']) ?></div>
                                    <span class="username">@<?= salesReportEscape($transaction['username']) ?></span>
                                </td>
                                <td>
                                    <div class="fee-breakdown">
                                        <div>Service Fee: <strong><?= salesReportPeso($transaction['service_fee']) ?></strong></div>
                                        <div>Months: <?= (int)$transaction['months'] ?></div>
                                        <div>Original Amount: <?= salesReportPeso($transaction['original_amount']) ?></div>
                                        <div>Points Redeemed: <?= number_format((int)$transaction['points_redeemed']) ?></div>
                                        <div>Discount: <?= salesReportPeso($transaction['discount_amount']) ?></div>
                                        <div>Total: <strong><?= salesReportPeso($transaction['total_amount']) ?></strong></div>
                                    </div>
                                </td>
                                <td class="money"><?= salesReportPeso($transaction['amount_paid']) ?></td>
                                <td class="money"><?= salesReportPeso($transaction['balance_due']) ?></td>
                                <td><span class="sales-badge <?= salesReportBadge($status) ?>"><?= salesReportEscape($status !== '' ? $status : 'Not Set') ?></span></td>
                                <td>
                                    <span style="white-space:nowrap;"><?= salesReportEscape(date('M d, Y', strtotime($transaction['created_at']))) ?></span>
                                    <span class="username"><?= salesReportEscape(date('h:i A', strtotime($transaction['created_at']))) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="sales-report-foot">
                <div>Showing <?= number_format(count($transactions)) ?> subscription record(s).</div>
                <div>Outstanding Balance: <span class="sales-balance"><?= salesReportPeso($totalBalance) ?></span></div>
            </div>
            <p class="sales-note">Revenue includes only records marked Paid and uses <strong>subscriptions.amount_paid</strong>.</p>
        </div>
    </div>
</div>

<script>
(function () {
    // Show or hide the custom date inputs based on the selected timeframe.
    const timeframe = document.getElementById('timeframe');
    const startField = document.getElementById('salesStartField');
    const endField = document.getElementById('salesEndField');

    function toggleSalesDates() {
        const show = timeframe && timeframe.value === 'custom';
        if (startField) startField.classList.toggle('open', show);
        if (endField) endField.classList.toggle('open', show);
    }

    if (timeframe) timeframe.addEventListener('change', toggleSalesDates);
    toggleSalesDates();
})();
</script>
