<?php
// TESTING ONLY. Seeds medicines, medicine batches, stock movements, appointments,
// diagnoses, and prescriptions for every health center in health_center_system.
// Delete this file when testing is done.
//
// Usage (logged in as admin):
//   seed_health_center_system.php?action=cleanup   removes everything seeded, restores stock
//   seed_health_center_system.php?action=seed      creates fresh seed data (default)
// Run cleanup before seeding if seed data already exists.
//
// Everything created here is tagged:
//   - appointments.service starts with "[SEED TEST] "
//   - diagnoses.notes = "Seed test data — safe to delete"
//   - prescriptions.instructions = "Seed test data"
//   - medicine_batches.created_by = "seed-test"
//   - medicine_stock_movements.reason starts with "Seed test data"
//
// Stock stays consistent with the rest of the app: each seeded prescription draws
// from a seeded batch and writes an ISSUE movement in the same reason format the app
// uses. Cleanup reverses all of it. Each run is one transaction, so a failure changes nothing.

session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "admin") {
    die("Admin only.");
}

const SEED_SERVICE_PREFIX = "[SEED TEST] ";
const SEED_NOTE           = "Seed test data — safe to delete";
const SEED_INSTRUCTIONS   = "Seed test data";
const SEED_REASON_PREFIX  = "Seed test data";
const SEED_BATCH_CREATOR  = "seed-test";
const SEED_PERFORMER      = "seed-test";
const OLDER_VISIT_DIVISOR = 3; // older visits = recent visits / 3, dated 35 to 70 days ago

// Each scenario:
//   stock  = stock left after seeding (the medicine's remaining count)
//   qty    = quantity per prescription
//   recent = completed visits in the last 30 days (what the forecast counts)
// Existing medicines at a center are reused, so their stock is their current stock plus these numbers.
const MEDICINE_SCENARIOS = [
    ["name" => "Paracetamol",        "unit" => "tablet",  "stock" => 50,  "qty" => 4, "recent" => 60], // 240 / 30d = 8/day
    ["name" => "Amoxicillin 500mg",  "unit" => "capsule", "stock" => 100, "qty" => 6, "recent" => 50], // 300 / 30d = 10/day
    ["name" => "Cetirizine",         "unit" => "tablet",  "stock" => 300, "qty" => 3, "recent" => 50], // 150 / 30d = 5/day
    ["name" => "Metformin 500mg",    "unit" => "tablet",  "stock" => 200, "qty" => 2, "recent" => 45], // 90 / 30d = 3/day
    ["name" => "Amlodipine 5mg",     "unit" => "tablet",  "stock" => 120, "qty" => 3, "recent" => 30], // 90 / 30d = 3/day
    ["name" => "Salbutamol inhaler", "unit" => "inhaler", "stock" => 20,  "qty" => 1, "recent" => 8],  // 8 / 30d
    ["name" => "ORS Sachet",         "unit" => "sachet",  "stock" => 80,  "qty" => 5, "recent" => 2],  // too few events
];

// Service names match the ones the app offers, paired with a fake diagnosis label.
const VISIT_TEMPLATES = [
    ["General Consultation",                 "Acute upper respiratory infection"],
    ["Family Planning Services",             "Routine family planning follow-up"],
    ["TB Follow Up",                         "TB treatment, progressing well"],
    ["Adolescent Consultation",              "Routine adolescent check"],
    ["Pre-Natal Check Up / Maternal Services", "Routine prenatal follow-up"],
];

/* ---------- small database helpers ---------- */

function dbRun(mysqli $conn, string $sql, string $types = "", array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $insertId = (int) $stmt->insert_id;
    $stmt->close();
    return $insertId;
}

function dbRows(mysqli $conn, string $sql, string $types = "", array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Usernames for a role, optionally limited to users linked to a health center. */
function usernamesFor(mysqli $conn, string $role, ?int $centerId = null): array
{
    if ($centerId === null) {
        $rows = dbRows($conn, "SELECT username FROM users WHERE role = ?", "s", [$role]);
    } else {
        $rows = dbRows($conn, "
            SELECT users.username FROM users
            JOIN patients ON patients.username = users.username
            WHERE users.role = ? AND patients.health_center_id = ?
        ", "si", [$role, $centerId]);
    }
    return array_column($rows, "username");
}

function pick(array $items)
{
    return $items[array_rand($items)];
}

/** One row in medicine_stock_movements, in the same shape the app writes. */
function logMovement(
    mysqli $conn,
    int $medicineId,
    int $batchId,
    int $centerId,
    string $type,
    int $qty,
    int $before,
    int $after,
    string $reason,
    string $at
): void {
    dbRun($conn, "
        INSERT INTO medicine_stock_movements
            (medicine_id, batch_id, health_center_id, performed_by, movement_type, quantity, stock_before, stock_after, reason, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ", "iiissiiiss", [$medicineId, $batchId, $centerId, SEED_PERFORMER, $type, $qty, $before, $after, $reason, $at]);
}

/* ---------- seeding ---------- */

function seedMedicineScenario(mysqli $conn, int $centerId, array $s, array $patients, array $doctors): void
{
    // Batch and medicine dates. The seeded batch is dated earlier than any real batch,
    // so FIFO issuing takes it first.
    $batchAcquired = date("Y-m-d", strtotime("-75 days"));
    $batchExpires  = date("Y-m-d", strtotime("+" . rand(365, 900) . " days"));

    // 1. Medicine: reuse it if the center already has it, otherwise create it with zero stock.
    $existing = dbRows($conn, "SELECT id, stock_quantity FROM medicines WHERE health_center_id = ? AND name = ?", "is", [$centerId, $s["name"]]);
    $isNew = !$existing;
    if ($isNew) {
        $medicineId = dbRun($conn, "
            INSERT INTO medicines (health_center_id, name, unit, stock_quantity, reorder_threshold, acquisition_date, expiration_date)
            VALUES (?, ?, ?, 0, ?, ?, ?)
        ", "ississ", [$centerId, $s["name"], $s["unit"], (int) ($s["stock"] * 0.2), $batchAcquired, $batchExpires]);
        $stock = 0;
    } else {
        $medicineId = (int) $existing[0]["id"];
        $stock = (int) $existing[0]["stock_quantity"];
    }

    // 2. Plan visits: recent ones (inside the 30-day window) and older ones (outside it).
    //    Sorted oldest first so stock runs down in date order.
    $daysAgoList = [];
    for ($i = 0; $i < $s["recent"]; $i++) {
        $daysAgoList[] = rand(1, 29);
    }
    for ($i = 0; $i < intdiv($s["recent"], OLDER_VISIT_DIVISOR); $i++) {
        $daysAgoList[] = rand(35, 70);
    }
    rsort($daysAgoList);
    $totalIssued = count($daysAgoList) * $s["qty"];

    // 3. Seeded batch: received = target stock + everything the visits will issue,
    //    so the stock left after all visits equals the scenario's target.
    $received = $s["stock"] + $totalIssued;
    $batchId = dbRun($conn, "
        INSERT INTO medicine_batches
            (medicine_id, health_center_id, quantity_received, quantity_remaining, acquisition_date, expiration_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ", "iiiisss", [$medicineId, $centerId, $received, $received, $batchAcquired, $batchExpires, SEED_BATCH_CREATOR]);

    $receiveType = $isNew ? "INITIAL" : "RESTOCK";
    $receiveNote = $isNew ? "initial batch" : "restock";
    logMovement($conn, $medicineId, $batchId, $centerId, $receiveType, $received, $stock, $stock + $received,
        SEED_REASON_PREFIX . " — $receiveNote; batch #$batchId", "$batchAcquired 08:00:00");
    $stock += $received;
    dbRun($conn, "UPDATE medicines SET stock_quantity = ? WHERE id = ?", "ii", [$stock, $medicineId]);

    // 4. Completed visits: appointment, diagnosis, prescription, then stock out.
    foreach ($daysAgoList as $daysAgo) {
        $visitDate = date("Y-m-d", strtotime("-$daysAgo days"));
        $visitTime = sprintf("%02d:00:00", rand(8, 16));
        $visitAt   = "$visitDate $visitTime";
        $patient   = pick($patients);
        $doctor    = pick($doctors);
        [$service, $diagnosisLabel] = pick(VISIT_TEMPLATES);

        $apptId = dbRun($conn, "
            INSERT INTO appointments
                (username, health_center_id, service, appointment_date, appointment_time, status, created_at, assigned_doctor)
            VALUES (?, ?, ?, ?, ?, 'Completed', ?, ?)
        ", "sisssss", [$patient, $centerId, SEED_SERVICE_PREFIX . $service, $visitDate, $visitTime,
            date("Y-m-d H:i:s", strtotime("$visitDate -2 days")), $doctor]);

        $diagId = dbRun($conn, "
            INSERT INTO diagnoses (appointment_id, username, staff_username, diagnosis, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ", "isssss", [$apptId, $patient, $doctor, "Seed test visit — " . $diagnosisLabel, SEED_NOTE, $visitAt]);

        $rxId = dbRun($conn, "
            INSERT INTO prescriptions (diagnosis_id, medicine_id, quantity, instructions, created_at, prescribed_by, prescribed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", "iiissss", [$diagId, $medicineId, $s["qty"], SEED_INSTRUCTIONS, $visitAt, $doctor, $visitAt]);

        $before = $stock;
        $stock -= $s["qty"];
        dbRun($conn, "UPDATE medicine_batches SET quantity_remaining = quantity_remaining - ? WHERE batch_id = ?", "ii", [$s["qty"], $batchId]);
        dbRun($conn, "UPDATE medicines SET stock_quantity = ? WHERE id = ?", "ii", [$stock, $medicineId]);
        logMovement($conn, $medicineId, $batchId, $centerId, "ISSUE", $s["qty"], $before, $stock,
            SEED_REASON_PREFIX . " — Prescription #diagnosis-$diagId; batch #$batchId", $visitAt);
    }

    $count = count($daysAgoList);
    echo "  {$s['name']}: $count visit(s), {$s['qty']} {$s['unit']} each, stock now $stock.\n";
}

/** A few non-completed appointments so schedule and status views have variety. */
function seedOtherAppointments(mysqli $conn, int $centerId, array $patients, array $doctors): void
{
    $plan = [
        ["Pending",   rand(1, 14)],
        ["Pending",   rand(1, 14)],
        ["Pending",   rand(1, 14)],
        ["Pending",   rand(1, 14)],
        ["No-show",   -rand(1, 29)],
        ["No-show",   -rand(1, 29)],
        ["Cancelled", -rand(1, 29)],
    ];

    foreach ($plan as [$status, $dayOffset]) {
        [$service] = pick(VISIT_TEMPLATES);
        $date = date("Y-m-d", strtotime("$dayOffset days"));
        $time = sprintf("%02d:00:00", rand(8, 16));
        // Pending requests have no doctor yet, matching what the app stores.
        $doctor = $status === "Pending" ? null : pick($doctors);

        dbRun($conn, "
            INSERT INTO appointments
                (username, health_center_id, service, appointment_date, appointment_time, status, created_at, assigned_doctor)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ", "sisssss", [pick($patients), $centerId, SEED_SERVICE_PREFIX . $service, $date, $time, $status, $doctor]);
    }
    echo "  Added 4 pending, 2 no-show, and 1 cancelled appointment(s).\n";
}

function seedAll(mysqli $conn): void
{
    $allPatients = usernamesFor($conn, "patient");
    $allDoctors  = usernamesFor($conn, "doctor");
    if (!$allPatients) {
        throw new RuntimeException("No patient account exists yet. Register at least one patient first.");
    }
    if (!$allDoctors) {
        throw new RuntimeException("No doctor account exists yet. Approve at least one doctor first.");
    }

    $centers = dbRows($conn, "SELECT id, name FROM health_centers ORDER BY id");
    foreach ($centers as $center) {
        $centerId = (int) $center["id"];
        echo "=== {$center['name']} (id $centerId) ===\n";

        $existing = dbRows($conn, "SELECT COUNT(*) AS c FROM appointments WHERE health_center_id = ? AND service LIKE ?", "is", [$centerId, SEED_SERVICE_PREFIX . "%"]);
        if ((int) $existing[0]["c"] > 0) {
            echo "  Seed data already present. Run ?action=cleanup first to reseed.\n\n";
            continue;
        }

        // Prefer patients and doctors linked to this center, fall back to everyone.
        $patients = usernamesFor($conn, "patient", $centerId) ?: $allPatients;
        $doctors  = usernamesFor($conn, "doctor", $centerId)  ?: $allDoctors;

        foreach (MEDICINE_SCENARIOS as $scenario) {
            seedMedicineScenario($conn, $centerId, $scenario, $patients, $doctors);
        }
        seedOtherAppointments($conn, $centerId, $patients, $doctors);
        echo "\n";
    }
    echo "Done. Check the Supply Forecast tab and the appointment views.\n";
}

/* ---------- cleanup ---------- */

function cleanupSeedData(mysqli $conn): void
{
    // 1. Put back the stock that seeded prescriptions issued, on the batch and on the medicine.
    $issues = dbRows($conn,
        "SELECT medicine_id, batch_id, quantity FROM medicine_stock_movements WHERE movement_type = 'ISSUE' AND reason LIKE ?",
        "s", [SEED_REASON_PREFIX . "%"]);
    foreach ($issues as $issue) {
        if ($issue["batch_id"] !== null) {
            dbRun($conn, "UPDATE medicine_batches SET quantity_remaining = quantity_remaining + ? WHERE batch_id = ?", "ii", [$issue["quantity"], $issue["batch_id"]]);
        }
        dbRun($conn, "UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE id = ?", "ii", [$issue["quantity"], $issue["medicine_id"]]);
    }
    echo "  Restored issued stock from " . count($issues) . " seeded issue(s).\n";

    // 2. Take the seeded batches' received stock back out of medicine stock, then delete the batches.
    $batches = dbRows($conn, "SELECT medicine_id, quantity_received FROM medicine_batches WHERE created_by = ?", "s", [SEED_BATCH_CREATOR]);
    foreach ($batches as $batch) {
        dbRun($conn, "UPDATE medicines SET stock_quantity = stock_quantity - ? WHERE id = ?", "ii", [$batch["quantity_received"], $batch["medicine_id"]]);
    }
    dbRun($conn, "DELETE FROM medicine_batches WHERE created_by = ?", "s", [SEED_BATCH_CREATOR]);
    echo "  Removed " . count($batches) . " seeded batch(es).\n";

    // 3. Delete the seeded movement log rows.
    dbRun($conn, "DELETE FROM medicine_stock_movements WHERE reason LIKE ?", "s", [SEED_REASON_PREFIX . "%"]);

    // 4. Delete seeded appointments. Their diagnoses and prescriptions cascade automatically.
    dbRun($conn, "DELETE FROM appointments WHERE service LIKE ?", "s", [SEED_SERVICE_PREFIX . "%"]);
    echo "  Deleted seeded appointments (with their diagnoses and prescriptions).\n";

    // 5. Delete medicines the seed created. Matched by scenario name, zero stock, no batches,
    //    and never prescribed, so real medicines with stock or history are left alone.
    $names        = array_column(MEDICINE_SCENARIOS, "name");
    $placeholders = implode(",", array_fill(0, count($names), "?"));
    $empty = dbRows($conn, "
        SELECT id FROM medicines
        WHERE name IN ($placeholders)
          AND stock_quantity = 0
          AND id NOT IN (SELECT medicine_id FROM medicine_batches)
          AND id NOT IN (SELECT medicine_id FROM prescriptions)
    ", str_repeat("s", count($names)), $names);
    foreach ($empty as $medicine) {
        dbRun($conn, "DELETE FROM medicine_low_stock_alerts WHERE medicine_id = ?", "i", [$medicine["id"]]);
        dbRun($conn, "DELETE FROM medicines WHERE id = ?", "i", [$medicine["id"]]);
    }
    echo "  Removed " . count($empty) . " empty seeded medicine(s).\n";
}

/* ---------- entry point ---------- */

echo "<pre style='font-family:monospace;'>";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$action = $_GET["action"] ?? "seed";

try {
    $conn->begin_transaction();
    if ($action === "cleanup") {
        echo "CLEANUP\n";
        cleanupSeedData($conn);
    } else {
        echo "SEED\n\n";
        seedAll($conn);
    }
    $conn->commit();
    echo "\nCommitted.\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "\nFAILED. Nothing was changed.\n" . htmlspecialchars($e->getMessage()) . "\n";
}

echo "</pre>";
?>