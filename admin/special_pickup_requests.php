<?php

// This file is included inside dashboard.php.
// Do not add another HTML, HEAD or BODY tag.

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/send_email.php";

$message = "";
$message_class = "";

// CSRF TOKEN
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// HANDLE ADMIN ACTIONS
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {

        $message = "Invalid request. Please try again.";
        $message_class = "error";

    } else {

        $request_id = (int)($_POST["request_id"] ?? 0);


// ACCEPT OR DENY + EMAIL NOTIFICATION
if (isset($_POST["decision"])) {

    $decision = $_POST["decision"];

    if (
        $request_id > 0 &&
        in_array($decision, ["approved", "rejected"], true)
    ) {

        // GET REQUEST AND RESIDENT EMAIL
        $lookup = $conn->prepare("
            SELECT
                cs.*,
                u.fullname,
                u.email,
                u.email_verified
            FROM collection_schedules cs
            LEFT JOIN users u ON cs.user_id = u.id
            WHERE cs.id = ?
              AND cs.status = 'pending'
            LIMIT 1
        ");

        if (!$lookup) {
            $message = "Database error: " . $conn->error;
            $message_class = "error";
        } else {

            $lookup->bind_param("i", $request_id);
            $lookup->execute();

            $result = $lookup->get_result();
            $request = $result->fetch_assoc();

            $lookup->close();

            if (!$request) {

                $message = "Request already reviewed or not found.";
                $message_class = "warning";

            } else {

                // UPDATE REQUEST STATUS
                $stmt = $conn->prepare("
                    UPDATE collection_schedules
                    SET status = ?
                    WHERE id = ?
                      AND status = 'pending'
                ");

                if ($stmt) {

                    $stmt->bind_param("si", $decision, $request_id);

                    if ($stmt->execute() && $stmt->affected_rows > 0) {

                        $status_text = ucfirst($decision);

                        $message = "Request successfully " .
                            ($decision === "approved"
                                ? "approved!"
                                : "rejected!");

                        $message_class = "success";

                        // SEND EMAIL ONLY TO VERIFIED EMAIL ADDRESSES
                        if (
                            !empty($request["email"]) &&
                            (int)$request["email_verified"] === 1
                        ) {

                            $resident_name = htmlspecialchars(
                                $request["fullname"] ?? "Resident",
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            $date = htmlspecialchars(
                                $request["requested_date"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            $time = htmlspecialchars(
                                $request["requested_time"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            $location = htmlspecialchars(
                                $request["location_address"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            $reason = htmlspecialchars(
                                $request["pickup_reason"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            );

                            if ($decision === "approved") {

                                $subject = "Special Pickup Request Approved - EcoTrack";

                                $heading = "Your Pickup Request Has Been Approved!";

                                $status_message = "
                                    Good news! Your special pickup request
                                    has been approved by the EcoTrack administrator.
                                ";

                                $status_color = "#198754";

                            } else {

                                $subject = "Special Pickup Request Rejected - EcoTrack";

                                $heading = "Your Pickup Request Has Been Rejected";

                                $status_message = "
                                    Unfortunately, your special pickup request
                                    has been rejected by the EcoTrack administrator.
                                    Please contact the administration if you need assistance.
                                ";

                                $status_color = "#dc3545";
                            }

                            $body = "
                                <div style='font-family:Arial,sans-serif;
                                            max-width:600px;
                                            margin:auto;
                                            padding:25px;
                                            border:1px solid #e5e5e5;
                                            border-radius:10px;'>

                                    <h2 style='color:#198754;'>
                                        EcoTrack
                                    </h2>

                                    <h3 style='color:{$status_color};'>
                                        {$heading}
                                    </h3>

                                    <p>Hello {$resident_name},</p>

                                    <p>{$status_message}</p>

                                    <h4>Pickup Request Details</h4>

                                    <p><strong>Date:</strong> {$date}</p>
                                    <p><strong>Time:</strong> {$time}</p>
                                    <p><strong>Location:</strong> {$location}</p>
                                    <p><strong>Reason:</strong> {$reason}</p>

                                    <p>
                                        <strong>Status:</strong>
                                        <span style='color:{$status_color};
                                                     font-weight:bold;'>
                                            {$status_text}
                                        </span>
                                    </p>

                                    <hr>

                                    <p style='color:#777;font-size:12px;'>
                                        This is an automated notification
                                        from the EcoTrack Waste Collection
                                        Management System.
                                    </p>

                                </div>
                            ";

                            if (!sendEmail(
                                $request["email"],
                                $subject,
                                $body
                            )) {
                                error_log(
                                    "Pickup notification email failed for request ID: "
                                    . $request_id
                                );

                                $message .= " However, the notification email could not be sent.";
                                $message_class = "warning";
                            }

                        } else {

                            $message .= " No notification was sent because the resident's email is not verified.";
                        }

                    } else {

                        $message = "Request already reviewed or not found.";
                        $message_class = "warning";
                    }

                    $stmt->close();

                } else {

                    $message = "Database error: " . $conn->error;
                    $message_class = "error";
                }
            }
        }

    } else {

        $message = "Invalid request.";
        $message_class = "error";
    }
}


        // DELETE REQUEST
        elseif (isset($_POST["delete_request"])) {

            if ($request_id > 0) {

                $stmt = $conn->prepare("
             DELETE FROM collection_schedules
             WHERE id = ?
            ");

                if ($stmt) {

                    $stmt->bind_param("i", $request_id);

                    if ($stmt->execute() && $stmt->affected_rows > 0) {

                        $message = "Pickup request successfully deleted!";
                        $message_class = "success";

                    } else {

                        $message = "Request not found or already deleted.";
                        $message_class = "warning";

                    }

                    $stmt->close();

                } else {

                    $message = "Database error: " . $conn->error;
                    $message_class = "error";

                }

            } else {

                $message = "Invalid request.";
                $message_class = "error";

            }

        } else {

            $message = "Invalid action.";
            $message_class = "error";

        }
    }
}

// GET ALL PICKUP REQUESTS
$requests_result = $conn->query("
    SELECT *
    FROM collection_schedules
    ORDER BY id DESC
");

?>

<style>

.special-pickup-page {
    width: 100%;
    min-width: 0;
    color: #333;
    font-family: Arial, sans-serif;
}

.special-pickup-page .pickup-card {
    background: white;
    width: 100%;
    padding: 25px 30px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e8eeea;
}

.special-pickup-page .pickup-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #eee;
    gap: 10px;
}

.special-pickup-page h2 {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 26px;
    color: #183d2b;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: 0;
    margin: 0 0 7px;
}

.special-pickup-page .pickup-subtitle {
    color: #78877e;
    font-size: 14px;
    margin: 0;
}

.special-pickup-page .message {
    padding: 12px;
    margin-bottom: 15px;
    border-radius: 5px;
}

.special-pickup-page .success {
    background: #d4edda;
    color: #155724;
}

.special-pickup-page .warning {
    background: #fff3cd;
    color: #856404;
}

.special-pickup-page .error {
    background: #f8d7da;
    color: #721c24;
}

.special-pickup-page .table-responsive {
    overflow-x: auto;
    width: 100%;
}

.special-pickup-page table {
    width: 100%;
    min-width: 800px;
    border-collapse: collapse;
    text-align: left;
    background: white;
}

.special-pickup-page th,
.special-pickup-page td {
    padding: 14px 12px;
    border-bottom: 1px solid #ddd;
    vertical-align: middle;
}

.special-pickup-page th {
    background: #e8f5e9;
    color: #2e7d32;
    white-space: nowrap;
}

.special-pickup-page tbody tr:hover {
    background: #fafdfb;
}

.special-pickup-page .badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    display: inline-block;
}

.special-pickup-page .pending {
    background: #fff3cd;
    color: #856404;
}

.special-pickup-page .approved {
    background: #d4edda;
    color: #155724;
}

.special-pickup-page .rejected {
    background: #f8d7da;
    color: #721c24;
}

.special-pickup-page .actions {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.special-pickup-page .actions form {
    margin: 0;
}

.special-pickup-page button {
    border: none;
    padding: 8px 13px;
    border-radius: 5px;
    color: white;
    cursor: pointer;
    font-weight: bold;
    white-space: nowrap;
}

.special-pickup-page .accept {
    background: #2e7d32;
}

.special-pickup-page .deny {
    background: #c62828;
}

.special-pickup-page .delete {
    background: #e53935;
}

.special-pickup-page .accept:hover {
    background: #256629;
}

.special-pickup-page .deny:hover {
    background: #a91f1f;
}

.special-pickup-page .delete:hover {
    background: #b71c1c;
}

.special-pickup-page .reviewed {
    color: #777;
    font-size: 13px;
    white-space: nowrap;
}

.special-pickup-page .empty {
    text-align: center;
    padding: 25px;
    color: #777;
}

@media(max-width: 600px) {

    .special-pickup-page .pickup-card {
        padding: 15px;
    }

    .special-pickup-page .pickup-header {
        align-items: flex-start;
    }

    .special-pickup-page h2 {
        font-size: 21px;
    }

}

</style>


<div class="special-pickup-page">

    <div class="pickup-card">

        <!-- HEADER -->

        <div class="pickup-header">

            <div>

                <h2>
                    Pickup Requests & Admin Decisions
                </h2>

                <p class="pickup-subtitle">
                    Review, approve, reject, and manage special pickup requests.
                </p>

            </div>

        </div>


        <!-- SUCCESS OR ERROR MESSAGE -->

        <?php if (!empty($message)): ?>

            <div class="message <?= htmlspecialchars($message_class) ?>">

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <!-- PICKUP REQUEST TABLE -->

        <div class="table-responsive">

            <table>

                <thead>

                    <tr>

                        <th>Date & Time</th>

                        <th>Reason</th>

                        <th>Location</th>

                        <th>Status</th>

                        <th>Action</th>

                    </tr>

                </thead>


                <tbody>

                <?php if ($requests_result && $requests_result->num_rows > 0): ?>

                    <?php while ($row = $requests_result->fetch_assoc()): ?>

                        <?php

                            $status = strtolower($row["status"] ?? "pending");

                            $badge_class = "pending";

                            if (
                         $status === "approved" ||
                         $status === "scheduled" ||
                         $status === "completed"
                        ) {
                        $badge_class = "approved";
                        } elseif ($status === "rejected") {
    $badge_class = "rejected";
}
                        ?>


                        <tr>

                            <!-- DATE AND TIME -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars($row["requested_date"] ?? "") ?>

                                </strong>

                                <br>

                                <small>

                                    <?= htmlspecialchars($row["requested_time"] ?? "") ?>

                                </small>

                            </td>


                            <!-- REASON -->

                            <td>

                                <?= htmlspecialchars($row["pickup_reason"] ?? "") ?>

                            </td>


                            <!-- LOCATION -->

                            <td>

                                <?= htmlspecialchars($row["location_address"] ?? "") ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span class="badge <?= $badge_class ?>">

                                    <?= htmlspecialchars(strtoupper($status)) ?>

                                </span>

                            </td>


                            <!-- ADMIN ACTION -->
                            <td>
                                <div class="actions">

                                    <?php if ($status === "pending"): ?>

                                        <!-- ACCEPT -->
                                        <form method="POST"
                                            action="dashboard.php?page=special_pickup_requests"
                                            class="pickup-action-form"
                                            data-title="Accept this request?"
                                            data-text="Are you sure you want to approve this pickup request?"
                                            data-icon="question"
                                            data-confirm="Yes, accept">

                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                                            <input type="hidden" name="request_id"
                                                value="<?= (int)$row["id"] ?>">

                                            <button type="submit" name="decision"
                                                value="approved" class="accept">
                                                Accept
                                            </button>
                                        </form>

                                        <!-- DENY -->
                                        <form method="POST"
                                            action="dashboard.php?page=special_pickup_requests"
                                            class="pickup-action-form"
                                            data-title="Deny this request?"
                                            data-text="Are you sure you want to reject this pickup request?"
                                            data-icon="warning"
                                            data-confirm="Yes, deny">

                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                                            <input type="hidden" name="request_id"
                                                value="<?= (int)$row["id"] ?>">

                                            <button type="submit" name="decision"
                                                value="rejected" class="deny">
                                                Deny
                                            </button>
                                        </form>

                                    <?php else: ?>

                                        <span class="reviewed">
                                            Already Reviewed
                                        </span>

                                        <!-- DELETE: ONLY APPROVED OR REJECTED tsaka completed -->
                                       <?php if (in_array($status, ["approved", "rejected", "completed"], true)): ?>

                                            <form method="POST"
                                                action="dashboard.php?page=special_pickup_requests"
                                                class="pickup-action-form"
                                                data-title="Delete this request?"
                                                data-text="This pickup request will be permanently deleted. This action cannot be undone."
                                                data-icon="warning"
                                                data-confirm="Yes, delete it"
                                                data-confirm-color="#dc3545">

                                                <input type="hidden" name="csrf_token"
                                                    value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                                                <input type="hidden" name="request_id"
                                                    value="<?= (int)$row["id"] ?>">

                                                <button type="submit" name="delete_request"
                                                    value="1" class="delete">
                                                    Delete
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                </div>
                            </td>

                        </tr>


                    <?php endwhile; ?>


                <?php else: ?>


                    <tr>

                        <td colspan="5" class="empty">

                            No pickup requests found.

                        </td>

                    </tr>


                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>

document.addEventListener("DOMContentLoaded", function () {

    document.querySelectorAll(".pickup-action-form").forEach(function (form) {

        form.addEventListener("submit", function (event) {

            event.preventDefault();

            // Remember which button was clicked
            const submitter = event.submitter;

            const title = form.dataset.title;
            const message = form.dataset.text;
            const icon = form.dataset.icon;
            const confirmText = form.dataset.confirm;
            const confirmColor = form.dataset.confirmColor || "#198754";

            Swal.fire({
                title: title,
                text: message,
                icon: icon,
                showCancelButton: true,
                confirmButtonColor: confirmColor,
                cancelButtonColor: "#6c757d",
                confirmButtonText: confirmText,
                cancelButtonText: "Cancel",
                reverseButtons: true
            }).then(function (result) {

                if (result.isConfirmed) {

                    // Include the clicked button's name and value
                    if (submitter && submitter.name) {
                        const hiddenInput = document.createElement("input");

                        hiddenInput.type = "hidden";
                        hiddenInput.name = submitter.name;
                        hiddenInput.value = submitter.value;

                        form.appendChild(hiddenInput);
                    }

                    // Submit without triggering the confirmation again
                    HTMLFormElement.prototype.submit.call(form);
                }

            });

        });

    });

});

</script>
