
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/send_email.php";

$message = "";
$success = "";

// DISPLAY REGISTRATION NOTICE
if (!empty($_SESSION["verification_notice"])) {
    $notice = $_SESSION["verification_notice"];
    unset($_SESSION["verification_notice"]);

    if (
        stripos($notice, "unable") !== false ||
        stripos($notice, "failed") !== false
    ) {
        $message = $notice;
    } else {
        $success = $notice;
    }
}

// CHECK PENDING VERIFICATION
if (!isset($_SESSION["pending_verification_user"])) {
    header("Location: register.php");
    exit;
}

$user_id = (int) $_SESSION["pending_verification_user"];

// CSRF TOKEN
if (empty($_SESSION["verify_csrf"])) {
    $_SESSION["verify_csrf"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["verify_csrf"];

// GET USER DETAILS
$user_stmt = mysqli_prepare(
    $conn,
    "SELECT fullname, email, email_verified
     FROM users
     WHERE id = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($user_stmt, "i", $user_id);
mysqli_stmt_execute($user_stmt);

$user_result = mysqli_stmt_get_result($user_stmt);
$user = mysqli_fetch_assoc($user_result);

mysqli_stmt_close($user_stmt);

if (!$user) {
    unset($_SESSION["pending_verification_user"]);
    header("Location: register.php");
    exit;
}

// ALREADY VERIFIED
if ((int) $user["email_verified"] === 1) {
    unset($_SESSION["pending_verification_user"]);
    unset($_SESSION["verify_csrf"]);

    header("Location: index.php?verified=1");
    exit;
}

// HANDLE FORM SUBMISSIONS
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrf_token, $submitted_token)) {

        $message = "Invalid request. Please refresh the page and try again.";

    } elseif (isset($_POST["resend"])) {

        // CHECK RESEND COOLDOWN
        $latest_stmt = mysqli_prepare(
            $conn,
            "SELECT created_at
             FROM email_otps
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );

        mysqli_stmt_bind_param($latest_stmt, "i", $user_id);
        mysqli_stmt_execute($latest_stmt);

        $latest_result = mysqli_stmt_get_result($latest_stmt);
        $latest = mysqli_fetch_assoc($latest_result);

        mysqli_stmt_close($latest_stmt);

        if ($latest) {
            $last_sent = strtotime($latest["created_at"]);
            $remaining = 60 - (time() - $last_sent);

            if ($remaining > 0) {
                $message = "Please wait " . $remaining .
                    " seconds before requesting another OTP.";
            }
        }

        if ($message === "") {

            $otp = (string) random_int(100000, 999999);
            $hashed_otp = password_hash($otp, PASSWORD_DEFAULT);

            $expires_at = date(
                "Y-m-d H:i:s",
                time() + 300
            );

            $subject = "Your New Email Verification Code";

            $body = "
                <h2>Waste Collection Management System</h2>
                <p>Hello " . htmlspecialchars($user["fullname"]) . ",</p>
                <p>Here is your new email verification code:</p>
                <h1 style='color:#198754; letter-spacing:5px;'>
                    " . $otp . "
                </h1>
                <p>This code expires in 5 minutes.</p>
                <p>If you did not request this code, please ignore this email.</p>
            ";

            $new_otp_id = 0;

            try {

                // SAVE NEW OTP FIRST
                mysqli_begin_transaction($conn);

                $delete = mysqli_prepare(
                    $conn,
                    "DELETE FROM email_otps
                     WHERE user_id = ?"
                );

                mysqli_stmt_bind_param($delete, "i", $user_id);
                mysqli_stmt_execute($delete);
                mysqli_stmt_close($delete);

                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO email_otps
                    (user_id, otp_code, expires_at, attempts, created_at)
                    VALUES (?, ?, ?, 0, NOW())"
                );

                mysqli_stmt_bind_param(
                    $insert,
                    "iss",
                    $user_id,
                    $hashed_otp,
                    $expires_at
                );

                if (!mysqli_stmt_execute($insert)) {
                    throw new Exception("Unable to save OTP.");
                }

                $new_otp_id = mysqli_insert_id($conn);

                mysqli_stmt_close($insert);

                mysqli_commit($conn);

                // SEND EMAIL
                if (sendEmail($user["email"], $subject, $body)) {

                    $success = "A new OTP has been sent to your email.";

                } else {

                    // REMOVE UNSENT OTP
                    $remove = mysqli_prepare(
                        $conn,
                        "DELETE FROM email_otps
                         WHERE id = ? AND user_id = ?"
                    );

                    mysqli_stmt_bind_param(
                        $remove,
                        "ii",
                        $new_otp_id,
                        $user_id
                    );

                    mysqli_stmt_execute($remove);
                    mysqli_stmt_close($remove);

                    $message = "Unable to send email. Please try again.";
                }

            } catch (Throwable $e) {

                try {
                    mysqli_rollback($conn);
                } catch (Throwable $rollback_error) {
                    // Ignore rollback errors.
                }

                $message = "Unable to generate a new OTP. Please try again.";

                error_log($e->getMessage());
            }
        }

    } elseif (isset($_POST["verify"])) {

        // VERIFY OTP
        $otp = trim($_POST["otp"] ?? "");

        if (!preg_match('/^[0-9]{6}$/', $otp)) {

            $message = "Please enter a valid 6-digit OTP.";

        } else {

            $stmt = mysqli_prepare(
                $conn,
                "SELECT id, otp_code, expires_at, attempts
                 FROM email_otps
                 WHERE user_id = ?
                 ORDER BY id DESC
                 LIMIT 1"
            );

            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);
            $otp_data = mysqli_fetch_assoc($result);

            mysqli_stmt_close($stmt);

            if (!$otp_data) {

                $message = "No OTP found. Please request a new one.";

            } elseif ((int) $otp_data["attempts"] >= 5) {

                $message = "Too many attempts. Please request a new OTP.";

            } elseif (strtotime($otp_data["expires_at"]) < time()) {

                $message = "OTP expired. Please request a new OTP.";

            } elseif (!password_verify($otp, $otp_data["otp_code"])) {

                // INCREASE FAILED ATTEMPTS
                $update = mysqli_prepare(
                    $conn,
                    "UPDATE email_otps
                     SET attempts = attempts + 1
                     WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $update,
                    "i",
                    $otp_data["id"]
                );

                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);

                $message = "Incorrect OTP. Please try again.";

            } else {

                // OTP IS CORRECT
                try {

                    mysqli_begin_transaction($conn);

                    $update_user = mysqli_prepare(
                        $conn,
                        "UPDATE users
                         SET email_verified = 1,
                             email_verified_at = NOW()
                         WHERE id = ?
                         AND email_verified = 0"
                    );

                    mysqli_stmt_bind_param(
                        $update_user,
                        "i",
                        $user_id
                    );

                    if (
                        !mysqli_stmt_execute($update_user) ||
                        mysqli_stmt_affected_rows($update_user) !== 1
                    ) {
                        throw new Exception("Account verification failed.");
                    }

                    mysqli_stmt_close($update_user);

                    // DELETE USED OTP
                    $delete_otp = mysqli_prepare(
                        $conn,
                        "DELETE FROM email_otps
                         WHERE user_id = ?"
                    );

                    mysqli_stmt_bind_param(
                        $delete_otp,
                        "i",
                        $user_id
                    );

                    mysqli_stmt_execute($delete_otp);
                    mysqli_stmt_close($delete_otp);

                    mysqli_commit($conn);

                    unset($_SESSION["pending_verification_user"]);
                    unset($_SESSION["verify_csrf"]);

                    header("Location: index.php?verified=1");
                    exit;

                } catch (Throwable $e) {

                    try {
                        mysqli_rollback($conn);
                    } catch (Throwable $rollback_error) {
                        // Ignore rollback errors.
                    }

                    $message = "Verification failed. Please try again.";

                    error_log($e->getMessage());
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Verify Your Email | EcoTrack</title>

    <link rel="stylesheet"
          href="assets/vendor/bootstrap/css/bootstrap.min.css">

    <style>
        :root {
            --primary-green: #198754;
            --dark-green: #145c38;
            --light-green: #eaf5ee;
            --text-dark: #183d2b;
            --text-muted: #718078;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 25px 15px;

            display: flex;
            justify-content: center;
            align-items: center;

            font-family: "Segoe UI", Arial, sans-serif;

            background:
                radial-gradient(circle at top left, #dcefe2 0, transparent 38%),
                radial-gradient(circle at bottom right, #d8eadf 0, transparent 35%),
                #f4f8f5;
        }

        .verify-wrapper {
            width: 100%;
            max-width: 460px;
        }

        .brand {
            text-align: center;
            margin-bottom: 22px;
        }

        .brand-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 12px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 18px;
            background: var(--primary-green);
            color: white;

            font-size: 28px;
            font-weight: 700;

            box-shadow: 0 7px 18px rgba(25, 135, 84, 0.2);
        }

        .brand-name {
            color: var(--dark-green);
            font-size: 23px;
            font-weight: 800;
            letter-spacing: 1px;
            margin: 0;
        }

        .brand-subtitle {
            color: var(--text-muted);
            font-size: 13px;
            margin-top: 3px;
        }

        .verify-card {
            background: #ffffff;
            padding: 38px 34px;
            border-radius: 20px;

            border: 1px solid rgba(25, 135, 84, 0.08);

            box-shadow:
                0 15px 45px rgba(23, 65, 42, 0.09);
        }

        .mail-icon {
            width: 76px;
            height: 76px;
            margin: 0 auto 22px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;
            background: var(--light-green);
            color: var(--primary-green);

            font-size: 35px;
        }

        .verify-title {
            color: var(--text-dark);
            font-size: 25px;
            font-weight: 800;
            text-align: center;
            margin-bottom: 10px;
        }

        .verify-description {
            color: var(--text-muted);
            text-align: center;
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 8px;
        }

        .email-address {
            display: block;
            text-align: center;
            color: var(--dark-green);
            font-weight: 700;
            font-size: 14px;
            overflow-wrap: anywhere;
            margin-bottom: 25px;
        }

        .otp-label {
            display: block;
            color: var(--text-dark);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .otp-input {
            width: 100%;
            height: 62px;

            border: 1.5px solid #dce7df;
            border-radius: 12px;

            background: #fbfdfb;

            text-align: center;
            font-size: 27px;
            font-weight: 700;
            letter-spacing: 13px;
            color: var(--dark-green);

            padding-left: 18px;

            transition: all 0.2s ease;
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--primary-green);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(25, 135, 84, 0.10);
        }

        .otp-input::placeholder {
            color: #c5d2c9;
            letter-spacing: 10px;
        }

        .otp-hint {
            color: #89968d;
            font-size: 12px;
            margin-top: 9px;
            text-align: center;
        }

        .btn-verify {
            width: 100%;
            min-height: 49px;
            margin-top: 22px;

            border: none;
            border-radius: 10px;

            background: var(--primary-green);
            color: white;

            font-size: 15px;
            font-weight: 700;

            transition: all 0.2s ease;
        }

        .btn-verify:hover {
            background: var(--dark-green);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 5px 13px rgba(25, 135, 84, 0.18);
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 25px 0 20px;

            color: #a0aaa3;
            font-size: 12px;
        }

        .divider::before,
        .divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: #e8eee9;
        }

        .resend-text {
            color: var(--text-muted);
            text-align: center;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .btn-resend {
            width: 100%;
            min-height: 45px;

            border: 1.5px solid var(--primary-green);
            border-radius: 10px;

            color: var(--primary-green);
            background: white;

            font-size: 14px;
            font-weight: 700;

            transition: all 0.2s ease;
        }

        .btn-resend:hover {
            color: white;
            background: var(--primary-green);
        }

        .alert {
            border-radius: 10px;
            font-size: 13px;
            padding: 12px 14px;
        }

        .security-note {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 7px;

            margin-top: 22px;

            color: #849188;
            font-size: 11px;
            text-align: center;
        }

        .footer-text {
            margin-top: 22px;
            text-align: center;
            color: #8a978e;
            font-size: 11px;
        }

        @media (max-width: 480px) {
            .verify-card {
                padding: 30px 22px;
            }

            .verify-title {
                font-size: 22px;
            }

            .otp-input {
                height: 57px;
                font-size: 24px;
                letter-spacing: 11px;
            }
        }
    </style>
</head>

<body>

<div class="verify-wrapper">

    <!-- BRAND -->
    <div class="brand">
        <div class="brand-icon">E</div>

        <h1 class="brand-name">ECOTRACK</h1>

        <p class="brand-subtitle">
            Waste Collection Management System
        </p>
    </div>

    <!-- VERIFICATION CARD -->
    <div class="verify-card">

        <div class="mail-icon">
            &#9993;
        </div>

        <h2 class="verify-title">
            Verify Your Email
        </h2>

        <p class="verify-description">
            We've sent a 6-digit verification code to your email address.
            Please enter the code below to activate your account.
        </p>

        <span class="email-address">
            <?php echo htmlspecialchars($user["email"]); ?>
        </span>

        <!-- ERROR MESSAGE -->
        <?php if ($message !== "") { ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <!-- SUCCESS MESSAGE -->
        <?php if ($success !== "") { ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php } ?>

        <!-- VERIFY FORM -->
        <form method="POST">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo htmlspecialchars($csrf_token); ?>">

            <label class="otp-label" for="otp">
                Enter your verification code
            </label>

            <input
                type="text"
                id="otp"
                name="otp"
                class="otp-input"
                maxlength="6"
                pattern="[0-9]{6}"
                inputmode="numeric"
                placeholder="000000"
                autocomplete="one-time-code"
                aria-label="Six-digit verification code"
                required
            >

            <p class="otp-hint">
                Your verification code expires in 5 minutes.
            </p>

            <button type="submit"
                    name="verify"
                    class="btn btn-verify">
                Verify Email Address
            </button>

        </form>

        <div class="divider">
            OR
        </div>

        <!-- RESEND OTP FORM -->
        <form method="POST">

            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo htmlspecialchars($csrf_token); ?>">

            <p class="resend-text">
                Didn't receive your verification code?
            </p>

            <button type="submit"
                    name="resend"
                    class="btn btn-resend">
                Resend Verification Code
            </button>

        </form>

        <div class="security-note">
            <span>&#128274;</span>
            Your verification code is private and secure.
        </div>

    </div>

    <div class="footer-text">
        &copy; <?php echo date("Y"); ?>
        EcoTrack Waste Collection Management System
    </div>

</div>

<script>
    // Allow numbers only in the OTP field.
    const otpInput = document.getElementById("otp");

    otpInput.addEventListener("input", function () {
        this.value = this.value.replace(/[^0-9]/g, "").slice(0, 6);
    });
</script>

</body>
</html>
