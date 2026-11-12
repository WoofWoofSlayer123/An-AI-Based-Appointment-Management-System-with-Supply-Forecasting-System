<?php
session_start();

require_once __DIR__ . "/connection.php";
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/notifications_helper.php";
require_once __DIR__ . "/email_helper.php";

// Only front-desk staff can confirm or reject appointments.
if (($_SESSION["role"] ?? "") !== "staff") {
    header("Location: staff_dashboard.php");
    exit;
}

$username = $_SESSION["username"] ?? "";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: staff_dashboard.php");
    exit;
}

$id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
$newStatus = $_POST["status"] ?? "";
$assignedDoctor = trim($_POST["assigned_doctor"] ?? "");
$rejectionReason = trim($_POST["rejection_reason"] ?? "");

if (!$id || !in_array($newStatus, ["Confirmed", "Rejected"], true)) {
    header("Location: staff_dashboard.php?type=error&msg=" . urlencode("Invalid appointment request."));
    exit;
}

// Get the staff member's assigned health center.
$stmt = $conn->prepare(
    "SELECT health_center_id FROM patients WHERE username = ?"
);
$stmt->bind_param("s", $username);
$stmt->execute();
$staffRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$centerId = (int) ($staffRow["health_center_id"] ?? 0);

if ($centerId <= 0) {
    header("Location: staff_dashboard.php?type=error&msg=" . urlencode("Your account has no assigned health center."));
    exit;
}

// Only process appointments belonging to this center that are still pending.
$stmt = $conn->prepare("
    SELECT *
    FROM appointments
    WHERE id = ?
      AND health_center_id = ?
      AND status = 'Pending'
");
$stmt->bind_param("ii", $id, $centerId);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
    header("Location: staff_dashboard.php?type=error&msg=" . urlencode("Appointment not found or already processed."));
    exit;
}

$doctorName = "";
$assignedDoctor = $assignedDoctor ?: null;

// Validate the doctor before confirming.
if ($newStatus === "Confirmed") {
    if (!$assignedDoctor) {
        header("Location: staff_dashboard.php?tab=home&type=error&msg=" . urlencode("Please assign a doctor before confirming."));
        exit;
    }

    $stmt = $conn->prepare("
        SELECT patients.full_name
        FROM users
        JOIN patients ON patients.username = users.username
        WHERE users.username = ?
          AND users.role = 'doctor'
          AND patients.health_center_id = ?
    ");
    $stmt->bind_param("si", $assignedDoctor, $centerId);
    $stmt->execute();
    $doctorRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doctorRow) {
        header("Location: staff_dashboard.php?tab=home&type=error&msg=" . urlencode("That doctor is not assigned to this health center."));
        exit;
    }

    $doctorName = $doctorRow["full_name"] ?: $assignedDoctor;
}
if ($newStatus === "Rejected") {
    if ($rejectionReason === "") {
        header(
            "Location: staff_dashboard.php?tab=home&type=error&msg="
            . urlencode("Please provide a reason for rejecting the appointment.")
        );
        exit;
    }

    if (mb_strlen($rejectionReason, "UTF-8") > 1000) {
        header(
            "Location: staff_dashboard.php?tab=home&type=error&msg="
            . urlencode("The rejection reason must not exceed 1000 characters.")
        );
        exit;
    }
}

// Update only if the appointment is still pending and belongs to this center.
// This also helps prevent two staff actions from processing the same request.

if ($newStatus === "Confirmed") {
    $stmt = $conn->prepare("
        UPDATE appointments
        SET status = 'Confirmed',
            assigned_doctor = ?
        WHERE id = ?
          AND health_center_id = ?
          AND status = 'Pending'
    ");

    $stmt->bind_param("sii", $assignedDoctor, $id, $centerId);

} else {
    $stmt = $conn->prepare("
        UPDATE appointments
        SET status = 'Rejected',
            rejection_reason = ?
        WHERE id = ?
          AND health_center_id = ?
          AND status = 'Pending'
    ");

    $stmt->bind_param(
        "sii",
        $rejectionReason,
        $id,
        $centerId
    );
}

$stmt->execute();
$updated = $stmt->affected_rows;
$stmt->close();


if ($updated !== 1) {
    header("Location: staff_dashboard.php?type=error&msg=" . urlencode("The appointment could not be updated. It may already have been processed."));
    exit;
}

$appointmentDate = date(
    "M j, Y",
    strtotime($appt["appointment_date"])
);

$appointmentTime = date(
    "g:i A",
    strtotime($appt["appointment_time"])
);

$patientDashboardUrl = "patient_dashboard.php?tab=appointments";
$staffDashboardUrl = "staff_dashboard.php?tab=schedule";
$doctorDashboardUrl = "doctor_dashboard.php";

// Notify the patient.
if ($newStatus === "Confirmed") {
    $patientMessage =
        "Your " . $appt["service"] .
        " appointment on " . $appointmentDate .
        " at " . $appointmentTime .
        " has been confirmed with Dr. " . $doctorName . ".";

    notify_with_email(
        $conn,
        $appt["username"],
        $patientMessage,
        $id,
        $patientDashboardUrl,
        "View Appointment",
        "Appointment Confirmed"
    );
} else {
    $patientMessage =
        "Your " . $appt["service"] .
        " appointment request on " . $appointmentDate .
        " at " . $appointmentTime .
        " was not approved. Please contact the health center or submit a new appointment request.";

    notify_with_email(
        $conn,
        $appt["username"],
        $patientMessage,
        $id,
        $patientDashboardUrl,
        "View Appointments",
        "Appointment Request Rejected"
    );
}

// Notify the assigned doctor when an appointment is confirmed.
if ($newStatus === "Confirmed") {
    $patientNameStmt = $conn->prepare(
        "SELECT full_name FROM patients WHERE username = ?"
    );
    $patientNameStmt->bind_param("s", $appt["username"]);
    $patientNameStmt->execute();
    $patientRow = $patientNameStmt->get_result()->fetch_assoc();
    $patientNameStmt->close();

    $patientName = $patientRow["full_name"] ?? "A patient";

    $doctorMessage =
        "You have been assigned an appointment with " . $patientName .
        " for " . $appt["service"] .
        " on " . $appointmentDate .
        " at " . $appointmentTime . ".";

    notify_with_email(
        $conn,
        $assignedDoctor,
        $doctorMessage,
        $id,
        $doctorDashboardUrl,
        "View Schedule",
        "New Patient Assignment"
    );
}

header(
    "Location: staff_dashboard.php?tab=home&type=success&msg=" .
    urlencode("Appointment " . $newStatus . ".")
);
exit;
?>