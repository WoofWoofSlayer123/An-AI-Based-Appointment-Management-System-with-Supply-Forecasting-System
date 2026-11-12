<?php
session_start();
include "connection.php";
include "auth_check.php";

$username = $_SESSION["username"];
$id = $_GET["id"] ?? null;

if (!$id) {
    header("Location: patient_dashboard.php?tab=appointments");
    exit;
}

// Ownership check — a patient can only print their own appointment's slip[cite: 5].
$stmt = $conn->prepare("
    SELECT appointments.*, health_centers.name AS center_name, health_centers.address AS center_address,
           health_centers.contact_number AS center_contact
    FROM appointments
    JOIN health_centers ON health_centers.id = appointments.health_center_id
    WHERE appointments.id = ? AND appointments.username = ?
");
$stmt->bind_param("is", $id, $username);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
    header("Location: patient_dashboard.php?tab=appointments");
    exit;
}

$stmt = $conn->prepare("SELECT full_name, patient_id FROM patients WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Appointment Confirmation — <?= htmlspecialchars($patient["patient_id"]) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="homepagestyle.css">
<style>
  body{
    font-family:'Inter',sans-serif; color:var(--ink); background:var(--sage-50);
    margin:0; padding:32px 16px; display:flex; justify-content:center; min-height:100vh; align-items:center;
  }
  .slip{
    background:#fff; width:100%; max-width:520px; border-radius:16px; overflow:hidden;
    border:1px solid var(--line); box-shadow:0 20px 40px -20px rgba(14,77,70,0.25);
  }
  .slip-header{
    background:var(--teal-900); color:#fff; padding:24px 28px;
  }
  .slip-header .mark{ font-size:12px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; opacity:0.85; }
  .slip-header h1{ font-family:'Fraunces',serif; font-weight:500; font-size:22px; margin:8px 0 0; }
  .slip-body{ padding:26px 28px; }
  .row{ display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--line); font-size:14px; }
  .row:last-child{ border-bottom:none; }
  .row .label{ color:var(--ink-soft); }
  .row .value{ font-weight:600; text-align:right; }
  .status-pill{
    display:inline-block; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:700;
    text-transform:uppercase; background:var(--sage-100); color:var(--teal-900);
  }
  .patient-id-line{
    text-align:center; background:var(--sage-100); border-radius:10px; padding:14px; margin-top:20px;
  }
  .patient-id-line .lbl{ font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--ink-soft); }
  .patient-id-line .val{ font-family:'Fraunces',serif; font-size:20px; color:var(--teal-900); margin-top:2px; }
  .note{ font-size:12px; color:var(--ink-soft); margin-top:18px; line-height:1.5; }
  .actions{ text-align:center; margin-top:22px; }
  .print-btn{
    background:var(--gold); border:none; padding:11px 24px; border-radius:10px; font-weight:700;
    font-size:14px; cursor:pointer; color:#2B1B04; width:100%;
  }
  .print-btn:hover { background: var(--gold-dark); }
  @media print {
    body{ background:#fff; padding:0; display:block; }
    .slip{ box-shadow:none; border:none; max-width:100%; }
    .actions{ display:none; }
  }
</style>
</head>
<body>

<div class="slip">
  <div class="slip-header">
    <div class="mark">Malabon District II Health Centers</div>
    <h1>Appointment Confirmation</h1>
  </div>
  <div class="slip-body">
    <div class="row"><span class="label">Patient</span><span class="value"><?= htmlspecialchars($patient["full_name"]) ?></span></div>
    <div class="row"><span class="label">Health center</span><span class="value"><?= htmlspecialchars($appt["center_name"]) ?></span></div>
    <div class="row"><span class="label">Service</span><span class="value"><?= htmlspecialchars($appt["service"]) ?></span></div>
    <div class="row"><span class="label">Date</span><span class="value"><?= date("F j, Y (l)", strtotime($appt["appointment_date"])) ?></span></div>
    <div class="row"><span class="label">Time</span><span class="value"><?= date("g:i A", strtotime($appt["appointment_time"])) ?></span></div>
    <div class="row"><span class="label">Status</span><span class="value"><span class="status-pill"><?= htmlspecialchars($appt["status"]) ?></span></span></div>

    <div class="patient-id-line">
      <div class="lbl">Patient ID</div>
      <div class="val"><?= htmlspecialchars($patient["patient_id"]) ?></div>
    </div>

    <p class="note">
      Present this slip (printed or on your phone) at <?= htmlspecialchars($appt["center_name"]) ?>
      on your appointment date. Contact: <?= htmlspecialchars($appt["center_contact"] ?: "see center for contact details") ?>[cite: 5].
      A "Pending" status means your appointment is still awaiting confirmation from the health center[cite: 5].
    </p>

    <div class="actions">
      <button class="print-btn" onclick="window.print()">Print / Save as PDF</button>
    </div>
  </div>
</div>

</body>
</html>