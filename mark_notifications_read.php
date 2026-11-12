<?php
session_start();
include "connection.php";
include "auth_check.php";

$username = $_SESSION["username"];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: patient_dashboard.php");
    exit;
}

if (($_POST["scope"] ?? "") === "all") {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
} elseif (!empty($_POST["id"])) {
    // Ownership check, same as everywhere else someone acts on their own row.
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND username = ?");
    $stmt->bind_param("is", $_POST["id"], $username);
    $stmt->execute();
    $stmt->close();
}

// This file is shared across every role now (patients, doctors, staff all
// get notifications) — redirect back to whichever dashboard actually
// belongs to them, not always the patient one.
$dashboardByRole = [
    "patient" => "patient_dashboard.php",
    "doctor" => "doctor_dashboard.php",
    "staff" => "staff_dashboard.php",
    "admin" => "admin_dashboard.php",
];
$redirectTo = $dashboardByRole[$_SESSION["role"]] ?? "patient_dashboard.php";

header("Location: $redirectTo?tab=home");
exit;
?>
