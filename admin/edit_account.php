<?php
/* Standalone edit-account page. Save as admin/edit_account.php. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../config/database.php";
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit("Database connection is unavailable.");
}
$conn->set_charset("utf8mb4");

function editAccountEscape($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["edit_account_csrf"])) {
    $_SESSION["edit_account_csrf"] = bin2hex(random_bytes(32));
}

$currentAdminId = (int)$_SESSION["user_id"];
$accountId = filter_var($_GET["id"] ?? $_POST["account_id"] ?? null, FILTER_VALIDATE_INT);
if (!$accountId || $accountId < 1) {
    header("Location: dashboard.php?page=accounts");
    exit;
}

$error = "";
$success = "";
$allowedRoles = ["admin", "collector", "residents"];

/* NOTE: assumes the users table has an `address` column and a `password` column. */
$getAccount = $conn->prepare("SELECT id, fullname, users, role, address FROM users WHERE id = ?");
$getAccount->bind_param("i", $accountId);
$getAccount->execute();
$account = $getAccount->get_result()->fetch_assoc();
$getAccount->close();

if (!$account) {
    http_response_code(404);
    exit("The selected account was not found.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    $fullname = trim((string)($_POST["fullname"] ?? ""));
    $username = trim((string)($_POST["username"] ?? ""));
    $role = (string)($_POST["role"] ?? "");
    $address = trim((string)($_POST["address"] ?? ""));
    $newPassword = (string)($_POST["new_password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_password"] ?? "");

    if (!hash_equals($_SESSION["edit_account_csrf"], $token)) {
        $error = "Your security token is invalid. Please reload the page and try again.";
    } elseif ($fullname === "" || $username === "") {
        $error = "Full name and username are required.";
    } elseif (mb_strlen($fullname) > 150 || mb_strlen($username) > 100) {
        $error = "The full name or username is too long.";
    } elseif (mb_strlen($address) > 255) {
        $error = "The address is too long (maximum 255 characters).";
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = "Please choose a valid account type.";
    } elseif ((int)$accountId === $currentAdminId && $role !== $account["role"]) {
        $error = "For security, you cannot change your own account type.";
    } elseif ($newPassword !== "" || $confirmPassword !== "") {
        /* Password is optional. Only validated when something was typed. */
        if (strlen($newPassword) < 8) {
            $error = "The new password must be at least 8 characters long.";
        } elseif (strlen($newPassword) > 72) {
            $error = "The new password is too long (maximum 72 characters).";
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $error = "The new password and confirmation do not match.";
        }
    }

    if ($error === "") {
        /* Do not demote the final administrator. */
        if ($account["role"] === "admin" && $role !== "admin") {
            $countResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
            $adminCount = (int)($countResult->fetch_assoc()["total"] ?? 0);
            $countResult->free();
            if ($adminCount <= 1) {
                $error = "You cannot change the role of the last administrator.";
            }
        }
    }

    if ($error === "") {
        $sql = "UPDATE users SET fullname = ?, users = ?, role = ?, address = ?";
        $types = "ssss";
        $params = [$fullname, $username, $role, $address];

        if ($newPassword !== "") {
            $sql .= ", password = ?";
            $types .= "s";
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = ?";
        $types .= "i";
        $params[] = $accountId;

        $update = $conn->prepare($sql);
        if (!$update) {
            error_log("Edit account prepare failed: " . $conn->error);
            $error = "Unable to prepare the account update.";
        } else {
            $update->bind_param($types, ...$params);
            if ($update->execute()) {
                $update->close();
                $_SESSION["edit_account_csrf"] = bin2hex(random_bytes(32));
                header("Location: dashboard.php?page=accounts&updated=1");
                exit;
            } else {
                if ($conn->errno === 1062) {
                    $error = "That username is already being used. Please choose another one.";
                } else {
                    error_log("Edit account update failed: " . $update->error);
                    $error = "Unable to update this account. Please check the PHP error log.";
                }
                $update->close();
            }
        }
    }

    /* Preserve submitted values so the admin does not need to retype them.
       Passwords are intentionally never sent back to the page. */
    $account["fullname"] = $fullname;
    $account["users"] = $username;
    $account["role"] = $role;
    $account["address"] = $address;
    $_SESSION["edit_account_csrf"] = bin2hex(random_bytes(32));
}

/* Initials for the profile avatar. */
$nameParts = preg_split('/\s+/u', trim((string)$account["fullname"]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$initials = "";
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === "") {
    $initials = "?";
}
$isSelf = ((int)$accountId === $currentAdminId);
$roleOptions = [
    "admin"     => ["Administrator",     "bi-shield-check", "Manages accounts and system settings."],
    "collector" => ["Garbage collector", "bi-truck",        "Collects waste on assigned routes."],
    "residents" => ["Resident",          "bi-house-heart",  "Uses the resident portal."],
];
$roleLabels = ["admin" => "Administrator", "collector" => "Garbage collector", "residents" => "Resident"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Account | EcoTrack Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root{
            --ink:#1c2a23;
            --muted:#66766c;
            --forest:#176b40;
            --forest-dark:#115331;
            --moss:#e7f1ea;
            --paper:#f4f6f3;
            --surface:#ffffff;
            --line:#dde5df;
            --danger:#b3392f;
            --danger-bg:#fdeceb;
            --warn:#b7791f;
            --radius:12px;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--paper);color:var(--ink);font-family:"Figtree",system-ui,-apple-system,"Segoe UI",Arial,sans-serif;font-size:15px;line-height:1.5}

        /* Top bar */
        .topbar{background:var(--forest);color:#fff;padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between}
        .brand{display:inline-flex;align-items:center;gap:9px;font-weight:700;font-size:17px;color:#fff;text-decoration:none}
        .brand i{font-size:20px}

        /* Page */
        .page-wrap{max-width:940px;margin:0 auto;padding:26px 20px 110px}
        .crumbs{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);margin-bottom:18px}
        .crumbs a{color:var(--forest);text-decoration:none;font-weight:600}
        .crumbs a:hover{text-decoration:underline}

        /* Profile summary */
        .profile{display:flex;align-items:center;gap:18px;margin-bottom:22px}
        .avatar{width:68px;height:68px;border-radius:50%;background:var(--forest);color:#fff;display:flex;align-items:center;justify-content:center;font-size:25px;font-weight:700;flex-shrink:0}
        .profile h1{font-size:26px;font-weight:700;margin:0;line-height:1.2;overflow-wrap:anywhere}
        .profile-sub{display:flex;align-items:center;flex-wrap:wrap;gap:8px 12px;margin-top:6px;color:var(--muted);font-size:14px}
        .badge-role{display:inline-flex;align-items:center;gap:5px;background:var(--moss);color:var(--forest-dark);font-weight:600;font-size:13px;padding:3px 10px;border-radius:999px}
        .badge-role.admin{background:#e9eefb;color:#2c4a99}
        .badge-role.collector{background:#fdf3de;color:#8a5a0b}

        /* Alert */
        .form-alert{display:flex;gap:12px;align-items:flex-start;background:var(--danger-bg);border:1px solid #f2c4c0;color:#7d241d;border-radius:var(--radius);padding:14px 16px;margin-bottom:20px;font-weight:500}
        .form-alert i{font-size:18px;margin-top:1px}

        /* Form panel */
        .panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius)}
        .panel-section{display:grid;grid-template-columns:240px 1fr;gap:28px;padding:28px}
        .panel-section + .panel-section{border-top:1px solid var(--line)}
        .section-info h2{font-size:16px;font-weight:700;margin:0 0 4px}
        .section-info p{margin:0;color:var(--muted);font-size:13.5px}

        .field{margin-bottom:18px}
        .field:last-child{margin-bottom:0}
        .field label,.field .label{display:block;font-size:14px;font-weight:600;margin-bottom:6px}
        .field .hint{display:block;font-size:12.5px;color:var(--muted);margin-top:6px}
        .form-control{min-height:46px;border:1px solid #cfd9d2;border-radius:9px;font-size:15px;font-family:inherit;color:var(--ink);padding:10px 13px}
        textarea.form-control{min-height:96px;resize:vertical}
        .form-control:focus{border-color:var(--forest);box-shadow:0 0 0 3px rgba(23,107,64,.16)}
        .form-control::placeholder{color:#9aa8a0}

        /* Role choices */
        .role-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
        .role-choice{position:relative}
        .role-choice input{position:absolute;opacity:0;inset:0;width:100%;height:100%;margin:0;cursor:pointer}
        .role-choice .tile{height:100%;display:block;border:1px solid #cfd9d2;border-radius:10px;padding:14px;background:#fff;transition:border-color .15s,background-color .15s,box-shadow .15s}
        .role-choice .tile i{font-size:20px;color:var(--muted);display:block;margin-bottom:8px}
        .role-choice .tile strong{display:block;font-size:14px;font-weight:600}
        .role-choice .tile span{display:block;font-size:12.5px;color:var(--muted);margin-top:2px;line-height:1.4}
        .role-choice input:hover + .tile{border-color:#9db8a8}
        .role-choice input:checked + .tile{border-color:var(--forest);background:var(--moss);box-shadow:inset 0 0 0 1px var(--forest)}
        .role-choice input:checked + .tile i{color:var(--forest)}
        .role-choice input:focus-visible + .tile{outline:3px solid rgba(23,107,64,.35);outline-offset:2px}
        .role-choice input:disabled{cursor:not-allowed}
        .role-choice input:disabled + .tile{opacity:.6}
        .role-choice input:disabled:checked + .tile{opacity:1}
        .self-note{display:flex;gap:7px;align-items:center;font-size:12.5px;color:var(--muted);margin-top:10px}

        /* Password */
        .pw-toggle{border:1px solid #cfd9d2;border-left:0;background:#fff;color:var(--muted);border-radius:0 9px 9px 0;min-width:46px}
        .pw-toggle:hover{background:var(--moss);color:var(--forest)}
        .pw-toggle:focus-visible{outline:3px solid rgba(23,107,64,.35);outline-offset:-2px}
        .input-group .form-control{border-radius:9px 0 0 9px}
        .strength{display:flex;align-items:center;gap:10px;margin-top:9px}
        .strength-bars{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;flex:1;max-width:220px}
        .strength-bars span{height:5px;border-radius:3px;background:#e3e9e5;transition:background-color .2s}
        .strength-text{font-size:12.5px;color:var(--muted);min-width:70px}
        .match{font-size:12.5px;margin-top:6px;min-height:19px}
        .match.ok{color:var(--forest)}
        .match.bad{color:var(--danger)}

        /* Sticky action bar */
        .action-bar{position:sticky;bottom:0;background:rgba(255,255,255,.96);backdrop-filter:blur(6px);border:1px solid var(--line);border-radius:var(--radius);margin-top:18px;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;box-shadow:0 -6px 20px rgba(28,42,35,.07)}
        .action-bar .note{color:var(--muted);font-size:13.5px}
        .action-bar .buttons{display:flex;gap:10px;margin-left:auto}
        .btn-cancel,.btn-save{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:44px;padding:0 20px;border-radius:9px;font-size:15px;font-weight:600;text-decoration:none;font-family:inherit;cursor:pointer;transition:background-color .15s,border-color .15s}
        .btn-cancel{background:#fff;border:1px solid #cfd9d2;color:var(--ink)}
        .btn-cancel:hover{background:var(--paper);color:var(--ink)}
        .btn-save{background:var(--forest);border:1px solid var(--forest);color:#fff}
        .btn-save:hover{background:var(--forest-dark);border-color:var(--forest-dark);color:#fff}
        .btn-cancel:focus-visible,.btn-save:focus-visible{outline:3px solid rgba(23,107,64,.4);outline-offset:2px}

        @media(max-width:820px){
            .panel-section{grid-template-columns:1fr;gap:18px;padding:22px 20px}
            .role-grid{grid-template-columns:1fr}
            .role-choice .tile{display:grid;grid-template-columns:28px 1fr;column-gap:10px}
            .role-choice .tile i{grid-row:span 2;margin:2px 0 0}
        }
        @media(max-width:576px){
            .topbar{padding:0 16px}
            .page-wrap{padding:20px 14px 110px}
            .profile{gap:14px}
            .avatar{width:56px;height:56px;font-size:21px}
            .profile h1{font-size:22px}
            .action-bar .note{display:none}
            .action-bar .buttons{width:100%}
            .action-bar .buttons>*{flex:1}
        }
        @media(prefers-reduced-motion:reduce){*{transition:none!important}}
    </style>
</head>
<body>
<nav class="topbar">
    <a href="dashboard.php" class="brand"><i class="bi bi-recycle"></i>EcoTrack Admin</a>
</nav>

<main class="page-wrap">
    <div class="crumbs">
        <a href="dashboard.php?page=accounts">Manage accounts</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span>Edit account</span>
    </div>

    <header class="profile">
        <div class="avatar" id="avatar" aria-hidden="true"><?= editAccountEscape($initials) ?></div>
        <div>
            <h1 id="profileName"><?= editAccountEscape($account["fullname"]) ?></h1>
            <div class="profile-sub">
                <span id="profileUser">@<?= editAccountEscape($account["users"]) ?></span>
                <span class="badge-role <?= editAccountEscape($account["role"]) ?>"><i class="bi bi-person-badge"></i><?= editAccountEscape($roleLabels[$account["role"]] ?? ucfirst($account["role"])) ?></span>
                <span>Account #<?= (int)$account["id"] ?></span>
            </div>
        </div>
    </header>

    <?php if ($error !== ""): ?>
        <div class="form-alert" role="alert">
            <i class="bi bi-exclamation-circle-fill"></i>
            <div><?= editAccountEscape($error) ?></div>
        </div>
    <?php endif; ?>

    <form method="post" id="editAccountForm" action="edit_account.php?id=<?= (int)$accountId ?>" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= editAccountEscape($_SESSION["edit_account_csrf"]) ?>">
        <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">

        <div class="panel">
            <!-- Profile -->
            <section class="panel-section">
                <div class="section-info">
                    <h2>Profile</h2>
                    <p>The name and username this person uses to sign in.</p>
                </div>
                <div>
                    <div class="field">
                        <label for="fullname">Full name</label>
                        <input type="text" class="form-control" id="fullname" name="fullname"
                               value="<?= editAccountEscape($account["fullname"]) ?>" maxlength="150" required>
                    </div>
                    <div class="field">
                        <label for="username">Username</label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= editAccountEscape($account["users"]) ?>" maxlength="100" required>
                    </div>
                </div>
            </section>

            <!-- Address -->
            <section class="panel-section">
                <div class="section-info">
                    <h2>Address</h2>
                    <p>Where this person lives or is based.</p>
                </div>
                <div class="field">
                    <label for="address">Home address</label>
                    <textarea class="form-control" id="address" name="address" rows="3" maxlength="255"
                              placeholder="House no., street, barangay, city, province"><?= editAccountEscape($account["address"] ?? "") ?></textarea>
                    <span class="hint"><span id="addressCount">0</span> / 255 characters</span>
                </div>
            </section>

            <!-- Access -->
            <section class="panel-section">
                <div class="section-info">
                    <h2>Account type</h2>
                    <p>Decides what this person can see and do in EcoTrack.</p>
                </div>
                <div>
                    <div class="role-grid" role="radiogroup" aria-label="Account type">
                        <?php foreach ($roleOptions as $value => [$label, $icon, $desc]): ?>
                            <label class="role-choice">
                                <input type="radio" name="role" value="<?= editAccountEscape($value) ?>"
                                       <?= $account["role"] === $value ? "checked" : "" ?>
                                       <?= $isSelf ? "disabled" : "" ?> required>
                                <span class="tile">
                                    <i class="bi <?= editAccountEscape($icon) ?>"></i>
                                    <strong><?= editAccountEscape($label) ?></strong>
                                    <span><?= editAccountEscape($desc) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($isSelf): ?>
                        <input type="hidden" name="role" value="<?= editAccountEscape($account["role"]) ?>">
                        <div class="self-note"><i class="bi bi-lock"></i>You can't change your own account type.</div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Password -->
            <section class="panel-section">
                <div class="section-info">
                    <h2>Password</h2>
                    <p>Leave both fields empty to keep the current password.</p>
                </div>
                <div>
                    <div class="field">
                        <label for="new_password">New password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="new_password" name="new_password"
                                   minlength="8" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters">
                            <button type="button" class="btn pw-toggle" data-target="new_password" aria-label="Show new password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="strength" id="strength" hidden>
                            <div class="strength-bars" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                            <span class="strength-text" id="strengthText" aria-live="polite"></span>
                        </div>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm new password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                   minlength="8" maxlength="72" autocomplete="new-password" placeholder="Type it again">
                            <button type="button" class="btn pw-toggle" data-target="confirm_password" aria-label="Show confirmation password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="match" id="matchMsg" aria-live="polite"></div>
                    </div>
                </div>
            </section>
        </div>

        <div class="action-bar">
            <span class="note">Changes apply as soon as you save.</span>
            <div class="buttons">
                <a href="dashboard.php?page=accounts" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-save"><i class="bi bi-check2-circle"></i>Save changes</button>
            </div>
        </div>
    </form>
</main>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const editForm = document.getElementById("editAccountForm");
    if (!editForm) return;

    const fullname = document.getElementById("fullname");
    const username = document.getElementById("username");
    const address = document.getElementById("address");
    const newPw = document.getElementById("new_password");
    const confirmPw = document.getElementById("confirm_password");

    /* Live profile header */
    function initialsOf(name) {
        const parts = name.trim().split(/\s+/).filter(Boolean).slice(0, 2);
        return parts.length ? parts.map(p => p.charAt(0).toUpperCase()).join("") : "?";
    }
    fullname.addEventListener("input", function () {
        document.getElementById("profileName").textContent = fullname.value.trim() || "Unnamed account";
        document.getElementById("avatar").textContent = initialsOf(fullname.value);
    });
    username.addEventListener("input", function () {
        document.getElementById("profileUser").textContent = "@" + username.value.trim();
    });

    /* Address counter */
    const addressCount = document.getElementById("addressCount");
    function updateAddressCount() { addressCount.textContent = address.value.length; }
    address.addEventListener("input", updateAddressCount);
    updateAddressCount();

    /* Show / hide password */
    document.querySelectorAll(".pw-toggle").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const input = document.getElementById(btn.dataset.target);
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            btn.querySelector("i").className = show ? "bi bi-eye-slash" : "bi bi-eye";
            btn.setAttribute("aria-label", (show ? "Hide " : "Show ") + (btn.dataset.target === "new_password" ? "new password" : "confirmation password"));
        });
    });

    /* Password strength + match */
    const strengthBox = document.getElementById("strength");
    const strengthText = document.getElementById("strengthText");
    const bars = strengthBox.querySelectorAll(".strength-bars span");
    const matchMsg = document.getElementById("matchMsg");
    const levels = [
        { label: "Too short", color: "#b3392f" },
        { label: "Weak",      color: "#d9822b" },
        { label: "Okay",      color: "#c9a227" },
        { label: "Strong",    color: "#176b40" }
    ];

    function scorePassword(pw) {
        if (pw.length < 8) return 0;
        let score = 1;
        if (pw.length >= 12) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/\d/.test(pw) && /[^A-Za-z0-9]/.test(pw)) score++;
        return Math.min(score, 3);
    }
    function updateStrength() {
        if (!newPw.value) { strengthBox.hidden = true; return; }
        strengthBox.hidden = false;
        const level = scorePassword(newPw.value);
        bars.forEach(function (bar, i) {
            bar.style.background = i <= level ? levels[level].color : "";
        });
        strengthText.textContent = levels[level].label;
    }
    function updateMatch() {
        if (!confirmPw.value) { matchMsg.textContent = ""; matchMsg.className = "match"; return; }
        const same = newPw.value === confirmPw.value;
        matchMsg.textContent = same ? "Passwords match." : "Passwords don't match yet.";
        matchMsg.className = "match " + (same ? "ok" : "bad");
    }
    newPw.addEventListener("input", function () { updateStrength(); updateMatch(); });
    confirmPw.addEventListener("input", updateMatch);

    /* Confirm before saving */
    editForm.addEventListener("submit", function (event) {
        event.preventDefault();

        if (newPw.value !== "" || confirmPw.value !== "") {
            if (newPw.value.length < 8) {
                Swal.fire({ icon: "error", title: "Password is too short", text: "Use at least 8 characters for the new password.", confirmButtonColor: "#176b40" });
                newPw.focus();
                return;
            }
            if (newPw.value !== confirmPw.value) {
                Swal.fire({ icon: "error", title: "Passwords don't match", text: "Type the same password in both fields.", confirmButtonColor: "#176b40" });
                confirmPw.focus();
                return;
            }
        }

        Swal.fire({
            title: "Save changes?",
            text: newPw.value !== ""
                ? "This updates the account details and sets a new password."
                : "This updates the account details.",
            icon: "question",
            showCancelButton: true,
            confirmButtonColor: "#176b40",
            cancelButtonColor: "#6c757d",
            confirmButtonText: "Save changes",
            cancelButtonText: "Cancel",
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) {
                editForm.submit();
            }
        });
    });
});
</script>

</body>
</html>
