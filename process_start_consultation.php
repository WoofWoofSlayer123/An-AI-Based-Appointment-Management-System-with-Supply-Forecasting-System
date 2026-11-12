<?php
session_start();

require_once "connection.php";
require_once "auth_check.php";

// Only doctors may start consultations.
if (
    !isset($_SESSION["role"]) ||
    strtolower($_SESSION["role"]) !== "doctor"
) {
    header("Location: login.php");
    exit;
}

// Only accept form submissions.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: doctor_dashboard.php");
    exit;
}

$doctorUsername = $_SESSION["username"] ?? "";
$appointmentId = filter_input(INPUT_POST, "appointment_id", FILTER_VALIDATE_INT);

if (!$appointmentId || $doctorUsername === "") {
    header("Location: doctor_dashboard.php?type=error");
    exit;
}

// Start only an appointment assigned to this doctor,
// scheduled for today, and still Confirmed.
$stmt = $conn->prepare("
    UPDATE appointments
    SET status = 'In consultation'
    WHERE id = ?
      AND assigned_doctor = ?
      AND status = 'Confirmed'
      AND appointment_date = CURDATE()
");

$stmt->bind_param("is", $appointmentId, $doctorUsername);
$stmt->execute();

$updated = $stmt->affected_rows;
$stmt->close();

if ($updated === 1) {
    header(
        "Location: view_patient.php?id=" .
        $appointmentId . "&start=success"
    );
    exit;
}

// No row was changed: the appointment may be invalid,
// assigned to another doctor, not scheduled for today,
// or no longer Confirmed.
header(
    "Location: view_patient.php?id=" .
    $appointmentId . "&start=error"
);
exit;
?>
