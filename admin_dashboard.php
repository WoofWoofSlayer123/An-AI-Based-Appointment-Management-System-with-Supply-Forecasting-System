<?php

session_start();

include "connection.php";

include "auth_check.php";

include "forecast_helper.php";



if ($_SESSION["role"] !== "admin") {

  if ($_SESSION["role"] === "patient") {

    header("Location: dashboard.php");

  } elseif ($_SESSION["role"] === "doctor") {

    header("Location: doctor_dashboard.php");

  } else {

    header("Location: staff_dashboard.php");

  }

  exit;

}



$username = $_SESSION["username"];



// Consistent escaping for values rendered into HTML.

if (!function_exists("admin_escape")) {

  function admin_escape($value)
  {

    return htmlspecialchars((string) ($value ?? ""), ENT_QUOTES, "UTF-8");

  }

}



$stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");

$stmt->bind_param("s", $username);

$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();

$stmt->close();



$stmtUser = $conn->prepare("SELECT email FROM users WHERE username = ?");

$stmtUser->bind_param("s", $username);

$stmtUser->execute();

$userRow = $stmtUser->get_result()->fetch_assoc();

$stmtUser->close();



// All health centers, for dropdowns everywhere

$centersResult = $conn->query("SELECT * FROM health_centers ORDER BY name");

$centers = [];

while ($c = $centersResult->fetch_assoc())

  $centers[] = $c;

function center_name_admin($centers, $id)
{

  foreach ($centers as $c)

    if ($c["id"] == $id)

      return $c["name"];

  return "Unassigned";

}



// Pending staff/doctor requests

$stmt = $conn->query("

    SELECT users.username, patients.full_name, patients.staff_position,

           patients.staff_claimed_center_id, patients.staff_employee_id,

           health_centers.name AS claimed_center_name

    FROM users

    JOIN patients ON patients.username = users.username

    LEFT JOIN health_centers ON health_centers.id = patients.staff_claimed_center_id

    WHERE patients.pending_staff = 1 AND users.role = 'patient'

    ORDER BY patients.id

");

$pendingRequests = $stmt;



// All currently approved staff/doctor accounts

$staffList = $conn->query("

    SELECT users.username, users.role, patients.full_name, patients.health_center_id,

           health_centers.name AS center_name

    FROM users

    JOIN patients ON patients.username = users.username

    LEFT JOIN health_centers ON health_centers.id = patients.health_center_id

    WHERE users.role IN ('staff','doctor')

    ORDER BY health_centers.name, patients.full_name

");



// District-wide quick stats

$pendingCount = $pendingRequests->num_rows;

$centerCount = count($centers);

$lowStockDistrict = $conn->query("SELECT COUNT(*) AS c FROM medicines WHERE stock_quantity <= reorder_threshold")->fetch_assoc()["c"];

$totalPatients = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'patient'")->fetch_assoc()["c"];



// Retrieve registered patient accounts for the Manage Accounts table

$registeredPatients = $conn->query("

    SELECT users.username, users.email, patients.full_name

    FROM users

    INNER JOIN patients

        ON patients.username = users.username

    WHERE users.role = 'patient'

      AND COALESCE(patients.pending_staff, 0) = 0

    ORDER BY patients.full_name ASC

");



if (!$registeredPatients) {

  error_log("Registered patients query failed: " . $conn->error);

  $totalPatients = 0;

} else {

  $totalPatients = $registeredPatients->num_rows;

}



// Today's appointment count and district-wide appointment status distribution.

$todayAppointments = 0;

$todayResult = $conn->query("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = CURDATE() AND status NOT IN ('Cancelled','Rejected')");

if ($todayResult)

  $todayAppointments = (int) ($todayResult->fetch_assoc()["c"] ?? 0);



$appointmentStatusCounts = [];

$statusCountResult = $conn->query("SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status ORDER BY cnt DESC");

if ($statusCountResult) {

  while ($statusRow = $statusCountResult->fetch_assoc()) {

    $appointmentStatusCounts[(string) $statusRow["status"]] = (int) $statusRow["cnt"];

  }

}

$appointmentStatusTotal = array_sum($appointmentStatusCounts);

$firstName = explode(" ", trim((string) ($admin["full_name"] ?? $username)))[0] ?? $username;



// Upcoming status overrides (today and future), for the Health Centers tab list

$statusOverrides = $conn->query("

    SELECT health_center_status.*, health_centers.name AS center_name

    FROM health_center_status

    JOIN health_centers ON health_centers.id = health_center_status.health_center_id

    WHERE status_date >= CURDATE()

    ORDER BY status_date, center_name

");



// ---------------- DISTRICT-WIDE REPORTS ----------------



// Appointments per day, last 7 days, all centers combined

$apptsByDay = [];

for ($i = 6; $i >= 0; $i--)

  $apptsByDay[date("Y-m-d", strtotime("-$i days"))] = 0;

$res = $conn->query("

    SELECT appointment_date, COUNT(*) AS cnt FROM appointments

    WHERE appointment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()

          AND status NOT IN ('Cancelled','Rejected')

    GROUP BY appointment_date

");

while ($row = $res->fetch_assoc())

  $apptsByDay[$row["appointment_date"]] = (int) $row["cnt"];



// Appointments by center (all-time, not cancelled/rejected) — which center is busiest

$res = $conn->query("

    SELECT health_centers.name, COUNT(appointments.id) AS cnt

    FROM health_centers

    LEFT JOIN appointments ON appointments.health_center_id = health_centers.id

          AND appointments.status NOT IN ('Cancelled','Rejected')

    GROUP BY health_centers.id, health_centers.name

    ORDER BY cnt DESC

");

$centerApptLabels = [];

$centerApptValues = [];

while ($row = $res->fetch_assoc()) {

  $centerApptLabels[] = $row["name"];

  $centerApptValues[] = (int) $row["cnt"];

}



// Most utilized service, district-wide, top 5

$res = $conn->query("

    SELECT service, COUNT(*) AS cnt FROM appointments

    WHERE status NOT IN ('Cancelled','Rejected')

    GROUP BY service ORDER BY cnt DESC LIMIT 5

");

$serviceLabels = [];

$serviceValues = [];

while ($row = $res->fetch_assoc()) {

  $serviceLabels[] = $row["service"];

  $serviceValues[] = (int) $row["cnt"];

}



// Low-stock medicine count by center — which center is chronically short

$res = $conn->query("

    SELECT health_centers.name, COUNT(medicines.id) AS low_count

    FROM health_centers

    LEFT JOIN medicines ON medicines.health_center_id = health_centers.id

          AND medicines.stock_quantity <= medicines.reorder_threshold

    GROUP BY health_centers.id, health_centers.name

    ORDER BY low_count DESC

");

$lowStockLabels = [];

$lowStockValues = [];

while ($row = $res->fetch_assoc()) {

  $lowStockLabels[] = $row["name"];

  $lowStockValues[] = (int) $row["low_count"];

}



// District-wide supply forecast — no center filter, so this naturally

// surfaces a shortage at one center sitting right next to surplus at

// another once sorted by urgency.

$districtForecast = get_medicine_forecast($conn);

$districtCriticalCount = count(array_filter($districtForecast, fn($f) => $f["status"] === "critical"));



$statusMsg = $_GET["msg"] ?? null;

$statusType = $_GET["type"] ?? "info";

?>

<!DOCTYPE html>

<html lang="en">



<head>

  <meta charset="UTF-8" />

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <title>Admin Dashboard — Malabon District II Health Centers</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">

  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <link
    href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap"
    rel="stylesheet">

  <link rel="stylesheet" href="homepagestyle.css">

  <link rel="stylesheet" href="patient_style.css">

  <link rel="stylesheet" href="admin_dashboard.css">

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

  <style>
    .admin-chart-legend {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 18px;
      margin-top: 12px;
      font-size: 0.88rem;
      color: #475569;
    }

    .admin-chart-legend-item {
      display: inline-flex;
      align-items: center;
      gap: 7px;
    }

    .admin-chart-dot {
      display: inline-block;
      flex: 0 0 11px;
      width: 11px;
      height: 11px;
      border-radius: 50%;
    }

    .admin-chart-note {
      margin: 7px 0 0;
      color: #64748b;
      font-size: 0.82rem;
      line-height: 1.45;
    }
  </style>
</head>



<body class="dashboard-body">



  <div class="dash-shell">



    <aside class="sidebar">

      <div class="sidebar-brand"><span class="dot"></span> MALABON DISTRICT II</div>

      <div class="sidebar-user">

        <div class="sidebar-name"><?= admin_escape($admin["full_name"] ?? $username) ?></div>

        <div class="sidebar-id">District Admin</div>

      </div>

      <nav class="sidebar-nav">

        <a href="#" class="nav-link" data-tab="home">

          Home

          <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>

        </a>

        <a href="#" class="nav-link" data-tab="accounts">

          Manage Accounts

          <?php if ($pendingCount > 0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>

        </a>

        <a href="#" class="nav-link" data-tab="centers">Health Centers</a>

        <a href="#" class="nav-link" data-tab="reports">Reports</a>

        <a href="#" class="nav-link" data-tab="forecast">

          Supply Forecast

          <?php if ($districtCriticalCount > 0): ?><span
              class="nav-badge"><?= $districtCriticalCount ?></span><?php endif; ?>

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

      <section class="dash-section admin-home" id="tab-home">

        <div class="admin-page-heading">

          <div>

            <p class="admin-eyebrow"><?= date("l, F j, Y") ?></p>

            <h1 class="dash-title">Welcome back, <?= admin_escape($firstName) ?></h1>

            <p class="dash-sub">District-wide overview of appointments, health centers, and medicine supplies.</p>

          </div>

          <div class="admin-heading-mark" aria-hidden="true">+</div>

        </div>



        <div class="admin-stats-grid">

          <a href="#" class="admin-stat-card" data-tab="accounts">

            <span class="admin-stat-icon icon-purple">♙</span>

            <span class="admin-stat-label">Pending requests</span>

            <strong class="admin-stat-value"><?= (int) $pendingCount ?></strong>

            <span class="admin-stat-note">Staff and doctor approvals</span>

          </a>

          <a href="#" class="admin-stat-card" data-tab="centers">

            <span class="admin-stat-icon icon-teal">⌂</span>

            <span class="admin-stat-label">Health centers</span>

            <strong class="admin-stat-value"><?= (int) $centerCount ?></strong>

            <span class="admin-stat-note">Participating centers</span>

          </a>

          <a href="#" class="admin-stat-card" data-tab="reports">

            <span class="admin-stat-icon icon-blue">▦</span>

            <span class="admin-stat-label">Today's appointments</span>

            <strong class="admin-stat-value"><?= (int) $todayAppointments ?></strong>

            <span class="admin-stat-note">Not cancelled or rejected</span>

          </a>

          <a href="#" class="admin-stat-card" data-tab="forecast">

            <span class="admin-stat-icon icon-orange">✚</span>

            <span class="admin-stat-label">Low-stock medicines</span>

            <strong class="admin-stat-value"><?= (int) $lowStockDistrict ?></strong>

            <span class="admin-stat-note">At or below reorder threshold</span>

          </a>

          <a href="#" class="admin-stat-card" data-tab="accounts">

            <span class="admin-stat-icon icon-green">♧</span>

            <span class="admin-stat-label">Registered patients</span>

            <strong class="admin-stat-value"><?= (int) $totalPatients ?></strong>

            <span class="admin-stat-note">Patient accounts in the system</span>

          </a>

          <a href="#" class="admin-stat-card" data-tab="forecast">

            <span class="admin-stat-icon icon-red">!</span>

            <span class="admin-stat-label">Critical forecasts</span>

            <strong class="admin-stat-value"><?= (int) $districtCriticalCount ?></strong>

            <span class="admin-stat-note">Medicine supply needs attention</span>

          </a>

        </div>



        <?php if ($pendingCount > 0 || $districtCriticalCount > 0 || (int) $lowStockDistrict > 0): ?>

          <div class="admin-alert-grid">

            <?php if ($pendingCount > 0): ?>

              <div class="admin-alert alert-warn"><span class="admin-alert-symbol">!</span>

                <div><strong><?= (int) $pendingCount ?> request(s) awaiting review</strong>

                  <p>Review staff and doctor registrations to complete account approval.</p><a href="#"
                    data-tab="accounts">Review accounts →</a>

                </div>

              </div>

            <?php endif; ?>

            <?php if ($districtCriticalCount > 0): ?>

              <div class="admin-alert alert-danger"><span class="admin-alert-symbol">!</span>

                <div><strong><?= (int) $districtCriticalCount ?> critical supply forecast(s)</strong>

                  <p>Check medicines predicted to run out soon.</p><a href="#" data-tab="forecast">Open supply forecast

                    →</a>

                </div>

              </div>

            <?php elseif ((int) $lowStockDistrict > 0): ?>

              <div class="admin-alert alert-warn"><span class="admin-alert-symbol">!</span>

                <div><strong><?= (int) $lowStockDistrict ?> low-stock medicine item(s)</strong>

                  <p>Review inventory levels across the district.</p><a href="#" data-tab="reports">View reports →</a>

                </div>

              </div>

            <?php endif; ?>

          </div>

        <?php endif; ?>



        <div class="admin-home-grid">

          <div class="dash-card admin-chart-card">

            <div class="card-heading-row">

              <div>

                <h2 class="card-heading">Appointment status</h2>

                <p class="admin-card-subtitle">Distribution of recorded appointment statuses.</p>

              </div><a href="#" class="link-btn" data-tab="reports">View reports</a>

            </div>

            <?php if ($appointmentStatusTotal > 0): ?>

              <div class="admin-status-chart-wrap"><canvas id="adminAppointmentStatusChart"></canvas></div>

              <div class="admin-status-legend">

                <?php foreach ($appointmentStatusCounts as $statusLabel => $statusCount): ?>

                  <span><i class="legend-dot"
                      data-status="<?= admin_escape($statusLabel) ?>"></i><?= admin_escape($statusLabel) ?><strong><?= (int) $statusCount ?></strong></span>

                <?php endforeach; ?>

              </div>

            <?php else: ?>

              <p class="admin-empty-state">No appointment records are available yet.</p><?php endif; ?>

          </div>



          <div class="dash-card admin-quick-card">

            <h2 class="card-heading">Quick actions</h2>

            <p class="admin-card-subtitle">Go directly to common district administration tasks.</p>

            <a href="#" class="admin-quick-action" data-tab="accounts"><span>♙</span>

              <div><strong>Review account requests</strong><small>Approve or reject staff and doctor accounts</small>

              </div><b>→</b>

            </a>

            <a href="#" class="admin-quick-action" data-tab="centers"><span>⌂</span>

              <div><strong>Manage health centers</strong><small>Add a center or update its information</small></div>

              <b>→</b>

            </a>

            <a href="#" class="admin-quick-action" data-tab="forecast"><span>✚</span>

              <div><strong>Check supply forecast</strong><small>Review projected medicine availability</small></div>

              <b>→</b>

            </a>

            <a href="#" class="admin-quick-action" data-tab="reports"><span>▤</span>

              <div><strong>Open district reports</strong><small>View appointment and inventory summaries</small></div>

              <b>→</b>

            </a>

          </div>

        </div>



        <div class="dash-card admin-status-management">

          <details>

            <summary><span><strong>Set a health center status</strong><small>Schedule a closure, holiday, event, or

                  custom operating hours.</small></span><span class="admin-summary-plus">+</span></summary>

            <form action="process_health_center.php" method="POST" class="appt-form admin-form-inner">

              <input type="hidden" name="action" value="add_status">

              <div class="field-row">

                <div class="field"><label for="st-center">Health center</label><select id="st-center"
                    name="health_center_id" required><?php foreach ($centers as $c): ?>

                      <option value="<?= (int) $c["id"] ?>"><?= admin_escape($c["name"]) ?></option><?php endforeach; ?>

                  </select></div>

                <div class="field"><label for="st-date">Date</label><input type="date" id="st-date" name="status_date"
                    min="<?= date("Y-m-d") ?>" required></div>

              </div>

              <div class="field-row">

                <div class="field"><label for="st-status">Status</label><select id="st-status" name="status" required>

                    <option value="Open">Open (custom hours)</option>

                    <option value="Closed">Closed (holiday)</option>

                    <option value="Event">Event (e.g. vaccination drive)</option>

                  </select></div>

                <div class="field"><label for="st-hours">Hours <span class="hint">(if Open or

                      Event)</span></label><input type="text" id="st-hours" name="custom_hours"
                    placeholder="8:00 AM – 12:00 PM"></div>

              </div>

              <div class="field"><label for="st-note">Note <span class="hint">(shown to patients)</span></label><input
                  type="text" id="st-note" name="note" placeholder="e.g. Vaccination Drive, Barangay Fiesta"></div>

              <button type="submit" class="btn-primary">Save status</button>

            </form>

          </details>

        </div>



        <div class="dash-card admin-table-card">

          <div class="card-heading-row">

            <div>

              <h2 class="card-heading">Upcoming health center statuses</h2>

              <p class="admin-card-subtitle">Future closures, events, and adjusted hours.</p>

            </div><input type="search" class="admin-table-search" data-table-search="upcoming-status-table"
              placeholder="Search statuses…" aria-label="Search upcoming statuses">

          </div>

          <div class="admin-table-wrap">

            <table class="dash-table" id="upcoming-status-table">

              <thead>

                <tr>

                  <th>Date</th>

                  <th>Center</th>

                  <th>Status</th>

                  <th>Hours</th>

                  <th>Note</th>

                  <th>Action</th>

                </tr>

              </thead>

              <tbody>

                <?php if ($statusOverrides && $statusOverrides->num_rows > 0):

                  while ($row = $statusOverrides->fetch_assoc()): ?>

                    <tr>

                      <td><?= date("M j, Y", strtotime($row["status_date"])) ?></td>

                      <td><?= admin_escape($row["center_name"]) ?></td>

                      <td><span
                          class="status-pill status-<?= $row["status"] === "Closed" ? "rejected" : ($row["status"] === "Event" ? "pending" : "confirmed") ?>"><?= admin_escape($row["status"]) ?></span>

                      </td>

                      <td><?= admin_escape($row["custom_hours"] ?: "—") ?></td>

                      <td><?= admin_escape($row["note"] ?: "—") ?></td>

                      <td>

                        <form action="process_health_center.php" method="POST" class="inline-form"
                          data-confirm="Remove this scheduled status?"><input type="hidden" name="action"
                            value="delete_status"><input type="hidden" name="id" value="<?= (int) $row["id"] ?>"><button
                            type="submit" class="link-btn">Remove</button></form>

                      </td>

                    </tr>

                  <?php endwhile; else: ?>

                  <tr>

                    <td colspan="6" class="admin-empty-cell">No upcoming statuses set.</td>

                  </tr><?php endif; ?>

              </tbody>

            </table>

          </div>

        </div>

      </section>



      <!-- MANAGE ACCOUNTS -->

      <section class="dash-section" id="tab-accounts">

        <h1 class="dash-title">Manage Accounts</h1>

        <p class="dash-sub">Approve staff/doctor requests, and reassign existing staff between centers.</p>



        <div class="dash-card">

          <div class="card-heading-row">

            <h2 class="card-heading">Pending requests</h2><input type="search" class="admin-table-search"
              data-table-search="pending-accounts-table" placeholder="Search requests…"
              aria-label="Search pending account requests">

          </div>

          <div class="admin-table-wrap">

            <table class="dash-table" id="pending-accounts-table">

              <thead>

                <tr>

                  <th>Name</th>

                  <th>Claimed position</th>

                  <th>Claimed center</th>

                  <th>Employee ID</th>

                  <th></th>

                </tr>

              </thead>

              <tbody>

                <?php if ($pendingRequests->num_rows > 0):

                  while ($row = $pendingRequests->fetch_assoc()): ?>

                    <tr>

                      <td><?= htmlspecialchars($row["full_name"]) ?></td>

                      <td><?= htmlspecialchars($row["staff_position"] ?: "—") ?></td>

                      <td><?= htmlspecialchars($row["claimed_center_name"] ?: "—") ?></td>

                      <td><?= htmlspecialchars($row["staff_employee_id"] ?: "—") ?></td>

                      <td class="table-actions">

                        <form action="process_admin_account.php" method="POST" class="approve-form">

                          <input type="hidden" name="action" value="approve">

                          <input type="hidden" name="username" value="<?= htmlspecialchars($row["username"]) ?>">

                          <select name="new_role" required>

                            <option value="">Role</option>

                            <option value="staff">Staff</option>

                            <option value="doctor">Doctor</option>

                          </select>

                          <select name="health_center_id" required>

                            <option value="">Center</option>

                            <?php foreach ($centers as $c): ?>

                              <option value="<?= $c["id"] ?>" <?= $c["id"] == $row["staff_claimed_center_id"] ? "selected" : "" ?>><?= htmlspecialchars($c["name"]) ?></option>

                            <?php endforeach; ?>

                          </select>

                          <button type="submit" class="btn-approve">Approve</button>

                        </form>

                        <form action="process_admin_account.php" method="POST" style="display:inline;">

                          <input type="hidden" name="action" value="reject">

                          <input type="hidden" name="username" value="<?= htmlspecialchars($row["username"]) ?>">

                          <button type="submit" class="btn-reject"
                            onclick="return confirm('Reject this request? The account stays a regular patient account.');">Reject</button>

                        </form>

                      </td>

                    </tr>

                  <?php endwhile; else: ?>

                  <tr>

                    <td colspan="5">No pending requests.</td>

                  </tr>

                <?php endif; ?>

              </tbody>

            </table>

          </div>

        </div>



        <div class="dash-card">

          <div class="card-heading-row">

            <h2 class="card-heading">Current staff & doctors</h2><input type="search" class="admin-table-search"
              data-table-search="staff-accounts-table" placeholder="Search staff…"
              aria-label="Search staff and doctors">

          </div>

          <div class="admin-table-wrap">

            <table class="dash-table" id="staff-accounts-table">

              <thead>

                <tr>

                  <th>Name</th>

                  <th>Role</th>

                  <th>Health center</th>

                  <th>Move to</th>

                </tr>

              </thead>

              <tbody>

                <?php if ($staffList->num_rows > 0):

                  while ($row = $staffList->fetch_assoc()): ?>

                    <tr>

                      <td><?= htmlspecialchars($row["full_name"]) ?></td>

                      <td><?= ucfirst($row["role"]) ?></td>

                      <td><?= htmlspecialchars($row["center_name"] ?: "Unassigned") ?></td>

                      <td>

                        <form action="process_admin_account.php" method="POST" class="move-form">

                          <input type="hidden" name="action" value="move">

                          <input type="hidden" name="username" value="<?= htmlspecialchars($row["username"]) ?>">

                          <select name="health_center_id" required>

                            <?php foreach ($centers as $c): ?>

                              <option value="<?= $c["id"] ?>" <?= $c["id"] == $row["health_center_id"] ? "selected" : "" ?>>

                                <?= htmlspecialchars($c["name"]) ?>

                              </option>

                            <?php endforeach; ?>

                          </select>

                          <button type="submit" class="btn-move">Move</button>

                        </form>

                      </td>

                    </tr>

                  <?php endwhile; else: ?>

                  <tr>

                    <td colspan="4">No staff or doctors yet.</td>

                  </tr>

                <?php endif; ?>

              </tbody>

            </table>

          </div>

        </div>



        <!-- REGISTERED PATIENTS -->

        <div class="dash-card">

          <h2 class="card-heading">Registered Patients</h2>

          <p class="admin-card-subtitle">

            View patient accounts registered in the system.

          </p>

          <div class="patient-search-container">

            <input type="search" id="registeredPatientSearch" class="patient-search-input"
              placeholder="Search by name, username, or email..." aria-label="Search registered patients">

          </div>



          <div class="table-responsive">

            <table class="dash-table">

              <thead>

                <tr>

                  <th>Full Name</th>

                  <th>Username</th>

                  <th>Email</th>

                  <th>Account Type</th>

                </tr>

              </thead>



              <tbody>

                <?php if ($registeredPatients && $registeredPatients->num_rows > 0): ?>

                  <?php while ($patientRow = $registeredPatients->fetch_assoc()): ?>

                    <tr class="registered-patient-row">

                      <td><?= admin_escape($patientRow["full_name"] ?: "—") ?></td>

                      <td><?= admin_escape($patientRow["username"]) ?></td>

                      <td><?= admin_escape($patientRow["email"] ?: "—") ?></td>

                      <td><span class="status-badge confirmed">Patient</span></td>

                    </tr>

                  <?php endwhile; ?>

                <?php else: ?>

                  <tr>

                    <td colspan="4">

                      No registered patient accounts found.

                    </td>

                  </tr>

                <?php endif; ?>

              </tbody>

            </table>

          </div>

        </div>

      </section>



      <!-- HEALTH CENTERS -->

      <section class="dash-section" id="tab-centers">

        <h1 class="dash-title">Health Centers</h1>

        <p class="dash-sub">Add centers, edit their info, and set today's or an upcoming status.</p>



        <div class="dash-card">

          <h2 class="card-heading">Add a health center</h2>

          <form action="process_health_center.php" method="POST" class="appt-form">

            <input type="hidden" name="action" value="add_center">

            <div class="field">

              <label for="hc-name">Name</label>

              <input type="text" id="hc-name" name="name" required>

            </div>

            <div class="field-row">

              <div class="field">

                <label for="hc-address">Address</label>

                <input type="text" id="hc-address" name="address">

              </div>

              <div class="field">

                <label for="hc-contact">Contact number</label>

                <input type="text" id="hc-contact" name="contact_number">

              </div>

            </div>

            <div class="field">

              <label for="hc-hours">Regular operating hours</label>

              <input type="text" id="hc-hours" name="operating_hours" placeholder="Mon–Fri, 8:00 AM – 5:00 PM">

            </div>

            <button type="submit" class="btn-primary">Add center</button>

          </form>

        </div>



        <div class="dash-card admin-table-card">

          <div class="card-heading-row">

            <h2 class="card-heading">Existing centers</h2><input type="search" class="admin-table-search"
              id="center-search" placeholder="Search health centers…" aria-label="Search health centers">

          </div>

          <div class="admin-center-list">

            <?php foreach ($centers as $c): ?>

              <details class="admin-center-item"
                data-center-search="<?= admin_escape($c["name"] . " " . ($c["address"] ?? "") . " " . ($c["contact_number"] ?? "")) ?>">

                <summary>

                  <span><strong><?= admin_escape($c["name"]) ?></strong><small><?= admin_escape($c["address"] ?: "Address not provided") ?></small></span><span
                    class="admin-center-edit-label">Edit details +</span>

                </summary>

                <form action="process_health_center.php" method="POST" class="edit-center-form admin-form-inner"
                  data-confirm="Save changes to this health center?">

                  <input type="hidden" name="action" value="edit_center">

                  <input type="hidden" name="id" value="<?= $c["id"] ?>">

                  <div class="field-row">

                    <div class="field"><label>Name</label><input type="text" name="name"
                        value="<?= htmlspecialchars($c["name"]) ?>" required></div>

                    <div class="field"><label>Contact</label><input type="text" name="contact_number"
                        value="<?= htmlspecialchars($c["contact_number"]) ?>"></div>

                  </div>

                  <div class="field-row">

                    <div class="field"><label>Address</label><input type="text" name="address"
                        value="<?= htmlspecialchars($c["address"]) ?>"></div>

                    <div class="field"><label>Hours</label><input type="text" name="operating_hours"
                        value="<?= htmlspecialchars($c["operating_hours"]) ?>"></div>

                  </div>

                  <button type="submit" class="btn-secondary" style="width:auto;padding:8px 18px;">Save changes</button>

                </form>

              </details>

            <?php endforeach; ?>

          </div>

        </div>

      </section>



      <!-- REPORTS -->

      <section class="dash-section" id="tab-reports">

        <div class="card-heading-row" style="align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div>
            <h1 class="dash-title">Reports</h1>
            <p class="dash-sub">District-wide, across all <?= $centerCount ?> health centers.</p>
          </div>
        </div>



        <div class="dash-card">

          <div class="card-heading-row" style="align-items:center;gap:12px;flex-wrap:wrap;">
            <h2 class="card-heading">Appointment status distribution</h2>
            <a href="appointment_status_monthly_report.php" class="btn-primary btn-inline" target="_blank"
              rel="noopener">Monthly status report</a>
          </div>

          <p class="admin-card-subtitle">All recorded appointments, grouped by current status.</p>

          <?php if ($appointmentStatusTotal > 0): ?>

            <div class="admin-report-status-wrap"><canvas id="reportsAppointmentStatusChart"></canvas></div>
            <div class="admin-status-legend" aria-label="Appointment status color legend">
              <?php foreach ($appointmentStatusCounts as $statusLabel => $statusCount): ?>
                <span><i class="legend-dot"
                    data-status="<?= admin_escape($statusLabel) ?>"></i><?= admin_escape($statusLabel) ?><strong><?= (int) $statusCount ?></strong></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>

            <p class="admin-empty-state">No appointment records are available yet.</p><?php endif; ?>

        </div>



        <div class="dash-card">

          <div class="card-heading-row">

            <h2 class="card-heading">Appointments, last 7 days (all centers)</h2>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            </div>

          </div>

          <canvas id="apptsChart" height="90"></canvas>
          <div class="admin-chart-legend" aria-label="Appointment activity chart legend">
            <span class="admin-chart-legend-item"><i class="admin-chart-dot"
                style="background:#146B61"></i><strong>Teal</strong> — appointments per day</span>
          </div>
          <p class="admin-chart-note">Counts include appointments that are not cancelled or rejected.</p>

        </div>



        <div class="dash-card">

          <div class="card-heading-row" style="align-items:center;gap:12px;flex-wrap:wrap;">
            <h2 class="card-heading">Appointments by center (all time)</h2>
          </div>

          <?php if (empty($centerApptLabels)): ?>

            <p class="hint">No appointments recorded yet.</p><?php else: ?>

            <canvas id="centerApptChart" height="100"></canvas>
            <div class="admin-chart-legend" aria-label="Appointments by center chart legend">
              <span class="admin-chart-legend-item"><i class="admin-chart-dot" style="background:#0E4D46"></i><strong>Dark
                  green</strong> — appointment count per health center</span>
            </div>
            <p class="admin-chart-note">All-time totals; cancelled and rejected appointments are excluded.</p>

          <?php endif; ?>

        </div>



        <div class="dash-card">

          <div class="card-heading-row" style="align-items:center;gap:12px;flex-wrap:wrap;">
            <h2 class="card-heading">Most utilized services (district-wide, all time)</h2>
            <a href="most_utilized_services_report.php" class="btn-primary btn-inline" target="_blank"
              rel="noopener">View Most Utilized Services</a>
          </div>

          <?php if (empty($serviceLabels)): ?>

            <p class="hint">No appointments recorded yet.</p><?php else: ?>

            <canvas id="serviceChart" height="100"></canvas>
            <div class="admin-chart-legend" aria-label="Most utilized services chart legend">
              <span class="admin-chart-legend-item"><i class="admin-chart-dot"
                  style="background:#E8A33D"></i><strong>Amber</strong> — times booked per service</span>
            </div>
            <p class="admin-chart-note">Shows appointment bookings by service, not unique patients.</p>

          <?php endif; ?>

        </div>



        <div class="dash-card">

          <div class="card-heading-row">

            <h2 class="card-heading">Low-stock medicines by center</h2>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
              <a href="low_stock_medicines_by_center_report.php" class="btn-primary btn-inline" target="_blank"
                rel="noopener">View Low Stock Medicinet</a>
            </div>

          </div>

          <?php if (empty($lowStockLabels)): ?>

            <p class="hint">No medicines added at any center yet.</p><?php else: ?>

            <canvas id="lowStockChart" height="100"></canvas>
            <div class="admin-chart-legend" aria-label="Low-stock medicines chart legend">
              <span class="admin-chart-legend-item"><i class="admin-chart-dot"
                  style="background:#C0392B"></i><strong>Red</strong> — count of medicine items at or below their reorder
                threshold</span>
            </div>
            <p class="admin-chart-note">A higher count means more medicine items at that center are low-stock; it does not
              represent total units missing.</p>

          <?php endif; ?>

        </div>

      </section>



      <!-- SUPPLY FORECAST -->

      <section class="dash-section" id="tab-forecast">

        <h1 class="dash-title">Supply Forecast</h1>

        <p class="dash-sub">District-wide, across all <?= $centerCount ?> health centers.</p>



        <div class="dash-card">

          <div class="card-heading-row">

            <h2 class="card-heading">

              Projected days of stock remaining

              <?php if ($districtCriticalCount > 0): ?><span
                  class="status-pill status-rejected"><?= $districtCriticalCount ?> critical</span><?php endif; ?>

            </h2>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><a
                href="remainingai_medicine_forecast_report.php" class="btn-primary btn-inline" target="_blank"
                rel="noopener">View Projected Days Report</a></div>

          </div>

          <p class="hint" style="margin-bottom:12px;">

            Based on a 14-day consumption trend per medicine, per center — not just an average, so a recent

            spike shows up faster. Anything with fewer than 3 prescriptions in that window is marked

            "Not enough data" rather than showing a number that would just be noise. Sorted by urgency — a

            shortage at one center and surplus at another for the same medicine will both show up here.

          </p>



          <?php

          $districtChartable = array_values(array_filter($districtForecast, fn($f) => $f["days_remaining"] !== null));

          $forecastColorMap = ["critical" => "#C0392B", "soon" => "#E8A33D", "fine" => "#1E7A4C"];

          ?>

          <?php if (!empty($districtChartable)): ?>

            <canvas id="forecastChart" height="<?= max(100, count($districtChartable) * 26) ?>"></canvas>

            <?php if (count($districtChartable) < count($districtForecast)): ?>

              <p class="hint" style="margin-top:8px;">

                <?= count($districtForecast) - count($districtChartable) ?> medicine(s) with insufficient data aren't shown

                on the chart — see the table below.

              </p>

            <?php endif; ?>

          <?php endif; ?>



          <div class="card-heading-row admin-forecast-table-heading">

            <h3 class="card-heading">Forecast details</h3><input type="search" class="admin-table-search"
              data-table-search="district-forecast-table" placeholder="Search medicine or center…"
              aria-label="Search supply forecasts">

          </div>

          <div class="admin-table-wrap">

            <table class="dash-table" id="district-forecast-table">

              <thead>

                <tr>

                  <th>Medicine</th>

                  <th>Health center</th>

                  <th>Stock</th>

                  <th>Daily use (avg)</th>

                  <th>Est. days left</th>

                  <th>Status</th>

                </tr>

              </thead>

              <tbody>

                <?php if (!empty($districtForecast)):

                  foreach ($districtForecast as $f): ?>

                    <tr>

                      <td><?= htmlspecialchars($f["name"]) ?></td>

                      <td><?= htmlspecialchars($f["center_name"]) ?></td>

                      <td><?= $f["stock_quantity"] ?>     <?= htmlspecialchars($f["unit"]) ?></td>

                      <td>

                        <?= $f["daily_rate"] !== null ? number_format($f["daily_rate"], 1) . " " . htmlspecialchars($f["unit"]) . "/day" : "—" ?>

                      </td>

                      <td><?= $f["days_remaining"] !== null ? $f["days_remaining"] . " days" : "—" ?></td>

                      <td>

                        <?php

                        $forecastPill = [

                          "critical" => ["status-rejected", "Critical"],

                          "soon" => ["status-pending", "Reorder soon"],

                          "fine" => ["status-confirmed", "Fine"],

                          "insufficient" => ["status-completed", "Not enough data"],

                        ][$f["status"]];

                        ?>

                        <span class="status-pill <?= $forecastPill[0] ?>"><?= $forecastPill[1] ?></span>

                      </td>

                    </tr>

                  <?php endforeach; else: ?>

                  <tr>

                    <td colspan="6">No medicines added at any center yet.</td>

                  </tr>

                <?php endif; ?>

              </tbody>

            </table>

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

                <input type="email" id="acc-email" name="email" value="<?= admin_escape($userRow["email"] ?? "") ?>"
                  required>

              </div>

              <div class="field">

                <label for="acc-mobile">Mobile number</label>

                <input type="tel" id="acc-mobile" name="mobile" value="<?= admin_escape($admin["mobile"] ?? "") ?>"
                  required>

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

                <button type="button" class="toggle-password" data-target="acc-current" aria-label="Show password">

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

                <button type="button" class="toggle-password" data-target="acc-newpass" aria-label="Show password">

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

    (function () {

      "use strict";

      const statusLabels = <?= json_encode(array_keys($appointmentStatusCounts), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

      const statusValues = <?= json_encode(array_values($appointmentStatusCounts)) ?>;

      const statusPalette = { confirmed: "#198754", completed: "#2673b8", pending: "#e6a23c", cancelled: "#8b93a1", rejected: "#c74747", "no-show": "#8c63b5" };

      const statusColors = statusLabels.map(s => statusPalette[String(s).toLowerCase()] || "#4f8b83");

      function makeChart(id, config) {

        const canvas = document.getElementById(id);

        if (canvas && window.Chart) new Chart(canvas, config);

      }



      const statusConfig = {

        type: "doughnut",

        data: { labels: statusLabels, datasets: [{ data: statusValues, backgroundColor: statusColors, borderColor: "#ffffff", borderWidth: 3, hoverOffset: 5 }] },

        options: { responsive: true, maintainAspectRatio: false, cutout: "66%", plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed}` } } } }

      };

      makeChart("adminAppointmentStatusChart", JSON.parse(JSON.stringify(statusConfig)));

      makeChart("reportsAppointmentStatusChart", JSON.parse(JSON.stringify(statusConfig)));



      <?php if (!empty($districtChartable)): ?>

        makeChart("forecastChart", {

          type: "bar",

          data: {

            labels: <?= json_encode(array_map(fn($f) => $f["name"] . " — " . $f["center_name"], $districtChartable), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,

            datasets: [{ label: "Estimated days remaining", data: <?= json_encode(array_column($districtChartable, "days_remaining")) ?>, backgroundColor: <?= json_encode(array_map(fn($f) => $forecastColorMap[$f["status"]], $districtChartable)) ?>, borderRadius: 5 }]

          },

          options: { indexAxis: "y", responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { afterLabel: function (context) { const rates = <?= json_encode(array_map(fn($f) => number_format($f["daily_rate"], 1), $districtChartable)) ?>; const units = <?= json_encode(array_column($districtChartable, "unit")) ?>; return "Average use: " + rates[context.dataIndex] + " " + units[context.dataIndex] + "/day"; } } } }, scales: { x: { beginAtZero: true, title: { display: true, text: "Days remaining" } } } }

        });

      <?php endif; ?>



      const apptsByDay = <?= json_encode($apptsByDay) ?>;

      makeChart("apptsChart", {

        type: "bar",

        data: { labels: Object.keys(apptsByDay).map(d => new Date(d + "T00:00:00").toLocaleDateString(undefined, { month: "short", day: "numeric" })), datasets: [{ label: "Appointments", data: Object.values(apptsByDay), backgroundColor: "#146B61", borderRadius: 5 }] },

        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }

      });

      <?php if (!empty($centerApptLabels)): ?>

        makeChart("centerApptChart", { type: "bar", data: { labels: <?= json_encode($centerApptLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, datasets: [{ label: "Appointments", data: <?= json_encode($centerApptValues) ?>, backgroundColor: "#0E4D46", borderRadius: 4 }] }, options: { indexAxis: "y", responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } } });

      <?php endif; ?>

      <?php if (!empty($serviceLabels)): ?>

        makeChart("serviceChart", { type: "bar", data: { labels: <?= json_encode($serviceLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, datasets: [{ label: "Times booked", data: <?= json_encode($serviceValues) ?>, backgroundColor: "#E8A33D", borderRadius: 4 }] }, options: { indexAxis: "y", responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } } } });

      <?php endif; ?>

      <?php if (!empty($lowStockLabels)): ?>

        makeChart("lowStockChart", { type: "bar", data: { labels: <?= json_encode($lowStockLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, datasets: [{ label: "Low-stock medicines", data: <?= json_encode($lowStockValues) ?>, backgroundColor: "#C0392B", borderRadius: 4 }] }, options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } } });

      <?php endif; ?>



      // Generic live search for tables. Does not alter the underlying data or forms.

      document.querySelectorAll("[data-table-search]").forEach(input => {

        input.addEventListener("input", () => {

          const table = document.getElementById(input.dataset.tableSearch);

          if (!table) return;

          const query = input.value.trim().toLowerCase();

          table.querySelectorAll("tbody tr").forEach(row => {

            const emptyRow = row.querySelector("td[colspan]");

            row.hidden = !emptyRow && !row.innerText.toLowerCase().includes(query);

          });

        });

      });

      const centerSearch = document.getElementById("center-search");

      if (centerSearch) centerSearch.addEventListener("input", () => {

        const query = centerSearch.value.trim().toLowerCase();

        document.querySelectorAll(".admin-center-item").forEach(item => { item.hidden = !item.dataset.centerSearch.toLowerCase().includes(query); });

      });

      document.querySelectorAll("form[data-confirm]").forEach(form => form.addEventListener("submit", event => {

        if (!window.confirm(form.dataset.confirm)) event.preventDefault();

      }));

      const statusSelect = document.getElementById("st-status");

      const statusHours = document.getElementById("st-hours");

      if (statusSelect && statusHours) {

        const updateHours = () => { const needsHours = statusSelect.value !== "Closed"; statusHours.disabled = !needsHours; if (!needsHours) statusHours.value = ""; };

        statusSelect.addEventListener("change", updateHours); updateHours();

      }

      // Charts created inside hidden tabs can need a resize after their tab is opened.

      document.querySelectorAll("[data-tab]").forEach(link => link.addEventListener("click", () => {

        window.setTimeout(() => { if (window.Chart) Object.values(Chart.instances).forEach(chart => chart.resize()); }, 120);

      }));



      document.addEventListener("DOMContentLoaded", function () {

        const searchInput = document.getElementById("registeredPatientSearch");



        if (!searchInput) return;



        const table = searchInput.closest(".dash-card")

          ?.querySelector("table");



        if (!table) return;



        const tbody = table.querySelector("tbody");

        const patientRows = Array.from(

          tbody.querySelectorAll(".registered-patient-row")

        );



        // Create a message for searches with no matches.

        const noResultsRow = document.createElement("tr");

        noResultsRow.id = "noPatientSearchResults";

        noResultsRow.style.display = "none";
        const noResultsCell = document.createElement("td");
        noResultsCell.colSpan = 4;
        noResultsCell.textContent = "No matching patients found.";
        noResultsCell.style.textAlign = "center";
        noResultsCell.style.padding = "18px";
        noResultsRow.appendChild(noResultsCell);
        tbody.appendChild(noResultsRow);
        searchInput.addEventListener("input", function () {
          const searchTerm = this.value.trim().toLowerCase();
          let visibleCount = 0;
          patientRows.forEach(function (row) {
            const fullName = row.cells[0]?.textContent.toLowerCase() || "";
            const username = row.cells[1]?.textContent.toLowerCase() || "";
            const email = row.cells[2]?.textContent.toLowerCase() || "";
            const matches =
              fullName.includes(searchTerm) ||
              username.includes(searchTerm) ||
              email.includes(searchTerm);
            row.style.display = matches ? "" : "none";
            if (matches) {
              visibleCount++;
            }
          });
          noResultsRow.style.display =
            visibleCount === 0 && patientRows.length > 0 ? "" : "none";
        });
      });
    })();
  </script>
</body>

</html>