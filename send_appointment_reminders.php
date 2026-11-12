<?php
// Run once a day from the command line (see setup notes), early morning before the centers open:
//   php send_appointment_reminders.php
// Task Scheduler (Windows) or cron (Linux) can do this.
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI only"); }

include __DIR__ . "/connection.php";
include __DIR__ . "/notifications_helper.php";
include __DIR__ . "/email_helper.php";

// 1) No-show: confirmed appointments happening TODAY where the patient got at least one
//    reminder and never replied. Marking them No-show frees the slot (No-show isn't counted
//    toward the 2-hour cap, so the window opens up again).
$res = $conn->query("
    SELECT id, username, service, appointment_date, appointment_time FROM appointments
    WHERE status = 'Confirmed' AND attendance_confirmed = 0 AND reminder_count >= 1
      AND appointment_date = CURDATE()
");
while ($a = $res->fetch_assoc()) {
    $stmt = $conn->prepare("UPDATE appointments SET status = 'No-show' WHERE id = ? AND status = 'Confirmed' AND attendance_confirmed = 0");
    $stmt->bind_param("i", $a["id"]);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed) {
        notify_with_email($conn, $a["username"],
            "Your " . $a["service"] . " appointment today (" . date("g:i A", strtotime($a["appointment_time"])) . ") was marked as a no-show because it wasn't confirmed after our reminders. The slot has been released. You can book a new appointment anytime.",
            $a["id"]);
        echo "No-show: appointment {$a['id']}\n";
    }
}

// 2) Daily reminders, 3 / 2 / 1 days before, until the patient confirms.
$res = $conn->query("
    SELECT a.id, a.username, a.service, a.appointment_date, a.appointment_time, a.confirm_token, hc.name AS center_name
    FROM appointments a JOIN health_centers hc ON hc.id = a.health_center_id
    WHERE a.status = 'Confirmed' AND a.attendance_confirmed = 0
      AND a.appointment_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
      AND (a.last_reminder_date IS NULL OR a.last_reminder_date < CURDATE())
");
while ($a = $res->fetch_assoc()) {
    $token = $a["confirm_token"] ?: bin2hex(random_bytes(16));
    $link = rtrim(APP_BASE_URL, "/") . "/confirm_attendance.php?token=" . $token;

    $stmt = $conn->prepare("UPDATE appointments SET confirm_token = ?, reminder_count = reminder_count + 1, last_reminder_date = CURDATE() WHERE id = ?");
    $stmt->bind_param("si", $token, $a["id"]);
    $stmt->execute();
    $stmt->close();

    notify_with_email($conn, $a["username"],
        "Reminder: your " . $a["service"] . " appointment at " . $a["center_name"] . " is on " . date("F j, Y", strtotime($a["appointment_date"])) . " at " . date("g:i A", strtotime($a["appointment_time"])) . ". Please confirm you'll attend, or the slot may be released.",
        $a["id"], $link, "Confirm attendance");
    echo "Reminder: appointment {$a['id']}\n";
}
