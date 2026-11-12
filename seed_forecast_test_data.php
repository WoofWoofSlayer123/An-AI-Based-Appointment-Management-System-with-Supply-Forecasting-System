<?php
// TESTING ONLY. Creates medicines + a fake but FK-valid prescription history
// (appointments -> diagnoses -> prescriptions) so the supply forecast has
// real numbers to compute from. Delete this file once you're done testing —
// it's not something that should ever exist on a real deployment.
//
// Everything it creates is tagged so it can be found and removed later:
//   - appointments.service is prefixed "[SEED TEST] "
//   - diagnoses.notes is set to "Seed test data — safe to delete"
// Deleting those appointments cascades to remove their diagnoses and
// prescriptions automatically (see clinical_schema.sql's ON DELETE CASCADE).

session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "admin") {
    die("Admin only.");
}

echo "<pre style='font-family:monospace;'>";

// Need at least one patient account to attach fake visits to.
$patientRow = $conn->query("SELECT username FROM users WHERE role = 'patient' LIMIT 1")->fetch_assoc();
if (!$patientRow) {
    die("No patient account exists yet. Register at least one patient account first, then re-run this.");
}
$patientUsername = $patientRow["username"];
echo "Using patient account: $patientUsername\n\n";

// Any doctor, system-wide — used as staff_username on the fake diagnoses.
// Preferring one actually assigned to the center being seeded, but falling
// back to any doctor so this still works before assignments exist.
$anyDoctorRow = $conn->query("SELECT username FROM users WHERE role = 'doctor' LIMIT 1")->fetch_assoc();
if (!$anyDoctorRow) {
    die("No doctor account exists yet. Approve at least one doctor account first, then re-run this.");
}
$fallbackDoctor = $anyDoctorRow["username"];

// Medicine scenarios: [name, unit, stock, per-visit qty, number of visits]
// Chosen so stock ÷ (total qty / 30) lands in each forecast bucket on purpose.
$scenarios = [
    ["Paracetamol 500mg", "tablet", 50,  50, 5],  // 250 qty / 30d ≈ 8.3/day → ~6 days  → Critical
    ["Amoxicillin 500mg", "capsule", 100, 60, 5],  // 300 qty / 30d = 10/day  → 10 days → Reorder soon
    ["Cetirizine 10mg",   "tablet", 300, 30, 5],  // 150 qty / 30d = 5/day   → 60 days → Fine
    ["ORS Sachet",        "sachet", 80,  10, 2],  // only 2 events           →          Not enough data
];

$centersResult = $conn->query("SELECT id, name FROM health_centers");
while ($center = $centersResult->fetch_assoc()) {
    $centerId = $center["id"];
    echo "=== {$center['name']} (id $centerId) ===\n";

    // Prefer a doctor actually assigned to this center for the fake visits.
    $stmt = $conn->prepare("
        SELECT users.username FROM users
        JOIN patients ON patients.username = users.username
        WHERE users.role = 'doctor' AND patients.health_center_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $centerId);
    $stmt->execute();
    $centerDoctorRow = $stmt->get_result()->fetch_assoc();
    $doctorUsername = $centerDoctorRow["username"] ?? $fallbackDoctor;
    $stmt->close();

    // Skip re-seeding a center that already has seed data, so repeated runs
    // don't keep inflating the numbers.
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM diagnoses
        JOIN appointments ON appointments.id = diagnoses.appointment_id
        WHERE appointments.health_center_id = ? AND diagnoses.notes = 'Seed test data — safe to delete'
    ");
    $stmt->bind_param("i", $centerId);
    $stmt->execute();
    $already = $stmt->get_result()->fetch_assoc()["c"];
    $stmt->close();
    if ($already > 0) {
        echo "  Already seeded ($already diagnoses found) — skipping to avoid inflating numbers.\n\n";
        continue;
    }

    foreach ($scenarios as [$name, $unit, $stock, $qtyPerVisit, $visits]) {
        // Reuse the medicine if it already exists at this center; otherwise create it.
        $stmt = $conn->prepare("SELECT id FROM medicines WHERE health_center_id = ? AND name = ?");
        $stmt->bind_param("is", $centerId, $name);
        $stmt->execute();
        $med = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($med) {
            $medicineId = $med["id"];
            echo "  $name: already exists (id $medicineId), reusing.\n";
        } else {
            $threshold = (int) ($stock * 0.2);
            $stmt = $conn->prepare("INSERT INTO medicines (health_center_id, name, unit, stock_quantity, reorder_threshold) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issii", $centerId, $name, $unit, $stock, $threshold);
            $stmt->execute();
            $medicineId = $stmt->insert_id;
            $stmt->close();
            echo "  $name: created (id $medicineId, stock $stock).\n";
        }

        // Create N fake completed visits, each prescribing this medicine once.
        for ($i = 0; $i < $visits; $i++) {
            $apptDate = date("Y-m-d", strtotime("-" . rand(1, 29) . " days"));
            $apptTime = sprintf("%02d:00:00", rand(8, 16));
            $service = "[SEED TEST] General Consultation";

            $stmt = $conn->prepare("
                INSERT INTO appointments (username, health_center_id, service, appointment_date, appointment_time, status, assigned_doctor)
                VALUES (?, ?, ?, ?, ?, 'Completed', ?)
            ");
            $stmt->bind_param("sissss", $patientUsername, $centerId, $service, $apptDate, $apptTime, $doctorUsername);
            $stmt->execute();
            $apptId = $stmt->insert_id;
            $stmt->close();

            $diagnosisText = "Seed test visit — no real clinical content.";
            $notes = "Seed test data — safe to delete";
            $stmt = $conn->prepare("
                INSERT INTO diagnoses (appointment_id, username, staff_username, diagnosis, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss", $apptId, $patientUsername, $doctorUsername, $diagnosisText, $notes);
            $stmt->execute();
            $diagnosisId = $stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO prescriptions (diagnosis_id, medicine_id, quantity, instructions) VALUES (?, ?, ?, ?)");
            $instructions = "Seed test data";
            $stmt->bind_param("iiis", $diagnosisId, $medicineId, $qtyPerVisit, $instructions);
            $stmt->execute();
            $stmt->close();
        }
        echo "    -> $visits prescription(s) of $qtyPerVisit $unit each logged.\n";
    }
    echo "\n";
}

echo "Done. Check the Supply Forecast tab on the staff and admin dashboards.\n";
echo "\nTo clean this up later, run:\n";
echo "  DELETE FROM appointments WHERE service = '[SEED TEST] General Consultation';\n";
echo "(diagnoses and prescriptions attached to those appointments cascade-delete automatically)\n";
echo "</pre>";
?>
