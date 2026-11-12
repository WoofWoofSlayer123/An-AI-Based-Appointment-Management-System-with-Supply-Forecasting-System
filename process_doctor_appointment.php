<?php
session_start();
include "connection.php";
include "auth_check.php";
include "notifications_helper.php";

if ($_SESSION["role"] !== "doctor") {
    header("Location: staff_dashboard.php");
    exit;
}

$doctorUsername = $_SESSION["username"];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: doctor_dashboard.php");
    exit;
}

$action = $_POST["action"] ?? "";

if ($action === "no_show") {
    $apptId = $_POST["id"];

    // Same atomic-claim pattern as process_visit.php — only succeeds if the
    // appointment is still Confirmed and actually assigned to this doctor.
    $stmt = $conn->prepare("UPDATE appointments SET status = 'No-show' WHERE id = ? AND assigned_doctor = ? AND status = 'Confirmed'");
    $stmt->bind_param("is", $apptId, $doctorUsername);
    $stmt->execute();
    $claimed = $stmt->affected_rows === 1;
    $stmt->close();

    if (!$claimed) {
        header("Location: doctor_dashboard.php?tab=schedule&type=error&msg=" . urlencode("Appointment not found, not assigned to you, or already resolved."));
        exit;
    }

    $stmt = $conn->prepare("SELECT username, service, appointment_date FROM appointments WHERE id = ?");
    $stmt->bind_param("i", $apptId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    create_notification(
        $conn, $appt["username"],
        "You were marked as a no-show for your " . $appt["service"] . " appointment on " . date("M j, Y", strtotime($appt["appointment_date"])) . ". Please contact the health center to reschedule.",
        $apptId
    );

    header("Location: doctor_dashboard.php?tab=schedule&type=success&msg=" . urlencode("Marked as no-show."));
    exit;
}

header("Location: doctor_dashboard.php");
exit;
?>
