<?php
// Opened from the reminder email. The token identifies the appointment, so no login is needed.
// GET shows a button; POST records the confirmation (so email scanners can't confirm by prefetching).
include "connection.php";
include "booking_rules.php";

$token = $_GET["token"] ?? ($_POST["token"] ?? "");
$appt = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $stmt = $conn->prepare("
        SELECT a.*, hc.name AS center_name FROM appointments a
        JOIN health_centers hc ON hc.id = a.health_center_id
        WHERE a.confirm_token = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $appt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$message = "";
$state = "invalid";
if ($appt) {
    $stillValid = $appt["status"] === "Confirmed" && $appt["appointment_date"] >= date("Y-m-d");
    if (!$stillValid) {
        $message = "This appointment is no longer active, so it can't be confirmed.";
    } elseif ($appt["attendance_confirmed"]) {
        $state = "done"; $message = "You already confirmed this appointment. See you there!";
    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $stmt = $conn->prepare("UPDATE appointments SET attendance_confirmed = 1, attendance_confirmed_at = NOW() WHERE id = ? AND status = 'Confirmed'");
        $stmt->bind_param("i", $appt["id"]);
        $stmt->execute();
        $stmt->close();
        $state = "done"; $message = "Thank you! Your attendance is confirmed and your slot is kept.";
    } else {
        $state = "ask";
    }
} else {
    $message = "This confirmation link is invalid.";
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirm Attendance — CarePulse</title>
<style>
body{font-family:Arial,sans-serif;background:#faf8ff;color:#131b2e;display:flex;justify-content:center;padding:40px 16px}
.card{background:#fff;border-radius:14px;padding:28px;max-width:440px;width:100%;box-shadow:0 8px 24px rgba(0,0,0,.08)}
h1{color:#00685f;font-size:22px;margin:0 0 12px} button{background:#00685f;color:#fff;border:0;border-radius:8px;padding:12px 24px;font-weight:bold;font-size:15px;cursor:pointer}
</style></head><body><div class="card">
<h1>CarePulse</h1>
<?php if ($state === "ask"): ?>
  <p>Please confirm you will attend:</p>
  <p><strong><?= htmlspecialchars($appt["service"]) ?></strong><br>
  <?= htmlspecialchars($appt["center_name"]) ?><br>
  <?= date("F j, Y", strtotime($appt["appointment_date"])) ?> at <?= date("g:i A", strtotime($appt["appointment_time"])) ?></p>
  <form method="POST"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><button type="submit">Yes, I'll be there</button></form>
<?php else: ?>
  <p><?= htmlspecialchars($message) ?></p>
<?php endif; ?>
</div></body></html>
