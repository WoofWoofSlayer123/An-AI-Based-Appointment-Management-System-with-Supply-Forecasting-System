<?php
session_start();

require_once __DIR__ . "/low_stock_helper.php";
include "connection.php";
include "auth_check.php";

if (($_SESSION["role"] ?? "") !== "staff") {
    header("Location: staff_dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: staff_dashboard.php?tab=inventory");
    exit;
}

$username = $_SESSION["username"] ?? "";

function inventoryRedirect($type, $message)
{
    header(
        "Location: staff_dashboard.php?tab=inventory&type=" .
        urlencode($type) . "&msg=" . urlencode($message)
    );
    exit;
}

// Verify staff health center.
$stmt = $conn->prepare(
    "SELECT health_center_id FROM patients WHERE username = ?"
);
$stmt->bind_param("s", $username);
$stmt->execute();
$staffRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$centerId = (int) ($staffRow["health_center_id"] ?? 0);

if ($centerId <= 0) {
    inventoryRedirect("error", "No health center is assigned to your account.");
}

$action = $_POST["action"] ?? "";

try {

    if ($action === "add") {
        $name = trim($_POST["name"] ?? "");
        $unit = strtolower(trim($_POST["unit"] ?? ""));
        $stockInput = $_POST["stock_quantity"] ?? "";
        $thresholdInput = $_POST["reorder_threshold"] ?? "";

        // Keep this list consistent with the Unit dropdown in staff_dashboard.php.
        $allowedUnits = [
            "tablet",
            "capsule",
            "bottle",
            "vial",
            "ampoule",
            "sachet",
            "tube",
            "box",
            "pack",
            "piece",
            "strip",
            "blister",
            "ml",
            "dose"
        ];

        // Validate required fields and numeric values.
        if (
            $name === "" ||
            $unit === "" ||
            !in_array($unit, $allowedUnits, true) ||
            filter_var($stockInput, FILTER_VALIDATE_INT) === false ||
            filter_var($thresholdInput, FILTER_VALIDATE_INT) === false
        ) {
            inventoryRedirect(
                "error",
                "Enter a valid medicine name, unit, stock, and threshold."
            );
        }

        $stock = (int) $stockInput;
        $threshold = (int) $thresholdInput;

        if ($stock < 0 || $threshold < 0) {
            inventoryRedirect(
                "error",
                "Stock and reorder threshold cannot be negative."
            );
        }

        // Validate acquisition and expiration dates.
        $acquisitionDate = trim($_POST["acquisition_date"] ?? "");
        $expirationDate = trim($_POST["expiration_date"] ?? "");

        $acquisitionDate = $acquisitionDate !== ""
            ? $acquisitionDate
            : null;

        $expirationDate = $expirationDate !== ""
            ? $expirationDate
            : null;

        $validDate = function ($date) {
            if ($date === null) {
                return true;
            }

            $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);

            return $parsed !== false
                && $parsed->format("Y-m-d") === $date;
        };

        if (
            !$validDate($acquisitionDate) ||
            !$validDate($expirationDate)
        ) {
            inventoryRedirect("error", "Please provide valid dates.");
        }

        if (
            $acquisitionDate !== null &&
            $expirationDate !== null &&
            $expirationDate < $acquisitionDate
        ) {
            inventoryRedirect(
                "error",
                "The expiration date cannot be earlier than the acquisition date."
            );
        }

        // Prevent duplicate medicine names in this health center.
        // Case and surrounding spaces are ignored.
        $stmt = $conn->prepare("
        SELECT id
        FROM medicines
        WHERE health_center_id = ?
          AND LOWER(TRIM(name)) = LOWER(?)
        LIMIT 1
    ");

        $stmt->bind_param("is", $centerId, $name);
        $stmt->execute();

        $duplicate = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($duplicate) {
            inventoryRedirect(
                "error",
                "This medicine already exists in your health center. Please restock the existing record instead."
            );
        }

        // Create the medicine and record its opening stock together.
        $conn->begin_transaction();

        $stmt = $conn->prepare("
        INSERT INTO medicines (
            health_center_id,
            name,
            unit,
            stock_quantity,
            reorder_threshold,
            acquisition_date,
            expiration_date
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

        $stmt->bind_param(
            "issiiss",
            $centerId,
            $name,
            $unit,
            $stock,
            $threshold,
            $acquisitionDate,
            $expirationDate
        );

        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            $stmt->close();

            throw new RuntimeException(
                "Medicine record was not inserted."
            );
        }

        $medicineId = (int) $conn->insert_id;
        $stmt->close();

        if ($medicineId <= 0) {
            throw new RuntimeException(
                "Could not retrieve the new medicine ID."
            );
        }

        $stmt = $conn->prepare("
    INSERT INTO medicine_batches (
        medicine_id,
        health_center_id,
        quantity_received,
        quantity_remaining,
        acquisition_date,
        expiration_date,
        created_by
    )
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

        $stmt->bind_param(
            "iiiisss",
            $medicineId,
            $centerId,
            $stock,
            $stock,
            $acquisitionDate,
            $expirationDate,
            $username
        );

        $stmt->execute();
        $stmt->close();


        // Record the initial stock movement.
        $stmt = $conn->prepare("
        INSERT INTO medicine_stock_movements (
            medicine_id,
            health_center_id,
            performed_by,
            movement_type,
            quantity,
            stock_before,
            stock_after,
            reason
        )
        VALUES (?, ?, ?, 'INITIAL', ?, 0, ?, 'Initial stock')
    ");

        $stmt->bind_param(
            "iisii",
            $medicineId,
            $centerId,
            $username,
            $stock,
            $stock
        );

        $stmt->execute();
        $stmt->close();

        $conn->commit();
        check_medicine_low_stock($conn, $medicineId, $centerId);

        inventoryRedirect(
            "success",
            "Medicine added and initial stock recorded."
        );
    }

    if ($action === "restock") {
        $medIdInput = $_POST["medicine_id"] ?? "";
        $quantityInput = $_POST["add_quantity"] ?? "";

        $acquisitionDate = trim($_POST["acquisition_date"] ?? "");
        $expirationDate = trim($_POST["expiration_date"] ?? "");

        // Validate medicine ID and quantity.
        if (
            filter_var($medIdInput, FILTER_VALIDATE_INT) === false ||
            filter_var($quantityInput, FILTER_VALIDATE_INT) === false
        ) {
            inventoryRedirect(
                "error",
                "Enter a valid medicine and restock quantity."
            );
        }

        $medId = (int) $medIdInput;
        $addQty = (int) $quantityInput;

        if ($medId <= 0 || $addQty <= 0) {
            inventoryRedirect(
                "error",
                "Restock quantity must be greater than zero."
            );
        }

        // Acquisition date is required; expiration date is optional.
        $acquisitionDate = $acquisitionDate !== ""
            ? $acquisitionDate
            : null;

        $expirationDate = $expirationDate !== ""
            ? $expirationDate
            : null;

        // Strictly validate YYYY-MM-DD dates.
        $validDate = function ($date) {
            if ($date === null) {
                return true;
            }

            $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);

            return $parsed !== false
                && $parsed->format("Y-m-d") === $date;
        };

        if (
            !$validDate($acquisitionDate) ||
            !$validDate($expirationDate) ||
            $acquisitionDate === null
        ) {
            inventoryRedirect(
                "error",
                "Enter a valid acquisition date and expiration date, if applicable."
            );
        }

        if (
            $expirationDate !== null &&
            $expirationDate < $acquisitionDate
        ) {
            inventoryRedirect(
                "error",
                "The expiration date cannot be earlier than the acquisition date."
            );
        }

        // Begin transaction so stock and movement history stay consistent.
        $conn->begin_transaction();

        // Lock this medicine's row and verify that it belongs to this center.
        $stmt = $conn->prepare("
            SELECT stock_quantity
            FROM medicines
            WHERE id = ? AND health_center_id = ?
            FOR UPDATE
        ");

        $stmt->bind_param("ii", $medId, $centerId);
        $stmt->execute();

        $medicine = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$medicine) {
            throw new RuntimeException(
                "Medicine not found in the staff member's health center."
            );
        }

        $before = (int) $medicine["stock_quantity"];

        if ($before < 0 || $addQty > PHP_INT_MAX - $before) {
            throw new RuntimeException(
                "Invalid stock quantity or resulting quantity is too large."
            );
        }

        $after = $before + $addQty;

        // Update stock and the medicine's displayed dates.

        $before = (int) $medicine["stock_quantity"];

        if ($before < 0 || $addQty > PHP_INT_MAX - $before) {
            throw new RuntimeException("Invalid or excessive stock quantity.");
        }

        $after = $before + $addQty;

        // Create a separate batch for the newly received stock.
        $stmt = $conn->prepare("
    INSERT INTO medicine_batches (
        medicine_id,
        health_center_id,
        quantity_received,
        quantity_remaining,
        acquisition_date,
        expiration_date,
        created_by
    )
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

        $stmt->bind_param(
            "iiiisss",
            $medId,
            $centerId,
            $addQty,
            $addQty,
            $acquisitionDate,
            $expirationDate,
            $username
        );

        $stmt->execute();
        $stmt->close();

        // Update only the aggregate stock total on the medicine record.
        $stmt = $conn->prepare("
    UPDATE medicines
    SET stock_quantity = ?
    WHERE id = ? AND health_center_id = ?
");

        $stmt->bind_param("iii", $after, $medId, $centerId);
        $stmt->execute();
        $stmt->close();

        // Preserve the existing stock movement history.
        $reason = "Stock replenishment";

        $stmt = $conn->prepare("
    INSERT INTO medicine_stock_movements (
        medicine_id,
        health_center_id,
        performed_by,
        movement_type,
        quantity,
        stock_before,
        stock_after,
        reason
    )
    VALUES (?, ?, ?, 'RESTOCK', ?, ?, ?, ?)
");

        $stmt->bind_param(
            "iisiiis",
            $medId,
            $centerId,
            $username,
            $addQty,
            $before,
            $after,
            $reason
        );

        $stmt->execute();
        $stmt->close();

        $conn->commit();
        check_medicine_low_stock($conn, $medId, $centerId);

        inventoryRedirect(
            "success",
            "New medicine batch added and stock movement recorded."
        );


        // Record the restock in the stock movement history.
        $reason = "Stock replenishment";

        $stmt = $conn->prepare("
            INSERT INTO medicine_stock_movements (
                medicine_id,
                health_center_id,
                performed_by,
                movement_type,
                quantity,
                stock_before,
                stock_after,
                reason
            )
            VALUES (?, ?, ?, 'RESTOCK', ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "iisiiis",
            $medId,
            $centerId,
            $username,
            $addQty,
            $before,
            $after,
            $reason
        );

        $stmt->execute();
        $stmt->close();

        $conn->commit();

        inventoryRedirect(
            "success",
            "Stock restocked successfully and stock movement recorded."
        );
    }



    inventoryRedirect("error", "Invalid inventory action.");

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // The transaction may already have been rolled back.
    }

    error_log("Medicine inventory error: " . $e->getMessage());

    inventoryRedirect(
        "error",
        "The inventory operation could not be completed. Please try again."
    );
}
?>