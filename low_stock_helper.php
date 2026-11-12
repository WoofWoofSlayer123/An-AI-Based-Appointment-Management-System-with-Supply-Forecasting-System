<?php

require_once __DIR__ . "/notifications_helper.php";
require_once __DIR__ . "/email_helper.php";

/**
 * Check one medicine and create a notification only when its
 * stock enters the low-stock state.
 *
 * If stock rises above the threshold, the alert is reset so a
 * later drop can generate a new notification.
 */
function check_medicine_low_stock(
    mysqli $conn,
    int $medicineId,
    int $centerId
): void {
    $conn->begin_transaction();

    try {
        // Lock the medicine record to serialize checks for this medicine.
        $stmt = $conn->prepare("
            SELECT name, stock_quantity, reorder_threshold
            FROM medicines
            WHERE id = ? AND health_center_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $medicineId, $centerId);
        $stmt->execute();
        $medicine = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$medicine) {
            $conn->commit();
            return;
        }

        $stock = (int) $medicine["stock_quantity"];
        $threshold = (int) $medicine["reorder_threshold"];
        $isLow = $stock <= $threshold;

        // Ensure there is one tracking record per medicine and center.
        $stmt = $conn->prepare("
            INSERT INTO medicine_low_stock_alerts
                (medicine_id, health_center_id, is_active)
            VALUES (?, ?, 0)
            ON DUPLICATE KEY UPDATE id = id
        ");
        $stmt->bind_param("ii", $medicineId, $centerId);
        $stmt->execute();
        $stmt->close();

        // Lock the alert state while deciding whether to notify.
        $stmt = $conn->prepare("
            SELECT is_active
            FROM medicine_low_stock_alerts
            WHERE medicine_id = ? AND health_center_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $medicineId, $centerId);
        $stmt->execute();
        $alert = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$isLow) {
            // Restocking above the threshold resets the alert.
            $stmt = $conn->prepare("
                UPDATE medicine_low_stock_alerts
                SET is_active = 0
                WHERE medicine_id = ? AND health_center_id = ?
            ");
            $stmt->bind_param("ii", $medicineId, $centerId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            return;
        }

        // An alert is already active; don't create duplicates.
        if ((int) $alert["is_active"] === 1) {
            $conn->commit();
            return;
        }

        $stmt = $conn->prepare("
            UPDATE medicine_low_stock_alerts
            SET is_active = 1, last_alerted_at = NOW()
            WHERE medicine_id = ? AND health_center_id = ?
        ");
        $stmt->bind_param("ii", $medicineId, $centerId);
        $stmt->execute();
        $stmt->close();

        // Notify staff assigned to this health center.

        $stmt = $conn->prepare("
    SELECT username
    FROM patients
    WHERE health_center_id = ?
      AND LOWER(role) = 'staff'
");

        $stmt->bind_param("i", $centerId);
        $stmt->execute();
        $staffResult = $stmt->get_result();

        $staffUsernames = [];

        while ($staff = $staffResult->fetch_assoc()) {
            $staffUsernames[] = $staff["username"];
        }

        $stmt->close();

        $message = "LOW STOCK ALERT: "
            . $medicine["name"]
            . " has " . $stock
            . " remaining, at or below its reorder threshold of "
            . $threshold
            . ". Please arrange a restock.";

        // Commit the alert state before sending notifications or emails.
        $conn->commit();

        // Notify each staff member and attempt to send an email.
        foreach ($staffUsernames as $staffUsername) {
            try {
                notify_with_email(
                    $conn,
                    $staffUsername,
                    $message,
                    null,
                    "staff_dashboard.php?tab=inventory",
                    "View Inventory",
                    "CarePulse Low Stock Alert: " . $medicine["name"]
                );
            } catch (Throwable $e) {
                error_log(
                    "Low-stock notification failed: " . $e->getMessage()
                );
            }
        }
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
