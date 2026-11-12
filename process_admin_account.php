
<?php
session_start();

require_once __DIR__ . "/connection.php";
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/email_helper.php";
require_once __DIR__ . "/notifications_helper.php";

if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: staff_dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: admin_dashboard.php");
    exit;
}

$action = $_POST["action"] ?? "";

function admin_account_redirect($type, $message)
{
    header(
        "Location: admin_dashboard.php?tab=accounts&type=" .
        urlencode($type) . "&msg=" . urlencode($message)
    );
    exit;
}

if ($action === "approve") {
    $targetUsername = trim($_POST["username"] ?? "");
    $newRole = $_POST["new_role"] ?? "";
    $centerId = filter_input(INPUT_POST, "health_center_id", FILTER_VALIDATE_INT);

    if (
        $targetUsername === "" ||
        !in_array($newRole, ["staff", "doctor"], true) ||
        !$centerId ||
        $centerId < 1
    ) {
        admin_account_redirect("error", "Invalid account approval details.");
    }

    // Verify the selected health center exists.
    $stmt = $conn->prepare(
        "SELECT name FROM health_centers WHERE id = ?"
    );
    $stmt->bind_param("i", $centerId);
    $stmt->execute();
    $center = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$center) {
        admin_account_redirect("error", "The selected health center does not exist.");
    }

    // Retrieve only an account that is still awaiting approval.
    $stmt = $conn->prepare("
        SELECT users.username, patients.full_name
        FROM users
        JOIN patients ON patients.username = users.username
        WHERE users.username = ?
          AND users.role = 'patient'
          AND patients.pending_staff = 1
    ");
    $stmt->bind_param("s", $targetUsername);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        admin_account_redirect(
            "error",
            "Request not found or already resolved."
        );
    }

    // Update the account only while the request is still pending.
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            UPDATE users
            SET role = ?
            WHERE username = ?
              AND role = 'patient'
        ");
        $stmt->bind_param("ss", $newRole, $targetUsername);
        $stmt->execute();
        $roleUpdated = $stmt->affected_rows;
        $stmt->close();

        if ($roleUpdated !== 1) {
            throw new RuntimeException("The account role could not be updated.");
        }

        $stmt = $conn->prepare("
            UPDATE patients
            SET health_center_id = ?,
                pending_staff = 0
            WHERE username = ?
              AND pending_staff = 1
        ");
        $stmt->bind_param("is", $centerId, $targetUsername);
        $stmt->execute();
        $patientUpdated = $stmt->affected_rows;
        $stmt->close();

        if ($patientUpdated !== 1) {
            throw new RuntimeException("The pending account request could not be updated.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Admin account approval failed: " . $e->getMessage());

        admin_account_redirect(
            "error",
            "The account could not be approved. Please try again."
        );
    }

    // Send the notification after the account update succeeds.
    $roleLabel = ucfirst($newRole);
    $centerName = $center["name"];

    $message =
        "Your request to become a " . $roleLabel .
        " has been approved. Your assigned health center is " .
        $centerName . ".";

    try {
        notify_with_email(
            $conn,
            $targetUsername,
            $message,
            null,
            "patient_dashboard.php?tab=account",
            "View Account",
            "CarePulse Account Approved"
        );
    } catch (Throwable $e) {
        // A notification failure should not undo a successful approval.
        error_log("Approval notification failed: " . $e->getMessage());
    }

    admin_account_redirect(
        "success",
        "Account approved as " . $roleLabel . "."
    );
}

if ($action === "reject") {
    $targetUsername = trim($_POST["username"] ?? "");

    if ($targetUsername === "") {
        admin_account_redirect("error", "Invalid account request.");
    }

    // Only reject a regular patient account with a pending staff request.
    $stmt = $conn->prepare("
        SELECT username
        FROM users
        WHERE username = ?
          AND role = 'patient'
    ");
    $stmt->bind_param("s", $targetUsername);
    $stmt->execute();
    $valid = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$valid) {
        admin_account_redirect(
            "error",
            "Request not found or already resolved."
        );
    }

    $stmt = $conn->prepare("
        UPDATE patients
        SET pending_staff = 0
        WHERE username = ?
          AND pending_staff = 1
    ");
    $stmt->bind_param("s", $targetUsername);
    $stmt->execute();
    $rejected = $stmt->affected_rows;
    $stmt->close();

    if ($rejected !== 1) {
        admin_account_redirect(
            "error",
            "Request not found or already resolved."
        );
    }

    $message =
        "Your request to become a staff member or doctor was not approved. " .
        "Your account remains a regular patient account.";

    try {
        notify_with_email(
            $conn,
            $targetUsername,
            $message,
            null,
            "patient_dashboard.php?tab=account",
            "View Account",
            "CarePulse Account Request Update"
        );
    } catch (Throwable $e) {
        error_log("Rejection notification failed: " . $e->getMessage());
    }

    admin_account_redirect("success", "Request rejected.");
}

if ($action === "move") {
    $targetUsername = trim($_POST["username"] ?? "");
    $newCenterId = filter_input(INPUT_POST, "health_center_id", FILTER_VALIDATE_INT);

    if ($targetUsername === "" || !$newCenterId || $newCenterId < 1) {
        admin_account_redirect("error", "Invalid reassignment details.");
    }

    // Verify the destination center exists.
    $stmt = $conn->prepare(
        "SELECT name FROM health_centers WHERE id = ?"
    );
    $stmt->bind_param("i", $newCenterId);
    $stmt->execute();
    $center = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$center) {
        admin_account_redirect("error", "The selected health center does not exist.");
    }

    // Confirm the target is a staff member or doctor.
    $stmt = $conn->prepare("
        SELECT users.role, patients.health_center_id
        FROM users
        JOIN patients ON patients.username = users.username
        WHERE users.username = ?
          AND users.role IN ('staff', 'doctor')
    ");
    $stmt->bind_param("s", $targetUsername);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        admin_account_redirect(
            "error",
            "That account isn't a staff or doctor account."
        );
    }

    $stmt = $conn->prepare("
        UPDATE patients
        SET health_center_id = ?
        WHERE username = ?
    ");
    $stmt->bind_param("is", $newCenterId, $targetUsername);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated < 0) {
        admin_account_redirect("error", "The account could not be moved.");
    }

    admin_account_redirect("success", "Account moved.");
}

header("Location: admin_dashboard.php");
exit;
?>