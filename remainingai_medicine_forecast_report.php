<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/forecast_helper.php';

if (empty($_SESSION['role']) || strtolower((string) $_SESSION['role']) !== 'admin') {
    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($role === 'patient') {
        header('Location: dashboard.php');
    } elseif ($role === 'doctor') {
        header('Location: doctor_dashboard.php');
    } elseif ($role === 'staff') {
        header('Location: staff_dashboard.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// Reuse the same forecasting helper as the Supply Forecast dashboard, without a
// center ID so the report covers all health centers in the district.
$forecast = get_medicine_forecast($conn);
if (!is_array($forecast))
    $forecast = [];
$counts = ['critical' => 0, 'soon' => 0, 'fine' => 0, 'insufficient' => 0];
$chartItems = [];
foreach ($forecast as $item) {
    $status = (string) ($item['status'] ?? 'insufficient');
    if (!array_key_exists($status, $counts))
        $status = 'insufficient';
    $counts[$status]++;
    if (isset($item['days_remaining']) && $item['days_remaining'] !== null && is_numeric($item['days_remaining'])) {
        $chartItems[] = [
            'name' => (string) ($item['name'] ?? 'Unnamed medicine'),
            'center_name' => (string) ($item['center_name'] ?? 'Unknown center'),
            'label' => (string) ($item['name'] ?? 'Unnamed medicine') . ' — ' . (string) ($item['center_name'] ?? 'Unknown center'),
            'stock_quantity' => (float) ($item['stock_quantity'] ?? 0),
            'unit' => (string) ($item['unit'] ?? ''),
            'daily_rate' => (float) $item['daily_rate'],
            'days_remaining' => max(0, (float) $item['days_remaining']),
            'status' => $status,
        ];
    }
}
usort($chartItems, static fn($a, $b) => $a['days_remaining'] <=> $b['days_remaining']);
$generatedAt = date('F j, Y g:i A');
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function forecastStatusLabel(string $status): string
{
    return match ($status) { 'critical' => 'Critical', 'soon' => 'Running low', 'fine' => 'Sufficient', default => 'Insufficient data'};
}
function forecastStatusClass(string $status): string
{
    return match ($status) { 'critical' => 'critical', 'soon' => 'soon', 'fine' => 'fine', default => 'insufficient'};
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projected Days of Stock Remaining — District Report</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --ink: #19312e;
            --muted: #64736f;
            --line: #dce5e2;
            --paper: #fff;
            --wash: #f3f7f5;
            --teal: #0b5c53;
            --red: #b42318;
            --amber: #a15c00;
            --green: #187347
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #eef3f1;
            color: var(--ink);
            font-family: Inter, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5
        }

        .page {
            max-width: 1180px;
            margin: 28px auto;
            padding: 0 18px 30px
        }

        .report {
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 8px 28px #173c3210
        }

        .topline {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
            border-bottom: 2px solid var(--teal);
            padding-bottom: 20px
        }

        .brand {
            font-size: 12px;
            letter-spacing: .12em;
            text-transform: uppercase;
            font-weight: 800;
            color: var(--teal)
        }

        h1 {
            font-size: 28px;
            line-height: 1.2;
            margin: 7px 0
        }

        .subtitle {
            color: var(--muted);
            margin: 0
        }

        .meta {
            text-align: right;
            color: var(--muted);
            font-size: 12px
        }

        .actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            margin: 18px 0 22px
        }

        .btn {
            display: inline-block;
            text-decoration: none;
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer
        }

        .btn-primary {
            background: var(--teal);
            color: #fff
        }

        .btn-secondary {
            background: #eaf1ef;
            color: var(--ink)
        }

        .section-title {
            font-size: 17px;
            margin: 28px 0 5px
        }

        .section-note {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 12px
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin: 20px 0
        }

        .stat {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            background: #fff
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted)
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            margin-top: 5px
        }

        .stat.critical .stat-value {
            color: var(--red)
        }

        .stat.soon .stat-value {
            color: var(--amber)
        }

        .stat.fine .stat-value {
            color: var(--green)
        }

        .chart-box {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 18px;
            height: 390px
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 10px
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px
        }

        th,
        td {
            text-align: left;
            padding: 11px 12px;
            border-bottom: 1px solid var(--line);
            vertical-align: top
        }

        th {
            background: var(--wash);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #52645f
        }

        tbody tr:last-child td {
            border-bottom: 0
        }

        .pill {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap
        }

        .pill.critical {
            background: #fde9e7;
            color: var(--red)
        }

        .pill.soon {
            background: #fff0d8;
            color: var(--amber)
        }

        .pill.fine {
            background: #e4f5eb;
            color: var(--green)
        }

        .pill.insufficient {
            background: #edf1f0;
            color: #596965
        }

        .number {
            font-variant-numeric: tabular-nums;
            white-space: nowrap
        }

        .method {
            background: var(--wash);
            border: 1px solid var(--line);
            padding: 15px 17px;
            border-radius: 10px;
            color: #425550
        }

        .empty {
            padding: 22px;
            text-align: center;
            color: var(--muted)
        }

        .footer {
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            font-size: 11px;
            color: var(--muted);
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap
        }

        @media(max-width:720px) {
            .page {
                margin: 10px auto;
                padding: 0 8px
            }

            .report {
                padding: 18px
            }

            .topline {
                display: block
            }

            .meta {
                text-align: left;
                margin-top: 12px
            }

            .summary {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            h1 {
                font-size: 23px
            }

            .chart-box {
                height: 340px
            }
        }

        @media print {
            @page {
                size: A4 landscape;
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

            .report {
                border: 0;
                border-radius: 0;
                padding: 0;
                box-shadow: none
            }

            .actions {
                display: none
            }

            .summary {
                gap: 7px
            }

            .stat {
                padding: 10px
            }

            .stat-value {
                font-size: 21px
            }

            .chart-box {
                height: 270px;
                break-inside: avoid
            }

            .section-title {
                margin-top: 18px
            }

            th,
            td {
                padding: 7px 8px
            }

            .table-wrap {
                overflow: visible
            }

            table {
                min-width: 0;
                font-size: 9px
            }

            .method {
                break-inside: avoid
            }

            .footer {
                margin-top: 12px
            }
        }
    </style>

</head>

<body>
    <div class="page">
        <main class="report">
            <header class="topline">
                <div>
                    <div class="brand">Malabon District II Health Centers</div>
                    <h1>Projected Days of Stock Remaining</h1>
                    <p class="subtitle">District-wide medicine supply forecast — all health centers</p>
                </div>
                <div class="meta"><strong>Generated</strong><br><?= h($generatedAt) ?><br><span>Live forecast from
                        current inventory and prescription history</span></div>
            </header>
            <div class="actions"><button class="btn btn-primary" onclick="window.print()">Print / Save as
                    PDF</button><a class="btn btn-secondary" href="admin_dashboard.php">Back to Admin Dashboard</a>
            </div>
            <section class="summary">
                <div class="stat critical">
                    <div class="stat-label">Critical forecast</div>
                    <div class="stat-value"><?= (int) $counts['critical'] ?></div>
                    <div class="stat-label">Estimated to run out in fewer than 7 days</div>
                </div>
                <div class="stat soon">
                    <div class="stat-label">Running low</div>
                    <div class="stat-value"><?= (int) $counts['soon'] ?></div>
                    <div class="stat-label">Estimated to run out in 7 to 13 days</div>
                </div>
                <div class="stat fine">
                    <div class="stat-label">Sufficient supply</div>
                    <div class="stat-value"><?= (int) $counts['fine'] ?></div>
                    <div class="stat-label">Forecast indicates more time remaining</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Insufficient data</div>
                    <div class="stat-value"><?= (int) $counts['insufficient'] ?></div>
                    <div class="stat-label">No reliable days-remaining estimate</div>
                </div>
            </section>
            <h2 class="section-title">Estimated days of supply remaining</h2>
            <p class="section-note">Medicines with numeric estimates are ordered from the shortest projected time
                remaining to the longest. Each entry is specific to a health center.</p>
            <div class="chart-box"><?php if ($chartItems): ?><canvas id="forecastChart"
                        aria-label="Projected days of stock remaining by medicine and center"></canvas><?php else: ?>
                    <div class="empty">No medicines currently have enough data for a days-remaining chart.</div>
                <?php endif; ?>
            </div>
            <h2 class="section-title">District forecast details</h2>
            <p class="section-note">The same district-wide forecast helper used by the Supply Forecast tab provides
                these values.</p>
            <div class="table-wrap">
                <table id="forecastTable">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Health center</th>
                            <th>Current stock</th>
                            <th>Estimated daily usage</th>
                            <th>Projected days remaining</th>
                            <th>Forecast status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($forecast):
                            foreach ($forecast as $item):
                                $status = (string) ($item['status'] ?? 'insufficient');
                                if (!isset($counts[$status]))
                                    $status = 'insufficient'; ?>
                                <tr>
                                    <td><?= h($item['name'] ?? 'Unnamed medicine') ?></td>
                                    <td><?= h($item['center_name'] ?? 'Unknown center') ?></td>
                                    <td class="number"><?= number_format((float) ($item['stock_quantity'] ?? 0)) ?>
                                        <?= h($item['unit'] ?? '') ?></td>
                                    <td class="number">
                                        <?= isset($item['daily_rate']) && $item['daily_rate'] !== null && is_numeric($item['daily_rate']) ? number_format((float) $item['daily_rate'], 2) . ' ' . h($item['unit'] ?? '') . '/day' : 'Unavailable' ?>
                                    </td>
                                    <td class="number">
                                        <?= isset($item['days_remaining']) && $item['days_remaining'] !== null && is_numeric($item['days_remaining']) ? number_format((float) $item['days_remaining'], 1) . ' days' : 'Unavailable' ?>
                                    </td>
                                    <td><span
                                            class="pill <?= h(forecastStatusClass($status)) ?>"><?= h(forecastStatusLabel($status)) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td colspan="6" class="empty">No medicine records are available for forecasting across the
                                    district.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <h2 class="section-title">Forecasting method and interpretation</h2>
            <div class="method">
                <p><strong>Model:</strong> linear regression applied to recent daily prescription totals over a 14-day
                    history window, using the system's forecasting helper.</p>
                <p><strong>Estimated daily usage:</strong> the model's estimated daily prescription rate.
                    <strong>Projected days remaining:</strong> current stock divided by the estimated usage rate when a
                    usable estimate is available.</p>
                <p><strong>Interpretation:</strong> Critical indicates fewer than 7 estimated days remaining; Running
                    low indicates 7 to 13 days; Sufficient indicates a longer estimated supply; Insufficient data means
                    a reliable numeric estimate is unavailable. Forecasts are decision-support estimates, not
                    guarantees. Staff should consider upcoming demand, expiry dates, deliveries, and actual stock counts
                    before ordering or dispensing.</p>
            </div>
            <footer class="footer"><span>Confidential operational report for authorized district
                    administrators.</span><span>Generated from current system data at <?= h($generatedAt) ?></span>
            </footer>
        </main>
    </div>
    <script>
        const forecastRows = <?= json_encode($chartItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        if (forecastRows.length && document.getElementById('forecastChart')) {
            const statusColors = { critical: '#C0392B', soon: '#E8A33D', fine: '#1E7A4C', insufficient: '#64748B' };
            new Chart(document.getElementById('forecastChart'), {
                type: 'bar',
                data: { labels: forecastRows.map(x => x.label), datasets: [{ label: 'Projected days remaining', data: forecastRows.map(x => x.days_remaining), backgroundColor: forecastRows.map(x => statusColors[x.status] || statusColors.insufficient), borderRadius: 4 }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ` ${Number(ctx.raw).toFixed(1)} estimated day(s) remaining` } } }, scales: { x: { beginAtZero: true, title: { display: true, text: 'Projected days remaining' } }, y: { ticks: { autoSkip: false } } } }
            });
        }
        function downloadForecastCsv() {
            const rows = [['Projected Days of Stock Remaining — District Report'], ['Scope', 'All health centers, Malabon District II'], ['Generated', <?= json_encode($generatedAt) ?>], [], ['Medicine', 'Health center', 'Current stock', 'Unit', 'Estimated daily usage', 'Projected days remaining', 'Forecast status']];
            <?php foreach ($forecast as $item):
                $st = (string) ($item['status'] ?? 'insufficient');
                if (!isset($counts[$st]))
                    $st = 'insufficient'; ?>
                rows.push([<?= json_encode((string) ($item['name'] ?? 'Unnamed medicine'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= json_encode((string) ($item['center_name'] ?? 'Unknown center'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= json_encode($item['stock_quantity'] ?? 0) ?>, <?= json_encode((string) ($item['unit'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, <?= json_encode($item['daily_rate'] ?? null) ?>, <?= json_encode($item['days_remaining'] ?? null) ?>, <?= json_encode(forecastStatusLabel($st)) ?>]);
            <?php endforeach; ?>
            const csv = rows.map(row => row.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
            const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'projected_days_of_stock_remaining_report.csv'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
        }
    </script>
</body>

</html>