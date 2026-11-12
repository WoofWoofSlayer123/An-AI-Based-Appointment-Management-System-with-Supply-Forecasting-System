<?php
session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "staff") {
    header("Location: staff_dashboard.php");
    exit;
}

$username = $_SESSION["username"];
$stmt = $conn->prepare("SELECT health_center_id FROM patients WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$centerId = $stmt->get_result()->fetch_assoc()["health_center_id"];
$stmt->close();

$stmt = $conn->prepare("
    SELECT appointments.appointment_date, appointments.appointment_time, patients.full_name,
           patients.patient_id, appointments.service, appointments.status
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.health_center_id = ?
    ORDER BY appointments.appointment_date DESC, appointments.appointment_time DESC
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$result = $stmt->get_result();

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=appointments_" . date("Y-m-d") . ".csv");

$out = fopen("php://output", "w");
fputcsv($out, ["Date", "Time", "Patient", "Patient ID", "Service", "Status"]);
while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row["appointment_date"], $row["appointment_time"], $row["full_name"],
        $row["patient_id"], $row["service"], $row["status"]
    ]);
}
fclose($out);
exit;
?>
