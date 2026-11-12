<?php
session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: staff_dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: admin_dashboard.php");
    exit;
}

$action = $_POST["action"] ?? "";

if ($action === "add_center") {
    $name = trim($_POST["name"]);
    $address = trim($_POST["address"] ?? "");
    $contact = trim($_POST["contact_number"] ?? "");
    $hours = trim($_POST["operating_hours"] ?? "");

    if ($name === "") {
        header("Location: admin_dashboard.php?tab=centers&type=error&msg=" . urlencode("Center name is required."));
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO health_centers (name, address, contact_number, operating_hours) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $address, $contact, $hours);
    $stmt->execute();
    $stmt->close();

    header("Location: admin_dashboard.php?tab=centers&type=success&msg=" . urlencode("Health center added."));
    exit;
}

if ($action === "edit_center") {
    $id = $_POST["id"];
    $name = trim($_POST["name"]);
    $address = trim($_POST["address"] ?? "");
    $contact = trim($_POST["contact_number"] ?? "");
    $hours = trim($_POST["operating_hours"] ?? "");

    if ($name === "") {
        header("Location: admin_dashboard.php?tab=centers&type=error&msg=" . urlencode("Center name is required."));
        exit;
    }

    $stmt = $conn->prepare("UPDATE health_centers SET name = ?, address = ?, contact_number = ?, operating_hours = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $name, $address, $contact, $hours, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: admin_dashboard.php?tab=centers&type=success&msg=" . urlencode("Health center updated."));
    exit;
}

if ($action === "add_status") {
    $centerId = $_POST["health_center_id"];
    $date = $_POST["status_date"];
    $status = $_POST["status"];
    $hours = trim($_POST["custom_hours"] ?? "");
    $note = trim($_POST["note"] ?? "");

    if (!in_array($status, ["Open", "Closed", "Event"])) {
        header("Location: admin_dashboard.php?tab=centers&type=error&msg=" . urlencode("Invalid status."));
        exit;
    }

    // One status per center per date — setting a new one for a date that
    // already has one overwrites it, rather than creating a duplicate.
    $stmt = $conn->prepare("
        INSERT INTO health_center_status (health_center_id, status_date, status, custom_hours, note)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), custom_hours = VALUES(custom_hours), note = VALUES(note)
    ");
    $stmt->bind_param("issss", $centerId, $date, $status, $hours, $note);
    $stmt->execute();
    $stmt->close();

    header("Location: admin_dashboard.php?tab=centers&type=success&msg=" . urlencode("Status saved."));
    exit;
}

if ($action === "delete_status") {
    $id = $_POST["id"];
    $stmt = $conn->prepare("DELETE FROM health_center_status WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: admin_dashboard.php?tab=centers&type=success&msg=" . urlencode("Status removed."));
    exit;
}

header("Location: admin_dashboard.php?tab=centers");
exit;
?>
