
<?php
// =====================================================
// MANAGE SUBSCRIPTIONS AND PAYMENTS
// ADMIN DASHBOARD MODULE
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

// ADMIN ACCESS
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    exit('Database connection error. Please check config/database.php.');
}

$conn->set_charset('utf8mb4');

// =====================================================
// HELPER FUNCTIONS
// =====================================================

function subEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function subPeso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

// =====================================================
// DASHBOARD ROUTING PARAMETERS
// =====================================================

$moduleAction = $_SERVER['PHP_SELF'];
$routeParams = [];

foreach ($_GET as $key => $value) {
    if (
        !in_array($key, [
            'search',
            'subscription_status',
            'payment_status',
            'date_filter',
            'start_date',
            'end_date'
        ], true) &&
        (is_string($value) || is_numeric($value))
    ) {
        $routeParams[$key] = $value;
    }
}

$resetUrl = $moduleAction;

if (!empty($routeParams)) {
    $resetUrl .= '?' . http_build_query($routeParams);
}

// =====================================================
// CSRF TOKEN
// =====================================================

if (empty($_SESSION['subscription_admin_csrf'])) {
    $_SESSION['subscription_admin_csrf'] = bin2hex(random_bytes(32));
}

$success_message = '';
$error_message = '';

// =====================================================
// PROCESS APPROVAL AND REJECTION
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_token = $_POST['csrf_token'] ?? '';
    $subscription_id = filter_input(INPUT_POST, 'subscription_id', FILTER_VALIDATE_INT);

    if (isset($_POST['confirm'])) {
        $action = 'confirm';
    } elseif (isset($_POST['reject'])) {
        $action = 'reject';
    } else {
        $action = '';
    }

    if (
        !is_string($posted_token) ||
        !hash_equals($_SESSION['subscription_admin_csrf'], $posted_token)
    ) {
        $error_message = 'Your session token is invalid. Please refresh the page.';

    } elseif (!$subscription_id || $subscription_id < 1) {
        $error_message = 'Invalid subscription record.';

    } elseif (!in_array($action, ['confirm', 'reject'], true)) {
        $error_message = 'Invalid administrator action.';

    } else {

        try {

            $conn->begin_transaction();

            // LOCK THE SUBSCRIPTION RECORD
            $lock_stmt = $conn->prepare("
                SELECT
                    id,
                    user_id,
                    total_amount,
                    subscription_status
                FROM subscriptions
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$lock_stmt) {
                throw new Exception('Unable to prepare subscription lookup.');
            }

            $lock_stmt->bind_param('i', $subscription_id);

            if (!$lock_stmt->execute()) {
                throw new Exception('Unable to retrieve subscription.');
            }

            $subscription = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();

            if (!$subscription) {
                throw new Exception('Subscription record was not found.');
            }

            if ($subscription['subscription_status'] !== 'Pending Approval') {
                throw new Exception(
                    'This subscription has already been processed. Please refresh the page.'
                );
            }

            $admin_id = (int) $_SESSION['user_id'];

            // =============================================
            // APPROVE: ACTIVE + PAID
            // =============================================

            if ($action === 'confirm') {

                $update_stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET
                        subscription_status = 'Active',
                        payment_status = 'Paid',
                        amount_paid = total_amount,
                        balance_due = 0.00
                    WHERE id = ?
                    AND subscription_status = 'Pending Approval'
                ");

                if (!$update_stmt) {
                    throw new Exception('Failed to prepare subscription approval.');
                }

                $update_stmt->bind_param('i', $subscription_id);

                if (!$update_stmt->execute()) {
                    throw new Exception('Failed to approve subscription.');
                }

                if ($update_stmt->affected_rows !== 1) {
                    $update_stmt->close();
                    throw new Exception('Subscription could not be approved. Please refresh the page.');
                }

                $update_stmt->close();

                // RECORD THE AUTOMATICALLY CONFIRMED PAYMENT
                $resident_id = (int) $subscription['user_id'];
                $payment_amount = (float) $subscription['total_amount'];

                $payment_stmt = $conn->prepare("
                    INSERT INTO resident_payments
                        (user_id, amount, status, paid_at)
                    VALUES (?, ?, 'Paid', NOW())
                ");

                if (!$payment_stmt) {
                    throw new Exception('Failed to prepare resident payment record.');
                }

                $payment_stmt->bind_param('id', $resident_id, $payment_amount);

                if (!$payment_stmt->execute()) {
                    throw new Exception('Failed to save resident payment record.');
                }

                $payment_stmt->close();

                // SAVE APPROVAL HISTORY
                $history_stmt = $conn->prepare("
                    INSERT INTO subscription_approval_history
                        (subscription_id, admin_id, action_status)
                    VALUES (?, ?, 'Approved')
                ");

                if (!$history_stmt) {
                    throw new Exception('Failed to prepare approval history.');
                }

                $history_stmt->bind_param('ii', $subscription_id, $admin_id);

                if (!$history_stmt->execute()) {
                    throw new Exception('Failed to save approval history.');
                }

                $history_stmt->close();

                $success_message =
                    'Subscription approved successfully. Its payment is automatically marked as Paid.';

            } else {

                // =============================================
                // REJECT: REMAIN UNPAID
                // =============================================

                $update_stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET
                        subscription_status = 'Rejected',
                        payment_status = 'Unpaid',
                        amount_paid = 0.00,
                        balance_due = total_amount
                    WHERE id = ?
                    AND subscription_status = 'Pending Approval'
                ");

                if (!$update_stmt) {
                    throw new Exception('Failed to prepare subscription rejection.');
                }

                $update_stmt->bind_param('i', $subscription_id);

                if (!$update_stmt->execute()) {
                    throw new Exception('Failed to reject subscription.');
                }

                if ($update_stmt->affected_rows !== 1) {
                    $update_stmt->close();
                    throw new Exception('Subscription could not be rejected. Please refresh the page.');
                }

                $update_stmt->close();

                // SAVE REJECTION HISTORY
                $history_stmt = $conn->prepare("
                    INSERT INTO subscription_approval_history
                        (subscription_id, admin_id, action_status)
                    VALUES (?, ?, 'Rejected')
                ");

                if (!$history_stmt) {
                    throw new Exception('Failed to prepare rejection history.');
                }

                $history_stmt->bind_param('ii', $subscription_id, $admin_id);

                if (!$history_stmt->execute()) {
                    throw new Exception('Failed to save rejection history.');
                }

                $history_stmt->close();

                $success_message =
                    'Subscription rejected successfully. It will no longer appear in pending records.';
            }

            // SAVE ALL CHANGES TOGETHER
            $conn->commit();

            // GENERATE A NEW CSRF TOKEN
            $_SESSION['subscription_admin_csrf'] = bin2hex(random_bytes(32));

        } catch (Throwable $exception) {

            $conn->rollback();

            error_log('Manage Subscriptions: ' . $exception->getMessage());

            $error_message = $exception->getMessage();
        }
    }
}


// =====================================================
// RETRIEVE PENDING APPROVAL SUBSCRIPTIONS ONLY
// =====================================================

$list_sql = "
    SELECT
        s.id,
        s.user_id,
        u.fullname,
        u.users AS username,
        s.service_fee,
        s.months,
        s.original_amount,
        s.points_redeemed,
        s.discount_amount,
        s.total_amount,
        s.amount_paid,
        s.balance_due,
        s.subscription_status,
        s.payment_status,
        s.created_at
    FROM subscriptions AS s
    INNER JOIN users AS u ON u.id = s.user_id
    WHERE s.subscription_status = 'Pending Approval'
    ORDER BY s.created_at DESC, s.id DESC
";

$result = $conn->query($list_sql);

if (!$result) {
    error_log('Subscription query failed: ' . $conn->error);
    exit('Unable to load subscription records.');
}

$all_rows = [];
$total_records = 0;
$pending_confirmations = 0;
$active_subscriptions = 0;

while ($row = $result->fetch_assoc()) {

    $all_rows[] = $row;
    $total_records++;

    if ($row['payment_status'] === 'Pending Confirmation') {
        $pending_confirmations++;
    }

    if (
        $row['subscription_status'] === 'Active' &&
        $row['payment_status'] === 'Paid' &&
        (float)$row['balance_due'] <= 0
    ) {
        $active_subscriptions++;
    }
}

$result->free();

// =====================================================
// SEARCH AND FILTERS
// =====================================================

$search = isset($_GET['search']) && is_string($_GET['search'])
    ? trim($_GET['search']) : '';

$subscription_filter = isset($_GET['subscription_status']) &&
    is_string($_GET['subscription_status'])
    ? $_GET['subscription_status'] : 'all';

$payment_filter = isset($_GET['payment_status']) &&
    is_string($_GET['payment_status'])
    ? $_GET['payment_status'] : 'all';

$date_filter = isset($_GET['date_filter']) &&
    is_string($_GET['date_filter'])
    ? $_GET['date_filter'] : 'all';

$start_date = isset($_GET['start_date']) && is_string($_GET['start_date'])
    ? $_GET['start_date'] : '';

$end_date = isset($_GET['end_date']) && is_string($_GET['end_date'])
    ? $_GET['end_date'] : '';

$allowed_subscription = [
    'all',
    'Pending Approval',
    'Active'
];

$allowed_payment = [
    'all',
    'Pending Confirmation',
    'Paid',
    'Unpaid'
];

$allowed_dates = [
    'all',
    'today',
    'week',
    'month',
    'custom'
];

if (!in_array($subscription_filter, $allowed_subscription, true)) {
    $subscription_filter = 'all';
}

if (!in_array($payment_filter, $allowed_payment, true)) {
    $payment_filter = 'all';
}

if (!in_array($date_filter, $allowed_dates, true)) {
    $date_filter = 'all';
}

function subValidDate(string $date): bool
{
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);

    return $dateObj && $dateObj->format('Y-m-d') === $date;
}

if (!subValidDate($start_date)) {
    $start_date = '';
}

if (!subValidDate($end_date)) {
    $end_date = '';
}

// =====================================================
// APPLY FILTERS
// =====================================================

$rows = array_values(array_filter(
    $all_rows,
    function ($row) use (
        $search,
        $subscription_filter,
        $payment_filter,
        $date_filter,
        $start_date,
        $end_date
    ) {

        if ($search !== '') {

            $search_match =
                stripos((string)$row['fullname'], $search) !== false ||
                stripos((string)$row['username'], $search) !== false ||
                stripos((string)$row['user_id'], $search) !== false;

            if (!$search_match) {
                return false;
            }
        }

        if (
            $subscription_filter !== 'all' &&
            $row['subscription_status'] !== $subscription_filter
        ) {
            return false;
        }

        if (
            $payment_filter !== 'all' &&
            $row['payment_status'] !== $payment_filter
        ) {
            return false;
        }

        $created_date = date('Y-m-d', strtotime($row['created_at']));

        if ($date_filter === 'today') {

            if ($created_date !== date('Y-m-d')) {
                return false;
            }

        } elseif ($date_filter === 'week') {

            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end = date('Y-m-d', strtotime('sunday this week'));

            if ($created_date < $week_start || $created_date > $week_end) {
                return false;
            }

        } elseif ($date_filter === 'month') {

            if (date('Y-m', strtotime($created_date)) !== date('Y-m')) {
                return false;
            }

        } elseif ($date_filter === 'custom') {

            if ($start_date !== '' && $created_date < $start_date) {
                return false;
            }

            if ($end_date !== '' && $created_date > $end_date) {
                return false;
            }
        }

        return true;
    }
));

$filtered_records = count($rows);

// =====================================================
// APPROVAL HISTORY
// =====================================================

$history_sql = "
    SELECT
        h.id,
        h.subscription_id,
        h.admin_id,
        h.approved_at,
        h.action_status,
        admin.fullname AS admin_name
    FROM subscription_approval_history AS h
    LEFT JOIN users AS admin ON admin.id = h.admin_id
    ORDER BY h.approved_at DESC
";

$history_result = $conn->query($history_sql);

if (!$history_result) {
    error_log('Approval history query failed: ' . $conn->error);
    exit('Unable to load approval history. Check that action_status exists in subscription_approval_history.');
}

$history_count = $history_result->num_rows;

?>

<style>
.subs {
    --green: #198754;
    --dark: #146c43;
    --forest: #183d2b;
    --muted: #78877e;
    --line: #e5ece7;
    width: 100%;
    min-width: 0;
    color: #263b30;
    font-family: Arial, Helvetica, sans-serif;
    line-height: 1.5;
}

.subs, .subs * { box-sizing: border-box; }

.subs h2, .subs h3, .subs h4, .subs p { margin: 0; }

.subs-header {
    display: flex;
    align-items: center;
    gap: 16px;
    background: linear-gradient(135deg, #146c43, #198754);
    color: #fff;
    border-radius: 15px;
    padding: 26px 28px;
    margin-bottom: 20px;
}

.subs-header-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: rgba(255,255,255,.16);
    font-size: 22px;
}

.subs-title {
    font-size: 26px;
    font-weight: 800;
    color: #fff;
    margin-bottom: 6px !important;
}

.subs-subtitle {
    color: rgba(255,255,255,.86);
    font-size: 14px;
}

.subs-alert {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 14px 16px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 13px;
}

.subs-alert.success {
    background: #e9f7ef;
    border: 1px solid #ccebd8;
    color: #1f6b41;
}

.subs-alert.danger {
    background: #fff0f0;
    border: 1px solid #f5d0d3;
    color: #a82d3a;
}

.subs-alert.warning {
    background: #fff8e4;
    border: 1px solid #f5e4b3;
    color: #7a5a0a;
}

.subs-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.subs-stat {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    background: #fff;
    border: 1px solid #edf1ee;
    border-radius: 13px;
    padding: 20px 22px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.subs-stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 7px;
}

.subs-stat-value {
    color: var(--forest);
    font-size: 28px;
    font-weight: 800;
    line-height: 1;
}

.subs-stat-value.warn { color: #b78103; }
.subs-stat-value.good { color: #198754; }

.subs-stat-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 12px;
    font-size: 20px;
}

.subs-stat-icon.green { background: #e4f4eb; color: #198754; }
.subs-stat-icon.yellow { background: #fff4d6; color: #b78103; }
.subs-stat-icon.blue { background: #e5efff; color: #2764b5; }

.subs-card {
    background: #fff;
    border: 1px solid #edf1ee;
    border-radius: 14px;
    box-shadow: 0 3px 14px rgba(0,0,0,.045);
    overflow: hidden;
}

.subs-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid #edf1ee;
}

.subs-card-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: var(--forest);
    font-size: 17px;
    font-weight: 800;
    margin-bottom: 4px !important;
}

.subs-card-title i { color: var(--green); }

.subs-card-caption {
    color: var(--muted);
    font-size: 12px;
}

.subs-card-body { padding: 24px; }

.subs-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 40px;
    padding: 0 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
    transition: .18s ease;
}

.subs-btn.sm {
    min-height: 34px;
    padding: 0 12px;
    font-size: 11px;
}

.subs-btn.primary {
    background: var(--green);
    border-color: var(--green);
    color: #fff;
}

.subs-btn.primary:hover {
    background: var(--dark);
    color: #fff;
}

.subs-btn.outline {
    background: #fff;
    border-color: var(--green);
    color: var(--green);
}

.subs-btn.outline:hover {
    background: var(--green);
    color: #fff;
}

.subs-btn.neutral {
    background: #fff;
    border-color: #d5dfd8;
    color: #5a6c61;
}

.subs-btn.danger-outline {
    background: #fff;
    border-color: #dc3545;
    color: #dc3545;
}

.subs-btn.danger-outline:hover {
    background: #dc3545;
    color: #fff;
}

.subs-pill {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 20px;
    background: #e4f4eb;
    color: #146c43;
    font-size: 11px;
    font-weight: 700;
}

.subs-filter {
    background: #f8fbf9;
    border: 1px solid #e1ece5;
    border-radius: 13px;
    padding: 22px;
    margin-bottom: 24px;
}

.subs-filter-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.subs-filter-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #e2f2e9;
    color: var(--green);
}

.subs-filter-title {
    color: #185c39;
    font-size: 15px;
    font-weight: 800;
}

.subs-filter-text {
    color: var(--muted);
    font-size: 12px;
}

.subs-filter-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 16px;
    align-items: end;
}

.subs-field { min-width: 0; }
.subs-field.s4 { grid-column: span 4; }
.subs-field.s2 { grid-column: span 2; }
.subs-field.actions { grid-column: span 2; }

.subs-label {
    display: block;
    margin-bottom: 7px;
    color: #41594a;
    font-size: 12px;
    font-weight: 700;
}

.subs-input, .subs-select {
    display: block;
    width: 100%;
    height: 42px;
    padding: 0 12px;
    border: 1px solid #dce7df;
    border-radius: 8px;
    background: #fff;
    color: #263b30;
    font-family: inherit;
    font-size: 13px;
    outline: none;
}

.subs-input:focus, .subs-select:focus {
    border-color: var(--green);
    box-shadow: 0 0 0 3px rgba(25,135,84,.12);
}

.subs-search { position: relative; }
.subs-search i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--green);
}
.subs-search .subs-input { padding-left: 38px; }

.subs-filter-actions { display: flex; gap: 8px; }
.subs-filter-actions .subs-btn.primary { flex: 1; }

.subs-range {
    display: none;
    margin-top: 18px;
    padding: 18px;
    border: 1px dashed #b9d8c4;
    border-radius: 10px;
    background: #fff;
}
.subs-range.open { display: block; }

.subs-range-title {
    color: var(--green);
    font-size: 13px;
    font-weight: 800;
    margin-bottom: 14px;
}

.subs-range-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    align-items: end;
}

.subs-table-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}

.subs-table-title {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--forest);
    font-size: 14px;
    font-weight: 800;
}

.subs-table-title i { color: var(--green); }

.subs-count {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border-radius: 8px;
    background: #e8f4ec;
    color: #17683e;
    font-size: 11px;
    font-weight: 700;
}

.subs-table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--line);
    border-radius: 10px;
}

.subs-table {
    width: 100%;
    min-width: 1080px;
    border-collapse: collapse;
    font-size: 12px;
}

.subs-table th {
    padding: 14px;
    background: #f1f6f3;
    border-bottom: 1px solid #e0e9e3;
    color: #53665a;
    font-size: 11px;
    font-weight: 800;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}

.subs-table td {
    padding: 14px;
    border-bottom: 1px solid #eef2ef;
    color: #405246;
    vertical-align: middle;
}

.subs-table tbody tr:last-child td { border-bottom: 0; }
.subs-table tbody tr:nth-child(even) { background: #fafcfb; }
.subs-table tbody tr:hover { background: #f4faf6; }

.subs-name {
    color: #234b34;
    font-size: 13px;
    font-weight: 700;
}

.subs-sub {
    display: block;
    margin-top: 3px;
    color: #87948a;
    font-size: 11px;
}

.subs-strong { color: #234b34; font-weight: 700; }
.subs-nowrap { white-space: nowrap; }

.subs-badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.subs-badge.green { background: #dff3e5; color: #1d6e42; }
.subs-badge.yellow { background: #fff1d6; color: #8a5d0a; }
.subs-badge.red { background: #fde7e7; color: #a43e3e; }
.subs-badge.gray { background: #eceff0; color: #5f6b66; }

.subs-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

.subs-actions form { margin: 0; }

.subs-done {
    color: #1d6e42;
    font-size: 12px;
    font-weight: 700;
}

.subs-none {
    color: #87948a;
    font-size: 11px;
}

.subs-empty {
    text-align: center;
    padding: 46px 20px;
    color: #829087;
}

.subs-empty > i {
    display: block;
    margin-bottom: 14px;
    color: #a8cbb5;
    font-size: 40px;
}

.subs-empty h4 {
    color: #385342;
    font-size: 15px;
    font-weight: 800;
    margin-bottom: 6px;
}

.subs-empty p {
    font-size: 12px;
    margin-bottom: 16px;
}

/* HISTORY MODAL */

.subs-modal {
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(15,35,25,.55);
}

.subs-modal.open { display: flex; }

.subs-modal-dialog {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 960px;
    max-height: calc(100vh - 40px);
    border-radius: 15px;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0,0,0,.3);
}

.subs-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #146c43, #198754);
    color: #fff;
}

.subs-modal-title {
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 4px !important;
}

.subs-modal-caption {
    color: rgba(255,255,255,.75);
    font-size: 12px;
}

.subs-modal-close {
    width: 34px;
    height: 34px;
    border: 0;
    border-radius: 8px;
    background: rgba(255,255,255,.16);
    color: #fff;
    cursor: pointer;
}

.subs-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    background: #f8faf9;
}

.subs-modal-foot {
    display: flex;
    justify-content: flex-end;
    padding: 14px 24px;
    border-top: 1px solid var(--line);
    background: #fff;
}

.subs-history-intro {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
    padding: 16px;
    border-radius: 10px;
    background: #e8f4ec;
    color: #245b3d;
    font-size: 13px;
}

.subs-history-intro > i {
    color: var(--green);
    font-size: 26px;
}

.subs-history-box {
    border: 1px solid var(--line);
    border-radius: 10px;
    background: #fff;
    overflow: hidden;
}

.subs-history-box .subs-table-wrap {
    border: 0;
    border-radius: 0;
}

.subs-history-box .subs-table { min-width: 700px; }

.subs-history-box .subs-table th {
    background: #e8f4ec;
    color: #205c3a;
}

.subs-history-id {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    background: #edf5ef;
    color: #17683e;
    font-size: 12px;
    font-weight: 700;
}

.subs-admin {
    display: flex;
    align-items: center;
    gap: 10px;
}

.subs-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #e2f2e9;
    color: var(--green);
}

.subs-date-icon {
    margin-right: 6px;
    color: var(--green);
}

/* SUBSCRIPTION ACTION BUTTONS - MATCH COLLECTION ROUTES PAGE */
.subscription-action-btn {
    border: 0;
    border-radius: 8px;
    padding: 10px 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
    transition: .18s;
    white-space: nowrap;
}

.subscription-action-btn:hover {
    transform: translateY(-1px);
    filter: brightness(.96);
}

.subscription-btn-approve {
    background: #198754;
    color: #fff;
}

.subscription-btn-approve:hover {
    background: #146c43;
    color: #fff;
}

.subscription-btn-reject {
    background: #dc3545;
    color: #fff;
}

.subscription-btn-reject:hover {
    background: #bb2d3b;
    color: #fff;
}

.subscription-action-group {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

@media (max-width: 1100px) {
    .subs-field.s4 { grid-column: span 12; }
    .subs-field.s2 { grid-column: span 4; }
    .subs-field.actions { grid-column: span 12; }
}

@media (max-width: 800px) {
    .subs-summary { grid-template-columns: 1fr; }
    .subs-range-grid { grid-template-columns: 1fr; }
    .subs-card-header, .subs-card-body { padding: 16px; }
    .subs-filter { padding: 16px; }
}

@media (max-width: 640px) {
    .subs-header { padding: 20px; }
    .subs-header-icon { display: none; }
    .subs-title { font-size: 21px; }
    .subs-field.s2 { grid-column: span 12; }
    .subs-modal { padding: 10px; }
    .subs-modal-body { padding: 16px; }
}
</style>

<div class="subs">

    <!-- PAGE HEADER -->
    <div class="subs-header">

        <div class="subs-header-icon">
            <i class="fas fa-wallet"></i>
        </div>

        <div>
            <h2 class="subs-title">Manage Subscriptions and Payments</h2>
            <p class="subs-subtitle">
                Review resident subscriptions, verify payment notices, and manage payment records.
            </p>
        </div>

    </div>

    <!-- SUCCESS MESSAGE -->
    <?php if ($success_message !== ''): ?>
        <div class="subs-alert success" role="status">
            <i class="fas fa-circle-check"></i>
            <div><?= subEscape($success_message) ?></div>
        </div>
    <?php endif; ?>

    <!-- ERROR MESSAGE -->
    <?php if ($error_message !== ''): ?>
        <div class="subs-alert danger" role="alert">
            <i class="fas fa-triangle-exclamation"></i>
            <div><?= subEscape($error_message) ?></div>
        </div>
    <?php endif; ?>

    <div class="subs-alert warning">
        <i class="fas fa-circle-info"></i>
        <div>
            <strong>Payment Approval Notice:</strong>
            The administrator's approval of a resident's subscription
            automatically confirms the simulated cash payment and marks
            the subscription as Paid. No separate payment confirmation is required.
        </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="subs-summary">

        <div class="subs-stat">
            <div>
                <div class="subs-stat-label">Pending / Unpaid Records</div>
                <div class="subs-stat-value"><?= number_format($total_records) ?></div>
            </div>
            <div class="subs-stat-icon green">
                <i class="fas fa-users"></i>
            </div>
        </div>

        <div class="subs-stat">
            <div>
                <div class="subs-stat-label">Awaiting Payment Confirmation</div>
                <div class="subs-stat-value warn"><?= number_format($pending_confirmations) ?></div>
            </div>
            <div class="subs-stat-icon yellow">
                <i class="fas fa-hourglass-half"></i>
            </div>
        </div>

        <div class="subs-stat">
            <div>
                <div class="subs-stat-label">Active and Paid Subscriptions</div>
                <div class="subs-stat-value good"><?= number_format($active_subscriptions) ?></div>
            </div>
            <div class="subs-stat-icon blue">
                <i class="fas fa-circle-check"></i>
            </div>
        </div>

    </div>

    <!-- SUBSCRIPTION RECORDS -->
    <div class="subs-card">

        <div class="subs-card-header">

            <div>
                <h3 class="subs-card-title">
                    <i class="fas fa-list"></i>
                    Subscription Records
                </h3>

                <p class="subs-card-caption">
                    Review and manage subscriptions awaiting administrator approval.
                </p>
            </div>

            <button type="button" class="subs-btn outline" data-subs-open="subsHistoryModal">
                <i class="fas fa-clock-rotate-left"></i>
                Approval History
                <span class="subs-pill"><?= number_format($history_count) ?></span>
            </button>

        </div>

        <div class="subs-card-body">

            <!-- FILTERS -->
            <div class="subs-filter">

                <div class="subs-filter-head">
                    <div class="subs-filter-icon">
                        <i class="fas fa-filter"></i>
                    </div>

                    <div>
                        <h4 class="subs-filter-title">Search and Filter</h4>
                        <p class="subs-filter-text">
                            Find specific subscription records using resident details, status, or date.
                        </p>
                    </div>
                </div>

                <form method="GET" action="<?= subEscape($moduleAction) ?>">

                    <?php foreach ($routeParams as $key => $value): ?>
                        <input
                            type="hidden"
                            name="<?= subEscape($key) ?>"
                            value="<?= subEscape($value) ?>"
                        >
                    <?php endforeach; ?>

                    <div class="subs-filter-grid">

                        <div class="subs-field s4">
                            <label class="subs-label" for="subsSearch">Search Resident</label>

                            <div class="subs-search">
                                <i class="fas fa-magnifying-glass"></i>
                                <input
                                    type="text"
                                    id="subsSearch"
                                    name="search"
                                    class="subs-input"
                                    placeholder="Name, username, or resident ID..."
                                    value="<?= subEscape($search) ?>"
                                >
                            </div>
                        </div>

                        <div class="subs-field s2">
                            <label class="subs-label" for="subsSubStatus">Subscription Status</label>

                            <select id="subsSubStatus" name="subscription_status" class="subs-select">
                                <option value="all" <?= $subscription_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="Pending Approval" <?= $subscription_filter === 'Pending Approval' ? 'selected' : '' ?>>Pending Approval</option>
                                <option value="Active" <?= $subscription_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                            </select>
                        </div>

                        <div class="subs-field s2">
                            <label class="subs-label" for="subsPayStatus">Payment Status</label>

                            <select id="subsPayStatus" name="payment_status" class="subs-select">
                                <option value="all" <?= $payment_filter === 'all' ? 'selected' : '' ?>>All Payments</option>
                                <option value="Pending Confirmation" <?= $payment_filter === 'Pending Confirmation' ? 'selected' : '' ?>>Pending Confirmation</option>
                                <option value="Paid" <?= $payment_filter === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="Unpaid" <?= $payment_filter === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            </select>
                        </div>

                        <div class="subs-field s2">
                            <label class="subs-label" for="subscriptionDateFilter">Date Created</label>

                            <select id="subscriptionDateFilter" name="date_filter" class="subs-select">
                                <option value="all" <?= $date_filter === 'all' ? 'selected' : '' ?>>All Dates</option>
                                <option value="today" <?= $date_filter === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= $date_filter === 'week' ? 'selected' : '' ?>>This Week</option>
                                <option value="month" <?= $date_filter === 'month' ? 'selected' : '' ?>>This Month</option>
                                <option value="custom" <?= $date_filter === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                            </select>
                        </div>

                        <div class="subs-field actions">
                            <div class="subs-filter-actions">
                                <button type="submit" class="subs-btn primary">
                                    <i class="fas fa-magnifying-glass"></i>
                                    Apply
                                </button>

                                <a href="<?= subEscape($resetUrl) ?>" class="subs-btn neutral" title="Reset Filters">
                                    <i class="fas fa-rotate-left"></i>
                                </a>
                            </div>
                        </div>

                    </div>

                    <div
                        id="subscriptionCustomDate"
                        class="subs-range <?= $date_filter === 'custom' ? 'open' : '' ?>"
                    >
                        <div class="subs-range-title">
                            <i class="fas fa-calendar-days"></i>
                            Custom Date Range
                        </div>

                        <div class="subs-range-grid">

                            <div class="subs-field">
                                <label class="subs-label" for="subsStart">Start Date</label>
                                <input
                                    type="date"
                                    id="subsStart"
                                    name="start_date"
                                    class="subs-input"
                                    value="<?= subEscape($start_date) ?>"
                                >
                            </div>

                            <div class="subs-field">
                                <label class="subs-label" for="subsEnd">End Date</label>
                                <input
                                    type="date"
                                    id="subsEnd"
                                    name="end_date"
                                    class="subs-input"
                                    value="<?= subEscape($end_date) ?>"
                                >
                            </div>

                            <p class="subs-filter-text">
                                You may leave either date blank if that boundary is not needed.
                            </p>

                        </div>
                    </div>

                </form>

            </div>

            <!-- TABLE INFORMATION -->
            <div class="subs-table-top">

                <div class="subs-table-title">
                    <i class="fas fa-table"></i>
                    Subscription Records
                </div>

                <div class="subs-count">
                    <i class="fas fa-filter"></i>
                    Showing <?= number_format($filtered_records) ?>
                    of <?= number_format($total_records) ?> records
                </div>

            </div>

            <?php if (empty($rows)): ?>

                <div class="subs-empty">
                    <i class="fas fa-inbox"></i>
                    <h4>No Pending Subscriptions</h4>
                    <p>There are currently no subscriptions awaiting administrator approval.</p>
                    <a href="<?= subEscape($resetUrl) ?>" class="subs-btn outline sm">
                        <i class="fas fa-rotate-left"></i>
                        Clear Filters
                    </a>
                </div>

            <?php else: ?>

                <div class="subs-table-wrap">

                    <table class="subs-table">

                        <thead>
                            <tr>
                                <th>Resident</th>
                                <th>Subscription</th>
                                <th>Eco Points</th>
                                <th>Total Due</th>
                                <th>Amount Paid</th>
                                <th>Balance</th>
                                <th>Subscription Status</th>
                                <th>Payment Status</th>
                                <th>Date Created</th>
                                <th style="min-width:190px;">Administrator Action</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($rows as $row): ?>

                            <?php
                            $payment_status = $row['payment_status'];
                            $subscription_status = $row['subscription_status'];

                            $payment_badge = 'gray';

                            if ($payment_status === 'Paid') {
                                $payment_badge = 'green';
                            } elseif ($payment_status === 'Pending Confirmation') {
                                $payment_badge = 'yellow';
                            } elseif ($payment_status === 'Unpaid') {
                                $payment_badge = 'red';
                            }

                            $subscription_badge = $subscription_status === 'Active'
                                ? 'green'
                                : 'gray';
                            ?>

                            <tr>

                                <td>
                                    <div class="subs-name"><?= subEscape($row['fullname']) ?></div>
                                    <span class="subs-sub">
                                        @<?= subEscape($row['username']) ?>
                                        · ID <?= (int)$row['user_id'] ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="subs-strong">
                                        <?= (int)$row['months'] ?> month(s)
                                    </span>
                                    <span class="subs-sub">
                                        Fee: <?= subPeso($row['service_fee']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= number_format((int)$row['points_redeemed']) ?> point(s)
                                    <span class="subs-sub">
                                        Discount: <?= subPeso($row['discount_amount']) ?>
                                    </span>
                                </td>

                                <td class="subs-strong subs-nowrap">
                                    <?= subPeso($row['total_amount']) ?>
                                </td>

                                <td class="subs-nowrap">
                                    <?= subPeso($row['amount_paid']) ?>
                                </td>

                                <td class="subs-nowrap">
                                    <?= subPeso($row['balance_due']) ?>
                                </td>

                                <td>
                                    <span class="subs-badge <?= $subscription_badge ?>">
                                        <?= subEscape($subscription_status) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="subs-badge <?= $payment_badge ?>">
                                        <?= subEscape($payment_status) ?>
                                    </span>

                                    <?php if ($payment_status === 'Pending Confirmation'): ?>
                                        <span class="subs-sub">Cash Simulation</span>
                                    <?php endif; ?>
                                </td>

                                <td class="subs-nowrap">
                                    <?= subEscape(date('M d, Y', strtotime($row['created_at']))) ?>
                                </td>

                                <!-- ADMINISTRATOR ACTION -->
                                <td>
                                    <?php if (
                                        $subscription_status === 'Pending Approval' &&
                                        $payment_status !== 'Paid'
                                    ): ?>

                                        <div class="subscription-action-group">

                                            <!-- APPROVE BUTTON -->
                                            <form method="POST"
                                                action=""
                                                onsubmit="return confirm('Are you sure you want to approve this subscription?');">

                                                <input type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($_SESSION['subscription_admin_csrf']) ?>">

                                                <input type="hidden"
                                                    name="subscription_id"
                                                    value="<?= (int)$row['id'] ?>">

                                                <button type="submit"
                                                        name="confirm"
                                                        value="1"
                                                        class="subscription-action-btn subscription-btn-approve">

                                                    <i class="fas fa-check-circle"></i>
                                                    Approve

                                                </button>
                                            </form>


                                            <!-- REJECT BUTTON -->
                                            <form method="POST"
                                                action=""
                                                onsubmit="return confirm('Are you sure you want to reject this subscription?');">

                                                <input type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($_SESSION['subscription_admin_csrf']) ?>">

                                                <input type="hidden"
                                                    name="subscription_id"
                                                    value="<?= (int)$row['id'] ?>">

                                                <button type="submit"
                                                        name="reject"
                                                        value="1"
                                                        class="subscription-action-btn subscription-btn-reject">

                                                    <i class="fas fa-times-circle"></i>
                                                    Reject

                                                </button>
                                            </form>

                                        </div>

                                    <?php else: ?>

                                        <span class="text-muted">No actions available</span>

                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- =====================================================
     SUBSCRIPTION APPROVAL HISTORY MODAL
===================================================== -->

<div
    class="subs-modal"
    id="subsHistoryModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="subsHistoryTitle"
>

    <div class="subs-modal-dialog">

        <div class="subs-modal-head">

            <div>
                <h3 class="subs-modal-title" id="subsHistoryTitle">
                    <i class="fas fa-clock-rotate-left"></i>
                    Subscription Approval History
                </h3>

                <p class="subs-modal-caption">
                    Records of approved and rejected subscription notices.
                </p>
            </div>

            <button type="button" class="subs-modal-close" data-subs-close aria-label="Close">
                <i class="fas fa-xmark"></i>
            </button>

        </div>

        <div class="subs-modal-body">

            <div class="subs-history-intro">
                <i class="fas fa-shield-halved"></i>

                <div>
                    <strong>Subscription Action Records</strong>
                    <div>
                        This section records administrators who approved or rejected subscription notices.
                    </div>
                </div>
            </div>

            <div class="subs-table-top">

                <div class="subs-table-title">
                    <i class="fas fa-list-check"></i>
                    Approval and Rejection Logs
                </div>

                <div class="subs-count">
                    <?= number_format($history_count) ?> record(s)
                </div>

            </div>

            <div class="subs-history-box">

                <div class="subs-table-wrap">

                    <table class="subs-table">

                        <thead>
                            <tr>
                                <th>History ID</th>
                                <th>Subscription ID</th>
                                <th>Action</th>
                                <th>Processed By</th>
                                <th>Date</th>
                                <th>Time</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($history_count > 0): ?>

                                <?php while ($history = $history_result->fetch_assoc()): ?>

                                    <tr>

                                        <td>
                                            <span class="subs-history-id">
                                                #<?= (int)$history['id'] ?>
                                            </span>
                                        </td>

                                        <td class="subs-strong">
                                            #<?= (int)$history['subscription_id'] ?>
                                        </td>

                                        <td>

                                            <?php if ($history['action_status'] === 'Approved'): ?>

                                                <span class="subs-badge green">
                                                    <i class="fas fa-circle-check"></i>
                                                    Approved
                                                </span>

                                            <?php else: ?>

                                                <span class="subs-badge red">
                                                    <i class="fas fa-circle-xmark"></i>
                                                    Rejected
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <div class="subs-admin">

                                                <span class="subs-avatar">
                                                    <i class="fas fa-user"></i>
                                                </span>

                                                <div>
                                                    <div class="subs-name">
                                                        <?= subEscape($history['admin_name'] ?? 'Unknown Admin') ?>
                                                    </div>

                                                    <span class="subs-sub">
                                                        Admin ID: <?= (int)$history['admin_id'] ?>
                                                    </span>
                                                </div>

                                            </div>

                                        </td>

                                        <td class="subs-nowrap">
                                            <i class="fas fa-calendar subs-date-icon"></i>
                                            <?= subEscape(date('M d, Y', strtotime($history['approved_at']))) ?>
                                        </td>

                                        <td class="subs-nowrap">
                                            <i class="fas fa-clock subs-date-icon"></i>
                                            <?= subEscape(date('h:i:s A', strtotime($history['approved_at']))) ?>
                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="6">

                                        <div class="subs-empty">
                                            <i class="fas fa-inbox"></i>
                                            <h4>No Approval History Yet</h4>
                                            <p>Approved and rejected subscription notices will appear here.</p>
                                        </div>

                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <div class="subs-modal-foot">
            <button type="button" class="subs-btn neutral" data-subs-close>
                <i class="fas fa-xmark"></i>
                Close
            </button>
        </div>

    </div>

</div>

<script>
(function () {

    // CUSTOM DATE FILTER
    const dateFilter = document.getElementById('subscriptionDateFilter');
    const customDate = document.getElementById('subscriptionCustomDate');

    if (dateFilter && customDate) {
        dateFilter.addEventListener('change', function () {
            customDate.classList.toggle('open', this.value === 'custom');
        });
    }

    // APPROVAL HISTORY MODAL
    function openModal(modal) {
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-subs-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(
                button.getAttribute('data-subs-open')
            );

            if (modal) {
                openModal(modal);
            }
        });
    });

    document.querySelectorAll('.subs-modal').forEach(function (modal) {

        modal.querySelectorAll('[data-subs-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                closeModal(modal);
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {

        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.subs-modal.open').forEach(closeModal);
    });

})();
</script>

<?php
if ($history_result instanceof mysqli_result) {
    $history_result->free();
}
?>
