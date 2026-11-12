
<?php
session_start();

require_once __DIR__ . "/connection.php";
require_once __DIR__ . "/auth_check.php";

if (
    !isset($_SESSION["username"]) ||
    ($_SESSION["role"] ?? "") !== "staff"
) {
    header("Location: login.php");
    exit;
}

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");
}

function displayDate($date): string
{
    if (empty($date)) {
        return "Not recorded";
    }

    $timestamp = strtotime($date);

    return $timestamp !== false
        ? date("M j, Y", $timestamp)
        : "Not recorded";
}

function prepareOrFail(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Stockpile report SQL prepare failed: " . $conn->error);
        http_response_code(500);
        exit("Unable to generate the stockpile report. Check the database schema and server error log.");
    }

    return $stmt;
}

/*
|--------------------------------------------------------------------------
| Get staff member's assigned health center
|--------------------------------------------------------------------------
*/
$username = $_SESSION["username"];

$staffStmt = prepareOrFail(
    $conn,
    "SELECT health_center_id
     FROM patients
     WHERE username = ?
     LIMIT 1"
);

$staffStmt->bind_param("s", $username);
$staffStmt->execute();

$staff = $staffStmt->get_result()->fetch_assoc();
$staffStmt->close();

if (!$staff || empty($staff["health_center_id"])) {
    exit("No health center is assigned to this staff account.");
}

$centerId = (int) $staff["health_center_id"];

/*
|--------------------------------------------------------------------------
| Validate report date range
|--------------------------------------------------------------------------
*/
$startDate = $_GET["start_date"] ?? date("Y-m-01");
$endDate = $_GET["end_date"] ?? date("Y-m-d");

$startObject = DateTimeImmutable::createFromFormat("!Y-m-d", $startDate);
$endObject = DateTimeImmutable::createFromFormat("!Y-m-d", $endDate);

if (
    !$startObject ||
    !$endObject ||
    $startObject->format("Y-m-d") !== $startDate ||
    $endObject->format("Y-m-d") !== $endDate ||
    $startDate > $endDate ||
    $endDate > date("Y-m-d")
) {
    http_response_code(400);
    exit("Invalid report date range.");
}

$startDateTime = $startDate . " 00:00:00";
$endDateTime = $endDate . " 23:59:59";
$nowDateTime = date("Y-m-d H:i:s");

/*
|--------------------------------------------------------------------------
| Get health center information
|--------------------------------------------------------------------------
*/
$centerStmt = prepareOrFail(
    $conn,
    "SELECT name, address, contact_number
     FROM health_centers
     WHERE id = ?
     LIMIT 1"
);

$centerStmt->bind_param("i", $centerId);
$centerStmt->execute();

$center = $centerStmt->get_result()->fetch_assoc();
$centerStmt->close();

if (!$center) {
    exit("Health center information could not be found.");
}

$centerName = $center["name"] ?? "Health Center";
$centerAddress = $center["address"] ?? "";
$centerContact = $center["contact_number"] ?? "";

/*
|--------------------------------------------------------------------------
| Load one row per medicine for monthly stock movement calculations
|--------------------------------------------------------------------------
*/
$medicineStmt = prepareOrFail(
    $conn,
    "SELECT
        id,
        name,
        unit,
        stock_quantity,
        reorder_threshold
     FROM medicines
     WHERE health_center_id = ?
     ORDER BY name ASC"
);

$medicineStmt->bind_param("i", $centerId);
$medicineStmt->execute();

$medicineResult = $medicineStmt->get_result();

$items = [];

while ($row = $medicineResult->fetch_assoc()) {
    $id = (int) $row["id"];
    $currentStock = (int) $row["stock_quantity"];

    $items[$id] = [
        "id" => $id,
        "name" => $row["name"],
        "unit" => $row["unit"],
        "current_stock" => $currentStock,
        "reorder_threshold" => (int) $row["reorder_threshold"],

        "opening_balance" => $currentStock,
        "received" => 0,
        "issued" => 0,
        "adjustments" => 0,
        "period_net_change" => 0,
        "ending_balance" => 0,

        "stock_status" => "In Stock",
        "record_warning" => false
    ];
}

$medicineStmt->close();

/*
|--------------------------------------------------------------------------
| Load stock movements from the report start through the present
|--------------------------------------------------------------------------
|
| Opening balance =
| current stock minus all stock changes since the report started.
|
| Ending balance =
| opening balance plus movements within the selected period.
|
| This reconstructs historical balances when movement records are
| complete and their quantities and stock_before/stock_after are correct.
|--------------------------------------------------------------------------
*/
if (!empty($items)) {
    $movementStmt = prepareOrFail(
        $conn,
        "SELECT
            id,
            medicine_id,
            movement_type,
            quantity,
            stock_before,
            stock_after,
            created_at
         FROM medicine_stock_movements
         WHERE health_center_id = ?
           AND created_at >= ?
           AND created_at <= ?
         ORDER BY created_at ASC, id ASC"
    );

    $movementStmt->bind_param(
        "iss",
        $centerId,
        $startDateTime,
        $nowDateTime
    );

    $movementStmt->execute();
    $movementResult = $movementStmt->get_result();

    while ($movement = $movementResult->fetch_assoc()) {
        $medicineId = (int) $movement["medicine_id"];

        if (!isset($items[$medicineId])) {
            continue;
        }

        $type = strtoupper(trim((string) $movement["movement_type"]));
        $quantity = (int) $movement["quantity"];
        $before = (int) $movement["stock_before"];
        $after = (int) $movement["stock_after"];

        /*
         * Determine the movement's effect on aggregate stock.
         * RESTOCK and ISSUE use positive recorded quantities.
         * Other movement types use the recorded stock difference.
         */
        if ($type === "RESTOCK") {
            $delta = $quantity;
        } elseif (in_array($type, ["ISSUE", "DISPENSE"], true)) {
            $delta = -abs($quantity);
        } else {
            $delta = $after - $before;
        }

        // Reverse every movement since the report start to reconstruct
        // the balance at the beginning of the reporting period.
        $items[$medicineId]["opening_balance"] -= $delta;

        $movementDate = substr((string) $movement["created_at"], 0, 10);

        if ($movementDate < $startDate || $movementDate > $endDate) {
            continue;
        }

        $items[$medicineId]["period_net_change"] += $delta;

        if ($type === "RESTOCK") {
            $items[$medicineId]["received"] += abs($quantity);
        } elseif (in_array($type, ["ISSUE", "DISPENSE"], true)) {
            $items[$medicineId]["issued"] += abs($quantity);
        } else {
            $items[$medicineId]["adjustments"] += $delta;
        }
    }

    $movementStmt->close();
}

/*
|--------------------------------------------------------------------------
| Calculate monthly balances and stock status
|--------------------------------------------------------------------------
*/
$totalMedicines = count($items);
$lowStockCount = 0;
$recordWarningCount = 0;

foreach ($items as &$item) {
    $item["ending_balance"] =
        $item["opening_balance"] + $item["period_net_change"];

    /*
     * Current stock can only be compared with the reconstructed ending
     * balance when the selected report ends today.
     */
    if (
        $endDate === date("Y-m-d") &&
        $item["ending_balance"] !== $item["current_stock"]
    ) {
        $item["record_warning"] = true;
    }

    if (
        $item["opening_balance"] < 0 ||
        $item["ending_balance"] < 0
    ) {
        $item["record_warning"] = true;
    }

    if ($item["record_warning"]) {
        $recordWarningCount++;
    }

    if ($item["current_stock"] <= $item["reorder_threshold"]) {
        $item["stock_status"] = "Low Stock";
        $lowStockCount++;
    } else {
        $item["stock_status"] = "In Stock";
    }
}

unset($item);

/*
|--------------------------------------------------------------------------
| Summary totals: monthly movements
|--------------------------------------------------------------------------
*/
$totalReceived = 0;
$totalIssued = 0;

foreach ($items as $item) {
    $totalReceived += $item["received"];
    $totalIssued += $item["issued"];
}

/*
|--------------------------------------------------------------------------
| Load current stockpile: one row per batch
|--------------------------------------------------------------------------
|
| This shows current batch quantities, including zero-quantity batches.
| These are current balances, not historical batch balances at the
| selected report end date.
|--------------------------------------------------------------------------
*/
$batchStmt = prepareOrFail(
    $conn,
    "SELECT
        m.id AS medicine_id,
        m.name,
        m.unit,
        m.stock_quantity AS total_stock,
        m.reorder_threshold,
        b.batch_id,
        b.quantity_received,
        b.quantity_remaining,
        b.acquisition_date,
        b.expiration_date
     FROM medicine_batches b
     INNER JOIN medicines m
        ON m.id = b.medicine_id
        AND m.health_center_id = b.health_center_id
     WHERE b.health_center_id = ?
     ORDER BY m.name ASC, b.acquisition_date ASC, b.batch_id ASC"
);

$batchStmt->bind_param("i", $centerId);
$batchStmt->execute();

$batchResult = $batchStmt->get_result();

$batches = [];
$today = new DateTimeImmutable("today");
$expiredBatchCount = 0;
$expiringSoonBatchCount = 0;
$totalBatchRemaining = 0;

while ($row = $batchResult->fetch_assoc()) {
    $row["days_until_expiry"] = null;
    $row["expiration_status"] = "Not Recorded";

    if (empty($row["expiration_date"])) {
        $row["expiration_status"] = "Not Recorded";
    } else {
        $expiryDate = DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            $row["expiration_date"]
        );

        if (!$expiryDate) {
            $row["expiration_status"] = "Invalid Date";
        } else {
            $daysRemaining = (int) $today
                ->diff($expiryDate)
                ->format("%r%a");

            $row["days_until_expiry"] = $daysRemaining;

            if ($daysRemaining < 0) {
                $row["expiration_status"] = "Expired";
                if ((int) $row["quantity_remaining"] > 0) {
                    $expiredBatchCount++;
                }
            } elseif ($daysRemaining === 0) {
                $row["expiration_status"] = "Expires Today";
                if ((int) $row["quantity_remaining"] > 0) {
                    $expiringSoonBatchCount++;
                }
            } elseif ($daysRemaining <= 90) {
                $row["expiration_status"] = "Expiring Soon";
                if ((int) $row["quantity_remaining"] > 0) {
                    $expiringSoonBatchCount++;
                }
            } else {
                $row["expiration_status"] = "Valid";
            }
        }
    }

    $totalBatchRemaining += (int) $row["quantity_remaining"];
    $batches[] = $row;
}

$batchStmt->close();

$centerName = $center["name"] ?? "Health Center";
$generatedAt = date("F j, Y, g:i A");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Medicine Stockpile Report | <?= h($centerName) ?></title>

    <link rel="stylesheet" href="homepagestyle.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap"
        rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 32px 16px;
            background: var(--sage-100, #e7eee7);
            color: var(--ink, #263432);
            font-family: "Inter", sans-serif;
        }

        .report-wrap {
            max-width: 1450px;
            margin: 0 auto;
        }

        .report-card {
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 12px 36px rgba(20, 50, 45, .10);
        }

        .report-header {
            background: var(--teal-900, #164e4a);
            color: #fff;
            padding: 32px;
            border-bottom: 5px solid var(--gold, #d6ad58);
        }

        .report-header h1 {
            font-family: "Fraunces", serif;
            font-size: clamp(25px, 3vw, 36px);
            line-height: 1.2;
            margin: 0 0 10px;
        }

        .report-header p {
            margin: 5px 0;
            color: rgba(255, 255, 255, .88);
            overflow-wrap: anywhere;
        }

        .report-body {
            padding: 28px;
        }

        .report-toolbar {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .date-form,
        .report-actions {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            flex-wrap: wrap;
            margin: 0;
        }

        .field {
            display: grid;
            gap: 6px;
        }

        .field label {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink-soft, #64736f);
        }

        .field input {
            min-height: 42px;
            border: 1px solid var(--line, #dce5df);
            border-radius: 9px;
            padding: 10px 12px;
            font: inherit;
            color: var(--ink, #263432);
            background: #fff;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border: 0;
            border-radius: 9px;
            padding: 11px 16px;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .button-primary {
            background: var(--teal-900, #164e4a);
            color: #fff;
        }

        .button-gold {
            background: var(--gold, #d6ad58);
            color: #243431;
        }

        .button-light {
            background: var(--sage-50, #f5f8f4);
            color: var(--teal-900, #164e4a);
            border: 1px solid var(--line, #dce5df);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--sage-50, #f5f8f4);
            border: 1px solid var(--line, #dce5df);
            border-radius: 12px;
            padding: 18px;
            min-width: 0;
        }

        .summary-label {
            font-size: 12px;
            color: var(--ink-soft, #64736f);
            font-weight: 600;
            margin-bottom: 8px;
        }

        .summary-value {
            font-size: 25px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .section {
            margin-top: 30px;
        }

        .section-title {
            font-family: "Fraunces", serif;
            font-size: 23px;
            margin: 0 0 8px;
        }

        .section-subtitle {
            color: var(--ink-soft, #64736f);
            font-size: 13px;
            line-height: 1.6;
            margin: 0 0 18px;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--line, #dce5df);
            border-radius: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 1000px;
        }

        thead {
            background: var(--sage-100, #e7eee7);
        }

        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--line, #dce5df);
            vertical-align: top;
        }

        th {
            color: var(--teal-900, #164e4a);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .035em;
            white-space: nowrap;
        }

        td {
            overflow-wrap: anywhere;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .status {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-good {
            color: var(--success, #26734d);
            background: #e4f4ea;
        }

        .status-warning {
            color: var(--warning, #9a6500);
            background: #fff1cf;
        }

        .status-danger {
            color: var(--danger, #a83232);
            background: #fbe7e7;
        }

        .status-neutral {
            color: #5b6561;
            background: #edf0ee;
        }

        .notes {
            margin-top: 28px;
            padding: 18px;
            border: 1px solid var(--line, #dce5df);
            border-radius: 12px;
            background: var(--sage-50, #f5f8f4);
            font-size: 12px;
            line-height: 1.7;
        }

        .notes h3 {
            margin: 0 0 8px;
            font-size: 14px;
        }

        .notes ul {
            padding-left: 20px;
            margin-bottom: 0;
        }

        .signature-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 50px;
            margin-top: 55px;
        }

        .signature-line {
            padding-top: 8px;
            border-top: 1px solid #6d7974;
            font-size: 12px;
            color: var(--ink-soft, #64736f);
        }

        .report-footer {
            text-align: center;
            padding: 20px;
            font-size: 11px;
            color: var(--ink-soft, #64736f);
            border-top: 1px solid var(--line, #dce5df);
        }

        @media (max-width: 900px) {
            .report-toolbar {
                align-items: flex-start;
            }

            .date-form, .report-actions {
                width: 100%;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {
            body {
                padding: 12px 8px;
            }

            .report-header, .report-body {
                padding: 20px;
            }

            .date-form {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .date-form .button {
                grid-column: 1 / -1;
            }

            .report-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .report-actions .button {
                width: 100%;
            }

            .summary-grid {
                gap: 8px;
            }

            .summary-card {
                padding: 12px;
            }

            .summary-value {
                font-size: 20px;
            }

            .signature-grid {
                grid-template-columns: 1fr;
                gap: 35px;
            }
        }

        @media print {
            @page {
                size: landscape;
                margin: 12mm;
            }

            body {
                background: #fff !important;
                padding: 0;
                color: #222;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .report-wrap {
                max-width: none;
            }

            .report-card {
                box-shadow: none;
                border-radius: 0;
            }

            .no-print {
                display: none !important;
            }

            .report-header {
                padding: 18px 22px;
            }

            .report-body {
                padding: 18px 0;
            }

            .summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 8px;
            }

            .summary-card {
                padding: 10px;
                break-inside: avoid;
            }

            .summary-value {
                font-size: 18px;
            }

            .table-wrap {
                overflow: visible;
                border-radius: 0;
            }

            table {
                min-width: 0;
                width: 100%;
                table-layout: auto;
                font-size: 8px;
            }

            th, td {
                padding: 6px 4px;
                overflow-wrap: anywhere;
            }

            th {
                white-space: normal;
                font-size: 7px;
            }

            tr {
                break-inside: avoid;
            }

            .status {
                white-space: normal;
                padding: 3px 4px;
                font-size: 7px;
            }

            .notes, .signature-grid {
                break-inside: avoid;
            }

            .report-footer {
                padding: 12px 0;
            }
        }
    </style>
</head>

<body>
<div class="report-wrap">
    <div class="report-card">

        <header class="report-header">
            <h1>Medicine Stockpile Report</h1>

            <p><strong><?= h($centerName) ?></strong></p>

            <?php if ($centerAddress !== ""): ?>
                <p><?= h($centerAddress) ?></p>
            <?php endif; ?>

            <?php if ($centerContact !== ""): ?>
                <p>Contact: <?= h($centerContact) ?></p>
            <?php endif; ?>

            <p>
                Reporting Period:
                <?= h(displayDate($startDate)) ?>
                to
                <?= h(displayDate($endDate)) ?>
            </p>

            <p>Generated on <?= h($generatedAt) ?></p>
        </header>

        <main class="report-body">

            <div class="report-toolbar no-print">
                <form method="GET" class="date-form">
                    <div class="field">
                        <label for="start_date">Start Date</label>
                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            value="<?= h($startDate) ?>"
                            max="<?= h($endDate) ?>"
                            required>
                    </div>

                    <div class="field">
                        <label for="end_date">End Date</label>
                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            value="<?= h($endDate) ?>"
                            max="<?= h(date("Y-m-d")) ?>"
                            required>
                    </div>

                    <button type="submit" class="button button-primary">
                        Generate Report
                    </button>
                </form>

                <div class="report-actions">
                    <button
                        type="button"
                        class="button button-gold"
                        onclick="window.print()">
                        Print / Save as PDF
                    </button>

                    <a
                        href="staff_dashboard.php?tab=inventory"
                        class="button button-light">
                        Back to Inventory
                    </a>
                </div>
            </div>

            <section class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">Total Medicine Types</div>
                    <div class="summary-value">
                        <?= number_format($totalMedicines) ?>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Total Received This Period</div>
                    <div class="summary-value">
                        <?= number_format($totalReceived) ?>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Total Issued This Period</div>
                    <div class="summary-value">
                        <?= number_format($totalIssued) ?>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Low Stock Items</div>
                    <div class="summary-value">
                        <?= number_format($lowStockCount) ?>
                    </div>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Monthly Stock Movements</h2>

                <p class="section-subtitle">
                    One row per medicine. Movement totals cover the selected
                    reporting period. Opening and ending balances are
                    reconstructed from recorded stock movements.
                </p>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Unit</th>
                            <th>Opening Balance</th>
                            <th>Received</th>
                            <th>Issued</th>
                            <th>Adjustments</th>
                            <th>Ending Balance</th>
                            <th>Current Stock</th>
                            <th>Stock Status</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="9">No medicines found for this health center.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><strong><?= h($item["name"]) ?></strong></td>
                                    <td><?= h($item["unit"]) ?></td>
                                    <td><?= number_format($item["opening_balance"]) ?></td>
                                    <td><?= number_format($item["received"]) ?></td>
                                    <td><?= number_format($item["issued"]) ?></td>
                                    <td><?= number_format($item["adjustments"]) ?></td>
                                    <td><?= number_format($item["ending_balance"]) ?></td>
                                    <td><?= number_format($item["current_stock"]) ?></td>
                                    <td>
                                        <?php
                                        $stockClass = $item["stock_status"] === "Low Stock"
                                            ? "status-warning"
                                            : "status-good";
                                        ?>
                                        <span class="status <?= h($stockClass) ?>">
                                            <?= h($item["stock_status"]) ?>
                                        </span>

                                        <?php if ($item["record_warning"]): ?>
                                            <br>
                                            <span class="status status-danger">
                                                Review Records
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Current Batch Stockpile</h2>

                <p class="section-subtitle">
                    One row per batch. Each batch has its own acquisition date,
                    expiration date, and remaining quantity. These are current
                    batch balances, not historical balances as of the selected
                    report end date.
                </p>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Batch ID</th>
                            <th>Acquisition Date</th>
                            <th>Expiration Date</th>
                            <th>Expiration Status</th>
                            <th>Batch Remaining</th>
                            <th>Total Medicine Stock</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="7">
                                    No batch records found. Existing untracked
                                    stock may need to be migrated into
                                    medicine_batches.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $batch): ?>
                                <?php
                                $expiryStatus = $batch["expiration_status"];

                                if ($expiryStatus === "Expired") {
                                    $expiryClass = "status-danger";
                                } elseif (
                                    $expiryStatus === "Expires Today" ||
                                    $expiryStatus === "Expiring Soon"
                                ) {
                                    $expiryClass = "status-warning";
                                } elseif ($expiryStatus === "Valid") {
                                    $expiryClass = "status-good";
                                } else {
                                    $expiryClass = "status-neutral";
                                }
                                ?>

                                <tr>
                                    <td><strong><?= h($batch["name"]) ?></strong></td>
                                    <td><?= (int) $batch["batch_id"] ?></td>
                                    <td><?= h(displayDate($batch["acquisition_date"])) ?></td>
                                    <td><?= h(displayDate($batch["expiration_date"])) ?></td>

                                    <td>
                                        <span class="status <?= h($expiryClass) ?>">
                                            <?= h($expiryStatus) ?>
                                        </span>

                                        <?php if ($batch["days_until_expiry"] !== null): ?>
                                            <br>
                                            <small>
                                                <?php if ($batch["days_until_expiry"] < 0): ?>
                                                    Expired
                                                    <?= number_format(abs($batch["days_until_expiry"])) ?>
                                                    day(s) ago
                                                <?php elseif ($batch["days_until_expiry"] === 0): ?>
                                                    Expires today
                                                <?php else: ?>
                                                    <?= number_format($batch["days_until_expiry"]) ?>
                                                    day(s) remaining
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= number_format((int) $batch["quantity_remaining"]) ?>
                                        <?= h($batch["unit"]) ?>
                                    </td>

                                    <td>
                                        <?= number_format((int) $batch["total_stock"]) ?>
                                        <?= h($batch["unit"]) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="notes">
                <h3>Report Notes</h3>

                <ul>
                    <li>
                        <strong>Opening Balance:</strong>
                        Estimated stock at the start of the reporting period,
                        reconstructed from current stock and subsequent movements.
                    </li>
                    <li>
                        <strong>Received:</strong>
                        Total quantities recorded under RESTOCK during the
                        selected reporting period.
                    </li>
                    <li>
                        <strong>Issued:</strong>
                        Total quantities recorded under ISSUE or DISPENSE during
                        the selected reporting period.
                    </li>
                    <li>
                        <strong>Adjustments:</strong>
                        Net stock changes from movement types other than RESTOCK,
                        ISSUE, or DISPENSE.
                    </li>
                    <li>
                        <strong>Ending Balance:</strong>
                        Opening balance plus the net change recorded during
                        the selected period.
                    </li>
                    <li>
                        <strong>Current Batch Stockpile:</strong>
                        Displays current quantities from medicine_batches.
                        It is not a historical batch balance for the selected
                        report end date.
                    </li>
                    <li>
                        <strong>Expiring Soon:</strong>
                        A batch with 90 days or fewer remaining before expiration.
                    </li>
                    <li>
                        <strong>Review Records:</strong>
                        The reconstructed balance is negative or, for a report
                        ending today, does not match the current aggregate stock.
                        Check for missing or inconsistent movement records.
                    </li>
                    <li>
                        Existing stock movement records must be complete and
                        accurate for reconstructed historical balances to be
                        reliable. Legacy movements may not identify individual
                        batches.
                    </li>
                    <li>
                        Expired stock should be handled according to health
                        center policy and must not be dispensed.
                    </li>
                </ul>
            </section>

            <div class="signature-grid">
                <div class="signature-line">
                    Prepared by: Health Center Staff
                </div>

                <div class="signature-line">
                    Reviewed by: Authorized Personnel
                </div>
            </div>

        </main>

        <footer class="report-footer">
            Medicine Inventory and Supply Forecasting System
            <br>
            <?= h($centerName) ?>
        </footer>

    </div>
</div>
</body>
</html>
