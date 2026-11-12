<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/auth_check.php';

if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admin access is required to view this district-wide report.');
}

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
$month = $_GET['month'] ?? date('Y-m');
if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month))
    $month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
$monthLabel = (new DateTimeImmutable($monthStart))->format('F Y');

$counts = [];
$stmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM appointments WHERE appointment_date >= ? AND appointment_date < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY status ORDER BY total DESC, status ASC");
$stmt->bind_param('ss', $monthStart, $monthEnd);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc())
    $counts[(string) ($row['status'] ?: 'Unknown')] = (int) $row['total'];
$stmt->close();
$total = array_sum($counts);

$centerRows = [];
$stmt = $conn->prepare("SELECT hc.name AS center_name, a.status, COUNT(*) AS total FROM appointments a LEFT JOIN health_centers hc ON hc.id = a.health_center_id WHERE a.appointment_date >= ? AND a.appointment_date < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY hc.id, hc.name, a.status ORDER BY hc.name, total DESC");
$stmt->bind_param('ss', $monthStart, $monthEnd);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc())
    $centerRows[] = $row;
$stmt->close();

$dailyRows = [];
$stmt = $conn->prepare("SELECT appointment_date, COUNT(*) AS total FROM appointments WHERE appointment_date >= ? AND appointment_date < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY appointment_date ORDER BY appointment_date");
$stmt->bind_param('ss', $monthStart, $monthEnd);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc())
    $dailyRows[] = $row;
$stmt->close();

$statusColors = ['Completed' => '#1E7A4C', 'Confirmed' => '#2673B8', 'Pending' => '#E8A33D', 'In consultation' => '#146B61', 'Cancelled' => '#8B93A1', 'Rejected' => '#C0392B', 'No-show' => '#8C63B5'];
$allStatuses = $counts;
ksort($allStatuses);
$centerStatusMap = [];
foreach ($centerRows as $row) {
    $center = (string) ($row['center_name'] ?? 'Unassigned');
    $status = (string) ($row['status'] ?? 'Unknown');
    if (!isset($centerStatusMap[$center]))
        $centerStatusMap[$center] = [];
    $centerStatusMap[$center][$status] = (int) $row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Appointment Status Report — <?= h($monthLabel) ?></title>
    <style>
        :root {
            --navy: #153e63;
            --teal: #146B61;
            --ink: #243447;
            --muted: #64748b;
            --line: #dbe3ea;
            --soft: #f3f7fa
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #edf2f6;
            color: var(--ink);
            font: 14px/1.5 Arial, sans-serif
        }

        .page {
            max-width: 1100px;
            margin: 26px auto;
            padding: 0 16px
        }

        .toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 14px
        }

        .toolbar form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap
        }

        .toolbar input,
        .toolbar button,
        .toolbar a {
            font: inherit;
            padding: 9px 12px;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: #fff;
            text-decoration: none;
            color: var(--ink)
        }

        .toolbar button {
            background: var(--navy);
            color: #fff;
            border-color: var(--navy);
            font-weight: bold;
            cursor: pointer
        }

        .report {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 25px #153e6310
        }

        .head {
            background: linear-gradient(120deg, #153e63, #146B61);
            color: white;
            padding: 28px 32px
        }

        .eyebrow {
            font-size: 11px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            opacity: .85;
            font-weight: bold
        }

        .head h1 {
            font-size: 27px;
            margin: 7px 0
        }

        .head p {
            margin: 0;
            color: #e5f0f4
        }

        .meta {
            display: flex;
            gap: 32px;
            flex-wrap: wrap;
            margin-top: 20px
        }

        .meta small {
            display: block;
            text-transform: uppercase;
            opacity: .75;
            font-size: 10px;
            letter-spacing: .7px
        }

        .meta strong {
            display: block;
            margin-top: 3px
        }

        .content {
            padding: 26px 32px
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 26px
        }

        .stat {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 15px
        }

        .stat span {
            display: block;
            color: var(--muted);
            font-size: 12px
        }

        .stat strong {
            display: block;
            font-size: 25px;
            color: var(--navy);
            margin-top: 5px
        }

        .section-title {
            font-size: 16px;
            color: var(--navy);
            margin: 24px 0 12px
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px
        }

        .status {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px
        }

        .status-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 7px
        }

        .bar {
            height: 7px;
            background: #edf1f4;
            border-radius: 9px;
            overflow: hidden;
            margin-top: 10px
        }

        .bar span {
            display: block;
            height: 100%;
            border-radius: 9px
        }

        .muted {
            color: var(--muted);
            font-size: 12px
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 8px
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th {
            background: #eaf1f6;
            text-align: left;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: .4px;
            color: #284963
        }

        th,
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e8edf1
        }

        tbody tr:nth-child(even) {
            background: #fafcfd
        }

        .right {
            text-align: right
        }

        .footer {
            border-top: 1px solid var(--line);
            margin-top: 24px;
            padding-top: 14px;
            color: var(--muted);
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap
        }

        @media(max-width:650px) {

            .head,
            .content {
                padding: 20px 16px
            }

            .stats,
            .status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            .head h1 {
                font-size: 22px
            }
        }

        .signature-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 50px;
            margin-top: 55px;
        }

        .signature-line {
            padding-top: 8px;
            border-top: 1px solid #6d7974;
            font-size: 12px;
            color: var(--ink-soft, #64736f);
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm
            }

            body {
                background: white;
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

            .head {
                padding: 18px 22px !important;
                background: #153e63 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact
            }

            .head h1 {
                font-size: 21px
            }

            .content {
                padding: 18px 22px
            }

            .stats {
                gap: 7px;
                margin-bottom: 18px
            }

            .stat {
                padding: 10px
            }

            .stat strong {
                font-size: 20px
            }

            .status-grid {
                gap: 7px
            }

            .status {
                padding: 8px
            }

            .bar span {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact
            }

            th {
                background: #eaf1f6 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact
            }

            tr {
                break-inside: avoid
            }

            .section-title {
                margin-top: 18px
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="toolbar"><a href="admin_dashboard.php#tab-reports">← Back to dashboard</a>
            <form method="get"><label for="month">Report month</label><input id="month" type="month" name="month"
                    value="<?= h($month) ?>"><button type="submit">Generate report</button></form><button type="button"
                onclick="window.print()">Print / Save as PDF</button>
        </div>
        <div class="report">
            <header class="head">
                <div class="eyebrow">Malabon District II Health Centers · District Report</div>
                <h1>Monthly Appointment Status Distribution</h1>
                <p>Appointment records grouped by their current status for <?= h($monthLabel) ?>.</p>
                <div class="meta">
                    <div><small>Reporting period</small><strong><?= h(date('F j, Y', strtotime($monthStart))) ?> –
                            <?= h(date('F j, Y', strtotime($monthEnd))) ?></strong></div>
                    <div><small>Appointments recorded</small><strong><?= number_format($total) ?></strong></div>
                    <div><small>Generated on</small><strong><?= h(date('F j, Y g:i A')) ?></strong></div>
                </div>
            </header>
            <div class="content">
                <div class="stats">
                    <div class="stat"><span>Total appointments this
                            month</span><strong><?= number_format($total) ?></strong></div>
                    <div class="stat"><span>Completed
                            appointments</span><strong><?= number_format($counts['Completed'] ?? 0) ?></strong></div>
                    <div class="stat"><span>Pending
                            appointments</span><strong><?= number_format($counts['Pending'] ?? 0) ?></strong></div>
                </div>
                <h2 class="section-title">Status breakdown</h2>
                <?php if ($total > 0): ?>
                    <div class="status-grid">
                        <?php foreach ($allStatuses as $status => $count):
                            $pct = $total > 0 ? ($count / $total) * 100 : 0;
                            $color = $statusColors[$status] ?? '#4f8b83'; ?>
                            <div class="status">
                                <div class="status-top"><span><i class="dot"
                                            style="background:<?= h($color) ?>"></i><?= h($status) ?></span><strong><?= number_format($count) ?></strong>
                                </div>
                                <div class="muted"><?= number_format($pct, 1) ?>% of monthly appointments</div>
                                <div class="bar"><span style="width:<?= min(100, $pct) ?>%;background:<?= h($color) ?>"></span>
                                </div>
                            </div><?php endforeach; ?>
                    </div><?php else: ?>
                    <p class="muted">No appointments were recorded during this month.</p><?php endif; ?>
                <h2 class="section-title">Appointment status by health center</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Health center</th>
                                <th>Status</th>
                                <th class="right">Appointments</th>
                                <th class="right">Share of monthly total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($centerRows):
                                foreach ($centerRows as $row):
                                    $pct = $total > 0 ? ((int) $row['total'] / $total) * 100 : 0; ?>
                                    <tr>
                                        <td><?= h($row['center_name'] ?? 'Unassigned') ?></td>
                                        <td><?= h($row['status'] ?? 'Unknown') ?></td>
                                        <td class="right"><?= number_format((int) $row['total']) ?></td>
                                        <td class="right"><?= number_format($pct, 1) ?>%</td>
                                    </tr><?php endforeach; else: ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;padding:22px">No appointment records for this
                                        period.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <h2 class="section-title">Daily appointment volume</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="right">Appointments</th>
                                <th>Share of monthly total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($dailyRows):
                                foreach ($dailyRows as $row):
                                    $pct = $total > 0 ? ((int) $row['total'] / $total) * 100 : 0; ?>
                                    <tr>
                                        <td><?= h(date('D, M j, Y', strtotime($row['appointment_date']))) ?></td>
                                        <td class="right"><?= number_format((int) $row['total']) ?></td>
                                        <td>
                                            <div class="bar"><span
                                                    style="width:<?= min(100, $pct) ?>%;background:#146B61"></span></div><span
                                                class="muted"><?= number_format($pct, 1) ?>%</span>
                                        </td>
                                    </tr><?php endforeach; else: ?>
                                <tr>
                                    <td colspan="3" style="text-align:center;padding:22px">No daily appointment activity to
                                        show.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="signature-grid">
                    <div class="signature-line">
                        Prepared by: Health Center Staff
                    </div>

                    <div class="signature-line">
                        Reviewed by: Authorized Personnel
                    </div>
                </div>
                <p class="muted" style="margin-top:14px">This report includes all appointment statuses, including
                    cancelled, rejected, and no-show records, so the distribution reflects every appointment recorded
                    during the selected month.</p>
                <div class="footer"><span>Malabon District II Health Centers — Monthly Appointment Status
                        Report</span><span>Generated <?= h(date('Y-m-d H:i')) ?></span></div>
            </div>
        </div>
    </div>
</body>

</html>