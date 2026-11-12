<?php
session_start();
include "connection.php";
include "auth_check.php";

// This page is for completing a clinical visit — doctor only. Staff manages
// appointments from staff_dashboard.php, not here.
if ($_SESSION["role"] !== "doctor") {
  header("Location: staff_dashboard.php");
  exit;
}

$doctorUsername = $_SESSION["username"];

$apptId = $_GET["id"] ?? null;
if (!$apptId) {
  header("Location: doctor_dashboard.php?tab=schedule");
  exit;
}

// Ownership check — this appointment must be assigned to THIS doctor
// specifically, not just at their center. Staff assigns a doctor at
// confirmation time; other doctors at the same center shouldn't see it.
$stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ? AND assigned_doctor = ?");
$stmt->bind_param("is", $apptId, $doctorUsername);
$stmt->execute();
$appt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appt) {
  header("Location: doctor_dashboard.php?tab=schedule&type=error&msg=" . urlencode("Appointment not found or not assigned to you."));
  exit;
}

$patientUsername = $appt["username"];

$stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");
$stmt->bind_param("s", $patientUsername);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Doctor's own health center — needed to scope the medicine dropdown.
$stmt = $conn->prepare("SELECT health_center_id FROM patients WHERE username = ?");
$stmt->bind_param("s", $doctorUsername);
$stmt->execute();
$centerId = $stmt->get_result()->fetch_assoc()["health_center_id"];
$stmt->close();

// Diagnosis history for this patient — across any center, since a patient's
// medical history isn't scoped the way a schedule is.
$stmt = $conn->prepare("
    SELECT diagnoses.*, appointments.appointment_date, appointments.service, health_centers.name AS center_name
    FROM diagnoses
    JOIN appointments ON appointments.id = diagnoses.appointment_id
    JOIN health_centers ON health_centers.id = appointments.health_center_id
    WHERE diagnoses.username = ?
    ORDER BY diagnoses.created_at DESC
");
$stmt->bind_param("s", $patientUsername);
$stmt->execute();
$diagnosisHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


// Prescription history for this patient's diagnoses, grouped by diagnosis.
$stmt = $conn->prepare("
        SELECT
            pr.id AS prescription_id,
            pr.diagnosis_id,
            m.name AS medicine_name,
            m.unit,
            pr.quantity,
            pr.instructions,
            COALESCE(pr.prescribed_at, pr.created_at) AS prescribed_at,
            COALESCE(
                NULLIF(doctor.full_name, ''),
                NULLIF(pr.prescribed_by, ''),
                d.staff_username,
                'Unknown'
            ) AS prescribed_by_name
        FROM prescriptions pr
        JOIN diagnoses d
            ON d.id = pr.diagnosis_id
        JOIN medicines m
            ON m.id = pr.medicine_id
        LEFT JOIN patients doctor
            ON doctor.username = COALESCE(
                NULLIF(pr.prescribed_by, ''),
                d.staff_username
            )
        WHERE d.username = ?
        ORDER BY pr.prescribed_at DESC, pr.id DESC
    ");

$stmt->bind_param("s", $patientUsername);
$stmt->execute();

$prescriptionsByDiagnosis = [];

foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $prescription) {
  $prescriptionsByDiagnosis[$prescription["diagnosis_id"]][] = $prescription;
}

$stmt->close();


// Addenda (corrections) for every diagnosis in this patient's history, grouped by diagnosis_id.
$addendaByDiagnosis = [];
if (!empty($diagnosisHistory)) {
  $diagIds = array_column($diagnosisHistory, "id");
  $placeholders = implode(",", array_fill(0, count($diagIds), "?"));
  $types = str_repeat("i", count($diagIds));
  $stmt = $conn->prepare("SELECT * FROM diagnosis_addenda WHERE diagnosis_id IN ($placeholders) ORDER BY created_at");
  $stmt->bind_param($types, ...$diagIds);
  $stmt->execute();
  foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
    $addendaByDiagnosis[$a["diagnosis_id"]][] = $a;
  }
}

// Medicines available at the doctor's own center, for the prescription dropdown.
$stmt = $conn->prepare("SELECT id, name, unit, stock_quantity FROM medicines WHERE health_center_id = ? ORDER BY name");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$medicines = $stmt->get_result();
$medicinesList = [];
while ($m = $medicines->fetch_assoc())
  $medicinesList[] = $m;

$statusMsg = $_GET["msg"] ?? null;
$statusType = $_GET["type"] ?? "info";
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($patient["full_name"]) ?> — Malabon District II Health Centers</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="homepagestyle.css">
  <link rel="stylesheet" href="patient_style.css">
</head>

<body class="dashboard-body">

  <div class="dash-shell single-column">
    <main class="dash-main">
      <a href="doctor_dashboard.php?tab=schedule" class="link-btn">&larr; Back to schedule</a>

      <?php if ($statusMsg): ?>
        <div class="msg <?= htmlspecialchars($statusType) ?>"><?= htmlspecialchars($statusMsg) ?></div>
      <?php endif; ?>

      <h1 class="dash-title"><?= htmlspecialchars($patient["full_name"]) ?></h1>
      <p class="dash-sub">
        <?= htmlspecialchars($patient["patient_id"]) ?> —
        <?= htmlspecialchars($appt["service"]) ?> on
        <?= date("F j, Y g:i A", strtotime($appt["appointment_date"] . " " . $appt["appointment_time"])) ?>
        — <span class="status-pill status-<?= strtolower($appt["status"]) ?>"><?= $appt["status"] ?></span>
      </p>

      <div class="dash-card">
        <h2 class="card-heading">Patient profile</h2>
        <div class="profile-grid">
          <div><span class="label">Date of
              birth</span><br><?= $patient["date_of_birth"] ? date("M j, Y", strtotime($patient["date_of_birth"])) : "Not on file" ?>
          </div>
          <div><span class="label">Gender</span><br><?= htmlspecialchars($patient["gender"] ?: "Not on file") ?></div>
          <div><span class="label">Mobile</span><br><?= htmlspecialchars($patient["mobile"] ?: "Not on file") ?></div>
          <div><span class="label">Address</span><br><?= htmlspecialchars($patient["address"] ?: "Not on file") ?></div>
          <div><span class="label">Emergency contact</span><br>
            <?= htmlspecialchars($patient["emergency_contact_name"] ?: "Not on file") ?>
            <?= $patient["emergency_contact_number"] ? " (" . htmlspecialchars($patient["emergency_contact_number"]) . ")" : "" ?>
          </div>
        </div>
      </div>

      <?php if (isset($_GET["start"]) && $_GET["start"] === "success"): ?>
        <p class="success-message">
          Consultation started successfully. You can now record the visit.
        </p>
      <?php elseif (isset($_GET["start"]) && $_GET["start"] === "error"): ?>
        <p class="error-message">
          The consultation could not be started. Refresh the page and
          check the appointment status.
        </p>
      <?php endif; ?>

      <?php if ($appt["status"] === "Confirmed"): ?>

        <h2>Appointment Actions</h2>

        <form action="process_start_consultation.php" method="POST">
          <input type="hidden" name="appointment_id" value="<?php echo (int) $appt["id"]; ?>">

          <button type="submit">
            Start Consultation
          </button>
        </form>

        <form action="process_doctor_appointment.php" method="POST">
          <input type="hidden" name="action" value="no_show">
          <input type="hidden" name="id" value="<?= $appt["id"] ?>">
          <button type="submit" class="btn-reject"
            onclick="return confirm('Mark this patient as a no-show? This cannot be undone.');">Mark as
            no-show</button>
        </form>
      <?php elseif ($appt["status"] === "In consultation"): ?>

        <h2>Record Consultation</h2>
        <form action="process_visit.php" method="POST" id="visitForm">
          <input type="hidden" name="appointment_id" value="<?= $appt["id"] ?>">

          <div class="field">
            <label for="diagnosis">Diagnosis</label>
            <textarea id="diagnosis" name="diagnosis" rows="3" required></textarea>
          </div>
          <div class="field">
            <label for="notes">Additional notes <span class="hint">(optional)</span></label>
            <textarea id="notes" name="notes" rows="2"></textarea>
          </div>

          <div id="prescriptionRows">
            <div class="field-row prescription-row">
              <div class="field">
                <label>Medicine</label>
                <select name="medicine_id[]">
                  <option value="">None</option>
                  <?php foreach ($medicinesList as $m): ?>
                    <option value="<?= $m["id"] ?>"><?= htmlspecialchars($m["name"]) ?> (<?= $m["stock_quantity"] ?>
                      <?= htmlspecialchars($m["unit"]) ?> in stock)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Quantity</label>
                <input type="number" name="quantity[]" min="1">
              </div>
              <div class="field">
                <label>Instructions</label>
                <input type="text" name="instructions[]" placeholder="e.g. 1 tablet twice daily">
              </div>
            </div>
          </div>
          <button type="button" class="link-btn" id="addRowBtn">+ Add another medicine</button>

          <div style="margin-top:18px;">
            <button type="submit" class="btn-primary">Complete visit &amp; notify patient</button>
          </div>
          </form>
    </div>
  <?php elseif ($appt["status"] === "Pending"): ?>

    <p>This appointment is waiting for confirmation.</p>

  <?php elseif ($appt["status"] === "Completed"): ?>

    <p>This consultation has already been completed.</p>

  <?php elseif ($appt["status"] === "No-show"): ?>

    <p>The patient was marked as a no-show.</p>

  <?php else: ?>

    <p>No consultation actions are available for this appointment status.</p>

  <?php endif; ?>

  <div class="dash-card">
    <h2 class="card-heading">Diagnosis history</h2>
    <?php if (!empty($diagnosisHistory)):
      foreach ($diagnosisHistory as $d): ?>
        <div class="notif-item">
          <div class="card-heading-row">
            <p class="notif-message" style="margin:0;">
              <strong><?= date("M j, Y", strtotime($d["appointment_date"])) ?></strong> —
              <?= htmlspecialchars($d["service"]) ?> at <?= htmlspecialchars($d["center_name"]) ?>
            </p>
            <a href="doctor_visit_summary.php?id=<?= $d["appointment_id"] ?>" class="link-btn" target="_blank">Print</a>
          </div>
          <p class="hint"><?= nl2br(htmlspecialchars($d["diagnosis"])) ?></p>
          <?php if ($d["notes"]): ?>
            <p class="hint"><em>Notes: <?= nl2br(htmlspecialchars($d["notes"])) ?></em></p><?php endif; ?>
          <p class="hint">Recorded by <?= htmlspecialchars($d["staff_username"]) ?></p>

          <?php if (!empty($prescriptionsByDiagnosis[$d["id"]])): ?>
            <div class="prescription-history">
              <h3>Prescribed Medicines</h3>

              <?php foreach ($prescriptionsByDiagnosis[$d["id"]] as $prescription): ?>
                <div class="prescription-history-item">
                  <p>
                    <strong>
                      <?= htmlspecialchars($prescription["medicine_name"]) ?>
                    </strong>
                    —
                    <?= (int) $prescription["quantity"] ?>
                    <?= htmlspecialchars($prescription["unit"] ?? "units") ?>
                  </p>

                  <?php if (!empty($prescription["instructions"])): ?>
                    <p class="hint">
                      Instructions:
                      <?= htmlspecialchars($prescription["instructions"]) ?>
                    </p>
                  <?php endif; ?>

                  <p class="hint">
                    Prescribed by:
                    <?= htmlspecialchars($prescription["prescribed_by_name"]) ?>
                  </p>

                  <p class="hint">
                    Date prescribed:
                    <?= !empty($prescription["prescribed_at"])
                      ? date("M j, Y g:i A", strtotime($prescription["prescribed_at"]))
                      : "Not recorded" ?>
                  </p>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>


          <?php if (!empty($addendaByDiagnosis[$d["id"]])):
            foreach ($addendaByDiagnosis[$d["id"]] as $a): ?>
              <div class="addendum-note">
                <?= nl2br(htmlspecialchars($a["addendum"])) ?>
                <br><small>— <?= htmlspecialchars($a["staff_username"]) ?>,
                  <?= date("M j, Y", strtotime($a["created_at"])) ?></small>
              </div>
            <?php endforeach; endif; ?>

          <details class="addendum-toggle">
            <summary class="link-btn" style="display:inline;">Add correction</summary>
            <form action="process_addendum.php" method="POST" class="appt-form" style="margin-top:8px;">
              <input type="hidden" name="diagnosis_id" value="<?= $d["id"] ?>">
              <input type="hidden" name="return_appt_id" value="<?= $apptId ?>">
              <div class="field">
                <textarea name="addendum" rows="2" placeholder="e.g. Correcting dosage noted above to..."
                  required></textarea>
              </div>
              <button type="submit" class="btn-secondary" style="width:auto;padding:8px 16px;">Save correction</button>
            </form>
          </details>
        </div>
      <?php endforeach; else: ?>
      <p class="hint">No prior diagnoses on file for this patient.</p>
    <?php endif; ?>
  </div>

  </main>
  </div>

  <script>
    document.getElementById("addRowBtn")?.addEventListener("click", function () {
      const rows = document.getElementById("prescriptionRows");
      const clone = rows.firstElementChild.cloneNode(true);
      clone.querySelectorAll("input, select").forEach((el) => (el.value = ""));
      rows.appendChild(clone);
    });
  </script>
</body>

</html>