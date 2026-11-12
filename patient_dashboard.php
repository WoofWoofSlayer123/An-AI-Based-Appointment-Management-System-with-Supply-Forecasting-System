<?php
session_start();
include "connection.php";
include "auth_check.php"; // restores session from remember-me cookie, or redirects to sign in
include "booking_rules.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// This dashboard is for patients only — bounce anyone else to their own dashboard.
if ($_SESSION["role"] !== "patient") {
    if ($_SESSION["role"] === "admin") {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: staff_dashboard.php");
    }
    exit;
}

$username = $_SESSION["username"];

// Re-fetch the patient's own record so this page always shows current data
$stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmtUser = $conn->prepare("SELECT email FROM users WHERE username = ?");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$userRow = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

// All health centers
$centers = [];
$centerResult = $conn->query("SELECT id, name, address, contact_number, operating_hours FROM health_centers ORDER BY name");
if ($centerResult) {
    while ($row = $centerResult->fetch_assoc()) {
        $centers[] = $row;
    }
}
function center_name($centers, $id)
{
    foreach ($centers as $c)
        if ($c["id"] == $id)
            return $c["name"];
    return "Unknown center";
}


/* Working hours and closure dates used by the booking form. */
$centerHours = [];
$centerClosures = [];

foreach ($centers as $c) {
    $centerId = (int) $c["id"];

    $centerHours[$centerId] = parse_operating_hours(
        $c["operating_hours"]
    );

    $centerClosures[$centerId] = [];
}

/* Load future closure dates for every center. */
$closureResult = $conn->query("
    SELECT health_center_id, status_date, note
    FROM health_center_status
    WHERE status = 'Closed'
      AND status_date BETWEEN CURDATE()
                          AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY status_date
");

if ($closureResult) {
    while ($row = $closureResult->fetch_assoc()) {
        $centerId = (int) $row["health_center_id"];

        if (isset($centerClosures[$centerId])) {
            $centerClosures[$centerId][$row["status_date"]] = [
                "status" => "Closed",
                "note" => $row["note"] ?? ""
            ];
        }
    }
}

// Today's status overrides
$todayStatus = [];
$statusResult = $conn->query("SELECT * FROM health_center_status WHERE status_date = CURDATE()");
if ($statusResult) {
    while ($row = $statusResult->fetch_assoc()) {
        $todayStatus[$row["health_center_id"]] = $row;
    }
}

// Today's booked counts per center
$centerBookedCounts = [];
$countResult = $conn->query("
    SELECT health_center_id, COUNT(*) AS booked
    FROM appointments
    WHERE appointment_date = CURDATE() AND status NOT IN ('Cancelled','Rejected')
    GROUP BY health_center_id
");
if ($countResult) {
    while ($row = $countResult->fetch_assoc()) {
        $centerBookedCounts[$row["health_center_id"]] = (int) $row["booked"];
    }
}
function booked_count($centerBookedCounts, $id)
{
    return $centerBookedCounts[$id] ?? 0;
}

// Hourly breakdown
$centerHourly = [];
$hourlyResult = $conn->query("
    SELECT health_center_id, HOUR(appointment_time) AS booked_hour, COUNT(*) AS cnt
    FROM appointments
    WHERE appointment_date = CURDATE() AND status NOT IN ('Cancelled','Rejected')
    GROUP BY health_center_id, HOUR(appointment_time)
    ORDER BY booked_hour
");
if ($hourlyResult) {
    while ($row = $hourlyResult->fetch_assoc()) {
        $centerHourly[$row["health_center_id"]][(int) $row["booked_hour"]] = (int) $row["cnt"];
    }
}
function hourly_breakdown_label($hour, $count)
{
    $label = date("g A", strtotime("$hour:00"));
    return "$label — $count booked";
}

// Least busy center
$leastBusyCenter = null;
$leastBusyCount = null;
foreach ($centers as $c) {
    if (isset($todayStatus[$c["id"]]) && $todayStatus[$c["id"]]["status"] === "Closed")
        continue;
    $c_count = booked_count($centerBookedCounts, $c["id"]);
    if ($leastBusyCount === null || $c_count < $leastBusyCount) {
        $leastBusyCount = $c_count;
        $leastBusyCenter = $c;
    }
}

// Countdown label helper
function countdown_label($date, $time)
{
    $apptDay = new DateTime($date);
    $today = new DateTime(date("Y-m-d"));
    $daysUntil = (int) $today->diff($apptDay)->format("%r%a");

    if ($daysUntil < 0)
        return "Past";
    if ($daysUntil === 0)
        return "Today, " . date("g:i A", strtotime($time));
    if ($daysUntil === 1)
        return "Tomorrow, " . date("g:i A", strtotime($time));
    if ($daysUntil <= 7)
        return "In $daysUntil days";
    return date("F j", strtotime($date));
}

$profileIncomplete = empty($patient["date_of_birth"]) || empty($patient["gender"])
    || empty($patient["address"]) || empty($patient["emergency_contact_name"])
    || empty($patient["emergency_contact_number"]);

// Notifications
$stmt = $conn->prepare("SELECT * FROM notifications WHERE username = ? ORDER BY created_at DESC LIMIT 10");
$stmt->bind_param("s", $username);
$stmt->execute();
$notifications = $stmt->get_result();

$stmt = $conn->prepare("SELECT COUNT(*) AS unread FROM notifications WHERE username = ? AND is_read = 0");
$stmt->bind_param("s", $username);
$stmt->execute();
$unreadCount = $stmt->get_result()->fetch_assoc()["unread"];

// Upcoming appointments
$stmt = $conn->prepare("
    SELECT * FROM appointments
    WHERE username = ? AND appointment_date >= CURDATE()
          AND status NOT IN ('Cancelled','Rejected','Completed')
    ORDER BY appointment_date, appointment_time
");
$stmt->bind_param("s", $username);
$stmt->execute();
$upcoming = $stmt->get_result();

// History appointments
$stmt = $conn->prepare("
    SELECT * FROM appointments
    WHERE username = ? AND (appointment_date < CURDATE() OR status IN ('Cancelled','Rejected','Completed'))
    ORDER BY appointment_date DESC, appointment_time DESC
");
$stmt->bind_param("s", $username);
$stmt->execute();
$history = $stmt->get_result();

$statusMsg = $_GET["msg"] ?? null;
$statusType = $_GET["type"] ?? "info";

// ---- Overview data ----
$next = null;
if ($upcoming->num_rows > 0) {
    $upcoming->data_seek(0);
    $next = $upcoming->fetch_assoc();
    $upcoming->data_seek(0);
}

// Things the patient needs to act on
$actionItems = [];
$pendingCount = 0;
$upcoming->data_seek(0);
while ($r = $upcoming->fetch_assoc()) {
    $d = (int) (new DateTime(date("Y-m-d")))->diff(new DateTime($r["appointment_date"]))->format("%r%a");
    if ($r["status"] === "Confirmed" && empty($r["attendance_confirmed"]) && $d >= 0 && $d <= 3)
        $actionItems[] = $r;
    if ($r["status"] === "Pending")
        $pendingCount++;
}
$upcoming->data_seek(0);

// Last three past visits
$recent = [];
$history->data_seek(0);
while (count($recent) < 3 && ($r = $history->fetch_assoc()))
    $recent[] = $r;
$history->data_seek(0);

// Online spots left in each center's current (or next) 2-hour window
$todayTimes = [];
$tr = $conn->query("SELECT health_center_id, TIME_TO_SEC(appointment_time) AS sec FROM appointments WHERE appointment_date = CURDATE() AND status NOT IN (" . INACTIVE_STATUSES_SQL . ")");
if ($tr)
    while ($r = $tr->fetch_assoc())
        $todayTimes[$r["health_center_id"]][] = (int) $r["sec"];
$nowMin = (int) date("G") * 60 + (int) date("i");
$centerSlots = [];
foreach ($centers as $c) {
    $h = $centerHours[$c["id"]];
    if ($nowMin >= $h["close"]) {
        $centerSlots[$c["id"]] = ["state" => "done"];
        continue;
    }
    [$ws, $we] = slot_window(max($nowMin, $h["open"]), $h["open"]);
    $used = 0;
    foreach (($todayTimes[$c["id"]] ?? []) as $sec)
        if ($sec >= $ws * 60 && $sec < $we * 60)
            $used++;
    $centerSlots[$c["id"]] = [
        "state" => $nowMin < $h["open"] ? "before" : "open",
        "left" => max(0, ONLINE_BOOKING_CAP - $used),
        "start" => $ws,
        "end" => min($we, $h["close"]),
    ];
}

// Display helpers
function status_class($status)
{
    return "status-" . strtolower(str_replace(" ", "-", $status));
}
function busy_info($count)
{
    if ($count >= 10)
        return ["busy-high", "Busy"];
    if ($count >= 5)
        return ["busy-medium", "Moderate"];
    return ["busy-low", "Light"];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Patient Dashboard — Malabon District II Health Centers System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="homepagestyle.css">
    <link rel="stylesheet" href="patient_style.css">

</head>

<body class="dashboard-body patient-dash" data-tab="overview">
    <div class="dash-shell">

        <!-- ---------- Sidebar ---------- -->
        <aside class="sidebar">
            <div class="sidebar-brand"><span class="dot"></span>Malabon District II Health Centers System</div>
            <div class="sidebar-user">
                <div class="sidebar-name"><?= htmlspecialchars($patient["full_name"] ?: $username) ?></div>
                <div class="sidebar-id">ID: <?= htmlspecialchars($patient["patient_id"]) ?></div>
            </div>
            <nav class="sidebar-nav">
                <a href="#overview" class="nav-link active"
                    data-tab="overview">Overview<?php if ($unreadCount > 0): ?><span
                            class="nav-badge"><?= (int) $unreadCount ?></span><?php endif; ?></a>
                <a href="#appointments" class="nav-link"
                    data-tab="appointments">Appointments<?php if ($upcoming->num_rows > 0): ?><span
                            class="nav-badge"><?= (int) $upcoming->num_rows ?></span><?php endif; ?></a>
                <a href="#centers" class="nav-link" data-tab="centers">Health Centers</a>
                <a href="#profile" class="nav-link" data-tab="profile">My Profile</a>
            </nav>
            <a href="logout.php" class="sidebar-logout">Sign out</a>
        </aside>

        <main class="dash-main">

            <?php if ($statusMsg): ?>
                <div
                    class="msg <?= $statusType === 'success' ? 'success' : ($statusType === 'error' ? 'error' : 'info') ?>">
                    <?= htmlspecialchars($statusMsg) ?>
                </div>
            <?php endif; ?>

            <?php if ($patient["pending_staff"]): ?>
                <div class="pending-banner global-banner">Your staff/doctor account request is still pending admin review.
                    You currently have patient-level access only.</div>
            <?php endif; ?>

            <?php if ($profileIncomplete): ?>
                <div class="msg error global-banner">Your profile is incomplete. Please fill in your personal and emergency
                    details. <a class="nav-link-inline" href="#profile" data-tab="profile">Complete profile</a></div>
            <?php endif; ?>

            <!-- ================= OVERVIEW ================= -->
            <section class="dash-section active" id="tab-overview">
                <h1 class="dash-title">Welcome back,
                    <?= htmlspecialchars(explode(" ", $patient["full_name"] ?: $username)[0]) ?>
                </h1>
                <p class="dash-sub"><?= date("l, F j, Y") ?> — you have <?= (int) $upcoming->num_rows ?> upcoming
                    appointment(s) in Malabon District II health facilities.</p>

                <div class="overview-grid">

                    <!-- Column 1: next appointment, quick actions, checklist -->
                    <div>
                        <div class="dash-card next-appt-card">
                            <div class="card-heading-row">
                                <h2 class="card-heading">Next appointment</h2>
                                <button type="button" class="link-btn" onclick="switchTab('appointments')">View full
                                    schedule</button>
                            </div>
                            <?php if ($next): ?>
                                <div class="next-appt-countdown">
                                    <?= countdown_label($next["appointment_date"], $next["appointment_time"]) ?>
                                </div>
                                <div class="next-appt"><?= htmlspecialchars($next["service"]) ?> —
                                    <?= htmlspecialchars(center_name($centers, $next["health_center_id"])) ?>
                                </div>
                                <div class="hint"><?= date("F j, Y", strtotime($next["appointment_date"])) ?> at
                                    <?= date("g:i A", strtotime($next["appointment_time"])) ?>
                                </div>
                                <a class="link-btn slip-link" href="appointment_slip.php?id=<?= (int) $next["id"] ?>"
                                    target="_blank">Confirmation slip</a>
                            <?php else: ?>
                                <p class="hint" style="margin:0 0 10px;">No upcoming appointments scheduled.</p>
                                <button type="button" class="btn-primary btn-inline"
                                    onclick="switchTab('appointments')">Book a visit</button>
                            <?php endif; ?>
                        </div>

                        <div class="dash-card">
                            <h2 class="card-heading">Quick actions</h2>
                            <div class="quick-actions">
                                <button type="button" class="btn-primary btn-inline"
                                    onclick="switchTab('appointments')">Book a visit</button>
                                <?php if ($next): ?><a class="btn-secondary"
                                        href="appointment_slip.php?id=<?= (int) $next["id"] ?>" target="_blank">View my
                                        slip</a><?php endif; ?>
                                <button type="button" class="btn-secondary" onclick="switchTab('profile')">Update
                                    profile</button>
                                <button type="button" class="btn-secondary" onclick="switchTab('centers')">Health
                                    centers</button>
                            </div>
                        </div>

                        <div class="dash-card">
                            <h2 class="card-heading">Before your visit</h2>
                            <ul class="checklist">
                                <li>Bring your Patient ID
                                    (<strong><?= htmlspecialchars($patient["patient_id"]) ?></strong>) and your
                                    confirmation slip, printed or on your phone.</li>
                                <li>Arrive about 15 minutes early.</li>
                                <li>Reply to the reminder emails to confirm you're coming. Unconfirmed slots may be
                                    released.</li>
                                <li>Need to cancel? Do it at least 24 hours ahead and give a reason.</li>
                                <li>Walk-ins are accepted when spots are free, but booked patients are seen first.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Column 2: action needed, notifications -->
                    <div>
                        <div class="dash-card">
                            <h2 class="card-heading">Action needed</h2>
                            <?php
                            $hasActions = !empty($actionItems) || $profileIncomplete || $patient["pending_staff"];
                            if (!$hasActions && $pendingCount === 0):
                                ?>
                                <p class="ok-text" style="margin:0;">You're all caught up. Nothing needs your attention.</p>
                            <?php else: ?>
                                <?php foreach ($actionItems as $it): ?>
                                    <div class="action-item">
                                        <div>Confirm you'll attend your <strong><?= htmlspecialchars($it["service"]) ?></strong>
                                            on <?= date("M j", strtotime($it["appointment_date"])) ?> at
                                            <?= date("g:i A", strtotime($it["appointment_time"])) ?>.
                                        </div>

                                        <form action="process_appointment.php" method="POST">
                                            <input type="hidden" name="action" value="confirm_attendance">
                                            <input type="hidden" name="id" value="<?= (int) $it["id"] ?>">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                                            <button type="submit" class="btn-small">Confirm</button>
                                        </form>

                                    </div>
                                <?php endforeach; ?>
                                <?php if ($profileIncomplete): ?>
                                    <div class="action-item">
                                        <div>Your profile is incomplete. Add your personal and emergency details.</div><button
                                            type="button" class="btn-small" onclick="switchTab('profile')">Complete</button>
                                    </div>
                                <?php endif; ?>
                                <?php if ($patient["pending_staff"]): ?>
                                    <div class="action-item">
                                        <div>Your staff/doctor account request is awaiting admin review. You have patient-level
                                            access until then.</div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($pendingCount > 0): ?>
                                    <div class="action-item">
                                        <div class="muted"><?= $pendingCount ?> appointment request(s) waiting for the health
                                            center to confirm. No action needed from you.</div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="dash-card">
                            <div class="card-heading-row">
                                <h2 class="card-heading">Notifications</h2>
                                <?php if ($unreadCount > 0): ?>
                                    <form action="mark_notifications_read.php" method="POST">
                                        <input type="hidden" name="scope" value="all">
                                        <button type="submit" class="link-btn">Mark all as read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <p class="hint" style="margin:0 0 8px;">Every notification is also sent to
                                <strong><?= htmlspecialchars($userRow["email"]) ?></strong>.
                            </p>
                            <div class="notif-list">
                                <?php if ($notifications->num_rows > 0):
                                    while ($notif = $notifications->fetch_assoc()): ?>
                                        <div class="notif-item <?= $notif["is_read"] ? "" : "unread" ?>">
                                            <p class="notif-message"><?= htmlspecialchars($notif["message"]) ?></p>
                                            <div class="notif-meta">
                                                <small><?= date("M j, Y g:i A", strtotime($notif["created_at"])) ?></small>
                                                <?php if (!$notif["is_read"]): ?>
                                                    <form action="mark_notifications_read.php" method="POST">
                                                        <input type="hidden" name="id" value="<?= (int) $notif["id"] ?>">
                                                        <button type="submit" class="link-btn">Mark as read</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endwhile; else: ?>
                                    <p class="hint" style="margin:0;">No notifications yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Column 3: centers today (hours, closures, open spots), recent visits -->
                    <div>
                        <div class="dash-card">
                            <div class="card-heading-row">
                                <h2 class="card-heading">Health centers today</h2>
                                <button type="button" class="link-btn" onclick="switchTab('centers')">Details</button>
                            </div>
                            <p class="hint" style="margin:0 0 6px;">Hours, closures and online spots left in the current
                                2-hour window.</p>
                            <div class="center-list">
                                <?php foreach ($centers as $c):
                                    $count = booked_count($centerBookedCounts, $c["id"]);
                                    $todayRow = $todayStatus[$c["id"]] ?? null;
                                    $closedToday = $todayRow && $todayRow["status"] === "Closed";
                                    $slot = $centerSlots[$c["id"]];
                                    [$busyClass, $busyLabel] = busy_info($count);
                                    ?>
                                    <div class="center-row">
                                        <div class="cr-main">
                                            <div class="name"><?= htmlspecialchars($c["name"]) ?></div>
                                            <div class="count">
                                                <?= htmlspecialchars($c["operating_hours"] ?: (minutes_label($centerHours[$c["id"]]["open"]) . " – " . minutes_label($centerHours[$c["id"]]["close"]))) ?>
                                                · <?= $count ?> booked today
                                            </div>
                                            <?php if ($closedToday): ?>
                                                <div class="slots" style="color:var(--red);">Closed
                                                    today<?= !empty($todayRow["note"]) ? " — " . htmlspecialchars($todayRow["note"]) : "" ?>
                                                </div>
                                            <?php elseif ($slot["state"] === "done"): ?>
                                                <div class="slots muted">Done for today</div>
                                            <?php else: ?>
                                                <div class="slots"><?= $slot["left"] ?> of <?= ONLINE_BOOKING_CAP ?> online
                                                    spots left,
                                                    <?= minutes_label($slot["start"]) ?>–<?= minutes_label($slot["end"]) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($todayRow && $todayRow["status"] === "Event"): ?>
                                                <div class="count">Event
                                                    today<?= !empty($todayRow["note"]) ? ": " . htmlspecialchars($todayRow["note"]) : "" ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="cr-side">
                                            <?php if ($closedToday): ?><span class="busy-pill busy-high">Closed</span>
                                            <?php else: ?><span
                                                    class="busy-pill <?= $busyClass ?>"><?= $busyLabel ?></span><?php endif; ?>
                                            <button type="button" class="btn-small"
                                                onclick="bookAt(<?= (int) $c["id"] ?>)">Book</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="dash-card">
                            <div class="card-heading-row">
                                <h2 class="card-heading">Recent visits</h2>
                                <button type="button" class="link-btn" onclick="switchTab('appointments')">All
                                    history</button>
                            </div>
                            <?php if ($recent):
                                foreach ($recent as $r): ?>
                                    <div class="recent-item">
                                        <div>
                                            <div class="name"><?= htmlspecialchars($r["service"]) ?></div>
                                            <div class="count"><?= date("M j, Y", strtotime($r["appointment_date"])) ?> ·
                                                <?= htmlspecialchars(center_name($centers, $r["health_center_id"])) ?>
                                            </div>
                                        </div>
                                        <span
                                            class="status-pill <?= status_class($r["status"]) ?>"><?= htmlspecialchars($r["status"]) ?></span>
                                    </div>
                                <?php endforeach; else: ?>
                                <p class="hint" style="margin:0;">No past visits yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </section>

            <!-- ================= APPOINTMENTS ================= -->
            <section class="dash-section" id="tab-appointments">
                <h1 class="dash-title">Appointments</h1>
                <p class="dash-sub">Book a new appointment or manage your scheduled visits.</p>

                <div class="dash-card">
                    <h2 class="card-heading">Book an appointment</h2>
                    <?php if ($leastBusyCenter && count($centers) > 1): ?>
                        <div class="msg info"><strong><?= htmlspecialchars($leastBusyCenter["name"]) ?></strong> has the
                            fewest patients booked today (<?= (int) $leastBusyCount ?>) — consider it for a shorter wait.
                        </div>
                    <?php endif; ?>
                    <div class="rules-note">
                        Booking rules: appointments can only be made within a center's working hours. Each 2-hour window
                        takes up to <?= ONLINE_BOOKING_CAP ?> online bookings, and the remaining spots are kept for
                        walk-ins. Cancellations need a reason and aren't allowed within 24 hours of the appointment.
                        Confirmed appointments are reminded by email 3, 2 and 1 days before. Please confirm your
                        attendance, or the slot may be released.
                    </div>
                    <form action="process_appointment.php" method="POST" class="appt-form">
                        <input type="hidden" name="action" value="add">
                        <div class="field-row">
                            <div class="field">
                                <label>Health center</label>
                                <select name="health_center_id" id="centerSelect" onchange="updateTimeOptions()"
                                    required>
                                    <option value="">Select health center</option>
                                    <?php foreach ($centers as $c): ?>
                                        <option value="<?= (int) $c["id"] ?>"><?= htmlspecialchars($c["name"]) ?> —
                                            <?= booked_count($centerBookedCounts, $c["id"]) ?> booked today
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Service</label>
                                <select name="service" required>
                                    <option value="">Select service</option>
                                    <option>General Consultation</option>
                                    <option>Family Planning Services</option>
                                    <option>Dispensing Maintenance Drug</option>
                                    <option>TB Follow Up</option>
                                    <option>Pre-Natal Check Up / Maternal Services</option>
                                    <option>Immunization (NIP)</option>
                                    <option>Adolescent Consultation</option>
                                    <option>Dental Extraction</option>
                                </select>
                            </div>
                        </div>
                        <div class="field-row">

                            <div class="field">
                                <label>Date</label>

                                <input type="date" id="ap-date" name="appointment_date" min="<?= date('Y-m-d') ?>"
                                    max="<?= date('Y-m-d', strtotime('+60 days')) ?>" required
                                    onchange="updateTimeOptions()">

                            </div>

                            <div class="field">
                                <label>Time</label>
                                <select name="appointment_time" id="timeSelect" required>
                                    <option value="">Select a health center first</option>
                                </select>
                                <p id="hoursHint" class="hours-hint"></p>
                            </div>
                            <div class="rules-note">
                                Booking an appointment is only available Monday through Friday and only within the time alloted by the health center.
                            </div>
                        </div>
                        <button type="submit" class="btn-primary btn-inline">Book appointment</button>
                    </form>
                </div>

                <div class="dash-card">
                    <h2 class="card-heading">Upcoming appointments</h2>
                    <div style="overflow-x:auto;">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Service</th>
                                    <th>Center</th>
                                    <th>Status</th>
                                    <th>Attendance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($upcoming->num_rows > 0):
                                    while ($row = $upcoming->fetch_assoc()):
                                        $apptTime = strtotime($row["appointment_date"] . " " . $row["appointment_time"]);
                                        $isLocked = in_array($row["status"], ["Confirmed"]) || ($apptTime - time()) < 86400;
                                        $daysUntil = (int) (new DateTime(date("Y-m-d")))->diff(new DateTime($row["appointment_date"]))->format("%r%a");
                                        $attConfirmed = !empty($row["attendance_confirmed"]);
                                        $canConfirmAttendance = $row["status"] === "Confirmed" && !$attConfirmed && $daysUntil >= 0 && $daysUntil <= 3;
                                        ?>
                                        <tr>
                                            <td><span
                                                    class="countdown-tag"><?= countdown_label($row["appointment_date"], $row["appointment_time"]) ?></span>
                                            </td>
                                            <td><?= date("M j, Y", strtotime($row["appointment_date"])) ?></td>
                                            <td><?= date("g:i A", strtotime($row["appointment_time"])) ?></td>
                                            <td><?= htmlspecialchars($row["service"]) ?></td>
                                            <td><?= htmlspecialchars(center_name($centers, $row["health_center_id"])) ?></td>
                                            <td><span
                                                    class="status-pill <?= status_class($row["status"]) ?>"><?= htmlspecialchars($row["status"]) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($row["status"] !== "Confirmed"): ?><span class="muted">—</span>
                                                <?php elseif ($attConfirmed): ?><span class="ok-text">You confirmed</span>
                                                <?php elseif ($canConfirmAttendance): ?>

                                                    <form action="process_appointment.php" method="POST">
                                                        <input type="hidden" name="action" value="confirm_attendance">
                                                        <input type="hidden" name="id" value="<?= (int) $row["id"] ?>">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>">

                                                        <button type="submit" class="btn-small">Confirm attendance</button>
                                                    </form>

                                                <?php else: ?><span class="muted">Reminders start 3 days before</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a class="link-btn" href="appointment_slip.php?id=<?= (int) $row["id"] ?>"
                                                        target="_blank">Slip</a>
                                                    <?php if (!$isLocked): ?>
                                                        <button type="button" class="link-danger"
                                                            onclick="openCancelModal(<?= (int) $row["id"] ?>)">Cancel</button>
                                                    <?php else: ?>
                                                        <span class="muted"
                                                            title="Confirmed or within 24 hours of the appointment">Locked</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; else: ?>
                                    <tr>
                                        <td colspan="8" class="muted">No upcoming appointments.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="dash-card">
                    <button type="button" class="history-toggle" id="historyToggle" aria-expanded="true"
                        aria-controls="appointmentHistoryContent">
                        <h2 class="card-heading">Appointment history</h2>
                        <span class="toggle-icon" id="historyToggleIcon">−</span>
                    </button>

                    <div id="appointmentHistoryContent">
                        <div style="overflow-x: auto;">
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Service</th>
                                        <th>Center</th>
                                        <th>Status</th>
                                        <th>Cancel reason</th>
                                        <th>Reject reason</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($history->num_rows > 0):
                                        while ($row = $history->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= date("M j, Y", strtotime($row["appointment_date"])) ?></td>
                                                <td><?= date("g:i A", strtotime($row["appointment_time"])) ?></td>
                                                <td><?= htmlspecialchars($row["service"]) ?></td>
                                                <td><?= htmlspecialchars(center_name($centers, $row["health_center_id"])) ?>
                                                </td>
                                                <td>
                                                    <span class="status-pill <?= status_class($row["status"]) ?>">
                                                        <?= htmlspecialchars($row["status"]) ?>
                                                    </span>
                                                </td>

                                                <td class="muted reason-cell">
                                                    <?= htmlspecialchars(
                                                        trim($row["cancel_reason"] ?? ""),
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?: "—" ?>
                                                </td>

                                                <td class="muted reason-cell reject-reason">
                                                    <?= htmlspecialchars(
                                                        trim($row["rejection_reason"] ?? ""),
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?: "—" ?>
                                                </td>

                                            </tr>
                                        <?php endwhile;
                                    else: ?>
                                        <tr>
                                            <td colspan="7" class="muted">No past appointments yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>


            <!-- ================= HEALTH CENTERS ================= -->
            <section class="dash-section" id="tab-centers">
                <h1 class="dash-title">Health Centers</h1>
                <p class="dash-sub">
                    Find a participating Malabon District II health center, check its details,
                    and book your appointment.
                </p>

                <div class="cards-row wrap centers-grid">
                    <?php foreach ($centers as $c):
                        $centerId = (int) $c["id"];
                        $count = booked_count($centerBookedCounts, $c["id"]);
                        [$busyClass, $busyLabel] = busy_info($count);
                        $today = $todayStatus[$c["id"]] ?? null;

                        $address = trim($c["address"] ?? "");
                        $centerName = $c["name"] ?? "Health Center";
                        $contact = trim($c["contact_number"] ?? "");
                        $hours = trim($c["operating_hours"] ?? "");

                        $locationQuery = $address ?: $centerName . ", Malabon, Philippines";
                        $mapQuery = urlencode($locationQuery);

                        // Prepare safe links for directions and phone calls.
                        $directionsUrl = "https://www.google.com/maps/dir/?api=1&destination=" . $mapQuery;
                        $phoneNumber = preg_replace('/[^0-9+]/', '', $contact);

                        // Optional services, indexed by health center ID.
                        $services = $service[$c["id"]] ?? [];
                        ?>

                        <div class="dash-card center-detail-card">

                            <!-- Center name and location -->
                            <div class="center-card-header">
                                <div class="center-title-group">
                                    <h3 class="card-heading">
                                        <?= htmlspecialchars($centerName) ?>
                                    </h3>

                                    <p class="center-info-line">
                                        <span class="center-info-icon">&#9906;</span>
                                        <?= htmlspecialchars($address ?: "Address not on file") ?>
                                    </p>

                                    <p class="center-info-line">
                                        <span class="center-info-icon">&#9742;</span>
                                        <?= htmlspecialchars($contact ?: "Contact not on file") ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Operating hours -->
                            <div class="center-hours-row">
                                <span class="center-info-icon">&#128336;</span>
                                <div>
                                    <span class="center-meta-label">Operating hours</span>
                                    <p class="center-hours-text">
                                        <?= htmlspecialchars($hours ?: "Hours not on file") ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Daily status -->
                            <div class="center-status-area">
                                <?php if ($today && ($today["status"] ?? "") === "Closed"): ?>

                                    <span class="busy-pill busy-high">
                                        Closed today
                                    </span>

                                    <?php if (!empty($today["note"])): ?>
                                        <p class="center-status-note">
                                            <?= htmlspecialchars($today["note"]) ?>
                                        </p>
                                    <?php endif; ?>

                                <?php elseif ($today && ($today["status"] ?? "") === "Event"): ?>

                                    <span class="center-status-pill center-status-event">
                                        Event today
                                    </span>

                                    <?php if (!empty($today["note"])): ?>
                                        <p class="center-status-note">
                                            <?= htmlspecialchars($today["note"]) ?>
                                        </p>
                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="center-status-pill center-status-normal">
                                        No closure reported today
                                    </span>

                                <?php endif; ?>
                            </div>

                            <!-- Booking activity -->
                            <div class="center-booking-activity">
                                <div class="center-activity-heading">
                                    <span>Today's booking activity</span>
                                    <strong><?= (int) $count ?> booked</strong>
                                </div>

                                <div class="center-activity-row">
                                    <span class="busy-pill <?= htmlspecialchars($busyClass) ?>">
                                        <?= htmlspecialchars($busyLabel) ?>
                                    </span>
                                    <span class="center-activity-caption">
                                        Based on appointment bookings
                                    </span>
                                </div>

                                <?php if (!empty($centerHourly[$c["id"]])): ?>
                                    <details class="center-hourly-details">
                                        <summary>View hourly booking breakdown</summary>

                                        <ul class="hourly-breakdown">
                                            <?php foreach ($centerHourly[$c["id"]] as $hour => $cnt): ?>
                                                <li>
                                                    <?= htmlspecialchars(
                                                        hourly_breakdown_label($hour, $cnt)
                                                    ) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>

                            <!-- Available services -->
                            <?php if (!empty($services)): ?>
                                <div class="center-services">
                                    <h4 class="center-subheading">Available services</h4>

                                    <div class="center-service-tags">
                                        <?php foreach ($services as $service): ?>
                                            <span class="center-service-tag">
                                                <?= htmlspecialchars($service) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Map -->
                            <div class="center-map">
                                <iframe src="https://www.google.com/maps?q=<?= $mapQuery ?>&output=embed"
                                    title="Map showing <?= htmlspecialchars($centerName) ?>" width="100%" height="170"
                                    style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                                    allowfullscreen>
                                </iframe>
                            </div>

                            <!-- Actions -->
                            <div class="center-card-actions">

                                <a href="<?= htmlspecialchars($directionsUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    class="center-action-btn center-directions-btn" target="_blank"
                                    rel="noopener noreferrer">
                                    <span aria-hidden="true">&#10148;</span>
                                    Get Directions
                                </a>

                                <?php if ($phoneNumber !== ""): ?>
                                    <a href="tel:<?= htmlspecialchars($phoneNumber, ENT_QUOTES, 'UTF-8') ?>"
                                        class="center-action-btn center-call-btn">
                                        <span aria-hidden="true">&#9742;</span>
                                        Call Center
                                    </a>
                                <?php else: ?>
                                    <span class="center-action-btn center-call-btn center-action-disabled" aria-disabled="true">
                                        <span aria-hidden="true">&#9742;</span>
                                        No phone number
                                    </span>
                                <?php endif; ?>

                                <button type="button" class="center-action-btn center-book-btn"
                                    onclick="bookAt(<?= $centerId ?>)">
                                    <span aria-hidden="true">&#43;</span>
                                    Book Appointment
                                </button>

                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            </section>



            <!-- ================= PROFILE ================= -->
            <?php
            $profileName = trim($patient["full_name"] ?? "");
            if ($profileName === "") {
                $profileName = "Patient";
            }

            $nameParts = preg_split('/\s+/', $profileName);
            $firstInitial = substr($nameParts[0] ?? "P", 0, 1);
            $lastInitial = count($nameParts) > 1
                ? substr($nameParts[count($nameParts) - 1], 0, 1)
                : "";

            $profileInitials = strtoupper($firstInitial . $lastInitial);
            ?>

            <section class="dash-section" id="tab-profile">

                <h1 class="dash-title">My Profile</h1>
                <p class="dash-sub">
                    Manage your personal information and emergency contact details.
                </p>

                <div class="profile-page">

                    <!-- PATIENT HEADER -->
                    <div class="dash-card profile-header-card">
                        <div class="profile-header-content">

                            <div class="profile-avatar">
                                <?= htmlspecialchars($profileInitials, ENT_QUOTES, "UTF-8") ?>
                            </div>

                            <div class="profile-header-info">
                                <h2>
                                    <?= htmlspecialchars($profileName, ENT_QUOTES, "UTF-8") ?>
                                </h2>
                                <p>Patient Account</p>

                                <div class="patient-id-badge">
                                    <span>Patient ID</span>
                                    <strong>
                                        <?= htmlspecialchars(
                                            (string) ($patient["patient_id"] ?? ""),
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </strong>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ACCOUNT INFORMATION -->
                    <div class="dash-card profile-section-card">
                        <div class="profile-section-heading">
                            <div class="profile-heading-icon">
                                <i class="fa-regular fa-user"></i>
                            </div>
                            <div>
                                <h2 class="card-heading">Account Information</h2>
                                <p class="profile-section-sub">
                                    Your registered account details.
                                </p>
                            </div>
                        </div>

                        <div class="profile-info-grid">

                            <div class="profile-info-item">
                                <span class="profile-info-label">Full Name</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        $patient["full_name"] ?? "Not provided",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?: "Not provided" ?>
                                </strong>
                            </div>

                            <div class="profile-info-item">
                                <span class="profile-info-label">Username</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        $username,
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </strong>
                            </div>

                            <div class="profile-info-item">
                                <span class="profile-info-label">Email Address</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        $userRow["email"] ?? "",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?: "Not provided" ?>
                                </strong>
                            </div>

                            <div class="profile-info-item">
                                <span class="profile-info-label">Mobile Number</span>
                                <strong>
                                    <?= htmlspecialchars(
                                        $patient["mobile"] ?? "",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?: "Not provided" ?>
                                </strong>
                            </div>

                        </div>

                        <p class="profile-info-note">
                            To change your email address or mobile number, use the
                            Contact Information section under Account Settings.
                        </p>
                    </div>

                    <!-- EDIT PERSONAL AND EMERGENCY INFORMATION -->
                    <div class="dash-card profile-section-card">

                        <div class="profile-section-heading">
                            <div class="profile-heading-icon">
                                <i class="fa-regular fa-address-card"></i>
                            </div>
                            <div>
                                <h2 class="card-heading">Personal Information</h2>
                                <p class="profile-section-sub">
                                    Keep your personal details accurate and up to date.
                                </p>
                            </div>
                        </div>

                        <form action="update_account.php" method="POST" class="profile-edit-form">

                            <input type="hidden" name="action" value="profile">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                                $_SESSION["csrf_token"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>">

                            <!-- PERSONAL DETAILS -->
                            <div class="profile-form-subsection">
                                <h3>Personal Details</h3>
                                <p>Information associated with your patient record.</p>
                            </div>

                            <div class="field-row">
                                <div class="field">
                                    <label for="profile_dob">Date of Birth</label>
                                    <input type="date" id="profile_dob" name="date_of_birth" value="<?= htmlspecialchars(
                                        $patient["date_of_birth"] ?? "",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>" max="<?= date("Y-m-d") ?>" required>
                                </div>

                                <div class="field">
                                    <label for="profile_gender">Gender</label>
                                    <select id="profile_gender" name="gender" required>
                                        <option value="">Select gender</option>

                                        <?php foreach (
                                            ["Male", "Female", "Other"] as $g
                                        ): ?>
                                            <option value="<?= htmlspecialchars($g, ENT_QUOTES, "UTF-8") ?>"
                                                <?= ($patient["gender"] ?? "") === $g
                                                    ? "selected"
                                                    : "" ?>>
                                                <?= htmlspecialchars($g, ENT_QUOTES, "UTF-8") ?>
                                            </option>
                                        <?php endforeach; ?>

                                    </select>
                                </div>
                            </div>

                            <div class="field">
                                <label for="profile_address">Complete Address</label>
                                <input type="text" id="profile_address" name="address" value="<?= htmlspecialchars(
                                    $patient["address"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>" placeholder="House number, street, barangay, city" maxlength="255" required>
                            </div>

                            <div class="profile-form-divider"></div>

                            <!-- EMERGENCY CONTACT -->
                            <div class="profile-form-subsection">
                                <h3>Emergency Contact</h3>
                                <p>
                                    Provide someone the health center can contact
                                    when necessary.
                                </p>
                            </div>

                            <div class="field-row">
                                <div class="field">
                                    <label for="profile_ec_name">
                                        Contact Person's Name
                                    </label>
                                    <input type="text" id="profile_ec_name" name="emergency_contact_name" value="<?= htmlspecialchars(
                                        $patient["emergency_contact_name"] ?? "",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>" placeholder="Enter contact person's full name" maxlength="150" required>
                                </div>

                                <div class="field">
                                    <label for="profile_ec_number">
                                        Contact Number
                                    </label>
                                    <input type="tel" id="profile_ec_number" name="emergency_contact_number" value="<?= htmlspecialchars(
                                        $patient["emergency_contact_number"] ?? "",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>" placeholder="e.g. 09XXXXXXXXX" maxlength="20" required>
                                </div>
                            </div>

                            <!-- FORM ACTIONS -->
                            <div class="profile-form-actions">
                                <button type="reset" class="profile-cancel-btn">
                                    Cancel
                                </button>

                                <button type="submit" class="btn-primary btn-inline profile-save-btn">
                                    <i class="fa-regular fa-floppy-disk"></i>
                                    Save Profile
                                </button>
                            </div>

                        </form>
                    </div>

                    <!-- ================= CONTACT DETAILS ================= -->

                    <div class="dash-card profile-section-card">

                        <div class="profile-section-heading">
                            <div class="profile-heading-icon">
                                <i class="fa-regular fa-envelope"></i>
                            </div>
                            <div>
                                <h2 class="card-heading">Contact Details</h2>
                                <p class="profile-section-sub">
                                    Manage where you receive appointment reminders and notifications.
                                </p>
                            </div>
                        </div>

                        <form action="update_account.php" method="POST" class="profile-edit-form">

                            <input type="hidden" name="action" value="contact">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                                $_SESSION["csrf_token"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>">

                            <div class="field">
                                <label for="profile_email">Email Address</label>
                                <input type="email" id="profile_email" name="email" value="<?= htmlspecialchars(
                                    $userRow["email"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>" maxlength="254" autocomplete="email" required>
                                <small class="profile-field-hint">
                                    Appointment reminders and notifications are sent to this address.
                                </small>
                            </div>

                            <div class="field">
                                <label for="profile_mobile">Mobile Number</label>
                                <input type="tel" id="profile_mobile" name="mobile" value="<?= htmlspecialchars(
                                    $patient["mobile"] ?? "",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>" maxlength="20" autocomplete="tel" placeholder="e.g. 09XXXXXXXXX" required>
                            </div>

                            <div class="profile-form-actions">
                                <button type="reset" class="profile-cancel-btn">
                                    Cancel
                                </button>

                                <button type="submit" class="btn-primary btn-inline profile-save-btn">
                                    <i class="fa-regular fa-floppy-disk"></i>
                                    Save Contact Details
                                </button>
                            </div>

                        </form>
                    </div>


                    <!-- ================= CHANGE PASSWORD ================= -->

                    <div class="dash-card profile-section-card">

                        <div class="profile-section-heading">
                            <div class="profile-heading-icon">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <div>
                                <h2 class="card-heading">Change Password</h2>
                                <p class="profile-section-sub">
                                    Update your password to help keep your account secure.
                                </p>
                            </div>
                        </div>

                        <form action="update_account.php" method="POST" class="profile-edit-form">

                            <input type="hidden" name="action" value="password">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
                                $_SESSION["csrf_token"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>">

                            <div class="field">
                                <label for="current_password">Current Password</label>

                                <div class="password-wrapper">
                                    <input type="password" id="current_password" name="current_password"
                                        autocomplete="current-password" required>

                                    <button type="button" class="toggle-password" aria-label="Show current password"
                                        aria-pressed="false" onclick="togglePassword(this)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path
                                                d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="field">
                                <label for="new_password">New Password</label>

                                <div class="password-wrapper">
                                    <input type="password" id="new_password" name="new_password"
                                        autocomplete="new-password" minlength="8" required>

                                    <button type="button" class="toggle-password" aria-label="Show new password"
                                        aria-pressed="false" onclick="togglePassword(this)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path
                                                d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                                        </svg>
                                    </button>
                                </div>

                                <small class="profile-field-hint">
                                    Use at least 8 characters, including a number and a special character.
                                </small>
                            </div>

                            <div class="field">
                                <label for="confirm_password">Confirm New Password</label>

                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password"
                                        autocomplete="new-password" minlength="8" required>

                                    <button type="button" class="toggle-password"
                                        aria-label="Show password confirmation" aria-pressed="false"
                                        onclick="togglePassword(this)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path
                                                d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="profile-password-notice">
                                <i class="fa-solid fa-shield-halved"></i>
                                <span>
                                    Your current password is required before a new password can be saved.
                                </span>
                            </div>

                            <div class="profile-form-actions">
                                <button type="reset" class="profile-cancel-btn">
                                    Clear Fields
                                </button>

                                <button type="submit" class="btn-primary btn-inline profile-save-btn">
                                    <i class="fa-solid fa-lock"></i>
                                    Update Password
                                </button>
                            </div>

                        </form>
                    </div>
                    <!-- ACCOUNT SECURITY REMINDER -->
                    <div class="profile-security-note">
                        <div class="profile-security-icon">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <strong>Keep your account secure</strong>
                            <p>
                                Protect your login credentials and make sure your
                                contact information is current. Change your password
                                through Account Settings.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Cancel modal: a reason is required -->
    <div id="cancelModal" class="modal-backdrop">
        <form action="process_appointment.php" method="POST" class="modal-card">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" id="cancelId">
            <h3 class="card-heading">Cancel appointment</h3>
            <p class="hint" style="margin:0 0 12px;">Please tell us why you're cancelling. The health center can see
                this.</p>
            <div class="field"><textarea name="cancel_reason" required minlength="5" maxlength="255" rows="3"
                    placeholder="Reason for cancelling"></textarea></div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeCancelModal()">Keep appointment</button>
                <button type="submit" class="btn-primary">Cancel appointment</button>
            </div>
        </form>
    </div>

    <script>
        window.CENTER_HOURS = <?= json_encode(
            $centerHours,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?>;

        window.CENTER_CLOSURES = <?= json_encode(
            $centerClosures,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?>;
    </script>
    <script src="patient_logic.js" defer></script>

</body>

</html>