<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/auth_check.php';

$role = strtolower((string)($_SESSION['role'] ?? ''));
if ($role !== 'admin') {
    if ($role === 'patient') {
        header('Location: dashboard.php');
    } elseif ($role === 'doctor') {
        header('Location: doctor_dashboard.php');
    } else {
        header('Location: staff_dashboard.php');
    }
    exit;
}

function service_report_escape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Match the dashboard's definition: exclude cancelled and rejected appointments.
$sql = "SELECT
            COALESCE(NULLIF(TRIM(service), ''), 'Unspecified service') AS service_name,
            COUNT(*) AS booking_count
        FROM appointments
        WHERE LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'rejected')
        GROUP BY COALESCE(NULLIF(TRIM(service), ''), 'Unspecified service')
        ORDER BY booking_count DESC, service_name ASC";
$result = $conn->query($sql);
if (!$result) {
    error_log('Most utilized services report query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to generate the service utilization report right now. Please check the server error log.');
}

$services = [];
$totalBookings = 0;
while ($row = $result->fetch_assoc()) {
    $row['booking_count'] = (int)$row['booking_count'];
    $services[] = $row;
    $totalBookings += $row['booking_count'];
}
$serviceCount = count($services);
$topService = $services[0] ?? null;
$generatedAt = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Most Utilized Services — District-Wide Report</title>
<style>
:root{--navy:#153e63;--teal:#146b61;--gold:#e8a33d;--ink:#172b3a;--muted:#64748b;--line:#dce5eb;--pale:#f3f7fa}
*{box-sizing:border-box}body{margin:0;background:#edf2f6;color:var(--ink);font:14px/1.5 Arial,Helvetica,sans-serif}.page{max-width:1100px;margin:26px auto;padding:0 18px}.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}.toolbar a,.toolbar button{border:0;border-radius:7px;padding:10px 14px;font-weight:700;text-decoration:none;cursor:pointer;font-size:13px}.toolbar a{background:white;color:var(--navy);border:1px solid var(--line)}.toolbar button{background:var(--teal);color:white}.report{background:white;border:1px solid var(--line);border-radius:13px;overflow:hidden;box-shadow:0 8px 26px #1e293b0d}.head{background:var(--navy);color:white;padding:30px 34px}.eyebrow{text-transform:uppercase;letter-spacing:1.2px;font-size:11px;font-weight:700;opacity:.85}.head h1{font-size:28px;line-height:1.2;margin:9px 0}.head p{margin:0;color:#e0eaf2}.meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:24px}.meta div{border-top:1px solid #ffffff55;padding-top:10px}.meta small{display:block;color:#d5e2ed;margin-bottom:4px}.meta strong{font-size:14px}.content{padding:26px 34px 30px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:26px}.stat{border:1px solid var(--line);border-radius:9px;padding:16px;background:#fff}.stat span{display:block;color:var(--muted);font-size:12px;margin-bottom:7px}.stat strong{display:block;font-size:25px;line-height:1.15}.stat .sub{font-size:11px;color:var(--muted);margin-top:6px}.section-title{font-size:16px;margin:24px 0 12px}.note{background:var(--pale);border-left:4px solid var(--gold);padding:12px 14px;color:#405466;border-radius:4px;margin:0 0 16px;font-size:12px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:12px}th{background:#eaf1f6;text-align:left;color:#334e62;padding:11px 10px;border-bottom:1px solid var(--line)}td{padding:11px 10px;border-bottom:1px solid #e7edf2}tbody tr:nth-child(even){background:#fafcfd}.num{text-align:right;font-variant-numeric:tabular-nums}.rank{font-weight:700;color:var(--navy);width:60px}.bar-track{height:9px;background:#e9eef2;border-radius:99px;overflow:hidden;min-width:100px}.bar{height:100%;background:var(--gold);border-radius:99px}.bar-cell{width:24%}.empty{text-align:center;padding:28px;color:var(--muted)}.footer{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:14px;margin-top:24px;color:var(--muted);font-size:11px}
@media(max-width:650px){.head,.content{padding:22px 18px}.head h1{font-size:23px}.meta,.stats{grid-template-columns:1fr}.page{margin:12px auto}}
@media print{@page{size:A4 portrait;margin:12mm}body{background:white;font-size:10px}.page{max-width:none;margin:0;padding:0}.toolbar{display:none!important}.report{border:0;border-radius:0;box-shadow:none}.head{padding:18px 22px!important;background:#153e63!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.head h1{font-size:21px}.content{padding:18px 22px}.stats{gap:7px;margin-bottom:18px}.stat{padding:10px}.stat strong{font-size:20px}.section-title{margin-top:18px}th{background:#eaf1f6!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}tr{break-inside:avoid}.bar{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div class="page">
  <div class="toolbar">
    <a href="admin_dashboard.php#tab-reports">← Back to admin reports</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
  </div>
  <main class="report">
    <header class="head">
      <div class="eyebrow">Malabon District II Health Centers · District Report</div>
      <h1>Most Utilized Services</h1>
      <p>All-time appointment bookings across all health centers in the district.</p>
      <div class="meta">
        <div><small>Reporting period</small><strong>All recorded dates</strong></div>
        <div><small>Countable appointments</small><strong><?= number_format($totalBookings) ?></strong></div>
        <div><small>Generated on</small><strong><?= service_report_escape($generatedAt) ?></strong></div>
      </div>
    </header>
    <section class="content">
      <div class="stats">
        <div class="stat"><span>Total countable bookings</span><strong><?= number_format($totalBookings) ?></strong><div class="sub">Cancelled and rejected appointments excluded</div></div>
        <div class="stat"><span>Services recorded</span><strong><?= number_format($serviceCount) ?></strong><div class="sub">Distinct service labels in appointment records</div></div>
        <div class="stat"><span>Most utilized service</span><strong style="font-size:19px"><?= $topService ? service_report_escape($topService['service_name']) : '—' ?></strong><div class="sub"><?= $topService ? number_format($topService['booking_count']) . ' bookings' : 'No appointment records' ?></div></div>
      </div>
      <h2 class="section-title">Service utilization ranking</h2>
      <p class="note">Bookings are counted by the service recorded on each appointment. This measures appointment volume, not unique patients or completed consultations. Cancelled and rejected appointments are excluded, consistent with the district dashboard chart.</p>
      <?php if ($services): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Rank</th><th>Service</th><th class="num">Bookings</th><th class="num">Share</th><th class="bar-cell">Relative volume</th></tr></thead>
        <tbody>
        <?php foreach ($services as $index => $service): $pct = $totalBookings > 0 ? ($service['booking_count'] / $totalBookings) * 100 : 0; $barPct = $topService && $topService['booking_count'] > 0 ? ($service['booking_count'] / $topService['booking_count']) * 100 : 0; ?>
          <tr>
            <td class="rank">#<?= $index + 1 ?></td>
            <td><?= service_report_escape($service['service_name']) ?></td>
            <td class="num"><?= number_format($service['booking_count']) ?></td>
            <td class="num"><?= number_format($pct, 1) ?>%</td>
            <td class="bar-cell"><div class="bar-track"><div class="bar" style="width:<?= number_format($barPct, 2, '.', '') ?>%"></div></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty">No appointment records are available for service utilization yet.</div><?php endif; ?>
      <div class="footer"><span>Malabon District II Health Centers · Most Utilized Services</span><span>Generated <?= service_report_escape($generatedAt) ?></span></div>
    </section>
  </main>
</div>
</body>
</html>
