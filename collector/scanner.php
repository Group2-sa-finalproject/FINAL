<?php
session_start();
include "../config/database.php";

// Only collectors can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    !in_array($_SESSION["role"], ["collector", "collectors"])
) {
    header("Location: ../index.php");
    exit;
}

$collector_id = (int) $_SESSION["user_id"];

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Handle scanner requests
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header("Content-Type: application/json");

    function respond($success, $message, $data = []) {
        echo json_encode([
            "success" => $success,
            "message" => $message,
            "data" => $data
        ]);
        exit;
    }

    // Verify CSRF token
    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        respond(false, "Invalid request. Please refresh the page.");
    }

    $action = $_POST["action"] ?? "";

    // =====================================================
    // VERIFY RESIDENT QR CODE
    // Payment status is based ONLY on subscription_approval_history
    // =====================================================

    if ($action === "verify") {

        $qr_data = trim($_POST["qr_data"] ?? "");

        if (!preg_match('/^RESIDENT\s*-\s*([1-9][0-9]*)$/i', $qr_data, $matches)) {
            respond(false, "Invalid resident QR code.");
        }

        $resident_id = (int) $matches[1];

        $stmt = mysqli_prepare($conn, "
            SELECT
                u.id,
                u.fullname,
                u.users,
                h.id AS household_id,
                h.house_address,

                sah.id AS approval_history_id,
                sah.action_status,
                sah.approved_at,
                sah.total_amount,
                sah.amount_paid,
                sah.balance_due

            FROM users u

            LEFT JOIN households h
                ON h.user_id = u.id

            LEFT JOIN subscriptions s
                ON s.user_id = u.id

            LEFT JOIN subscription_approval_history sah
                ON sah.id = (
                    SELECT MAX(sah2.id)
                    FROM subscription_approval_history sah2
                    WHERE sah2.subscription_id = s.id
                )

            WHERE u.id = ?
              AND u.role = 'residents'

            LIMIT 1
        ");

        if (!$stmt) {
            respond(false, "Database error while checking resident: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "i", $resident_id);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $resident = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);

        if (!$resident) {
            respond(false, "Resident account not found.");
        }

        // Read the latest approval-history record
        $action_status = strtoupper(
            trim((string)($resident["action_status"] ?? ""))
        );

        $has_payment_record = (
            $resident["approval_history_id"] !== null
        );

        // APPROVED means PAID, based on your requirement
        $payment_status = ($action_status === "APPROVED")
            ? "PAID"
            : "UNPAID";

        // Get amounts directly from subscription_approval_history
        $payment_amount = number_format(
            (float)($resident["total_amount"] ?? 0),
            2
        );

        $amount_paid = number_format(
            (float)($resident["amount_paid"] ?? 0),
            2
        );

        $balance_due = number_format(
            (float)($resident["balance_due"] ?? 0),
            2
        );

        // Display approval date when an approved record exists
        $paid_at = "No payment recorded";

        if ($payment_status === "PAID" && !empty($resident["approved_at"])) {
            $paid_at = date(
                "M d, Y h:i A",
                strtotime($resident["approved_at"])
            );
        }

        // Display subscription status using the history action
        if ($action_status === "APPROVED") {
            $subscription_status = "Active";
        } elseif ($action_status === "REJECTED") {
            $subscription_status = "Rejected";
        } elseif (!empty($action_status)) {
            $subscription_status = ucfirst(strtolower($action_status));
        } else {
            $subscription_status = "No approval record";
        }

        // Collection is allowed only when:
        // 1. Resident has a registered household.
        // 2. Latest approval-history action is Approved.
        $can_collect = (
            !empty($resident["household_id"]) &&
            $payment_status === "PAID"
        );

        respond(true, "Resident QR code recognized successfully.", [
            "resident_id" => $resident["id"],
            "fullname" => $resident["fullname"],
            "username" => $resident["users"],
            "household_id" => $resident["household_id"],
            "house_address" => $resident["house_address"] ?? "No household address registered",
            "subscription_status" => $subscription_status,

            "has_payment_record" => $has_payment_record,
            "payment_status" => $payment_status,
            "payment_amount" => $payment_amount,
            "amount_paid" => $amount_paid,
            "balance_due" => $balance_due,
            "paid_at" => $paid_at,

            "can_collect" => $can_collect
        ]);
    }

    // =====================================================
    // RECORD WASTE COLLECTION
    // Recheck approval history before saving
    // =====================================================

    if ($action === "collect") {

        $resident_id = (int)($_POST["resident_id"] ?? 0);
        $segregation = $_POST["segregation"] ?? "";

        if ($resident_id <= 0) {
            respond(false, "Invalid resident.");
        }

        if (!in_array($segregation, [
            "Properly Segregated",
            "Not Properly Segregated"
        ])) {
            respond(false, "Invalid segregation status.");
        }

        mysqli_begin_transaction($conn);

        try {

            // Recheck resident, household, and latest approval history.
            // No subscriptions table is used here.
            $stmt = mysqli_prepare($conn, "
                SELECT
                    u.id,
                    h.id AS household_id,
                    sah.id AS approval_history_id,
                    sah.action_status

                FROM users u

                INNER JOIN households h
                    ON h.user_id = u.id

                LEFT JOIN subscriptions s
                    ON s.user_id = u.id

                LEFT JOIN subscription_approval_history sah
                    ON sah.id = (
                        SELECT MAX(sah2.id)
                        FROM subscription_approval_history sah2
                        WHERE sah2.subscription_id = s.id
                    )

                WHERE u.id = ?
                  AND u.role = 'residents'

                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception(
                    "Unable to verify resident: " . mysqli_error($conn)
                );
            }

            mysqli_stmt_bind_param($stmt, "i", $resident_id);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $resident = mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);

            if (!$resident) {
                throw new Exception("Resident or registered household not found.");
            }

            $latest_action = strtoupper(
                trim((string)($resident["action_status"] ?? ""))
            );

            if (
                empty($resident["household_id"]) ||
                $resident["approval_history_id"] === null ||
                $latest_action !== "APPROVED"
            ) {
                throw new Exception(
                    "Collection is blocked. The resident must have an approved subscription in the subscription approval history and a registered household."
                );
            }

            $household_id = (int)$resident["household_id"];

            // Award 5 points only for proper segregation
            $points = ($segregation === "Properly Segregated") ? 5 : 0;

            // Insert collection record
            $stmt = mysqli_prepare($conn, "
                INSERT INTO waste_collections (
                    household_id,
                    collector_id,
                    collection_date,
                    segregation_status,
                    collection_status,
                    eco_points_awarded,
                    collected_at
                )
                VALUES (?, ?, CURDATE(), ?, 'Collected', ?, NOW())
            ");

            if (!$stmt) {
                throw new Exception(
                    "Unable to create collection record: " . mysqli_error($conn)
                );
            }

            mysqli_stmt_bind_param(
                $stmt,
                "iisi",
                $household_id,
                $collector_id,
                $segregation,
                $points
            );

            if (!mysqli_stmt_execute($stmt)) {

                if (mysqli_stmt_errno($stmt) == 1062) {
                    throw new Exception(
                        "This household has already been recorded as collected today."
                    );
                }

                throw new Exception(
                    "Unable to save collection record: " . mysqli_stmt_error($stmt)
                );
            }

            $collection_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            // Add eco-points only when properly segregated
            if ($points > 0) {

                $description = "Properly segregated household waste";

                $stmt = mysqli_prepare($conn, "
                    INSERT INTO eco_points (
                        user_id,
                        collection_id,
                        points,
                        description
                    )
                    VALUES (?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new Exception(
                        "Unable to prepare eco-points record: " . mysqli_error($conn)
                    );
                }

                mysqli_stmt_bind_param(
                    $stmt,
                    "iiis",
                    $resident_id,
                    $collection_id,
                    $points,
                    $description
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception(
                        "Unable to save eco-points: " . mysqli_stmt_error($stmt)
                    );
                }

                mysqli_stmt_close($stmt);
            }

            mysqli_commit($conn);

            $message = $points > 0
                ? "Collection recorded! 5 eco-points awarded."
                : "Collection recorded. No eco-points awarded.";

            respond(true, $message, [
                "points" => $points
            ]);

        } catch (Throwable $e) {

            mysqli_rollback($conn);
            respond(false, $e->getMessage());
        }
    }

    respond(false, "Unknown request.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collector QR Scanner</title>

    <style>
        /* =====================================================
           COLLECTOR SCANNER - SELF-CONTAINED DESIGN
           (does not depend on Bootstrap)
           ===================================================== */

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        :root {
            --green-900: #123d2a;
            --green-800: #17452f;
            --green-700: #198754;
            --green-600: #157347;
            --green-100: #e5f4eb;
            --ink: #263b32;
            --muted: #7b8981;
            --line: #edf1ee;
            --bg: #f4f7f6;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            min-height: 100vh;
        }

        /* ---------- NAVBAR ---------- */
        .app-navbar {
            position: sticky;
            top: 0;
            z-index: 50;
            overflow: hidden;
            border-radius: 20px;
            background:
                radial-gradient(circle at 92% -40%, rgba(255, 255, 255, .22), transparent 55%),
                linear-gradient(135deg, #123d2a 0%, #17623f 55%, #198754 100%);
            border: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 6px 16px rgba(23, 69, 47, .22);
        }

        .app-navbar-inner {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 24px;
            height: 74px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .app-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
            color: #fff;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 14px;
            font-size: 24px;
            line-height: 1;
        }

        .brand-text {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand-title {
            font-size: 21px;
            font-weight: 800;
            letter-spacing: .2px;
            white-space: nowrap;
        }

        .brand-sub {
            padding: 5px 13px;
            background: rgba(255, 255, 255, .16);
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: #d6f0e0;
            white-space: nowrap;
        }

        .nav-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            background: #fff;
            color: var(--green-800);
            padding: 10px 20px;
            border-radius: 999px;
            font-size: 13.5px;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
            transition: transform .2s, box-shadow .2s;
            white-space: nowrap;
        }

        .nav-pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .22);
        }

        /* ---------- LAYOUT ---------- */
        .scanner-container {
            width: 100%;
            max-width: 650px;
            margin: 32px auto;
            padding: 0 16px;
        }

        .scanner-card {
            background: #fff;
            padding: 28px;
            border-radius: 16px;
            border: 1px solid var(--line);
            box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
            text-align: center;
        }

        .scanner-title {
            margin: 0 0 6px;
            color: var(--green-800);
            font-size: 24px;
            font-weight: 800;
        }

        .scanner-subtitle {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 13px;
        }

        .section-title {
            margin: 22px 0 14px;
            color: var(--green-800);
            font-size: 17px;
            font-weight: 800;
            text-align: center;
        }

        hr {
            border: 0;
            border-top: 1px solid var(--line);
            margin: 20px 0;
        }

        /* ---------- QR READER ---------- */
        #reader {
            width: 100%;
            margin: 0 auto 18px;
            border: 2px dashed #bfdcca !important;
            border-radius: 14px;
            background: #f8fbf9;
            overflow: hidden;
            min-height: 120px;
        }

        #reader video {
            width: 100% !important;
            border-radius: 12px;
        }

        /* ---------- MESSAGE BOX ---------- */
        .msg {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            border: 1px solid transparent;
            text-align: center;
        }

        .msg-info {
            background: #e8f1fb;
            color: #1d5a94;
            border-color: #cfe1f4;
        }

        .msg-success {
            background: #d1e7dd;
            color: #0f5132;
            border-color: #b7dbc8;
        }

        .msg-danger {
            background: #f8d7da;
            color: #842029;
            border-color: #efbfc4;
        }

        .msg-warning {
            background: #fff3cd;
            color: #7a5a00;
            border-color: #f3e0a0;
        }

        /* ---------- RESULT ---------- */
        #resident-result {
            display: none;
            text-align: left;
        }

        .info-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
            font-size: 13px;
        }

        .info-table th,
        .info-table td {
            padding: 11px 14px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: middle;
        }

        .info-table tr:last-child th,
        .info-table tr:last-child td {
            border-bottom: 0;
        }

        .info-table th {
            width: 40%;
            background: #f4f9f6;
            color: #52665a;
            font-weight: 700;
        }

        .info-table td {
            color: var(--ink);
            font-weight: 600;
            word-break: break-word;
        }

        /* ---------- PAYMENT BOX ---------- */
        .payment-box {
            padding: 20px;
            border-radius: 14px;
            margin: 0 0 6px;
            text-align: center;
        }

        .payment-box h2,
        .payment-box h3,
        .payment-box h5 {
            margin: 0;
        }

        .payment-box h3 {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }

        .payment-box h2 {
            font-size: 30px;
            font-weight: 800;
        }

        .payment-box h5 {
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .payment-box p {
            margin: 0 0 3px;
            font-size: 12px;
            font-weight: 600;
            opacity: .85;
        }

        .payment-box hr {
            border-top-color: rgba(0, 0, 0, .12);
            margin: 14px 0;
        }

        .payment-box.paid {
            background: #d1e7dd;
            color: #0f5132;
        }

        .payment-box.unpaid {
            background: #f8d7da;
            color: #842029;
        }

        /* ---------- BUTTONS ---------- */
        .sc-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            min-height: 46px;
            padding: 11px 18px;
            border-radius: 10px;
            border: 1px solid transparent;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all .2s ease;
        }

        .sc-btn:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        .sc-btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .sc-btn + .sc-btn {
            margin-top: 10px;
        }

        .sc-btn-success {
            background: var(--green-700);
            color: #fff;
            box-shadow: 0 3px 10px rgba(25, 135, 84, .25);
        }

        .sc-btn-success:hover:not(:disabled) {
            background: var(--green-600);
        }

        .sc-btn-warning {
            background: #ffc107;
            color: #3d2f00;
            box-shadow: 0 3px 10px rgba(255, 193, 7, .3);
        }

        .sc-btn-warning:hover:not(:disabled) {
            background: #ffca2c;
        }

        .sc-btn-outline {
            background: #fff;
            color: var(--green-700);
            border-color: var(--green-700);
        }

        .sc-btn-outline:hover:not(:disabled) {
            background: var(--green-700);
            color: #fff;
        }

        .sc-btn-secondary {
            background: #eef2ef;
            color: #52665a;
            border-color: #dfe8e2;
        }

        .sc-btn-secondary:hover:not(:disabled) {
            background: #e1e8e3;
            color: var(--ink);
        }

        .back-wrap {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
        }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 576px) {
            .scanner-container {
                margin: 18px auto;
            }

            .scanner-card {
                padding: 18px;
            }

            .app-navbar-inner {
                padding: 0 14px;
            }

            .app-navbar-inner {
                height: 64px;
                padding: 0 14px;
            }

            .brand-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }

            .brand-title {
                font-size: 16px;
            }

            .brand-sub {
                font-size: 10px;
                padding: 4px 10px;
            }

            .nav-pill {
                padding: 8px 14px;
                font-size: 12px;
            }

            .info-table th {
                width: 44%;
            }
        }
    </style>
</head>

<body>

<nav class="app-navbar">
    <div class="app-navbar-inner">

        <div class="app-brand">
            <span class="brand-icon">♻</span>
            <span class="brand-text">
                <span class="brand-title">Waste Collection</span>
                <span class="brand-sub">Collector</span>
            </span>
        </div>


    </div>
</nav>

<div class="scanner-container">

    <div class="scanner-card">

        <h3 class="scanner-title">Resident QR Scanner</h3>

        <p class="scanner-subtitle">
            Scan the resident's QR code to view resident and payment information.
        </p>

        <div id="reader"></div>

        <div id="scan-message" class="msg msg-info">
            Waiting for QR code...
        </div>

        <div id="resident-result">

            <hr>

            <h4 class="section-title" style="margin-top:0;">Resident Information</h4>

            <table class="info-table">
                <tr>
                      <th>House Address</th>
                      <td id="resident-address"></td>
                </tr>
                <tr>
                    <th>Full Name</th>
                    <td id="resident-name"></td>
                </tr>
                <tr>
                    <th>Username</th>
                    <td id="resident-username"></td>
                </tr>
                <tr>
                    <th>Household ID</th>
                    <td id="household-id"></td>
                </tr>
                <tr>
                    <th>Subscription Status</th>
                    <td id="subscription-status"></td>
                </tr>
            </table>

            <div id="payment-information-section">

            <h4 class="section-title">Payment Information</h4>

            <div id="payment-box" class="payment-box unpaid">

                <h3 id="payment-status">UNPAID</h3>

                <p>Total Subscription Amount</p>
                <h2 id="payment-amount">₱0.00</h2>

                <hr>

                <p>Amount Paid</p>
                <h5 id="amount-paid">₱0.00</h5>

                <p>Balance Due</p>
                <h5 id="balance-due">₱0.00</h5>

                <p>
                    Payment Record: <span id="payment-date">No payment recorded</span>
                </p>

            </div>
            </div>

            <div id="collection-section" style="display:none;">

                <hr>

                <h5 class="section-title" style="margin-top:0;">Waste Segregation Assessment</h5>

                <button
                    type="button"
                    class="sc-btn sc-btn-success"
                    onclick="recordCollection('Properly Segregated')"
                >
                    Properly Segregated (+5 Eco-Points)
                </button>

                <button
                    type="button"
                    class="sc-btn sc-btn-warning"
                    onclick="recordCollection('Not Properly Segregated')"
                >
                    Not Properly Segregated (No Points)
                </button>

            </div>

            <div id="collection-blocked" class="msg msg-warning" style="margin-top:16px;">
                Collection cannot proceed because the subscription is inactive or the payment is unpaid.
            </div>

            <button
                type="button"
                class="sc-btn sc-btn-outline"
                style="margin-top:12px;"
                onclick="resetScanner()"
            >
                Back to Scanner
            </button>

        </div>


    </div>

</div>

<script src="https://unpkg.com/html5-qrcode"></script>

<script>
    const csrfToken = <?php echo json_encode($_SESSION["csrf_token"]); ?>;

    let residentId = null;
    let scanner = null;
    let scannerRunning = false;
    let busy = false;

    const message = document.getElementById("scan-message");
    const result = document.getElementById("resident-result");

    function showMessage(text, type = "info") {
        message.className = "msg msg-" + type;
        message.textContent = text;
        message.style.display = "block";
    }

    async function sendRequest(data) {
        const formData = new FormData();

        formData.append("csrf_token", csrfToken);

        for (const key in data) {
            formData.append(key, data[key]);
        }

        const response = await fetch("scanner.php", {
            method: "POST",
            body: formData,
            credentials: "same-origin"
        });

        return await response.json();
    }

    async function startScanner() {
        try {
            scanner = new Html5Qrcode("reader");

            await scanner.start(
                { facingMode: "environment" },
                {
                    fps: 10,
                    qrbox: { width: 250, height: 250 }
                },
                handleScan
            );

            scannerRunning = true;
            showMessage("Camera active. Scan a resident QR code.", "info");

        } catch (error) {
            showMessage(
                "Unable to access camera. Please allow camera permission.",
                "danger"
            );
        }
    }

    async function handleScan(decodedText) {
        if (busy) return;

        busy = true;

        try {
            if (scannerRunning) {
                await scanner.stop();
                scannerRunning = false;
            }

            showMessage("Recognizing resident QR code...", "info");

            const response = await sendRequest({
                action: "verify",
                qr_data: decodedText
            });

            if (!response.success) {
                showMessage(response.message, "danger");
                busy = false;
                setTimeout(resetScanner, 2500);
                return;
            }

            const data = response.data;

            residentId = data.resident_id;

            document.getElementById("resident-address").textContent =
                data.house_address || "No household address registered";

            document.getElementById("resident-name").textContent = data.fullname;
            document.getElementById("resident-username").textContent = data.username || "Not available";
            document.getElementById("household-id").textContent = data.household_id || "Not registered";
            document.getElementById("subscription-status").textContent = data.subscription_status;

            document.getElementById("payment-status").textContent = data.payment_status;

            const paymentSection = document.getElementById("payment-information-section");

            paymentSection.style.display =
                data.has_payment_record ? "block" : "none";

            document.getElementById("payment-amount").textContent =
                "₱" + data.payment_amount;

            document.getElementById("amount-paid").textContent =
                "₱" + data.amount_paid;

            document.getElementById("balance-due").textContent =
                "₱" + data.balance_due;

            document.getElementById("payment-date").textContent = data.paid_at;

            const paymentBox = document.getElementById("payment-box");

            if (data.payment_status === "PAID") {
                paymentBox.className = "payment-box paid";
            } else {
                paymentBox.className = "payment-box unpaid";
            }

            document.getElementById("collection-section").style.display =
                data.can_collect ? "block" : "none";

            document.getElementById("collection-blocked").style.display =
                data.can_collect ? "none" : "block";

            busy = false;

            result.style.display = "block";
            message.style.display = "none";

        } catch (error) {
            showMessage(
                "Unable to verify resident. Please check your database and try again.",
                "danger"
            );

            busy = false;
            setTimeout(resetScanner, 2500);
        }
    }

    async function recordCollection(status) {
        if (!residentId || busy) return;

        busy = true;

        const buttons = document.querySelectorAll("#collection-section button");
        buttons.forEach(button => button.disabled = true);

        showMessage("Saving collection record...", "info");
        message.style.display = "block";

        try {
            const response = await sendRequest({
                action: "collect",
                resident_id: residentId,
                segregation: status
            });

            if (response.success) {
                showMessage(response.message, "success");
                result.style.display = "none";

                setTimeout(resetScanner, 2500);

            } else {
                showMessage(response.message, "danger");
                buttons.forEach(button => button.disabled = false);
                busy = false;
            }

        } catch (error) {
            showMessage("Unable to save collection. Please try again.", "danger");
            buttons.forEach(button => button.disabled = false);
            busy = false;
        }
    }

    async function resetScanner() {
        residentId = null;
        busy = false;

        result.style.display = "none";
        message.style.display = "block";
        document.getElementById("reader").innerHTML = "";

        if (scanner && scannerRunning) {
            try {
                await scanner.stop();
            } catch (error) {}
        }

        scannerRunning = false;

        if (scanner) {
            try {
                await scanner.clear();
            } catch (error) {}
        }

        startScanner();
    }

    startScanner();
</script>

</body>
</html>
