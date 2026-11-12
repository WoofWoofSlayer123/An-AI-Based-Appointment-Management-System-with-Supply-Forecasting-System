<?php
session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: staff_dashboard.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT appointments.appointment_date, appointments.appointment_time, patients.full_name,
           patients.patient_id, health_centers.name AS center_name, appointments.service, appointments.status
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    JOIN health_centers ON health_centers.id = appointments.health_center_id
    ORDER BY appointments.appointment_date DESC, appointments.appointment_time DESC
");
$stmt->execute();
$result = $stmt->get_result();

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=district_appointments_" . date("Y-m-d") . ".csv");

$out = fopen("php://output", "w");
fputcsv($out, ["Date", "Time", "Patient", "Patient ID", "Health Center", "Service", "Status"]);
while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row["appointment_date"], $row["appointment_time"], $row["full_name"],
        $row["patient_id"], $row["center_name"], $row["service"], $row["status"]
    ]);
}
fclose($out);
exit;
?>
