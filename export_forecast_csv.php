<?php
session_start();
include "connection.php";
include "auth_check.php";
include "forecast_helper.php";

if ($_SESSION["role"] !== "admin") {
    header("Location: staff_dashboard.php");
    exit;
}

$forecast = get_medicine_forecast($conn);

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=supply_forecast_" . date("Y-m-d") . ".csv");

$out = fopen("php://output", "w");
fputcsv($out, ["Medicine", "Health Center", "Stock", "Unit", "Avg Daily Use", "Est. Days Left", "Status"]);
foreach ($forecast as $f) {
    fputcsv($out, [
        $f["name"], $f["center_name"], $f["stock_quantity"], $f["unit"],
        $f["daily_rate"] !== null ? number_format($f["daily_rate"], 2) : "N/A",
        $f["days_remaining"] !== null ? $f["days_remaining"] : "N/A",
        ucfirst($f["status"])
    ]);
}
fclose($out);
exit;
?>
