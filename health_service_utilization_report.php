<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/auth_check.php';

if (empty($_SESSION['role']) || strtolower((string) $_SESSION['role']) !== 'staff') {
    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($role === 'patient')
        header('Location: dashboard.php');
    elseif ($role === 'admin')
        header('Location: admin_dashboard.php');
    elseif ($role === 'doctor')
        header('Location: doctor_dashboard.php');
    else
        header('Location: login.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function validReportDate($value): bool
{
    if (!is_string($value))
        return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$username = (string) ($_SESSION['username'] ?? '');
$stmt = $conn->prepare('SELECT health_center_id FROM patients WHERE username = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare staff center lookup.');
}
$stmt->bind_param('s', $username);
$stmt->execute();
$staffRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$centerId = (int) ($staffRow['health_center_id'] ?? 0);
if ($centerId <= 0) {
    http_response_code(403);
    exit('Your staff account has no assigned health center. Please contact the administrator.');
}

$startDate = $_GET['report_start'] ?? date('Y-m-d', strtotime('-6 days'));
$endDate = $_GET['report_end'] ?? date('Y-m-d');
if (!validReportDate($startDate))
    $startDate = date('Y-m-d', strtotime('-6 days'));
if (!validReportDate($endDate))
    $endDate = date('Y-m-d');
if ($startDate > $endDate)
    [$startDate, $endDate] = [$endDate, $startDate];

$stmt = $conn->prepare('SELECT name FROM health_centers WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $centerId);
$stmt->execute();
$centerName = (string) ($stmt->get_result()->fetch_assoc()['name'] ?? 'Health Center');
$stmt->close();

// Keep the same utilization rule as the dashboard: count appointments except cancelled/rejected.
$sql = "SELECT COALESCE(NULLIF(TRIM(service), ''), 'Unspecified') AS service_name,
               COUNT(*) AS appointment_count,
               SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
               SUM(CASE WHEN status IN ('Pending', 'Confirmed', 'In consultation') THEN 1 ELSE 0 END) AS active_count
        FROM appointments
        WHERE health_center_id = ?
          AND appointment_date BETWEEN ? AND ?
          AND status NOT IN ('Cancelled', 'Rejected')
        GROUP BY COALESCE(NULLIF(TRIM(service), ''), 'Unspecified')
        ORDER BY appointment_count DESC, service_name ASC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare service utilization report.');
}
$stmt->bind_param('iss', $centerId, $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();
$services = [];
$totalCountedAppointments = 0;
$totalCompleted = 0;
while ($row = $result->fetch_assoc()) {
    $row['appointment_count'] = (int) $row['appointment_count'];
    $row['completed_count'] = (int) $row['completed_count'];
    $row['active_count'] = (int) $row['active_count'];
    $services[] = $row;
    $totalCountedAppointments += $row['appointment_count'];
    $totalCompleted += $row['completed_count'];
}
$stmt->close();
$serviceCount = count($services);
$topService = $services[0] ?? null;
$generatedAt = date('F j, Y g:i A');
$chartServices = array_slice($services, 0, 10);
$chartLabels = array_column($chartServices, 'service_name');
$chartValues = array_map('intval', array_column($chartServices, 'appointment_count'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Service Utilization Report | <?= h($centerName) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
            gap: 9px;
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

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 28px
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
            margin-top: 7px;
            overflow-wrap: anywhere
        }

        .section-title {
            font-size: 16px;
            color: var(--navy);
            margin: 26px 0 6px
        }

        .section-note {
            font-size: 12px;
            color: var(--muted);
            margin: 0 0 14px
        }

        .chart-wrap {
            height: 370px;
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 18px
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 8px
        }

        table {
            border-collapse: collapse;
            width: 100%;
            min-width: 650px
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

        .rank {
            font-weight: 700;
            color: var(--navy)
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

        .method {
            background: var(--soft);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px 16px;
            color: #4f6071;
            font-size: 12px
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

            .report-meta {
                gap: 16px
            }

            .chart-wrap {
                height: 330px
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

            .chart-wrap {
                height: 270px;
                break-inside: avoid
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
                margin-top: 18px
            }

            .footer {
                margin-top: 14px
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="toolbar"><button class="print" onclick="window.print()">Print / Save as PDF</button><a class="back"
                href="staff_dashboard.php?tab=reports">Back to Reports</a></div>
        <main class="report">
            <header class="report-head">
                <div class="eyebrow">Malabon District II Health Centers</div>
                <h1>Most Utilized Health Services Report</h1>
                <p><?= h($centerName) ?></p>
                <div class="report-meta">
                    <div>
                        <div class="meta-label">Reporting period</div>
                        <div class="meta-value"><?= h(date('M j, Y', strtotime($startDate))) ?> –
                            <?= h(date('M j, Y', strtotime($endDate))) ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Generated</div>
                        <div class="meta-value"><?= h($generatedAt) ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Measurement</div>
                        <div class="meta-value">Appointment count by service</div>
                    </div>
                </div>
            </header>
            <div class="content">
                <section class="stats">
                    <div class="stat">
                        <div class="stat-label">Appointments counted</div>
                        <div class="stat-value"><?= number_format($totalCountedAppointments) ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Distinct services</div>
                        <div class="stat-value"><?= number_format($serviceCount) ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Completed consultations</div>
                        <div class="stat-value"><?= number_format($totalCompleted) ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Most utilized service</div>
                        <div class="stat-value" style="font-size:19px">
                            <?= $topService ? h($topService['service_name']) : 'None' ?></div>
                    </div>
                </section>
                <h2 class="section-title">Service utilization ranking</h2>
                <p class="section-note">Top 10 services by appointment count, ordered from highest to lowest.</p>
                <div class="chart-wrap"><?php if ($chartServices): ?><canvas id="serviceUtilizationChart"
                            aria-label="Health service utilization chart"></canvas><?php else: ?>
                        <div class="empty">No service appointments were found for this reporting period.</div>
                    <?php endif; ?>
                </div>
                <h2 class="section-title">Service details</h2>
                <p class="section-note">Cancelled and rejected appointments are excluded to match the dashboard's
                    utilization chart.</p>
                <div class="table-wrap">
                    <table id="serviceTable">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Health service</th>
                                <th>Appointments counted</th>
                                <th>Completed</th>
                                <th>Pending / confirmed / in consultation</th>
                                <th>Share of appointments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($services):
                                foreach ($services as $index => $service): ?>
                                    <tr>
                                        <td class="rank"><?= $index + 1 ?></td>
                                        <td><?= h($service['service_name']) ?></td>
                                        <td><?= number_format($service['appointment_count']) ?></td>
                                        <td><?= number_format($service['completed_count']) ?></td>
                                        <td><?= number_format($service['active_count']) ?></td>
                                        <td><?= $totalCountedAppointments > 0 ? number_format(($service['appointment_count'] / $totalCountedAppointments) * 100, 1) . '%' : '0.0%' ?>
                                        </td>
                                    </tr><?php endforeach; else: ?>
                                <tr>
                                    <td colspan="6" class="empty">No service appointments were found for this reporting
                                        period.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <h2 class="section-title">How to interpret this report</h2>
                <div class="method">This report ranks health services using the number of appointments in the selected
                    date range. Cancelled and rejected appointments are excluded. The counts describe recorded
                    appointment activity, not necessarily unique patients or confirmed completed visits. Use the
                    completed column to distinguish completed consultations from bookings still in progress.</div>
                <div class="footer"><span>Malabon District II Health Centers · Health Service
                        Utilization</span><span>Generated <?= h($generatedAt) ?></span></div>
            </div>
        </main>
    </div>
    <script>
        const serviceRows = <?= json_encode($services, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const labels = <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const values = <?= json_encode($chartValues) ?>;
        <?php if ($chartServices): ?>
            new Chart(document.getElementById('serviceUtilizationChart'), { type: 'bar', data: { labels: labels, datasets: [{ label: 'Appointments counted', data: values, backgroundColor: '#0E4D46', borderRadius: 5 }] }, options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ` ${ctx.raw} appointments` } } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Appointment count' } }, y: { ticks: { autoSkip: false } } } } });
        <?php endif; ?>
        function downloadServiceCsv() {
            const header = ['Rank', 'Health service', 'Appointments counted', 'Completed', 'Pending / confirmed / in consultation', 'Share of appointments'];
            const total = serviceRows.reduce((sum, r) => sum + Number(r.appointment_count || 0), 0);
            const rows = [header, ...serviceRows.map((r, i) => [i + 1, r.service_name, r.appointment_count, r.completed_count, r.active_count, total ? ((Number(r.appointment_count) / total) * 100).toFixed(1) + '%' : '0.0%'])];
            const csv = rows.map(row => row.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'health_service_utilization_<?= h($startDate) ?>_to_<?= h($endDate) ?>.csv'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
        }
    </script>
</body>

</html>