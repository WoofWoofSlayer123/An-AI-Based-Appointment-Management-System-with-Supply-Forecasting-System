<?php
session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: staff_dashboard.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT health_centers.name AS center_name, medicines.name, medicines.unit,
           medicines.stock_quantity, medicines.reorder_threshold
    FROM medicines
    JOIN health_centers ON health_centers.id = medicines.health_center_id
    ORDER BY health_centers.name, medicines.name
");
$stmt->execute();
$result = $stmt->get_result();

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=district_medicine_inventory_" . date("Y-m-d") . ".csv");

$out = fopen("php://output", "w");
fputcsv($out, ["Health Center", "Medicine", "Unit", "Stock", "Low-Stock Threshold", "Status"]);
while ($row = $result->fetch_assoc()) {
    $status = $row["stock_quantity"] <= $row["reorder_threshold"] ? "Low" : "OK";
    fputcsv($out, [$row["center_name"], $row["name"], $row["unit"], $row["stock_quantity"], $row["reorder_threshold"], $status]);
}
fclose($out);
exit;
?>
