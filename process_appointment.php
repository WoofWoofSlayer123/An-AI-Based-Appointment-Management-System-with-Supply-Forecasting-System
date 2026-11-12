<?php
session_start();
require_once __DIR__ . "/connection.php";
require_once __DIR__ . "/auth_check.php";
require_once __DIR__ . "/booking_rules.php";
require_once __DIR__ . "/notifications_helper.php";
require_once __DIR__ . "/email_helper.php";

$username = $_SESSION["username"];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: patient_dashboard.php");
    exit;
}

function back($type, $msg)
{
    header("Location: patient_dashboard.php?tab=appointments&type=$type&msg=" . urlencode($msg));
    exit;
}

$action = $_POST["action"] ?? "";


if ($action === "add") {
    $health_center_id = (int) ($_POST["health_center_id"] ?? 0);
    $service = trim($_POST["service"] ?? "");
    $date = trim($_POST["appointment_date"] ?? "");
    $time = trim($_POST["appointment_time"] ?? "");

    // Only accept services offered by the booking form.
    $allowedServices = [
        "General Consultation",
        "Family Planning Services",
        "Dispensing Maintenance Drug",
        "TB Follow Up",
        "Pre-Natal Check Up / Maternal Services",
        "Immunization (NIP)",
        "Adolescent Consultation",
        "Dental Extraction"
    ];

    if (
        $health_center_id <= 0 ||
        !in_array($service, $allowedServices, true) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/', $time)
    ) {
        back("error", "Please provide valid appointment details.");
    }

    // Validate the actual calendar date.
    $dateParts = explode("-", $date);

    if (
        count($dateParts) !== 3 ||
        !checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])
    ) {
        back("error", "Please select a valid appointment date.");
    }

    $timeForDb = substr($time, 0, 5);
    $appointmentTimestamp = strtotime("$date $timeForDb");

    if ($appointmentTimestamp === false || $appointmentTimestamp <= time()) {
        back("error", "That appointment date or time has already passed.");
    }

    // Reject Saturdays and Sundays.
    $dayOfWeek = (int) date("N", strtotime($date));

    if ($dayOfWeek >= 6) {
        back("error", "Appointments cannot be booked on Saturdays or Sundays.");
    }

    // Retrieve the selected health center.
    $stmt = $conn->prepare("
        SELECT id, name, operating_hours
        FROM health_centers
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $health_center_id);
    $stmt->execute();
    $center = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$center) {
        back("error", "Health center not found.");
    }

    // Enforce operating hours.
    $hours = parse_operating_hours($center["operating_hours"]);

    $tMin = (int) substr($timeForDb, 0, 2) * 60
        + (int) substr($timeForDb, 3, 2);

    if ($tMin < $hours["open"] || $tMin >= $hours["close"]) {
        back(
            "error",
            $center["name"] . " accepts appointments only between "
            . minutes_label($hours["open"]) . " and "
            . minutes_label($hours["close"]) . "."
        );
    }

    // Reject dates explicitly marked Closed by an administrator.
    $stmt = $conn->prepare("
        SELECT note
        FROM health_center_status
        WHERE health_center_id = ?
          AND status_date = ?
          AND status = 'Closed'
        LIMIT 1
    ");
    $stmt->bind_param("is", $health_center_id, $date);
    $stmt->execute();
    $closed = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($closed) {
        $reason = trim($closed["note"] ?? "");
        $suffix = $reason !== "" ? " Reason: " . $reason : "";

        back(
            "error",
            "This health center is closed on that date."
            . $suffix
            . " Please choose another date or center."
        );
    }

    // Calculate the two-hour capacity window.
    [$wStart, $wEnd] = slot_window($tMin, $hours["open"]);

    $startSec = $wStart * 60;
    $endSec = $wEnd * 60;

    $conn->begin_transaction();

    try {
        /*
         * Lock the patient row first. This serializes simultaneous booking
         * attempts by the same patient, including when no appointment row
         * exists yet.
         */
        $stmt = $conn->prepare("
            SELECT username
            FROM users
            WHERE username = ?
            FOR UPDATE
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$patient) {
            throw new RuntimeException("Patient account could not be verified.");
        }

        // Mark overdue Pending or Confirmed appointments as No-show.
// Only appointments from previous dates are affected.
        $stmt = $conn->prepare("
    UPDATE appointments
    SET status = 'No-show'
    WHERE username = ?
      AND appointment_date < CURDATE()
      AND status IN ('Pending', 'Confirmed')
");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->close();


        // Only prevent multiple active appointments on the same date.
        $stmt = $conn->prepare("
            SELECT id, status
            FROM appointments
            WHERE username = ?
              AND appointment_date = ?
              AND status NOT IN (" . INACTIVE_STATUSES_SQL . ")
            LIMIT 1
        ");
        $stmt->bind_param("ss", $username, $date);
        $stmt->execute();
        $existingAppointment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existingAppointment) {
            $conn->rollback();

            back(
                "error",
                "You already have an active appointment on this date. Please choose another date or resolve your existing appointment."
            );
        }


        /*
         * Lock the health center row so concurrent bookings at this center
         * serialize before checking the two-hour capacity.
         */
        $stmt = $conn->prepare("
            SELECT id
            FROM health_centers
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("i", $health_center_id);
        $stmt->execute();
        $lockedCenter = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lockedCenter) {
            throw new RuntimeException("Health center is no longer available.");
        }

        // Count active online bookings in the selected two-hour window.
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM appointments
            WHERE health_center_id = ?
              AND appointment_date = ?
              AND status NOT IN (" . INACTIVE_STATUSES_SQL . ")
              AND TIME_TO_SEC(appointment_time) >= ?
              AND TIME_TO_SEC(appointment_time) < ?
        ");
        $stmt->bind_param(
            "isii",
            $health_center_id,
            $date,
            $startSec,
            $endSec
        );
        $stmt->execute();
        $capacityResult = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $taken = (int) ($capacityResult["total"] ?? 0);

        if ($taken >= ONLINE_BOOKING_CAP) {
            $conn->rollback();

            back(
                "error",
                "The " . minutes_label($wStart)
                . " - " . minutes_label($wEnd)
                . " window is fully booked online. Remaining capacity is reserved for walk-ins. Please choose another time."
            );
        }

        // Create the appointment only after all checks pass.
        $stmt = $conn->prepare("
            INSERT INTO appointments (
                username,
                health_center_id,
                service,
                appointment_date,
                appointment_time,
                status
            )
            VALUES (?, ?, ?, ?, ?, 'Pending')
        ");

        $stmt->bind_param(
            "sisss",
            $username,
            $health_center_id,
            $service,
            $date,
            $timeForDb
        );

        $stmt->execute();
        $newApptId = $stmt->insert_id;
        $stmt->close();

        $conn->commit();

    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Preserve the original error.
        }

        error_log("Appointment booking failed: " . $e->getMessage());

        back(
            "error",
            "We couldn't complete your booking. Please try again."
        );
    }

    // Preserve the existing in-system and email notification workflow.
    notify_with_email(
        $conn,
        $username,
        "Your appointment request for $service on "
        . date("M j, Y", strtotime($date))
        . " has been submitted and is awaiting confirmation.",
        $newApptId,
        "patient_dashboard.php?tab=appointments",
        "View Appointment",
        "Appointment Request Received"
    );

    back(
        "success",
        "Appointment requested. You'll see its status here once it's reviewed."
    );
}


if ($action === "cancel") {
    $id = (int) ($_POST["id"] ?? 0);
    $reason = trim($_POST["cancel_reason"] ?? "");

    if (mb_strlen($reason) < 5) {
        back("error", "Please give a reason for cancelling (at least 5 characters).");
    }
    $reason = mb_substr($reason, 0, 255);

    $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ? AND username = ?");
    $stmt->bind_param("is", $id, $username);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$appt)
        back("error", "Appointment not found.");

    // Last-minute cancellation is prohibited: confirmed, or within 24 hours of the slot.
    $apptTime = strtotime($appt["appointment_date"] . " " . $appt["appointment_time"]);
    $isLocked = $appt["status"] === "Confirmed" || ($apptTime - time()) < 86400;
    if ($isLocked) {
        back("error", "This appointment can no longer be cancelled — it's confirmed or too close to the scheduled time.");
    }


    $stmt = $conn->prepare("
    UPDATE appointments
    SET status = 'Cancelled',
        cancel_reason = ?,
        cancelled_at = NOW()
    WHERE id = ?
      AND username = ?
");
    $stmt->bind_param("sis", $reason, $id, $username);
    $stmt->execute();

    $cancelled = $stmt->affected_rows;
    $stmt->close();

    if ($cancelled !== 1) {
        back("error", "The appointment could not be cancelled. Please try again.");
    }

    notify_with_email(
        $conn,
        $username,
        "You cancelled your "
        . $appt["service"]
        . " appointment on "
        . date("M j, Y", strtotime($appt["appointment_date"]))
        . ". Reason: $reason",
        $id,
        "patient_dashboard.php?tab=appointments",
        "View Appointments",
        "Appointment Cancelled"
    );

    back("success", "Appointment cancelled.");

}


if ($action === "confirm_attendance") {
    // Only patients may confirm their own attendance.
    if (strtolower($_SESSION["role"] ?? "") !== "patient") {
        back("error", "You are not authorized to confirm attendance.");
    }

    // Validate the CSRF token.
    $submittedToken = $_POST["csrf_token"] ?? "";
    $sessionToken = $_SESSION["csrf_token"] ?? "";

    if (
        $sessionToken === "" ||
        !is_string($submittedToken) ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        back("error", "Your session verification failed. Please try again.");
    }

    $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

    if (!$id || $id <= 0) {
        back("error", "Invalid appointment ID.");
    }

    // Update only the logged-in patient's eligible appointment.
    $stmt = $conn->prepare("
        UPDATE appointments
        SET attendance_confirmed = 1,
            attendance_confirmed_at = NOW()
        WHERE id = ?
          AND username = ?
          AND status = 'Confirmed'
          AND appointment_date >= CURDATE()
          AND appointment_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          AND COALESCE(attendance_confirmed, 0) = 0
    ");

    $stmt->bind_param("is", $id, $username);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed !== 1) {
        back(
            "error",
            "Attendance could not be confirmed. Check that the appointment is confirmed, within the attendance confirmation period, and not already confirmed."
        );
    }

    try {
        $stmt = $conn->prepare("
            SELECT service, appointment_date, appointment_time
            FROM appointments
            WHERE id = ? AND username = ?
            LIMIT 1
        ");

        $stmt->bind_param("is", $id, $username);
        $stmt->execute();
        $appt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($appt) {
            notify_with_email(
                $conn,
                $username,
                "You confirmed your attendance for "
                . $appt["service"]
                . " on "
                . date("M j, Y", strtotime($appt["appointment_date"]))
                . " at "
                . date("g:i A", strtotime($appt["appointment_time"]))
                . ".",
                $id,
                "patient_dashboard.php?tab=appointments",
                "View Appointment",
                "Attendance Confirmed"
            );
        }
    } catch (Throwable $e) {
        // Do not undo a successful attendance confirmation
        // just because the notification failed.
        error_log("Attendance notification failed: " . $e->getMessage());
    }

    back("success", "Your attendance has been confirmed.");
}



header("Location: patient_dashboard.php");
exit;
?>