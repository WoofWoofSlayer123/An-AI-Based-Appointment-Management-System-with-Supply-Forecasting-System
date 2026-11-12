<?php
session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "doctor") {
    header("Location: staff_dashboard.php");
    exit;
}

$staffUsername = $_SESSION["username"];

include "notifications_helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: doctor_dashboard.php");
    exit;
}

$diagnosisId = $_POST["diagnosis_id"];
$addendumText = trim($_POST["addendum"]);
$patientUsername = $_POST["patient_username"] ?? null; // for redirecting back to the right page

if ($addendumText === "") {
    header("Location: view_patient.php?id=" . ($_POST["return_appt_id"] ?? "") . "&type=error&msg=" . urlencode("Addendum can't be empty."));
    exit;
}

// Confirm the diagnosis actually exists — any doctor can add a correction to
// any diagnosis, since a patient may see a different doctor on a follow-up
// visit who needs to flag something about an earlier one. The original
// diagnosis text itself is never touched.
$stmt = $conn->prepare("SELECT id, username AS patient_username FROM diagnoses WHERE id = ?");
$stmt->bind_param("i", $diagnosisId);
$stmt->execute();
$diagnosisRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$diagnosisRow) {
    header("Location: doctor_dashboard.php?type=error&msg=" . urlencode("Diagnosis not found."));
    exit;
}

$stmt = $conn->prepare("INSERT INTO diagnosis_addenda (diagnosis_id, staff_username, addendum) VALUES (?, ?, ?)");
$stmt->bind_param("iss", $diagnosisId, $staffUsername, $addendumText);
$stmt->execute();
$stmt->close();

create_notification(
    $conn, $diagnosisRow["patient_username"],
    "A note was added to one of your past visit records. Log in to view it.",
    null
);

$returnApptId = $_POST["return_appt_id"] ?? "";
header("Location: view_patient.php?id=$returnApptId&type=success&msg=" . urlencode("Correction added."));
exit;
?>
