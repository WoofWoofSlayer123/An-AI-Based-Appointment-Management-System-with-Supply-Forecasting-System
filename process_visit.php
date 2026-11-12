<?php
session_start();

require_once __DIR__ . "/connection.php";
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/notifications_helper.php";
require_once __DIR__ . "/low_stock_helper.php";

// Maximum quantity allowed per medicine prescription.
const MAX_MEDICINE_RELEASE = 10;

if (
    !isset($_SESSION["role"]) ||
    strtolower($_SESSION["role"]) !== "doctor"
) {
    header("Location: staff_dashboard.php");
    exit;
}

$doctorUsername = $_SESSION["username"] ?? "";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: doctor_dashboard.php?tab=schedule");
    exit;
}

$apptId = filter_input(INPUT_POST, "appointment_id", FILTER_VALIDATE_INT);

if (!$apptId || $apptId <= 0 || $doctorUsername === "") {
    header("Location: doctor_dashboard.php?tab=schedule&type=error&msg="
        . urlencode("Invalid appointment information."));
    exit;
}

$diagnosisText = trim($_POST["diagnosis"] ?? "");
$notes = trim($_POST["notes"] ?? "");

$medicineIds = $_POST["medicine_id"] ?? [];
$quantities = $_POST["quantity"] ?? [];
$instructions = $_POST["instructions"] ?? [];

if (
    !is_array($medicineIds) ||
    !is_array($quantities) ||
    !is_array($instructions) ||
    count($medicineIds) !== count($quantities) ||
    count($medicineIds) !== count($instructions)
) {
    header("Location: view_patient.php?id=" . (int) $apptId
        . "&type=error&msg=" . urlencode("Invalid prescription information."));
    exit;
}

if ($diagnosisText === "") {
    header("Location: view_patient.php?id=" . (int) $apptId
        . "&type=error&msg=" . urlencode("Diagnosis cannot be empty."));
    exit;
}

$transactionStarted = false;
$dispensedMedicineIds = [];

try {
    // Identify the doctor's health center.
    $stmt = $conn->prepare("
        SELECT health_center_id
        FROM patients
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $doctorUsername);
    $stmt->execute();
    $doctorRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doctorRow || empty($doctorRow["health_center_id"])) {
        throw new Exception("Unable to identify your assigned health center.");
    }

    $centerId = (int) $doctorRow["health_center_id"];

    $conn->begin_transaction();
    $transactionStarted = true;

    // Lock the appointment and verify that it belongs to this doctor.
    $stmt = $conn->prepare("
        SELECT *
        FROM appointments
        WHERE id = ?
          AND assigned_doctor = ?
          AND health_center_id = ?
          AND status = 'In consultation'
        FOR UPDATE
    ");
    $stmt->bind_param("isi", $apptId, $doctorUsername, $centerId);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appt) {
        throw new Exception(
            "Appointment not found, not assigned to you, or no longer in consultation."
        );
    }

    $patientUsername = $appt["username"];

    // Save the diagnosis.
    $stmt = $conn->prepare("
        INSERT INTO diagnoses
            (appointment_id, username, staff_username, diagnosis, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issss",
        $apptId,
        $patientUsername,
        $doctorUsername,
        $diagnosisText,
        $notes
    );
    $stmt->execute();
    $diagnosisId = (int) $conn->insert_id;
    $stmt->close();

    /*
     * Save prescriptions and dispense medicine using FIFO.
     *
     * Eligible batches:
     * - belong to the doctor's health center
     * - have remaining stock
     * - have no recorded expiration date, or expire today/later
     *
     * FIFO order: acquisition_date, then batch_id.
     */
    for ($i = 0; $i < count($medicineIds); $i++) {
        $rawMedicineId = trim((string) ($medicineIds[$i] ?? ""));

        // Ignore rows where the doctor did not select a medicine.
        if ($rawMedicineId === "") {
            continue;
        }

        if (
            !ctype_digit($rawMedicineId) ||
            (int) $rawMedicineId <= 0
        ) {
            throw new Exception("Invalid medicine selection.");
        }

        $medId = (int) $rawMedicineId;
        $rawQuantity = trim((string) ($quantities[$i] ?? ""));
        $instr = trim((string) ($instructions[$i] ?? ""));

        if (
            $rawQuantity === "" ||
            !ctype_digit($rawQuantity) ||
            (int) $rawQuantity <= 0
        ) {
            throw new Exception(
                "Enter a valid whole-number quantity for every selected medicine."
            );
        }

        $qty = (int) $rawQuantity;
        if ($qty > MAX_MEDICINE_RELEASE) {
            throw new Exception(
                "The maximum allowed quantity per medicine prescription is "
                . MAX_MEDICINE_RELEASE . " units."
            );
        }

        // Lock and validate the medicine's aggregate stock record.
        $stmt = $conn->prepare("
            SELECT id, name, stock_quantity
            FROM medicines
            WHERE id = ?
              AND health_center_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $medId, $centerId);
        $stmt->execute();
        $med = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$med) {
            throw new Exception(
                "A selected medicine was not found at your health center."
            );
        }

        $medicineName = $med["name"];
        $currentStock = (int) $med["stock_quantity"];

        if ($qty > $currentStock) {
            throw new Exception(
                "Insufficient total stock for " . $medicineName
                . ". Available: " . $currentStock . "."
            );
        }

        // Lock eligible batches, oldest acquisition date first.
        $stmt = $conn->prepare("
            SELECT batch_id, quantity_remaining
            FROM medicine_batches
            WHERE medicine_id = ?
              AND health_center_id = ?
              AND quantity_remaining > 0
              AND (
                    expiration_date IS NULL
                    OR expiration_date >= CURDATE()
              )
            ORDER BY acquisition_date ASC, batch_id ASC
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $medId, $centerId);
        $stmt->execute();
        $batchResult = $stmt->get_result();

        $batches = [];
        $eligibleStock = 0;

        while ($batch = $batchResult->fetch_assoc()) {
            $remaining = (int) $batch["quantity_remaining"];

            $batches[] = [
                "batch_id" => (int) $batch["batch_id"],
                "quantity_remaining" => $remaining
            ];

            $eligibleStock += $remaining;
        }

        $stmt->close();

        if ($eligibleStock < $qty) {
            throw new Exception(
                "Insufficient unexpired batch stock for " . $medicineName
                . ". Requested: " . $qty
                . "; available in eligible batches: " . $eligibleStock . "."
            );
        }

        // Save the prescription within the same transaction.
        $prescribedBy = $doctorUsername;

        $stmt = $conn->prepare("
            INSERT INTO prescriptions
                (diagnosis_id, medicine_id, quantity, instructions,
                 prescribed_by, prescribed_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            "iiiss",
            $diagnosisId,
            $medId,
            $qty,
            $instr,
            $prescribedBy
        );
        $stmt->execute();
        $stmt->close();

        // Allocate the prescribed quantity across batches using FIFO.
        $quantityLeft = $qty;
        $aggregateStockBefore = $currentStock;

        foreach ($batches as $batch) {
            if ($quantityLeft <= 0) {
                break;
            }

            $batchId = $batch["batch_id"];
            $batchRemaining = $batch["quantity_remaining"];
            $deduct = min($quantityLeft, $batchRemaining);

            if ($deduct <= 0) {
                continue;
            }

            $batchAfter = $batchRemaining - $deduct;

            // Reduce this batch's remaining quantity.
            $stmt = $conn->prepare("
                UPDATE medicine_batches
                SET quantity_remaining = ?
                WHERE batch_id = ?
                  AND medicine_id = ?
                  AND health_center_id = ?
                  AND quantity_remaining = ?
            ");
            $stmt->bind_param(
                "iiiii",
                $batchAfter,
                $batchId,
                $medId,
                $centerId,
                $batchRemaining
            );
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new Exception(
                    "A medicine batch changed during dispensing. Please try again."
                );
            }
            $stmt->close();

            // Update aggregate stock after each batch allocation.
            $aggregateStockAfter = $aggregateStockBefore - $deduct;

            $stmt = $conn->prepare("
                UPDATE medicines
                SET stock_quantity = ?
                WHERE id = ?
                  AND health_center_id = ?
                  AND stock_quantity = ?
            ");
            $stmt->bind_param(
                "iiii",
                $aggregateStockAfter,
                $medId,
                $centerId,
                $aggregateStockBefore
            );
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new Exception(
                    "Medicine stock could not be updated. Please try again."
                );
            }
            $stmt->close();

            // Record which batch supplied this portion of the prescription.
            $reason = "Prescription #diagnosis-" . $diagnosisId
                . "; batch #" . $batchId;

            $stmt = $conn->prepare("
                INSERT INTO medicine_stock_movements
                    (medicine_id, batch_id, health_center_id,
                     performed_by, movement_type, quantity,
                     stock_before, stock_after, reason, created_at)
                VALUES (?, ?, ?, ?, 'ISSUE', ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                "iiisiiis",
                $medId,
                $batchId,
                $centerId,
                $doctorUsername,
                $deduct,
                $aggregateStockBefore,
                $aggregateStockAfter,
                $reason
            );
            $stmt->execute();
            $stmt->close();

            $quantityLeft -= $deduct;
            $aggregateStockBefore = $aggregateStockAfter;
        }

        if ($quantityLeft !== 0) {
            throw new Exception(
                "The full quantity could not be dispensed for " . $medicineName . "."
            );
        }
        if ($quantityLeft !== 0) {
            throw new Exception(
                "The full quantity could not be dispensed for " . $medicineName . "."
            );
        }

        // Track this medicine for the post-transaction low-stock check.
        $dispensedMedicineIds[] = $medId;
    }

    // Complete the appointment only after all records and stock changes succeed.
    $stmt = $conn->prepare("
        UPDATE appointments
        SET status = 'Completed'
        WHERE id = ?
          AND assigned_doctor = ?
          AND status = 'In consultation'
    ");
    $stmt->bind_param("is", $apptId, $doctorUsername);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new Exception(
            "Unable to complete the appointment. Please try again."
        );
    }
    $stmt->close();

    $conn->commit();
    // Check for low-stock medicines after dispensing is committed.
    foreach ($dispensedMedicineIds as $medId) {
        try {
            check_medicine_low_stock($conn, (int) $medId, (int) $centerId);
        } catch (Throwable $e) {
            error_log("Low-stock alert failed: " . $e->getMessage());
        }
    }
    $transactionStarted = false;

} catch (Throwable $e) {
    if ($transactionStarted) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Preserve the original error.
        }
    }

    error_log("process_visit.php error: " . $e->getMessage());

    header(
        "Location: view_patient.php?id=" . (int) $apptId
        . "&type=error&msg=" . urlencode($e->getMessage())
    );
    exit;
}

// Notify after committing so a notification issue does not undo the visit.
try {
    create_notification(
        $conn,
        $patientUsername,
        "Your visit summary from "
        . date("M j, Y", strtotime($appt["appointment_date"]))
        . " is ready. Log in to view your diagnosis.",
        $apptId
    );

    $successMessage = "Visit completed and patient notified.";

} catch (Throwable $e) {
    error_log("Patient notification error: " . $e->getMessage());

    $successMessage =
        "Visit completed, but the patient notification could not be sent.";
}

header(
    "Location: doctor_dashboard.php?tab=schedule&type=success&msg="
    . urlencode($successMessage)
);
exit;
?>