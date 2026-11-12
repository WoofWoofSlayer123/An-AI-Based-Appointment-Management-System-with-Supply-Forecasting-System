<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/auth_check.php';

if (empty($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
  http_response_code(403);
  exit('Access denied.');
}
$username = (string) ($_SESSION['username'] ?? '');
$stmt = $conn->prepare('SELECT health_center_id FROM patients WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$staffRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$centerId = (int) ($staffRow['health_center_id'] ?? 0);
if ($centerId <= 0) {
  http_response_code(403);
  exit('Your account has no assigned health center.');
}
function reportDateIsValid($value): bool
{
  if (!is_string($value))
    return false;
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return $date !== false && $date->format('Y-m-d') === $value;
}
$startDate = $_GET['report_start'] ?? date('Y-m-d', strtotime('-6 days'));
$endDate = $_GET['report_end'] ?? date('Y-m-d');
if (!reportDateIsValid($startDate))
  $startDate = date('Y-m-d', strtotime('-6 days'));
if (!reportDateIsValid($endDate))
  $endDate = date('Y-m-d');
if ($startDate > $endDate)
  [$startDate, $endDate] = [$endDate, $startDate];

$stmt = $conn->prepare('SELECT name FROM health_centers WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $centerId);
$stmt->execute();
$centerName = (string) ($stmt->get_result()->fetch_assoc()['name'] ?? 'Health Center');
$stmt->close();

$sql = "SELECT a.id, a.appointment_date, a.appointment_time, p.full_name,
               a.username AS patient_username, a.service, a.status, a.assigned_doctor
        FROM appointments a
        LEFT JOIN patients p ON p.username = a.username
        WHERE a.health_center_id = ? AND a.appointment_date BETWEEN ? AND ?
        ORDER BY a.appointment_date ASC, a.appointment_time ASC, a.id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iss', $centerId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$statusCounts = ['Pending' => 0, 'Confirmed' => 0, 'In consultation' => 0, 'Completed' => 0, 'Cancelled' => 0, 'Rejected' => 0, 'No-show' => 0];
while ($row = $result->fetch_assoc()) {
  $rows[] = $row;
  $status = (string) ($row['status'] ?? 'Unknown');
  if (!isset($statusCounts[$status]))
    $statusCounts[$status] = 0;
  $statusCounts[$status]++;
}
$stmt->close();
$totalAppointments = count($rows);
$completedCount = $statusCounts['Completed'] ?? 0;
$pendingCount = $statusCounts['Pending'] ?? 0;
$cancelledCount = ($statusCounts['Cancelled'] ?? 0) + ($statusCounts['Rejected'] ?? 0);
function h($value): string
{
  return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function displayTime($value): string
{
  return !empty($value) ? date('g:i A', strtotime((string) $value)) : 'Not specified';
}
$csvUrl = 'export_appointments_csv.php?report_start=' . rawurlencode($startDate) . '&report_end=' . rawurlencode($endDate);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Appointment Activity Report | <?= h($centerName) ?></title>
  <style>
    :root {
      --navy: #153e63;
      --blue: #1d5f91;
      --teal: #168579;
      --ink: #233447;
      --muted: #68788a;
      --line: #dce4eb;
      --soft: #f3f7fa;
      --white: #fff
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      background: #edf2f6;
      color: var(--ink);
      font: 14px/1.5 Arial, Helvetica, sans-serif
    }

    .page {
      max-width: 1180px;
      margin: 30px auto;
      padding: 0 18px
    }

    .toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 16px
    }

    .toolbar a,
    .toolbar button {
      border: 0;
      border-radius: 7px;
      padding: 11px 15px;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      font-size: 13px
    }

    .back {
      background: #fff;
      color: var(--navy);
      border: 1px solid var(--line) !important
    }

    .csv {
      background: var(--teal);
      color: #fff
    }

    .print {
      background: var(--navy);
      color: #fff
    }

    .report {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 8px 28px #1836500d
    }

    .report-head {
      padding: 30px 34px;
      background: linear-gradient(120deg, #153e63, #1c6483);
      color: #fff
    }

    .eyebrow {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      opacity: .85
    }

    .report-head h1 {
      margin: 8px 0 4px;
      font-size: 27px;
      line-height: 1.2
    }

    .report-head p {
      margin: 0;
      color: #e1edf5
    }

    .report-meta {
      display: flex;
      gap: 30px;
      flex-wrap: wrap;
      margin-top: 23px
    }

    .meta-label {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .7px;
      opacity: .75
    }

    .meta-value {
      font-size: 14px;
      font-weight: 700;
      margin-top: 3px
    }

    .content {
      padding: 28px 34px 34px
    }

    .section-title {
      font-size: 16px;
      color: var(--navy);
      margin: 0 0 14px
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 30px
    }

    .stat {
      border: 1px solid var(--line);
      border-radius: 9px;
      padding: 17px;
      background: #fff
    }

    .stat-label {
      font-size: 12px;
      color: var(--muted)
    }

    .stat-value {
      font-size: 27px;
      line-height: 1.2;
      font-weight: 700;
      color: var(--navy);
      margin-top: 7px
    }

    .status-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 30px
    }

    .status-item {
      background: var(--soft);
      border-radius: 7px;
      padding: 12px 14px;
      display: flex;
      justify-content: space-between;
      gap: 8px
    }

    .status-item span {
      color: #4f6071
    }

    .status-item strong {
      color: var(--navy)
    }

    .table-wrap {
      overflow-x: auto;
      border: 1px solid var(--line);
      border-radius: 8px
    }

    table {
      border-collapse: collapse;
      width: 100%;
      min-width: 850px
    }

    thead th {
      background: #eaf1f6;
      color: #284963;
      text-align: left;
      font-size: 11px;
      letter-spacing: .4px;
      text-transform: uppercase;
      padding: 12px 13px;
      white-space: nowrap
    }

    tbody td {
      padding: 12px 13px;
      border-top: 1px solid #e8edf1;
      vertical-align: top
    }

    tbody tr:nth-child(even) {
      background: #fafcfd
    }

    .pill {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 20px;
      background: #eaf0f5;
      color: #435b70;
      font-size: 11px;
      font-weight: 700;
      white-space: nowrap
    }

    .pill-completed {
      background: #dff4e8;
      color: #17633b
    }

    .pill-pending {
      background: #fff1d8;
      color: #855500
    }

    .pill-cancelled,
    .pill-rejected,
    .pill-no-show {
      background: #fde7e7;
      color: #9a3030
    }

    .empty {
      text-align: center;
      padding: 28px;
      color: var(--muted)
    }

    .footer {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      border-top: 1px solid var(--line);
      margin-top: 24px;
      padding-top: 16px;
      color: var(--muted);
      font-size: 11px
    }

    .nowrap {
      white-space: nowrap
    }

    @media(max-width:760px) {
      .page {
        margin: 14px auto;
        padding: 0 10px
      }

      .report-head,
      .content {
        padding: 22px 18px
      }

      .report-head h1 {
        font-size: 23px
      }

      .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr))
      }

      .status-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr))
      }

      .report-meta {
        gap: 16px
      }
    }

    @media print {
      @page {
        size: landscape;
        margin: 12mm
      }

      body {
        background: #fff;
        font-size: 10px
      }

      .page {
        max-width: none;
        margin: 0;
        padding: 0
      }

      .toolbar {
        display: none !important
      }

      .report {
        border: 0;
        border-radius: 0;
        box-shadow: none
      }

      .report-head {
        background: #153e63 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        padding: 20px 24px
      }

      .report-head h1 {
        font-size: 21px
      }

      .content {
        padding: 18px 24px
      }

      .stats {
        gap: 7px;
        margin-bottom: 18px
      }

      .stat {
        padding: 10px
      }

      .stat-value {
        font-size: 20px
      }

      .status-grid {
        gap: 6px;
        margin-bottom: 18px
      }

      .status-item {
        padding: 7px 9px
      }

      table {
        min-width: 0;
        font-size: 9px
      }

      thead {
        display: table-header-group
      }

      tr {
        break-inside: avoid
      }

      thead th,
      tbody td {
        padding: 7px 8px
      }

      .table-wrap {
        overflow: visible
      }

      .section-title {
        margin-bottom: 8px
      }

      .footer {
        margin-top: 14px
      }
    }
  </style>
</head>

<body>
  <div class="page">
    <div class="toolbar">
      <a class="back"
        href="staff_dashboard.php?tab=reports&amp;report_start=<?= h($startDate) ?>&amp;report_end=<?= h($endDate) ?>">←
        Back to Reports</a>
      <div style="display:flex;gap:9px;flex-wrap:wrap">
        <a class="csv" href="<?= h($csvUrl) ?>">Download CSV</a>
        <button class="print" type="button" onclick="window.print()">Print / Save as PDF</button>
      </div>
    </div>
    <main class="report">
      <header class="report-head">
        <div class="eyebrow">Malabon District II Health Centers</div>
        <h1>Appointment Activity Report</h1>
        <p>Appointment volume and status summary for the selected reporting period.</p>
        <div class="report-meta">
          <div>
            <div class="meta-label">Health center</div>
            <div class="meta-value"><?= h($centerName) ?></div>
          </div>
          <div>
            <div class="meta-label">Report period</div>
            <div class="meta-value"><?= h(date('M j, Y', strtotime($startDate))) ?> –
              <?= h(date('M j, Y', strtotime($endDate))) ?></div>
          </div>
          <div>
            <div class="meta-label">Generated</div>
            <div class="meta-value"><?= h(date('M j, Y, g:i A')) ?></div>
          </div>
        </div>
      </header>
      <div class="content">
        <h2 class="section-title">Report summary</h2>
        <div class="stats">
          <div class="stat">
            <div class="stat-label">Total appointments</div>
            <div class="stat-value"><?= number_format($totalAppointments) ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Completed consultations</div>
            <div class="stat-value"><?= number_format($completedCount) ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Pending appointments</div>
            <div class="stat-value"><?= number_format($pendingCount) ?></div>
          </div>
          <div class="stat">
            <div class="stat-label">Cancelled / rejected</div>
            <div class="stat-value"><?= number_format($cancelledCount) ?></div>
          </div>
        </div>
        <h2 class="section-title">Appointment status breakdown</h2>
        <div class="status-grid">
          <?php foreach ($statusCounts as $status => $count): ?>
            <div class="status-item"><span><?= h($status) ?></span><strong><?= number_format($count) ?></strong></div>
          <?php endforeach; ?>
        </div>
        <h2 class="section-title">Appointment details</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Date</th>
                <th>Time</th>
                <th>Patient</th>
                <th>Patient username</th>
                <th>Service</th>
                <th>Status</th>
                <th>Assigned doctor</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="8" class="empty">No appointments were found for this health center during the selected
                    period.</td>
                </tr>
              <?php else:
                foreach ($rows as $row):
                  $statusClass = strtolower(str_replace(' ', '-', (string) ($row['status'] ?? 'unknown'))); ?>
                  <tr>
                    <td class="nowrap">#<?= h($row['id']) ?></td>
                    <td class="nowrap"><?= h(date('M j, Y', strtotime((string) $row['appointment_date']))) ?></td>
                    <td class="nowrap"><?= h(displayTime($row['appointment_time'] ?? null)) ?></td>
                    <td><?= h($row['full_name'] ?: 'Unknown patient') ?></td>
                    <td><?= h($row['patient_username']) ?></td>
                    <td><?= h($row['service'] ?: 'Not specified') ?></td>
                    <td><span class="pill pill-<?= h($statusClass) ?>"><?= h($row['status']) ?></span></td>
                    <td><?= h($row['assigned_doctor'] ?: 'Not assigned') ?></td>
                  </tr>
                <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="footer"><span>Confidential administrative report. Use only for authorized health center
            operations.</span><span><?= number_format($totalAppointments) ?> appointment record(s) · Report period:
            <?= h($startDate) ?> to <?= h($endDate) ?></span></div>
      </div>
    </main>
  </div>
</body>

</html>