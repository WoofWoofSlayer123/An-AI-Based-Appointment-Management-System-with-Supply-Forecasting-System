<?php
session_start();
include "connection.php";
include "auth_check.php";
include "forecast_helper.php";

// Front desk staff only — doctors have their own dashboard now.
if ($_SESSION["role"] !== "staff") {
    if ($_SESSION["role"] === "patient") {
        header("Location: dashboard.php");
    } elseif ($_SESSION["role"] === "admin") {
        header("Location: admin_dashboard.php");
    } elseif ($_SESSION["role"] === "doctor") {
        header("Location: doctor_dashboard.php");
    }
    exit;
}

$username = $_SESSION["username"];

$stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmtUser = $conn->prepare("SELECT email FROM users WHERE username = ?");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$userRow = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$centerId = $staff["health_center_id"];
if (!$centerId) {
    die("Your account has no assigned health center yet. Please contact your system administrator.");
}

$stmt = $conn->prepare("SELECT name FROM health_centers WHERE id = ?");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$centerName = $stmt->get_result()->fetch_assoc()["name"] ?? "Unknown center";
$stmt->close();

// Doctors at this center — used to populate the "assign doctor" dropdown
// whenever staff confirms a pending appointment.
$stmt = $conn->prepare("
    SELECT users.username, patients.full_name
    FROM users
    JOIN patients ON patients.username = users.username
    WHERE users.role = 'doctor' AND patients.health_center_id = ?
    ORDER BY patients.full_name
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$doctorsResult = $stmt->get_result();
$doctors = [];
while ($d = $doctorsResult->fetch_assoc())
    $doctors[] = $d;

// Pending requests — need a decision (confirm + assign doctor, or reject)
$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.health_center_id = ? AND appointments.status = 'Pending'
    ORDER BY appointments.appointment_date, appointments.appointment_time
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$pending = $stmt->get_result();

// Today's confirmed schedule
$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.health_center_id = ? AND appointments.status = 'Confirmed'
          AND appointments.appointment_date = CURDATE()
    ORDER BY appointments.appointment_time
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$today = $stmt->get_result();

// Full schedule for the Schedule tab (everything not yet completed/cancelled/rejected)
$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.health_center_id = ?
          AND appointments.status IN ('Pending','Confirmed')
    ORDER BY appointments.appointment_date, appointments.appointment_time
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$schedule = $stmt->get_result();

// History for this center
$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.health_center_id = ?
          AND appointments.status IN ('Completed','Cancelled','Rejected')
    ORDER BY appointments.appointment_date DESC, appointments.appointment_time DESC
    LIMIT 30
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$history = $stmt->get_result();


/* Medicine inventory: display each available batch separately.
   Medicines without batches are retained so existing records are visible. */
$stmt = $conn->prepare("
    SELECT
        m.id,
        m.name,
        m.unit,
        m.stock_quantity,
        m.reorder_threshold,
        b.batch_id,
        b.quantity_received,
        b.quantity_remaining,
        b.acquisition_date AS batch_acquisition_date,
        b.expiration_date AS batch_expiration_date
    FROM medicines m
    LEFT JOIN medicine_batches b
        ON b.medicine_id = m.id
        AND b.health_center_id = m.health_center_id
        AND b.quantity_remaining > 0
    WHERE m.health_center_id = ?
    ORDER BY
        m.name ASC,
        b.acquisition_date ASC,
        b.batch_id ASC
");

$stmt->bind_param("i", $centerId);
$stmt->execute();
$medicines = $stmt->get_result();
$stmt->close();


$stmt = $conn->prepare("SELECT COUNT(*) AS low FROM medicines WHERE health_center_id = ? AND stock_quantity <= reorder_threshold");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$lowStockCount = $stmt->get_result()->fetch_assoc()["low"];
$stmt->close();

// Medicine names for the Home dashboard alert panel.
$stmt = $conn->prepare("
    SELECT name, stock_quantity, unit, reorder_threshold
    FROM medicines
    WHERE health_center_id = ? AND stock_quantity <= reorder_threshold
    ORDER BY stock_quantity ASC, name ASC
    LIMIT 5
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$lowStockMedicinesResult = $stmt->get_result();
$lowStockMedicines = [];
while ($medicineAlert = $lowStockMedicinesResult->fetch_assoc()) {
    $lowStockMedicines[] = $medicineAlert;
}
$stmt->close();

$forecast = get_medicine_forecast($conn, $centerId);
$criticalCount = count(array_filter($forecast, fn($f) => $f["status"] === "critical"));


// ---------------- REPORTS DATA ----------------

// Validate the selected reporting dates.
$reportStart = $_GET["report_start"] ?? date("Y-m-d", strtotime("-6 days"));
$reportEnd = $_GET["report_end"] ?? date("Y-m-d");

$validDate = function ($value) {
    if (!is_string($value))
        return false;

    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
    return $date && $date->format("Y-m-d") === $value;
};

if (!$validDate($reportStart)) {
    $reportStart = date("Y-m-d", strtotime("-6 days"));
}

if (!$validDate($reportEnd)) {
    $reportEnd = date("Y-m-d");
}

// Prevent an invalid reversed date range.
if ($reportStart > $reportEnd) {
    [$reportStart, $reportEnd] = [$reportEnd, $reportStart];
}

// Appointment totals and status breakdown for the selected period.
$reportStatusCounts = [
    "Pending" => 0,
    "Confirmed" => 0,
    "Completed" => 0,
    "Cancelled" => 0,
    "Rejected" => 0
];

$stmt = $conn->prepare("
    SELECT status, COUNT(*) AS total
    FROM appointments
    WHERE health_center_id = ?
      AND appointment_date BETWEEN ? AND ?
    GROUP BY status
");
$stmt->bind_param("iss", $centerId, $reportStart, $reportEnd);
$stmt->execute();
$statusResult = $stmt->get_result();

while ($row = $statusResult->fetch_assoc()) {
    $reportStatusCounts[$row["status"]] = (int) $row["total"];
}
$stmt->close();

$reportTotalAppointments = array_sum($reportStatusCounts);
$reportCompleted = $reportStatusCounts["Completed"];

// Daily appointment trends, including zero-appointment dates.
// Excludes cancelled and rejected appointments from the volume chart.
$apptsByDay = [];

$periodStart = new DateTimeImmutable($reportStart);
$periodEnd = new DateTimeImmutable($reportEnd);
$periodDays = (int) $periodStart->diff($periodEnd)->days + 1;

// Limit chart size if an unusually large date range is requested.
$chartRangeTooLarge = $periodDays > 366;

if (!$chartRangeTooLarge) {
    for (
        $date = $periodStart;
        $date <= $periodEnd;
        $date = $date->modify("+1 day")
    ) {
        $apptsByDay[$date->format("Y-m-d")] = 0;
    }

    $stmt = $conn->prepare("
        SELECT appointment_date, COUNT(*) AS cnt
        FROM appointments
        WHERE health_center_id = ?
          AND appointment_date BETWEEN ? AND ?
          AND status NOT IN ('Cancelled', 'Rejected')
        GROUP BY appointment_date
    ");
    $stmt->bind_param("iss", $centerId, $reportStart, $reportEnd);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $apptsByDay[$row["appointment_date"]] = (int) $row["cnt"];
    }
    $stmt->close();
}

// Medicine stock snapshot for the existing stock chart.
$stmt = $conn->prepare("
    SELECT name, stock_quantity
    FROM medicines
    WHERE health_center_id = ?
    ORDER BY stock_quantity DESC, name ASC
    LIMIT 10
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$stockChartResult = $stmt->get_result();

$stockLabels = [];
$stockValues = [];

while ($row = $stockChartResult->fetch_assoc()) {
    $stockLabels[] = $row["name"];
    $stockValues[] = (int) $row["stock_quantity"];
}
$stmt->close();

// Medicine expiration monitoring.
// Includes already-expired medicines and those expiring within 30 days.

$stmt = $conn->prepare("
    SELECT
        m.id,
        m.name,
        m.unit,
        b.batch_id,
        b.quantity_remaining AS stock_quantity,
        b.acquisition_date,
        b.expiration_date
    FROM medicine_batches b
    JOIN medicines m
        ON m.id = b.medicine_id
        AND m.health_center_id = b.health_center_id
    WHERE b.health_center_id = ?
      AND b.quantity_remaining > 0
      AND b.expiration_date IS NOT NULL
      AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY b.expiration_date ASC, m.name ASC
");

$stmt->bind_param("i", $centerId);
$stmt->execute();
$expirationResult = $stmt->get_result();

$expirationRows = [];

while ($row = $expirationResult->fetch_assoc()) {
    $expirationRows[] = $row;
}
$stmt->close();

// Count batches expiring in the next 30 days, excluding already-expired batches.
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM medicine_batches b
    WHERE b.health_center_id = ?
      AND b.quantity_remaining > 0
      AND b.expiration_date >= CURDATE()
      AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");

$stmt->bind_param("i", $centerId);
$stmt->execute();
$expiringSoonCount = (int) $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();


// Most utilized services during the selected reporting period.
$stmt = $conn->prepare("
    SELECT service, COUNT(*) AS cnt
    FROM appointments
    WHERE health_center_id = ?
      AND appointment_date BETWEEN ? AND ?
      AND status NOT IN ('Cancelled', 'Rejected')
    GROUP BY service
    ORDER BY cnt DESC, service ASC
    LIMIT 5
");
$stmt->bind_param("iss", $centerId, $reportStart, $reportEnd);
$stmt->execute();
$serviceResult = $stmt->get_result();

$serviceLabels = [];
$serviceValues = [];

while ($row = $serviceResult->fetch_assoc()) {
    $serviceLabels[] = $row["service"];
    $serviceValues[] = (int) $row["cnt"];
}
$stmt->close();

// Reuse the existing PHP forecasting model.
$forecast = get_medicine_forecast($conn, $centerId);

$criticalCount = count(array_filter(
    $forecast,
    fn($f) => $f["status"] === "critical"
));

$soonCount = count(array_filter(
    $forecast,
    fn($f) => $f["status"] === "soon"
));

$insufficientCount = count(array_filter(
    $forecast,
    fn($f) => $f["status"] === "insufficient"
));

$forecastChartable = array_values(array_filter(
    $forecast,
    fn($f) => $f["days_remaining"] !== null
));

$forecastColorMap = [
    "critical" => "#C0392B",
    "soon" => "#E8A33D",
    "fine" => "#1E7A4C"
];

$statusMsg = $_GET["msg"] ?? null;
$statusType = $_GET["type"] ?? "info";

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Staff Dashboard — Malabon District II Health Centers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="homepagestyle.css">
    <link rel="stylesheet" href="patient_style.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
</head>

<body class="dashboard-body">

    <div class="dash-shell">

        <aside class="sidebar">
            <div class="sidebar-brand"><span class="dot"></span> MALABON DISTRICT II</div>
            <div class="sidebar-user">
                <div class="sidebar-name"><?= htmlspecialchars($staff["full_name"] ?: $username) ?></div>
                <div class="sidebar-id">Front Desk Staff — <?= htmlspecialchars($centerName) ?></div>
            </div>
            <nav class="sidebar-nav">
                <a href="#" class="nav-link" data-tab="home">
                    Home
                    <?php if ($pending->num_rows > 0): ?><span
                            class="nav-badge"><?= $pending->num_rows ?></span><?php endif; ?>
                </a>
                <a href="#" class="nav-link" data-tab="schedule">Schedule</a>
                <a href="#" class="nav-link" data-tab="inventory">
                    Medicine Inventory
                    <?php if ($lowStockCount > 0): ?><span class="nav-badge"><?= $lowStockCount ?></span><?php endif; ?>
                </a>
                <a href="#" class="nav-link" data-tab="reports">Reports</a>
                <a href="#" class="nav-link" data-tab="account">My Account</a>
            </nav>
            <a href="logout.php" class="sidebar-logout">Sign out</a>
        </aside>

        <main class="dash-main">

            <?php if ($statusMsg): ?>
                <div class="msg <?= htmlspecialchars($statusType) ?>"><?= htmlspecialchars($statusMsg) ?></div>
            <?php endif; ?>


            <!-- HOME -->
            <section class="dash-section staff-home" id="tab-home">

                <!-- Welcome Header -->
                <div class="home-welcome">
                    <div class="welcome-text">
                        <p class="welcome-date">
                            <?= date("l, F j, Y") ?>
                        </p>

                        <h1 class="dash-title">
                            Welcome back,
                            <?= htmlspecialchars(
                                explode(" ", $staff["full_name"] ?: $username)[0],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </h1>

                        <p class="welcome-center">
                            <?= htmlspecialchars($centerName, ENT_QUOTES, "UTF-8") ?>
                        </p>

                        <p class="welcome-description">
                            Here is your health center's daily overview.
                        </p>
                    </div>

                    <div class="welcome-icon" aria-hidden="true">
                        <span>+</span>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="home-stats">

                    <a href="#tab-appointments" class="home-stat stat-pending">
                        <div class="stat-icon" aria-hidden="true">
                            <i class="fa-regular fa-calendar"></i>
                        </div>

                        <div class="stat-content">
                            <span class="stat-label">Pending Requests</span>
                            <strong class="stat-number">
                                <?= (int) $pending->num_rows ?>
                            </strong>
                            <span class="stat-caption">Awaiting decision</span>
                        </div>

                        <span class="stat-arrow" aria-hidden="true">&rsaquo;</span>
                    </a>
                    <a href="#tab-appointments" class="home-stat stat-visits" data-tab="schedule">
                        <div class="stat-icon" aria-hidden="true">
                            <i class="fa-regular fa-calendar-check"></i>
                        </div>

                        <div class="stat-content">
                            <span class="stat-label">Today's Confirmed Visits</span>
                            <strong class="stat-number">
                                <?= (int) $today->num_rows ?>
                            </strong>
                            <span class="stat-caption">Scheduled for today</span>
                        </div>

                        <span class="stat-arrow" aria-hidden="true">&rsaquo;</span>
                    </a>
                    <a href="#tab-inventory" class="home-stat stat-stock" data-tab="inventory">
                        <div class="stat-icon" aria-hidden="true">
                            <i class="fa-solid fa-pills"></i>
                        </div>

                        <div class="stat-content">
                            <span class="stat-label">Low Stock Medicines</span>
                            <strong class="stat-number">
                                <?= (int) $lowStockCount ?>
                            </strong>
                            <span class="stat-caption">Check inventory</span>
                        </div>

                        <span class="stat-arrow" aria-hidden="true">&rsaquo;</span>
                    </a>

                </div>

                <!-- Dashboard content: schedule on the left, requests and medicine alerts on the right -->
                <div class="home-dashboard-grid">

                    <!-- LEFT SIDE: Today's Schedule -->
                    <div class="home-dashboard-left">
                        <!-- Today's Schedule -->
                        <div class="dash-card home-panel home-schedule-panel">

                            <div class="home-panel-header">
                                <div>
                                    <h2 class="card-heading">Today's Schedule</h2>
                                    <p class="home-panel-subtitle">
                                        Confirmed appointments for <?= date("F j, Y") ?>.
                                    </p>
                                </div>

                                <span class="home-count home-count-teal">
                                    <?= (int) $today->num_rows ?> visits
                                </span>
                            </div>

                            <div class="home-table-wrap">
                                <table class="dash-table home-table">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Patient</th>
                                            <th>Service</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php if ($today->num_rows > 0): ?>
                                            <?php while ($row = $today->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <span class="home-time">
                                                            <?= date(
                                                                "g:i A",
                                                                strtotime($row["appointment_time"])
                                                            ) ?>
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <span class="home-patient-name">
                                                            <?= htmlspecialchars(
                                                                $row["full_name"],
                                                                ENT_QUOTES,
                                                                "UTF-8"
                                                            ) ?>
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <?= htmlspecialchars(
                                                            $row["service"],
                                                            ENT_QUOTES,
                                                            "UTF-8"
                                                        ) ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>

                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3">
                                                    <div class="home-empty home-empty-compact">
                                                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                                                        <strong>No confirmed visits today</strong>
                                                        <span>Your schedule is clear for now.</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>


                    </div>

                    <!-- RIGHT SIDE: Pending Requests and Medicine Alerts -->
                    <div class="home-dashboard-right">
                        <!-- Pending Appointment Requests -->
                        <div class="dash-card home-panel">

                            <div class="home-panel-header">
                                <div>
                                    <h2 class="card-heading">
                                        Pending Appointment Requests
                                    </h2>
                                    <p class="home-panel-subtitle">
                                        Review requests and assign a doctor before confirming.
                                    </p>
                                </div>

                                <span class="home-count">
                                    <?= (int) $pending->num_rows ?> pending
                                </span>
                            </div>

                            <?php if (empty($doctors)): ?>
                                <div class="home-notice">
                                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                    <span>
                                        No doctors are assigned to this center yet.
                                        An administrator needs to assign doctors before staff
                                        can confirm appointment requests.
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="home-table-wrap">
                                <table class="dash-table home-table">
                                    <thead>
                                        <tr>
                                            <th>Patient</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Service</th>
                                            <th class="home-action-heading">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php if ($pending->num_rows > 0): ?>
                                            <?php while ($row = $pending->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <span class="home-patient-name">
                                                            <?= htmlspecialchars(
                                                                $row["full_name"],
                                                                ENT_QUOTES,
                                                                "UTF-8"
                                                            ) ?>
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <?= date(
                                                            "M j, Y",
                                                            strtotime($row["appointment_date"])
                                                        ) ?>
                                                    </td>

                                                    <td>
                                                        <?= date(
                                                            "g:i A",
                                                            strtotime($row["appointment_time"])
                                                        ) ?>
                                                    </td>

                                                    <td>
                                                        <?= htmlspecialchars(
                                                            $row["service"],
                                                            ENT_QUOTES,
                                                            "UTF-8"
                                                        ) ?>
                                                    </td>

                                                    <td class="home-actions-cell">
                                                        <div class="home-action-group">

                                                            <form action="update_appointment_status.php" method="POST"
                                                                class="home-confirm-form">
                                                                <input type="hidden" name="id" value="<?= (int) $row["id"] ?>">

                                                                <input type="hidden" name="status" value="Confirmed">

                                                                <select name="assigned_doctor" aria-label="Assign doctor"
                                                                    required <?= empty($doctors) ? "disabled" : "" ?>>
                                                                    <option value="">
                                                                        Assign doctor
                                                                    </option>

                                                                    <?php foreach ($doctors as $d): ?>
                                                                        <option value="<?= htmlspecialchars(
                                                                            $d["username"],
                                                                            ENT_QUOTES,
                                                                            "UTF-8"
                                                                        ) ?>">
                                                                            <?= htmlspecialchars(
                                                                                $d["full_name"],
                                                                                ENT_QUOTES,
                                                                                "UTF-8"
                                                                            ) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>

                                                                <button type="submit" class="home-btn home-btn-confirm"
                                                                    <?= empty($doctors) ? "disabled" : "" ?>>
                                                                    Confirm
                                                                </button>
                                                            </form>


                                                            <form action="update_appointment_status.php" method="POST"
                                                                class="home-reject-form">

                                                                <input type="hidden" name="id" value="<?= (int) $row["id"] ?>">
                                                                <input type="hidden" name="status" value="Rejected">

                                                                <label for="rejection-reason-<?= (int) $row["id"] ?>">
                                                                    Reason for rejection
                                                                </label>

                                                                <textarea id="rejection-reason-<?= (int) $row["id"] ?>"
                                                                    name="rejection_reason" rows="2" minlength="5"
                                                                    maxlength="500"
                                                                    placeholder="Explain why this appointment is being rejected..."
                                                                    required></textarea>

                                                                <button type="submit" class="home-btn home-btn-reject">
                                                                    Reject Appointment
                                                                </button>
                                                            </form>


                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>

                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5">
                                                    <div class="home-empty">
                                                        <i class="fa-regular fa-calendar-check" aria-hidden="true"></i>
                                                        <strong>No pending requests</strong>
                                                        <span>New appointment requests will appear here.</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Medicine Alerts -->
                        <div class="dash-card home-panel home-medicine-alerts">
                            <?php
                            $homeForecastAlerts = array_values(array_filter(
                                $forecast,
                                fn($f) => in_array($f["status"], ["critical", "soon"], true)
                            ));
                            ?>
                            <div class="home-panel-header">
                                <div>
                                    <h2 class="card-heading">Medicine Alerts</h2>
                                    <p class="home-panel-subtitle">Low stock and predicted shortages.</p>
                                </div>
                                <span
                                    class="home-count <?= (!empty($lowStockMedicines) || !empty($homeForecastAlerts)) ? '' : 'home-count-teal' ?>">
                                    <?= (int) (count($lowStockMedicines) + count($homeForecastAlerts)) ?> alerts
                                </span>
                            </div>

                            <?php if (empty($lowStockMedicines) && empty($homeForecastAlerts)): ?>
                                <div class="home-empty home-empty-compact">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    <strong>No medicine alerts</strong>
                                    <span>There are no low-stock medicines or predicted shortages right now.</span>
                                </div>
                            <?php else: ?>
                                <div class="home-alert-list">
                                    <?php foreach ($lowStockMedicines as $medicine): ?>
                                        <div class="home-alert-item">
                                            <span class="home-alert-icon home-alert-icon-low">
                                                <i class="fa-solid fa-pills" aria-hidden="true"></i>
                                            </span>
                                            <div class="home-alert-content">
                                                <strong><?= htmlspecialchars($medicine["name"], ENT_QUOTES, "UTF-8") ?></strong>
                                                <span class="home-alert-status home-alert-status-low">Low stock</span>
                                                <small>
                                                    <?= (int) $medicine["stock_quantity"] ?>
                                                    <?= htmlspecialchars($medicine["unit"], ENT_QUOTES, "UTF-8") ?> remaining
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php foreach ($homeForecastAlerts as $alert): ?>
                                        <?php
                                        // Avoid listing a medicine twice if it is both low-stock and forecast-critical.
                                        $alreadyListedAsLow = false;
                                        foreach ($lowStockMedicines as $lowMedicine) {
                                            if (strcasecmp($lowMedicine["name"], $alert["name"]) === 0) {
                                                $alreadyListedAsLow = true;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php if (!$alreadyListedAsLow): ?>
                                            <div class="home-alert-item">
                                                <span
                                                    class="home-alert-icon <?= $alert["status"] === "critical" ? "home-alert-icon-low" : "home-alert-icon-forecast" ?>">
                                                    <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                                                </span>
                                                <div class="home-alert-content">
                                                    <strong><?= htmlspecialchars($alert["name"], ENT_QUOTES, "UTF-8") ?></strong>
                                                    <span
                                                        class="home-alert-status <?= $alert["status"] === "critical" ? "home-alert-status-low" : "home-alert-status-forecast" ?>">
                                                        <?= $alert["status"] === "critical" ? "Critical forecast" : "Forecast shortage" ?>
                                                    </span>
                                                    <small>
                                                        <?= $alert["days_remaining"] !== null
                                                            ? (int) $alert["days_remaining"] . " estimated days remaining"
                                                            : "Review supply forecast" ?>
                                                    </small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <a href="#tab-inventory" class="home-alert-link" data-tab="inventory">
                                View medicine inventory <span aria-hidden="true">&rsaquo;</span>
                            </a>
                        </div>

                    </div>

                </div>

            </section>


            <!-- SCHEDULE -->
            <section class="dash-section" id="tab-schedule">
                <div class="schedule-page-header">
                    <h1 class="dash-title">Appointment Schedule</h1>
                    <p class="dash-sub">Browse pending and confirmed appointments, then review previous appointment
                        records for <?= htmlspecialchars($centerName, ENT_QUOTES, "UTF-8") ?>.</p>
                </div>
                <div class="dash-card schedule-card">
                    <div class="schedule-card-header">
                        <div>
                            <h2 class="card-heading">Upcoming Appointments</h2>
                            <p class="hint">Pending requests and confirmed visits at this health center.</p>
                        </div>
                        <label class="schedule-search-wrap"><span class="sr-only">Search upcoming
                                appointments</span><input type="search" class="schedule-search"
                                data-table-search="schedule-table" placeholder="Search patient or service..."
                                autocomplete="off"></label>
                    </div>
                    <div class="schedule-table-wrap">
                        <table class="dash-table" id="schedule-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Patient</th>
                                    <th>Service</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($schedule->num_rows > 0): ?>
                                    <?php while ($row = $schedule->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= date("M j, Y", strtotime($row["appointment_date"])) ?></td>
                                            <td><?= date("g:i A", strtotime($row["appointment_time"])) ?></td>
                                            <td><?= htmlspecialchars($row["full_name"], ENT_QUOTES, "UTF-8") ?></td>
                                            <td><?= htmlspecialchars($row["service"], ENT_QUOTES, "UTF-8") ?></td>
                                            <td><span
                                                    class="status-pill status-<?= strtolower(htmlspecialchars($row["status"], ENT_QUOTES, "UTF-8")) ?>"><?= htmlspecialchars($row["status"], ENT_QUOTES, "UTF-8") ?></span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr class="schedule-empty-row">
                                        <td colspan="5" class="schedule-empty-state"><span class="schedule-empty-icon"
                                                aria-hidden="true">&#128197;</span><strong>No upcoming
                                                appointments</strong><span>There are currently no pending or confirmed
                                                appointments to display.</span></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <p class="schedule-no-results" hidden>No appointments match your search.</p>
                    </div>
                    <p class="hint schedule-note">To confirm or reject a pending request, open the Home tab.</p>
                </div>
                <div class="dash-card schedule-card">
                    <div class="schedule-card-header">
                        <div>
                            <h2 class="card-heading">Recent Appointment History</h2>
                            <p class="hint">Review previously processed appointments and their recorded statuses.</p>
                        </div>
                        <label class="schedule-search-wrap"><span class="sr-only">Search appointment
                                history</span><input type="search" class="schedule-search"
                                data-table-search="history-table" placeholder="Search patient or service..."
                                autocomplete="off"></label>
                    </div>
                    <div class="schedule-table-wrap">
                        <table class="dash-table" id="history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Patient</th>
                                    <th>Service</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($history->num_rows > 0): ?>
                                    <?php while ($row = $history->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= date("M j, Y", strtotime($row["appointment_date"])) ?></td>
                                            <td><?= htmlspecialchars($row["full_name"], ENT_QUOTES, "UTF-8") ?></td>
                                            <td><?= htmlspecialchars($row["service"], ENT_QUOTES, "UTF-8") ?></td>
                                            <td><span
                                                    class="status-pill status-<?= strtolower(htmlspecialchars($row["status"], ENT_QUOTES, "UTF-8")) ?>"><?= htmlspecialchars($row["status"], ENT_QUOTES, "UTF-8") ?></span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr class="schedule-empty-row">
                                        <td colspan="4" class="schedule-empty-state"><span class="schedule-empty-icon"
                                                aria-hidden="true">&#128203;</span><strong>No appointment history
                                                yet</strong><span>Processed appointments will appear here when records are
                                                available.</span></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <p class="schedule-no-results" hidden>No history records match your search.</p>
                    </div>
                </div>
            </section>

            <!-- MEDICINE INVENTORY -->
            <section class="dash-section" id="tab-inventory">
                <h1 class="dash-title">Medicine Inventory</h1>
                <p class="dash-sub">Manage medicine stocks, acquisition dates, and expiration dates at
                    <?= htmlspecialchars($centerName) ?>.
                </p>
                <div class="dash-card">
                    <h2 class="card-heading">Add a Medicine</h2>
                    <form action="process_medicine.php" method="POST" class="appt-form">
                        <input type="hidden" name="action" value="add">
                        <div class="field-row">
                            <div class="field">
                                <label for="med-name">Medicine Name</label>
                                <input type="text" id="med-name" name="name" required>
                            </div>

                            <div class="field">
                                <label for="med-unit">Type</label>
                                <select id="med-unit" name="unit" required>
                                    <option value="" disabled selected>Select a Type</option>
                                    <option value="tablet">Tablet</option>
                                    <option value="capsule">Capsule</option>
                                    <option value="bottle">Bottle</option>
                                    <option value="vial">Vial</option>
                                    <option value="ampoule">Ampoule</option>
                                    <option value="sachet">Sachet</option>
                                    <option value="tube">Tube</option>
                                    <option value="box">Box</option>
                                    <option value="pack">Pack</option>
                                    <option value="piece">Piece</option>
                                    <option value="strip">Strip</option>
                                    <option value="blister">Blister</option>
                                    <option value="ml">Milliliter (mL)</option>
                                    <option value="dose">Dose</option>
                                </select>
                            </div>

                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label for="med-stock">Starting Stock</label>
                                <input type="number" id="med-stock" name="stock_quantity" min="0" required>
                            </div>
                            <div class="field">
                                <label for="med-threshold">Low-Stock Threshold</label>
                                <input type="number" id="med-threshold" name="reorder_threshold" min="0" value="10"
                                    required>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label for="med-acquisition">Acquisition Date</label>
                                <input type="date" id="med-acquisition" name="acquisition_date"
                                    max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="field">
                                <label for="med-expiration">Expiration Date</label>
                                <input type="date" id="med-expiration" name="expiration_date"
                                    min="<?= date('Y-m-d') ?>">
                                <small class="hint">Leave blank if the expiration date is unknown or not
                                    applicable.</small>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">Add Medicine</button>
                    </form>
                </div>

                <div class="dash-card">
                    <h2 class="card-heading">Current Stock by Batch</h2>
                    <p class="hint">
                        Each row represents a separate batch. Restocking creates a new batch
                        without changing the dates of existing batches.
                    </p>

                    <div class="table-responsive">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Batch ID</th>
                                    <th>Acquisition Date</th>
                                    <th>Expiration Date</th>
                                    <th>Expiration Status</th>
                                    <th>Type</th>
                                    <th>Batch Remaining</th>
                                    <th>Total Stock</th>
                                    <th>Stock Status</th>
                                    <th>Restock</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($medicines->num_rows > 0): ?>
                                    <?php while ($row = $medicines->fetch_assoc()): ?>
                                        <?php
                                        $isLow = (int) $row["stock_quantity"]
                                            <= (int) $row["reorder_threshold"];

                                        $hasBatch = $row["batch_id"] !== null;

                                        $batchAcquisition = $row["batch_acquisition_date"] ?? null;
                                        $batchExpiration = $row["batch_expiration_date"] ?? null;

                                        $today = new DateTimeImmutable("today");
                                        $expirationDate = !empty($batchExpiration)
                                            ? DateTimeImmutable::createFromFormat("!Y-m-d", $batchExpiration)
                                            : false;

                                        $expirationLabel = "Not recorded";
                                        $expirationClass = "status-completed";

                                        if (!$hasBatch) {
                                            $expirationLabel = "No batch record";
                                            $expirationClass = "status-pending";
                                        } elseif ($expirationDate instanceof DateTimeImmutable) {
                                            $daysUntilExpiration = (int) $today
                                                ->diff($expirationDate)->format("%r%a");

                                            if ($daysUntilExpiration < 0) {
                                                $expirationLabel = "Expired";
                                                $expirationClass = "status-rejected";
                                            } elseif ($daysUntilExpiration === 0) {
                                                $expirationLabel = "Expires today";
                                                $expirationClass = "status-pending";
                                            } elseif ($daysUntilExpiration <= 75) {
                                                $expirationLabel = "Expires in " . $daysUntilExpiration . " day(s)";
                                                $expirationClass = "status-pending";
                                            } else {
                                                $expirationLabel = "Valid";
                                                $expirationClass = "status-confirmed";
                                            }
                                        }
                                        ?>

                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($row["name"], ENT_QUOTES, "UTF-8") ?>
                                            </td>

                                            <td>
                                                <?= $hasBatch ? (int) $row["batch_id"] : "—" ?>
                                            </td>

                                            <td>
                                                <?= !empty($batchAcquisition)
                                                    ? htmlspecialchars(date("M j, Y", strtotime($batchAcquisition)))
                                                    : "—" ?>
                                            </td>

                                            <td>
                                                <?= $expirationDate instanceof DateTimeImmutable
                                                    ? htmlspecialchars($expirationDate->format("M j, Y"))
                                                    : "—" ?>
                                            </td>

                                            <td>
                                                <span class="status-pill <?= $expirationClass ?>">
                                                    <?= htmlspecialchars($expirationLabel, ENT_QUOTES, "UTF-8") ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($row["unit"], ENT_QUOTES, "UTF-8") ?>
                                            </td>

                                            <td>
                                                <?= $hasBatch
                                                    ? number_format((int) $row["quantity_remaining"])
                                                    : "Not batch-tracked" ?>
                                            </td>

                                            <td>
                                                <?= number_format((int) $row["stock_quantity"]) ?>
                                            </td>

                                            <td>
                                                <?php if (!$hasBatch): ?>
                                                    <span class="status-pill status-pending">
                                                        Batch needed
                                                    </span>
                                                <?php elseif ($isLow): ?>
                                                    <span class="status-pill status-rejected">
                                                        Low Stock
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-pill status-confirmed">
                                                        In Stock
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <form action="process_medicine.php" method="POST" class="restock-form">

                                                    <input type="hidden" name="action" value="restock">
                                                    <input type="hidden" name="medicine_id" value="<?= (int) $row["id"] ?>">

                                                    <label
                                                        for="restock-qty-<?= (int) $row["id"] ?>-<?= $hasBatch ? (int) $row["batch_id"] : 0 ?>">
                                                        Quantity to add
                                                    </label>

                                                    <input type="number"
                                                        id="restock-qty-<?= (int) $row["id"] ?>-<?= $hasBatch ? (int) $row["batch_id"] : 0 ?>"
                                                        name="add_quantity" min="1" required>

                                                    <label
                                                        for="restock-acq-<?= (int) $row["id"] ?>-<?= $hasBatch ? (int) $row["batch_id"] : 0 ?>">
                                                        Acquisition date
                                                    </label>

                                                    <input type="date"
                                                        id="restock-acq-<?= (int) $row["id"] ?>-<?= $hasBatch ? (int) $row["batch_id"] : 0 ?>"
                                                        name="acquisition_date" value="<?= date("Y-m-d") ?>"
                                                        max="<?= date("Y-m-d") ?>" required>

                                                    <label
                                                        for="restock-exp-<?= (int) $row["id"] ?>-<?= $hasBatch ? (int) $row["batch_id"] : 0 ?>">
                                                        Expiration date
                                                    </label>

                                                    <input type="date"
                                                        id="restock-exp-<?= (int) $row["id"] ?>-<?= $hasBatch ? (int) $row["batch_id"] : 0 ?>"
                                                        name="expiration_date" min="<?= date("Y-m-d") ?>">

                                                    <small class="hint">
                                                        Leave blank if unknown or not applicable.
                                                    </small>

                                                    <button type="submit" class="link-btn">
                                                        Add Batch
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="empty-state">
                                            No medicines have been added yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                $forecastChartable = array_values(array_filter($forecast, fn($f) => $f["days_remaining"] !== null));
                $forecastColorMap = ["critical" => "#C0392B", "soon" => "#E8A33D", "fine" => "#1E7A4C"];
                ?>
                <div class="dash-card">
                    <div class="card-heading-row">
                        <h2 class="card-heading">Supply Forecast</h2>
                        <?php if ($criticalCount > 0): ?>
                            <span class="status-pill status-rejected"><?= (int) $criticalCount ?> critical</span>
                        <?php endif; ?>
                    </div>
                    <p class="hint" style="margin-bottom:12px;">Based on a 14-day consumption trend. Medicines with
                        insufficient prescription history are marked "Not enough data."</p>
                    <?php if (!empty($forecastChartable)): ?>
                        <canvas id="forecastChart" height="<?= max(80, count($forecastChartable) * 28) ?>"></canvas>
                        <?php if (count($forecastChartable) < count($forecast)): ?>
                            <p class="hint" style="margin-top:8px;"><?= count($forecast) - count($forecastChartable) ?>
                                medicine(s) with insufficient data aren't shown on the chart. See the table below.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Stock</th>
                                    <th>Daily Use (Avg.)</th>
                                    <th>Estimated Days Left</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($forecast)): ?>
                                    <?php foreach ($forecast as $f): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($f["name"]) ?></td>
                                            <td><?= (int) $f["stock_quantity"] ?>         <?= htmlspecialchars($f["unit"]) ?></td>
                                            <td><?= $f["daily_rate"] !== null ? number_format($f["daily_rate"], 1) . " " . htmlspecialchars($f["unit"]) . "/day" : "—" ?>
                                            </td>
                                            <td><?= $f["days_remaining"] !== null ? (int) $f["days_remaining"] . " days" : "—" ?>
                                            </td>
                                            <td>
                                                <?php
                                                $forecastPill = [
                                                    "critical" => ["status-rejected", "Critical"],
                                                    "soon" => ["status-pending", "Reorder Soon"],
                                                    "fine" => ["status-confirmed", "Fine"],
                                                    "insufficient" => ["status-completed", "Not Enough Data"]
                                                ][$f["status"]] ?? ["status-completed", "Unknown"];
                                                ?>
                                                <span
                                                    class="status-pill <?= $forecastPill[0] ?>"><?= htmlspecialchars($forecastPill[1]) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="empty-state">No supply forecast data available.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>



            <!-- ================= REPORTS ================= -->
            <section class="dash-section" id="tab-reports">
                <style>
                    .report-table-legend{margin-top:12px;padding:12px 14px;border:1px solid #dce7e4;border-radius:9px;background:#f7fbfa;color:#425b57;font-size:12px;line-height:1.55}
                    .report-table-legend-title{font-weight:700;color:#234b45;margin:0 0 7px}
                    .report-table-legend-items{display:flex;flex-wrap:wrap;gap:8px 16px;margin:0;padding:0;list-style:none}
                    .report-table-legend-items li{display:flex;align-items:center;gap:6px}
                    .report-legend-dot{display:inline-block;width:9px;height:9px;border-radius:50%;flex:0 0 9px}
                    .report-legend-dot.danger{background:#c83d4d}.report-legend-dot.warning{background:#d99a24}.report-legend-dot.good{background:#27845b}.report-legend-dot.neutral{background:#87939e}
                    .report-legend-dot.appointments{background:#146b61}.report-legend-dot.stock{background:#e8a33d}.report-legend-dot.services{background:#0e4d46}
                    .report-table-legend p{margin:6px 0 0}
                    @media print{.report-table-legend{break-inside:avoid;background:#fff!important}}
                </style>
                <h1 class="dash-title">Reports & Analytics</h1>
                <p class="dash-sub">
                    <?= htmlspecialchars($centerName) ?> ·
                    Appointment activity, medicine inventory, and supply forecasts.
                </p>

                <!-- DATE FILTER -->
                <div class="dash-card">
                    <div class="card-heading-row">
                        <h2 class="card-heading">Report period</h2>
                    </div>

                    <form method="GET" action="staff_dashboard.php" class="report-filter">
                        <input type="hidden" name="tab" value="reports">

                        <div class="report-filter-field">
                            <label for="report_start">Start date</label>
                            <input type="date" id="report_start" name="report_start"
                                value="<?= htmlspecialchars($reportStart) ?>" required>
                        </div>

                        <div class="report-filter-field">
                            <label for="report_end">End date</label>
                            <input type="date" id="report_end" name="report_end"
                                value="<?= htmlspecialchars($reportEnd) ?>" required>
                        </div>

                        <button type="submit" class="btn-primary btn-inline">
                            Apply filter
                        </button>

                        <a href="staff_dashboard.php?tab=reports" class="btn-secondary">
                            Reset
                        </a>
                    </form>
                </div>

                <!-- SUMMARY CARDS -->
                <div class="report-summary-grid">

                    <div class="dash-card report-stat-card">
                        <div class="report-stat-icon">📅</div>
                        <div class="report-stat-label">Total appointments</div>
                        <div class="report-stat-value">
                            <?= number_format($reportTotalAppointments) ?>
                        </div>
                        <p class="hint">Within the selected period</p>
                    </div>

                    <div class="dash-card report-stat-card">
                        <div class="report-stat-icon">✓</div>
                        <div class="report-stat-label">Completed consultations</div>
                        <div class="report-stat-value">
                            <?= number_format($reportCompleted) ?>
                        </div>
                        <p class="hint">Visits marked completed</p>
                    </div>

                    <div class="dash-card report-stat-card">
                        <div class="report-stat-icon">📦</div>
                        <div class="report-stat-label">Low-stock medicines</div>
                        <div class="report-stat-value">
                            <?= number_format($lowStockCount) ?>
                        </div>
                        <p class="hint">At or below reorder threshold</p>
                    </div>

                    <div class="dash-card report-stat-card">
                        <div class="report-stat-icon">⌛</div>
                        <div class="report-stat-label">Expiring within 30 days</div>
                        <div class="report-stat-value">
                            <?= number_format($expiringSoonCount) ?>
                        </div>
                        <p class="hint">Excludes already expired stock</p>
                    </div>

                </div>

                <!-- APPOINTMENT REPORTS -->
                <div class="report-layout-grid">

                    <div class="dash-card report-card-wide">
                        <div class="card-heading-row">
                            <div>
                                <h2 class="card-heading">Appointment activity</h2>
                                <p class="hint">Daily appointment volume for the selected period. The CSV includes a report header, status summary, and appointment-level records for these dates.</p>
                            </div>

                            <div class="report-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <a
                                    href="appointment_activity_report.php?report_start=<?= rawurlencode($reportStart) ?>&amp;report_end=<?= rawurlencode($reportEnd) ?>"
                                    class="btn-primary btn-inline">
                                    View appointment report
                                </a>
                            </div>
                        </div>

                        <?php if (!empty($chartRangeTooLarge)): ?>
                            <p class="hint">
                                The selected period is too large for the daily chart.
                                Select a period of 366 days or less.
                            </p>
                        <?php else: ?>
                            <div class="report-chart-container">
                                <canvas id="apptsChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- MEDICINE INVENTORY REPORTS -->
                <div class="dash-card">
                    <div class="card-heading-row">
                        <div>
                            <h2 class="card-heading">Medicine stock overview</h2>
                            <p class="hint">
                                Current stock levels of the medicines with the highest quantities.
                            </p>
                        </div>

                        <a href="medicine_stockpile_report.php" class="btn-primary btn-inline">
                            Monthly stockpile report
                        </a>
                    </div>

                    <div class="report-chart-container">
                        <canvas id="stockChart"></canvas>
                    </div>
                    <div class="report-table-legend" aria-label="Medicine stock overview chart legend">
                        <p class="report-table-legend-title">Chart legend</p>
                        <ul class="report-table-legend-items">
                            <li><span class="report-legend-dot stock"></span><strong>Stock on hand:</strong> current recorded quantity for each medicine.</li>
                        </ul>
                        <p><strong>Medicine name:</strong> the item in inventory. <strong>Horizontal axis:</strong> current stock quantity. This chart compares quantities, not the relative risk of running out; use the AI supply forecast for estimated days remaining.</p>
                    </div>
                </div>

                <!-- EXPIRATION MONITORING -->
                <div class="dash-card">
                    <div class="card-heading-row">
                        <div>
                            <h2 class="card-heading">Medicine expiration monitoring</h2>
                            <p class="hint">
                                Expired medicines and medicines approaching their expiration dates.
                            </p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Acquisition date</th>
                                    <th>Expiration date</th>
                                    <th>Stock</th>
                                    <th>Expiration status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($expirationRows)): ?>
                                    <?php foreach ($expirationRows as $medicine): ?>
                                        <?php
                                        $today = new DateTimeImmutable('today');
                                        $expiry = new DateTimeImmutable(
                                            $medicine['expiration_date']
                                        );
                                        $daysToExpiry = (int) $today->diff($expiry)->format('%r%a');

                                        if ($daysToExpiry < 0) {
                                            $expiryLabel = 'Expired';
                                            $expiryClass = 'report-status-danger';
                                        } elseif ($daysToExpiry === 0) {
                                            $expiryLabel = 'Expires today';
                                            $expiryClass = 'report-status-warning';
                                        } else {
                                            $expiryLabel = 'Expires in ' . $daysToExpiry . ' day(s)';
                                            $expiryClass = $daysToExpiry <= 7
                                                ? 'report-status-warning'
                                                : 'report-status-neutral';
                                        }
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($medicine['name']) ?></td>
                                            <td>
                                                <?= !empty($medicine['acquisition_date'])
                                                    ? date('M j, Y', strtotime($medicine['acquisition_date']))
                                                    : 'Not recorded' ?>
                                            </td>
                                            <td>
                                                <?= date('M j, Y', strtotime($medicine['expiration_date'])) ?>
                                            </td>
                                            <td><?= number_format((int) $medicine['stock_quantity']) ?></td>
                                            <td>
                                                <span class="report-status <?= $expiryClass ?>">
                                                    <?= htmlspecialchars($expiryLabel) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="report-empty">
                                            No expired or soon-to-expire medicines found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="report-table-legend" aria-label="Medicine expiration table legend">
                        <p class="report-table-legend-title">Table legend</p>
                        <ul class="report-table-legend-items">
                            <li><span class="report-legend-dot danger"></span><strong>Expired:</strong> expiration date is before today.</li>
                            <li><span class="report-legend-dot warning"></span><strong>Urgent:</strong> expires today or within 7 days.</li>
                            <li><span class="report-legend-dot neutral"></span><strong>Upcoming:</strong> expires in more than 7 days.</li>
                        </ul>
                        <p><strong>Acquisition date</strong> is when the stock was received, if recorded. <strong>Stock</strong> is the quantity currently recorded in inventory. “Not recorded” means no acquisition date is available.</p>
                    </div>
                </div>

                <!-- AI SUPPLY FORECAST -->
                <div class="dash-card">
                    <div class="card-heading-row">
                        <div>
                            <h2 class="card-heading">Medicine supply forecasting</h2>
                            <p class="hint">
                                Estimated days remaining based on recent prescription trends.
                            </p>
                        </div>
                        <div class="report-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <a href="ai_medicine_forecast_report.php" class="btn-primary btn-inline">
                                View forecasting report
                            </a>
                        </div>
                    </div>

                    <div class="report-forecast-summary">
                        <div>
                            <span class="report-status report-status-danger">Critical</span>
                            <strong><?= (int) $criticalCount ?></strong>
                            <p class="hint">Estimated to run out in fewer than 7 days.</p>
                        </div>

                        <div>
                            <span class="report-status report-status-warning">Running low</span>
                            <strong><?= (int) $soonCount ?></strong>
                            <p class="hint">Estimated to run out in 7 to 13 days.</p>
                        </div>

                        <div>
                            <span class="report-status report-status-neutral">Insufficient data</span>
                            <strong><?= (int) $insufficientCount ?></strong>
                            <p class="hint">Not enough prescription history for a reliable estimate.</p>
                        </div>
                    </div>

                    <div class="report-chart-container" style="position: relative; min-height: 280px; height: 380px;">
                        <canvas id="reportForecastChart"></canvas>
                    </div>

                    <div class="table-responsive">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Current stock</th>
                                    <th>Daily usage estimate</th>
                                    <th>Days remaining</th>
                                    <th>Forecast status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($forecast)): ?>
                                    <?php foreach ($forecast as $item): ?>
                                        <?php
                                        $forecastStatus = $item['status'];

                                        $statusClass = match ($forecastStatus) {
                                            'critical' => 'report-status-danger',
                                            'soon' => 'report-status-warning',
                                            'fine' => 'report-status-good',
                                            default => 'report-status-neutral',
                                        };

                                        $statusLabel = match ($forecastStatus) {
                                            'critical' => 'Critical',
                                            'soon' => 'Running low',
                                            'fine' => 'Sufficient',
                                            default => 'Insufficient data',
                                        };
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['name']) ?></td>
                                            <td>
                                                <?= number_format((int) $item['stock_quantity']) ?>
                                                <?= htmlspecialchars($item['unit'] ?? '') ?>
                                            </td>
                                            <td>
                                                <?= $item['daily_rate'] !== null
                                                    ? number_format((float) $item['daily_rate'], 2)
                                                    : 'Unavailable' ?>
                                            </td>
                                            <td>
                                                <?= $item['days_remaining'] !== null
                                                    ? (int) $item['days_remaining'] . ' days'
                                                    : 'Unavailable' ?>
                                            </td>
                                            <td>
                                                <span class="report-status <?= $statusClass ?>">
                                                    <?= htmlspecialchars($statusLabel) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="report-empty">
                                            No medicine records are available for forecasting.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="report-table-legend" aria-label="AI medicine supply forecast table legend">
                        <p class="report-table-legend-title">Table legend</p>
                        <ul class="report-table-legend-items">
                            <li><span class="report-legend-dot danger"></span><strong>Critical:</strong> estimated to run out in fewer than 7 days.</li>
                            <li><span class="report-legend-dot warning"></span><strong>Running low:</strong> estimated to run out in 7–13 days.</li>
                            <li><span class="report-legend-dot good"></span><strong>Sufficient:</strong> estimated stock lasts 14 days or more.</li>
                            <li><span class="report-legend-dot neutral"></span><strong>Insufficient data:</strong> usage history is unavailable or not enough to estimate reliably.</li>
                        </ul>
                        <p><strong>Current stock</strong> is the recorded quantity and unit. <strong>Daily usage estimate</strong> is the model-estimated amount used per day. <strong>Days remaining</strong> is an estimate based on current stock and recent usage, not a guaranteed depletion date.</p>
                    </div>
                </div>

                <!-- SERVICE UTILIZATION -->
                <div class="dash-card">
                    <div class="card-heading-row">
                        <div>
                            <h2 class="card-heading">Most utilized health services</h2>
                            <p class="hint">
                                Most frequently booked services within the selected report period.
                            </p>
                        </div>
                        <div class="report-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <a href="health_service_utilization_report.php?report_start=<?= rawurlencode($reportStart) ?>&amp;report_end=<?= rawurlencode($reportEnd) ?>"
                               class="btn-primary btn-inline">View service report</a>
                        </div>
                    </div>

                    <div class="report-chart-container">
                        <canvas id="serviceChart"></canvas>
                    </div>
                    <div class="report-table-legend" aria-label="Most utilized health services chart legend">
                        <p class="report-table-legend-title">Chart legend</p>
                        <ul class="report-table-legend-items">
                            <li><span class="report-legend-dot services"></span><strong>Times booked:</strong> appointment count for each health service.</li>
                        </ul>
                        <p><strong>Service name:</strong> the health service. <strong>Horizontal axis:</strong> number of appointments. Higher bars indicate more recorded bookings in the selected period. This is appointment activity, not a count of unique patients or necessarily completed visits.</p>
                    </div>
                </div>

            </section>



            <!-- ACCOUNT -->
            <section class="dash-section" id="tab-account">
                <h1 class="dash-title">My Account</h1>
                <p class="dash-sub">Update your contact details or change your password.</p>

                <div class="dash-card">
                    <h2 class="card-heading">Contact details</h2>
                    <form action="update_account.php" method="POST" class="appt-form">
                        <input type="hidden" name="action" value="contact">
                        <div class="field-row">
                            <div class="field">
                                <label for="acc-email">Email</label>
                                <input type="email" id="acc-email" name="email"
                                    value="<?= htmlspecialchars($userRow["email"]) ?>" required>
                            </div>
                            <div class="field">
                                <label for="acc-mobile">Mobile number</label>
                                <input type="tel" id="acc-mobile" name="mobile"
                                    value="<?= htmlspecialchars($staff["mobile"]) ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">Save changes</button>
                    </form>
                </div>

                <div class="dash-card">
                    <h2 class="card-heading">Change password</h2>
                    <form action="update_account.php" method="POST" class="appt-form">
                        <input type="hidden" name="action" value="password">
                        <div class="field">
                            <label for="acc-current">Current password</label>
                            <div class="password-wrapper">
                                <input type="password" id="acc-current" name="current_password" required>
                                <button type="button" class="toggle-password" data-target="acc-current"
                                    aria-label="Show password">
                                    <svg class="icon-eye" viewBox="0 0 24 24">
                                        <path
                                            d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z" />
                                    </svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;">
                                        <path
                                            d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="field">
                            <label for="acc-newpass">New password</label>
                            <div class="password-wrapper">
                                <input type="password" id="acc-newpass" name="new_password" required>
                                <button type="button" class="toggle-password" data-target="acc-newpass"
                                    aria-label="Show password">
                                    <svg class="icon-eye" viewBox="0 0 24 24">
                                        <path
                                            d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z" />
                                    </svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;">
                                        <path
                                            d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z" />
                                    </svg>
                                </button>
                            </div>
                            <ul class="pw-requirements" id="pwRequirements">
                                <li data-rule="length">At least 8 characters</li>
                                <li data-rule="number">Contains a number</li>
                                <li data-rule="special">Contains a special character</li>
                            </ul>
                        </div>
                        <button type="submit" class="btn-primary">Update password</button>
                    </form>
                </div>
            </section>

        </main>
    </div>

    <script src="patient_logic.js"></script>
    <script>
        // Filter Schedule tables by any visible cell value.
        document.querySelectorAll("[data-table-search]").forEach(function (searchInput) {
            searchInput.addEventListener("input", function () {
                const table = document.getElementById(searchInput.dataset.tableSearch);
                if (!table) return;
                const query = searchInput.value.trim().toLocaleLowerCase();
                const rows = Array.from(table.querySelectorAll("tbody tr:not(.schedule-empty-row)"));
                let visibleCount = 0;
                rows.forEach(function (row) {
                    const matches = row.textContent.toLocaleLowerCase().includes(query);
                    row.hidden = !matches;
                    if (matches) visibleCount++;
                });
                const noResults = table.parentElement.querySelector(".schedule-no-results");
                if (noResults) noResults.hidden = query.length === 0 || visibleCount > 0 || rows.length === 0;
            });
        });


        <?php if (!empty($forecast)): ?>
            const reportForecastCanvas = document.getElementById("reportForecastChart");

            if (reportForecastCanvas) {
                const forecastItems = <?= json_encode(
                    array_values(array_filter(
                        $forecast,
                        fn($item) => $item["days_remaining"] !== null
                    )),
                    JSON_HEX_TAG | JSON_HEX_APOS |
                    JSON_HEX_AMP | JSON_HEX_QUOT
                ) ?>;

                const forecastColors = {
                    critical: "#C0392B",
                    soon: "#E8A33D",
                    fine: "#1E7A4C"
                };

                new Chart(reportForecastCanvas, {
                    type: "bar",
                    data: {
                        labels: forecastItems.map(item => item.name),
                        datasets: [{
                            label: "Estimated days remaining",
                            data: forecastItems.map(item =>
                                Number(item.days_remaining)
                            ),
                            backgroundColor: forecastItems.map(item =>
                                forecastColors[item.status] || "#64748B"
                            ),
                            borderRadius: 5,
                            barThickness: 24
                        }]
                    },
                    options: {
                        indexAxis: "y",
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    afterLabel: function (context) {
                                        const item = forecastItems[context.dataIndex];
                                        const rate = Number(item.daily_rate);

                                        return Number.isFinite(rate)
                                            ? "Estimated daily usage: " +
                                            rate.toFixed(2) + " " +
                                            (item.unit || "units") + "/day"
                                            : "Daily usage unavailable";
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: "Estimated days before stock runs out"
                                },
                                ticks: {
                                    precision: 0
                                }
                            },
                            y: {
                                ticks: {
                                    autoSkip: false
                                }
                            }
                        }
                    }
                });
            }
        <?php endif; ?>


        const apptsByDay = <?= json_encode($apptsByDay) ?>;
        new Chart(document.getElementById("apptsChart"), {
            type: "bar",
            data: {
                labels: Object.keys(apptsByDay).map(d => new Date(d + "T00:00:00").toLocaleDateString(undefined, { month: "short", day: "numeric" })),
                datasets: [{ label: "Appointments", data: Object.values(apptsByDay), backgroundColor: "#146B61" }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
        });

        <?php if (!empty($stockLabels)): ?>
            new Chart(document.getElementById("stockChart"), {
                type: "bar",
                data: {
                    labels: <?= json_encode($stockLabels) ?>,
                    datasets: [{ label: "Stock on hand", data: <?= json_encode($stockValues) ?>, backgroundColor: "#E8A33D" }]
                },
                options: { indexAxis: "y", plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
            });
        <?php endif; ?>

        <?php if (!empty($serviceLabels)): ?>
            new Chart(document.getElementById("serviceChart"), {
                type: "bar",
                data: {
                    labels: <?= json_encode($serviceLabels) ?>,
                    datasets: [{ label: "Times booked", data: <?= json_encode($serviceValues) ?>, backgroundColor: "#0E4D46" }]
                },
                options: { indexAxis: "y", plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } } }
            });
        <?php endif; ?>
    </script>
</body>

</html>