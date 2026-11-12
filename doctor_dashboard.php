<?php
session_start();
include "connection.php";
include "auth_check.php";

if ($_SESSION["role"] !== "doctor") {
  if ($_SESSION["role"] === "patient")
    header("Location: dashboard.php");
  elseif ($_SESSION["role"] === "admin")
    header("Location: admin_dashboard.php");
  else
    header("Location: staff_dashboard.php");
  exit;
}

$username = $_SESSION["username"];

$stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$doctor = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmtUser = $conn->prepare("SELECT email FROM users WHERE username = ?");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$userRow = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$centerId = $doctor["health_center_id"];
if (!$centerId) {
  die("Your account has no assigned health center yet. Please contact your system administrator.");
}

$stmt = $conn->prepare("SELECT name FROM health_centers WHERE id = ?");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$centerName = $stmt->get_result()->fetch_assoc()["name"] ?? "Unknown center";
$stmt->close();

// Notifications — same pattern as the patient dashboard.
$stmt = $conn->prepare("SELECT * FROM notifications WHERE username = ? ORDER BY created_at DESC LIMIT 10");
$stmt->bind_param("s", $username);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT COUNT(*) AS unread FROM notifications WHERE username = ? AND is_read = 0");
$stmt->bind_param("s", $username);
$stmt->execute();
$unreadCount = $stmt->get_result()->fetch_assoc()["unread"];

// Everything below is scoped to appointments SPECIFICALLY assigned to this
// doctor — not just "this center" — since staff assigns a doctor at
// confirmation time and other doctors at the same center shouldn't see it.

$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.assigned_doctor = ? AND appointments.status = 'Confirmed'
          AND appointments.appointment_date = CURDATE()
    ORDER BY appointments.appointment_time
");
$stmt->bind_param("s", $username);
$stmt->execute();
$today = $stmt->get_result();

// Overdue: still Confirmed, but the date has already passed — nothing ever
// resolved it (no completion, no no-show). Flagged separately since it's
// easy for this to quietly slip through a busy day otherwise.
$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.assigned_doctor = ? AND appointments.status = 'Confirmed'
          AND appointments.appointment_date < CURDATE()
    ORDER BY appointments.appointment_date
");
$stmt->bind_param("s", $username);
$stmt->execute();
$overdue = $stmt->get_result();
$overdueCount = $overdue->num_rows;

$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.assigned_doctor = ? AND appointments.status = 'Confirmed'
    ORDER BY appointments.appointment_date, appointments.appointment_time
");
$stmt->bind_param("s", $username);
$stmt->execute();
$upcoming = $stmt->get_result();


/* Today's consultation queue */
$stmt = $conn->prepare("
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients
        ON patients.username = appointments.username
    WHERE appointments.assigned_doctor = ?
      AND appointments.appointment_date = CURDATE()
      AND appointments.status IN (
          'Confirmed',
          'In consultation',
          'Completed'
      )
    ORDER BY appointments.appointment_time ASC
");
$stmt->bind_param("s", $username);
$stmt->execute();
$consultationQueue = $stmt->get_result();

/* Medicine availability for this doctor's health center */
$stmt = $conn->prepare("
    SELECT id, name, unit, stock_quantity
    FROM medicines
    WHERE health_center_id = ?
    ORDER BY stock_quantity ASC, name ASC
");
$stmt->bind_param("i", $centerId);
$stmt->execute();
$medicineAvailability = $stmt->get_result();


// Visit history = Completed + No-show, so a no-show visit doesn't just
// disappear with nowhere to review it. Optional date range filter.
$fromDate = $_GET["from"] ?? null;
$toDate = $_GET["to"] ?? null;

$historySql = "
    SELECT appointments.*, patients.full_name
    FROM appointments
    JOIN patients ON patients.username = appointments.username
    WHERE appointments.assigned_doctor = ? AND appointments.status IN ('Completed','No-show')
";
$historyParams = [$username];
$historyTypes = "s";
if ($fromDate) {
  $historySql .= " AND appointments.appointment_date >= ? ";
  $historyParams[] = $fromDate;
  $historyTypes .= "s";
}
if ($toDate) {
  $historySql .= " AND appointments.appointment_date <= ? ";
  $historyParams[] = $toDate;
  $historyTypes .= "s";
}
$historySql .= " ORDER BY appointments.appointment_date DESC, appointments.appointment_time DESC LIMIT 100";

$stmt = $conn->prepare($historySql);
$stmt->bind_param($historyTypes, ...$historyParams);
$stmt->execute();
$history = $stmt->get_result();

$statusMsg = $_GET["msg"] ?? null;
$statusType = $_GET["type"] ?? "info";
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Doctor Dashboard — Malabon District II Health Centers</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="patient_style.css">
  <link rel="stylesheet" href="homepagestyle.css">
  <link rel="stylesheet" href="doctor_dashboard.css">
</head>

<body class="dashboard-body doctor-dashboard-body">

  <div class="dash-shell">

    <aside class="sidebar">
      <div class="sidebar-brand"><span class="dot"></span> MALABON DISTRICT II</div>
      <div class="sidebar-user">
        <div class="sidebar-name">Dr. <?= htmlspecialchars($doctor["full_name"] ?: $username) ?></div>
        <div class="sidebar-id"><?= htmlspecialchars($centerName) ?></div>
      </div>
      <nav class="sidebar-nav">
        <a href="#" class="nav-link" data-tab="home">
          Home
          <?php if ($unreadCount > 0): ?><span class="nav-badge"><?= $unreadCount ?></span><?php endif; ?>
        </a>
        <a href="#" class="nav-link" data-tab="schedule">
          My Schedule
          <?php if ($overdueCount > 0): ?><span class="nav-badge"><?= $overdueCount ?></span><?php endif; ?>
        </a>
        <a href="#" class="nav-link" data-tab="account">My Account</a>
      </nav>
      <a href="logout.php" class="sidebar-logout">Sign out</a>
    </aside>

    <main class="dash-main">

      <?php if ($statusMsg): ?>
        <div class="msg <?= htmlspecialchars($statusType) ?>"><?= htmlspecialchars($statusMsg) ?></div>
      <?php endif; ?>


      <!-- HOME -->
      <section class="dash-section" id="tab-home">

        <!-- Welcome header -->
        <div class="doctor-welcome">
          <div>
            <h1 class="dash-title">
              Welcome, Dr. <?= htmlspecialchars(explode(" ", $doctor["full_name"] ?: $username)[0]) ?>
            </h1>
            <p class="dash-sub"><?= htmlspecialchars($centerName) ?></p>
          </div>
          <div class="doctor-date">
            <?= date("l, F j, Y") ?>
          </div>
        </div>

        <!-- Summary cards -->
        <div class="cards-row doctor-summary">

          <div class="dash-card summary-card">
            <div class="summary-top">
              <span class="summary-icon">◷</span>
              <span class="dash-card-label">Today's visits</span>
            </div>
            <div class="dash-card-value"><?= $today->num_rows ?></div>
            <p class="summary-note">Confirmed appointments today</p>
          </div>

          <div class="dash-card summary-card">
            <div class="summary-top">
              <span class="summary-icon">▤</span>
              <span class="dash-card-label">Upcoming appointments</span>
            </div>
            <div class="dash-card-value"><?= $upcoming->num_rows ?></div>
            <p class="summary-note">All confirmed appointments</p>
          </div>

          <div class="dash-card summary-card <?= $overdueCount > 0 ? 'summary-warning' : '' ?>">
            <div class="summary-top">
              <span class="summary-icon">!</span>
              <span class="dash-card-label">Overdue appointments</span>
            </div>
            <div class="dash-card-value"><?= $overdueCount ?></div>
            <p class="summary-note">Visits needing an outcome</p>
          </div>

        </div>

        <!-- Overdue notice -->
        <?php if ($overdueCount > 0): ?>
          <div class="pending-banner">
            You have <?= $overdueCount ?> confirmed appointment(s) with past dates
            and no recorded outcome.
            <a href="#" class="nav-link-inline" data-tab="schedule">Review now</a>
          </div>
        <?php endif; ?>


        <!-- Consultation Queue and Medicine Availability -->
        <div class="doctor-info-panels">

          <!-- Today's Consultation Queue -->
          <div class="dash-card doctor-queue-card">
            <div class="card-heading-row">
              <h2 class="card-heading">Today's Consultation Queue</h2>
              <a href="#" class="link-btn" data-tab="schedule">View schedule</a>
            </div>


            <div class="consultation-list">

              <?php if ($consultationQueue->num_rows > 0): ?>

                <?php while ($appointment = $consultationQueue->fetch_assoc()): ?>

                  <?php
                  $appointmentStatus = $appointment["status"];

                  if ($appointmentStatus === "Confirmed") {
                    $queueLabel = "Waiting";
                    $queueClass = "status-waiting";
                  } elseif ($appointmentStatus === "In consultation") {
                    $queueLabel = "In consultation";
                    $queueClass = "status-consulting";
                  } else {
                    $queueLabel = "Completed";
                    $queueClass = "status-completed";
                  }
                  ?>

                  <div class="consultation-item">

                    <div class="consultation-time">
                      <?= date(
                        "g:i A",
                        strtotime($appointment["appointment_time"])
                      ) ?>
                    </div>

                    <div class="consultation-details">
                      <strong>
                        <?= htmlspecialchars($appointment["full_name"]) ?>
                      </strong>

                      <span>
                        <?= htmlspecialchars($appointment["service"]) ?>
                      </span>
                    </div>

                    <span class="consultation-status <?= $queueClass ?>">
                      <?= htmlspecialchars($queueLabel) ?>
                    </span>

                    <a href="view_patient.php?id=<?= (int) $appointment["id"] ?>" class="link-btn">
                      Open
                    </a>

                  </div>

                <?php endwhile; ?>

              <?php else: ?>

                <p class="hint empty-state">
                  No appointments scheduled for today.
                </p>

              <?php endif; ?>

            </div>

            <p class="panel-footnote">
              Queue data is retrieved from today's appointments assigned to you.
            </p>
          </div>

          <!-- Medicine Availability -->
          <div class="dash-card doctor-medicine-card">
            <div class="card-heading-row">
              <h2 class="card-heading">Medicine Availability</h2>
              <span class="panel-subtitle">Stock overview</span>
            </div>


            <div class="medicine-list">

              <?php if ($medicineAvailability->num_rows > 0): ?>

                <?php while ($medicine = $medicineAvailability->fetch_assoc()): ?>

                  <?php
                  // Example threshold. Adjust this to your center's stock policy.
                  $lowStockThreshold = 15;
                  $isLowStock = (int) $medicine["stock_quantity"]
                    <= $lowStockThreshold;
                  ?>

                  <div class="medicine-item">

                    <div class="medicine-info">
                      <strong>
                        <?= htmlspecialchars($medicine["name"]) ?>
                      </strong>

                      <span>
                        <?= (int) $medicine["stock_quantity"] ?>
                        <?= htmlspecialchars($medicine["unit"]) ?> in stock
                      </span>
                    </div>

                    <span class="medicine-status <?= $isLowStock
                      ? 'medicine-low'
                      : 'medicine-available' ?>">
                      <?= $isLowStock ? "Low stock" : "Available" ?>
                    </span>

                  </div>

                <?php endwhile; ?>

              <?php else: ?>

                <p class="hint empty-state">
                  No medicines recorded for this health center.
                </p>

              <?php endif; ?>

            </div>

            <p class="panel-footnote">
              Stock quantities are retrieved from the health center's medicine inventory.
              Low-stock status uses a configurable threshold of 15 units.
            </p>
          </div>


          <!-- Main homepage panels -->
          <div class="home-panels">

            <!-- Notifications -->
            <div class="dash-card home-notifications-card">
              <div class="card-heading-row">
                <h2 class="card-heading">Notifications</h2>

                <?php if ($unreadCount > 0): ?>
                  <form action="mark_notifications_read.php" method="POST">
                    <input type="hidden" name="scope" value="all">
                    <button type="submit" class="link-btn">Mark all read</button>
                  </form>
                <?php endif; ?>
              </div>

              <div class="home-notification-list">
                <?php if (!empty($notifications)): ?>
                  <?php foreach ($notifications as $notif): ?>
                    <div class="notif-item <?= !$notif["is_read"] ? "unread" : "" ?>">
                      <p class="notif-message">
                        <?= htmlspecialchars($notif["message"]) ?>
                      </p>

                      <div class="notif-meta">
                        <small>
                          <?= date("M j, Y g:i A", strtotime($notif["created_at"])) ?>
                        </small>

                        <?php if (!$notif["is_read"]): ?>
                          <form action="mark_notifications_read.php" method="POST">
                            <input type="hidden" name="id" value="<?= (int) $notif["id"] ?>">
                            <button type="submit" class="link-btn">Mark as read</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <p class="hint empty-state">No notifications yet.</p>
                <?php endif; ?>
              </div>
            </div>

          </div>
      </section>


      <!-- SCHEDULE -->
      <section class="dash-section" id="tab-schedule">
        <h1 class="dash-title">My Schedule</h1>
        <p class="dash-sub">Appointments assigned to you.</p>

        <?php if ($overdueCount > 0): ?>
          <div class="dash-card">
            <h2 class="card-heading">Overdue — needs an outcome</h2>
            <p class="hint" style="margin-bottom:10px;">These were confirmed but their date has passed. Open each to
              complete the visit or mark it as a no-show.</p>
            <table class="dash-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Patient</th>
                  <th>Service</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $overdue->fetch_assoc()): ?>
                  <tr>
                    <td><?= date("M j, Y", strtotime($row["appointment_date"])) ?></td>
                    <td><?= htmlspecialchars($row["full_name"]) ?></td>
                    <td><?= htmlspecialchars($row["service"]) ?></td>
                    <td><a href="view_patient.php?id=<?= $row["id"] ?>" class="link-btn">Resolve</a></td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="dash-card">
          <h2 class="card-heading">Upcoming</h2>
          <div class="table-search-group">
            <label for="upcomingSearch">Find an appointment</label>
            <input type="text" id="upcomingSearch" placeholder="Search by patient or service..." class="table-search">
          </div>
          <table class="dash-table" id="upcomingTable">
            <thead>
              <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Patient</th>
                <th>Service</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($upcoming->num_rows > 0):
                while ($row = $upcoming->fetch_assoc()): ?>
                  <tr>
                    <td><?= date("M j, Y", strtotime($row["appointment_date"])) ?></td>
                    <td><?= date("g:i A", strtotime($row["appointment_time"])) ?></td>
                    <td><?= htmlspecialchars($row["full_name"]) ?></td>
                    <td><?= htmlspecialchars($row["service"]) ?></td>
                    <td><a href="view_patient.php?id=<?= $row["id"] ?>" class="link-btn">Open / Complete visit</a></td>
                  </tr>
                <?php endwhile; else: ?>
                <tr>
                  <td colspan="5">No upcoming appointments assigned to you.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="dash-card visit-history-card">

          <!-- Toggle Header -->
          <button type="button" class="visit-history-toggle" id="visitHistoryToggle" aria-expanded="false"
            aria-controls="visitHistoryContent">
            <div>
              <h2 class="card-heading">Visit History</h2>
              <p class="visit-history-subtitle">
                View and search your completed visits and no-show records.
              </p>
            </div>

            <span class="visit-history-arrow" id="visitHistoryArrow">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m6 9 6 6 6-6"></path>
              </svg>
            </span>
          </button>

          <!-- Toggle Content -->
          <div id="visitHistoryContent" hidden>

            <form action="doctor_dashboard.php" method="GET" class="filter-row">

              <input type="hidden" name="tab" value="schedule">

              <label>
                From
                <input type="date" name="from" value="<?= htmlspecialchars($fromDate ?? '', ENT_QUOTES, 'UTF-8') ?>">
              </label>

              <label>
                To
                <input type="date" name="to" value="<?= htmlspecialchars($toDate ?? '', ENT_QUOTES, 'UTF-8') ?>">
              </label>

              <button type="submit" class="btn-secondary" style="width:auto;padding:8px 16px;">
                Filter
              </button>

              <?php if (!empty($fromDate) || !empty($toDate)): ?>
                <a href="doctor_dashboard.php?tab=schedule" class="link-btn">
                  Clear
                </a>
              <?php endif; ?>

            </form>

            <div class="table-search-group">
              <input type="text" id="historySearch" placeholder="Search by patient or service..." class="table-search">
            </div>

            <div class="table-responsive">
              <table class="dash-table" id="historyTable">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Patient</th>
                    <th>Service</th>
                    <th>Outcome</th>
                    <th></th>
                  </tr>
                </thead>

                <tbody>
                  <?php if ($history->num_rows > 0): ?>
                    <?php while ($row = $history->fetch_assoc()): ?>
                      <tr>
                        <td>
                          <?= date(
                            "M j, Y",
                            strtotime($row["appointment_date"])
                          ) ?>
                        </td>

                        <td>
                          <?= htmlspecialchars(
                            $row["full_name"],
                            ENT_QUOTES,
                            'UTF-8'
                          ) ?>
                        </td>

                        <td>
                          <?= htmlspecialchars(
                            $row["service"],
                            ENT_QUOTES,
                            'UTF-8'
                          ) ?>
                        </td>

                        <td>
                          <span class="status-pill <?= $row["status"] === "No-show"
                            ? "status-rejected"
                            : "status-completed" ?>">
                            <?= htmlspecialchars(
                              $row["status"],
                              ENT_QUOTES,
                              'UTF-8'
                            ) ?>
                          </span>
                        </td>

                        <td>
                          <a href="view_patient.php?id=<?= urlencode((string) $row["id"]) ?>" class="link-btn">
                            View
                          </a>
                        </td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5">
                        No visit history in this range.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </section>


      <!-- ACCOUNT -->
      <section class="dash-section" id="tab-account">
        <div class="profile-page">

          <!-- Page heading -->
          <div class="profile-page-header">
            <div>
              <h1 class="dash-title">My Account</h1>
              <p class="dash-sub">
                Manage your account information and security settings.
              </p>
            </div>
            <div class="profile-header-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <circle cx="12" cy="8" r="4"></circle>
                <path d="M4 21a8 8 0 0 1 16 0"></path>
              </svg>
            </div>
          </div>


          <!-- Contact information -->
          <div class="dash-card profile-account-card">
            <div class="profile-card-heading">
              <div class="profile-card-icon">
                <svg viewBox="0 0 24 24">
                  <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                  <path d="m3 7 9 6 9-6"></path>
                </svg>
              </div>
              <div>
                <h2 class="card-heading">Contact details</h2>
                <p class="profile-card-sub">
                  Keep your contact information up to date.
                </p>
              </div>
            </div>

            <form action="update_account.php" method="POST" class="profile-account-form">

              <input type="hidden" name="action" value="contact">
              <input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

              <div class="field-row">
                <div class="field">
                  <label for="acc-email">Email address</label>
                  <input type="email" id="acc-email" name="email"
                    value="<?= htmlspecialchars($userRow['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Enter your email address" autocomplete="email" required>
                </div>

                <div class="field">
                  <label for="acc-mobile">Mobile number</label>
                  <input type="tel" id="acc-mobile" name="mobile"
                    value="<?= htmlspecialchars($doctor['mobile'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Enter your mobile number" autocomplete="tel">
                </div>
              </div>

              <div class="profile-form-actions">
                <button type="submit" class="btn-primary">
                  Save changes
                </button>
              </div>
            </form>
          </div>


          <!-- Password management -->
          <div class="dash-card profile-account-card">
            <div class="profile-card-heading">
              <div class="profile-card-icon">
                <svg viewBox="0 0 24 24">
                  <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                  <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                  <path d="M12 14v3"></path>
                </svg>
              </div>
              <div>
                <h2 class="card-heading">Change password</h2>
                <p class="profile-card-sub">
                  Choose a strong password to protect your account.
                </p>
              </div>
            </div>

            <form action="update_account.php" method="POST" class="appt-form profile-account-form">
              <input type="hidden" name="action" value="password">

              <div class="field">
                <label for="acc-current">Current password</label>
                <div class="password-wrapper">
                  <input type="hidden" name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                  <!-- Keep your existing password fields and button here. -->
                  <input type="password" id="acc-current" name="current_password"
                    placeholder="Enter your current password" autocomplete="current-password" required>


                  <button type="button" class="toggle-password" data-target="acc-current" aria-label="Show password"
                    aria-pressed="false">
                    <svg class="icon-eye" viewBox="0 0 24 24">
                      <path
                        d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z">
                      </path>
                    </svg>
                    <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;">
                      <path
                        d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z">
                      </path>
                    </svg>
                  </button>
                </div>
              </div>

              <div class="field">
                <label for="acc-newpass">New password</label>
                <div class="password-wrapper">
                  <input type="password" id="acc-newpass" name="new_password" placeholder="Enter your new password"
                    autocomplete="new-password" required>

                  <button type="button" class="toggle-password" data-target="acc-newpass" aria-label="Show password"
                    aria-pressed="false">
                    <svg class="icon-eye" viewBox="0 0 24 24">
                      <path
                        d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z">
                      </path>
                    </svg>
                    <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;">
                      <path
                        d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z">
                      </path>
                    </svg>
                  </button>
                </div>

                <ul class="pw-requirements" id="pwRequirements">
                  <li data-rule="length">At least 8 characters</li>
                  <li data-rule="number">Contains a number</li>
                  <li data-rule="special">Contains a special character</li>
                </ul>
              </div>

              <div class="profile-form-actions">
                <button type="submit" class="btn-primary">
                  Update password
                </button>
              </div>
            </form>
          </div>

          <!-- Security reminder -->
          <div class="profile-security-note">
            <div class="profile-security-icon" aria-hidden="true">!</div>
            <div>
              <strong>Keep your account secure</strong>
              <p>
                Never share your password with others. Use a password
                that is difficult to guess and update it if you suspect
                your account has been compromised.
              </p>
            </div>
          </div>

        </div>
      </section>


    </main>
  </div>
  <script>
    document.body.dataset.tab = "home";
  </script>
  <script src="patient_logic.js"></script>
  <script>
    function wireTableSearch(inputId, tableId) {
      const input = document.getElementById(inputId);
      const table = document.getElementById(tableId);
      if (!input || !table) return;
      input.addEventListener("input", function () {
        const term = this.value.toLowerCase();
        table.querySelectorAll("tbody tr").forEach((row) => {
          row.style.display = row.textContent.toLowerCase().includes(term) ? "" : "none";
        });
      });
    }
    wireTableSearch("upcomingSearch", "upcomingTable");
    wireTableSearch("historySearch", "historyTable");
  </script>
</body>

</html>