<?php
session_start();

require_once "connection.php";
require_once "auth_check.php";

if (
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "staff"
) {
    header("Location: staff_dashboard.php");
    exit;
}

$username = $_SESSION["username"] ?? "";

// Identify the staff member's assigned health center.
$stmt = $conn->prepare(
    "SELECT health_center_id
     FROM patients
     WHERE username = ?
     LIMIT 1"
);
$stmt->bind_param("s", $username);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staff || !$staff["health_center_id"]) {
    http_response_code(403);
    exit("Unable to identify the assigned health center.");
}

$centerId = (int) $staff["health_center_id"];

// Default to the current month through today.
$today = new DateTimeImmutable("today");
$startDefault = $today
    ->modify("first day of this month")
    ->format("Y-m-d");
$endDefault = $today->format("Y-m-d");

$startDate = $_GET["start_date"] ?? $startDefault;
$endDate = $_GET["end_date"] ?? $endDefault;

function isValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);

    return $parsed !== false
        && $parsed->format("Y-m-d") === $date;
}

if (
    !isValidDate($startDate) ||
    !isValidDate($endDate) ||
    $startDate > $endDate
) {
    http_response_code(400);
    exit("Invalid reporting dates.");
}

$startTimestamp = $startDate . " 00:00:00";

$endTimestamp = (new DateTimeImmutable($endDate))
    ->modify("+1 day")
    ->format("Y-m-d") . " 00:00:00";

// Stock movement change:
// INITIAL     = stock_after - stock_before
// RESTOCK     = positive quantity
// ISSUE       = negative quantity
// ADJUSTMENT  = stock_after - stock_before

$delta = "
    CASE
        WHEN sm.movement_type = 'INITIAL'
            THEN sm.stock_after - sm.stock_before
        WHEN sm.movement_type = 'RESTOCK'
            THEN sm.quantity
        WHEN sm.movement_type = 'ISSUE'
            THEN -sm.quantity
        WHEN sm.movement_type = 'ADJUSTMENT'
            THEN sm.stock_after - sm.stock_before
        ELSE 0
    END
";

$sql = "
    SELECT
        m.id,
        m.name,
        m.unit,
        m.stock_quantity AS current_stock,
        m.reorder_threshold,

        COALESCE(SUM(
            CASE
                WHEN sm.created_at >= ?
                 AND sm.created_at < ?
                 AND sm.movement_type = 'RESTOCK'
                THEN sm.quantity
                ELSE 0
            END
        ), 0) AS received,

        COALESCE(SUM(
            CASE
                WHEN sm.created_at >= ?
                 AND sm.created_at < ?
                 AND sm.movement_type = 'ISSUE'
                THEN sm.quantity
                ELSE 0
            END
        ), 0) AS issued,

        COALESCE(SUM(
            CASE
                WHEN sm.created_at >= ?
                 AND sm.created_at < ?
                 AND sm.movement_type = 'ADJUSTMENT'
                THEN sm.stock_after - sm.stock_before
                ELSE 0
            END
        ), 0) AS adjustments,

        COALESCE(SUM(
            CASE
                WHEN sm.created_at >= ?
                THEN $delta
                ELSE 0
            END
        ), 0) AS net_change_since_start,

        COALESCE(SUM(
            CASE
                WHEN sm.created_at >= ?
                THEN $delta
                ELSE 0
            END
        ), 0) AS net_change_after_period

    FROM medicines m

    LEFT JOIN medicine_stock_movements sm
        ON sm.medicine_id = m.id
        AND sm.health_center_id = m.health_center_id

    WHERE m.health_center_id = ?

    GROUP BY
        m.id,
        m.name,
        m.unit,
        m.stock_quantity,
        m.reorder_threshold

    ORDER BY m.name ASC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ssssssssi",
    $startTimestamp,
    $endTimestamp,
    $startTimestamp,
    $endTimestamp,
    $startTimestamp,
    $endTimestamp,
    $startTimestamp,
    $endTimestamp,
    $centerId
);

$stmt->execute();
$result = $stmt->get_result();

// Escape spreadsheet formula-like text fields.
function safeCsvText($value): string
{
    $value = (string) $value;

    if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

$filename = "medicine_stockpile_report_"
    . $startDate . "_to_" . $endDate . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header(
    'Content-Disposition: attachment; filename="' .
    $filename . '"'
);
header("Cache-Control: no-store, no-cache, must-revalidate");

$out = fopen("php://output", "w");

// UTF-8 BOM for compatibility with Excel.
fwrite($out, "\xEF\xBB\xBF");

// Report heading.
fputcsv($out, [
    "MALABON DISTRICT II HEALTH CENTERS"
]);
fputcsv($out, [
    "MEDICINE STOCKPILE AND CONSUMPTION REPORT"
]);
fputcsv($out, [
    "Reporting Period",
    $startDate . " to " . $endDate
]);
fputcsv($out, [
    "Generated On",
    date("Y-m-d H:i:s")
]);
fputcsv($out, [
    "Health Center ID",
    $centerId
]);
fputcsv($out, []);

// Detailed report table.
fputcsv($out, [
    "No.",
    "Medicine Name",
    "Unit",
    "Beginning Balance",
    "Quantity Received",
    "Quantity Issued / Consumed",
    "Net Adjustments",
    "Calculated Consumption",
    "Ending Balance",
    "Reorder Threshold",
    "Stock Status"
]);

$number = 1;
$totalItems = 0;
$lowStockItems = 0;
$negativeBalances = 0;

while ($row = $result->fetch_assoc()) {
    $currentStock = (int) $row["current_stock"];
    $threshold = (int) $row["reorder_threshold"];
    $received = (int) $row["received"];
    $issued = (int) $row["issued"];
    $adjustments = (int) $row["adjustments"];

    // Reconstruct balances using recorded movements.
    $openingStock = $currentStock
        - (int) $row["net_change_since_start"];

    $endingStock = $currentStock
        - (int) $row["net_change_after_period"];

    // Consumption calculated from the stock reconciliation.
    $calculatedConsumption = $openingStock
        + $received
        + $adjustments
        - $endingStock;

    if ($currentStock < 0 || $endingStock < 0) {
        $status = "Review Balance";
        $negativeBalances++;
    } elseif ($currentStock <= $threshold) {
        $status = "Low Stock";
        $lowStockItems++;
    } else {
        $status = "In Stock";
    }

    fputcsv($out, [
        $number++,
        safeCsvText($row["name"]),
        safeCsvText($row["unit"]),
        $openingStock,
        $received,
        $issued,
        $adjustments,
        $calculatedConsumption,
        $endingStock,
        $threshold,
        $status
    ]);

    $totalItems++;
}

$stmt->close();

fputcsv($out, []);
fputcsv($out, ["REPORT SUMMARY"]);
fputcsv($out, ["Total Medicine Items", $totalItems]);
fputcsv($out, ["Items at or Below Reorder Threshold", $lowStockItems]);
fputcsv($out, ["Items Requiring Balance Review", $negativeBalances]);

fputcsv($out, []);
fputcsv($out, [
    "Prepared By",
    "________________________________"
]);
fputcsv($out, [
    "Signature",
    "________________________________"
]);
fputcsv($out, [
    "Date",
    "________________________________"
]);

fputcsv($out, []);
fputcsv($out, [
    "Important",
    "Calculated balances depend on complete, accurate stock movement records."
]);
fputcsv($out, [
    "Important",
    "Quantities use each medicine's recorded unit and should not be summed across different units."
]);

fclose($out);
exit;
?>