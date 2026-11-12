<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/auth_check.php';

if (!isset($_SESSION['role']) || strtolower((string)$_SESSION['role']) !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrator access is required to view this report.');
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$selectedCenter = isset($_GET['center']) && $_GET['center'] !== '' ? (int)$_GET['center'] : 0;
$centers = [];
$centerResult = $conn->query("SELECT id, name FROM health_centers ORDER BY name ASC");
if ($centerResult) {
    while ($center = $centerResult->fetch_assoc()) $centers[] = $center;
}

$summarySql = "SELECT hc.id, hc.name,
    COUNT(m.id) AS medicine_count,
    COALESCE(SUM(CASE WHEN m.id IS NOT NULL THEN GREATEST(m.reorder_threshold - m.stock_quantity, 0) ELSE 0 END), 0) AS units_below_threshold
    FROM health_centers hc
    LEFT JOIN medicines m ON m.health_center_id = hc.id AND m.stock_quantity <= m.reorder_threshold";
if ($selectedCenter > 0) $summarySql .= " WHERE hc.id = ?";
$summarySql .= " GROUP BY hc.id, hc.name ORDER BY medicine_count DESC, hc.name ASC";
$stmt = $conn->prepare($summarySql);
if ($selectedCenter > 0) $stmt->bind_param('i', $selectedCenter);
$stmt->execute();
$summaryResult = $stmt->get_result();
$summary = [];
while ($row = $summaryResult->fetch_assoc()) $summary[] = $row;
$stmt->close();

$detailSql = "SELECT hc.name AS center_name, m.name AS medicine_name, m.stock_quantity, m.reorder_threshold, m.unit,
    m.expiration_date
    FROM medicines m JOIN health_centers hc ON hc.id = m.health_center_id
    WHERE m.stock_quantity <= m.reorder_threshold";
if ($selectedCenter > 0) $detailSql .= " AND hc.id = ?";
$detailSql .= " ORDER BY hc.name ASC, (m.stock_quantity = 0) DESC, (m.stock_quantity - m.reorder_threshold) ASC, m.name ASC";
$stmt = $conn->prepare($detailSql);
if ($selectedCenter > 0) $stmt->bind_param('i', $selectedCenter);
$stmt->execute();
$detailResult = $stmt->get_result();
$details = [];
while ($row = $detailResult->fetch_assoc()) $details[] = $row;
$stmt->close();
$totalLow = count($details);
$totalCenters = 0;
foreach ($summary as $s) if ((int)$s['medicine_count'] > 0) $totalCenters++;
$generatedAt = date('F j, Y g:i A');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Low-stock Medicines by Center</title>
<style>
:root{--ink:#183334;--muted:#637675;--teal:#0b6662;--line:#dce7e4;--pale:#f2f8f6;--warn:#fff1d7;--danger:#ffe5e3}
*{box-sizing:border-box}body{margin:0;background:#f4f7f6;color:var(--ink);font:14px/1.5 Arial,sans-serif}.wrap{max-width:1180px;margin:28px auto;padding:0 18px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}.eyebrow{color:var(--teal);font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:11px}h1{margin:4px 0 5px;font-size:28px}.muted{color:var(--muted)}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid var(--line);border-radius:8px;padding:10px 13px;background:#fff;color:var(--ink);text-decoration:none;cursor:pointer;font-weight:700}.primary{background:var(--teal);border-color:var(--teal);color:white}.filters,.card{background:white;border:1px solid var(--line);border-radius:12px;padding:17px;margin-top:18px}.filters form{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.filters label{display:block;font-weight:700;margin-bottom:5px}.filters select{min-width:230px;padding:10px;border:1px solid #cbdad6;border-radius:7px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:16px}.stat{background:white;border:1px solid var(--line);border-radius:12px;padding:17px}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{display:block;font-size:27px;margin-top:5px}.card h2{font-size:17px;margin:0 0 12px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:11px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:var(--pale)}tr:last-child td{border-bottom:0}.pill{display:inline-block;border-radius:20px;padding:4px 8px;font-size:11px;font-weight:700;background:var(--warn)}.zero{background:var(--danger);color:#9c2b25}.empty{text-align:center;padding:24px;color:var(--muted)}.table-wrap{overflow-x:auto}.small{font-size:12px;color:var(--muted)}
@media(max-width:700px){.stats{grid-template-columns:1fr}.wrap{margin:16px auto;padding:0 10px}h1{font-size:23px}th,td{padding:9px 7px}}
@media print{body{background:white}.wrap{max-width:none;margin:0;padding:0}.filters,.actions,.no-print{display:none!important}.card,.stat{break-inside:avoid;border-color:#bbb}.stats{grid-template-columns:repeat(3,1fr)}th{background:#eee!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head>
<body><main class="wrap">
<div class="top"><div><div class="eyebrow">District inventory report</div><h1>Low-stock Medicines by Center</h1><div class="muted">Medicines whose current stock is at or below their reorder threshold.</div><div class="small">Generated <?=h($generatedAt)?></div></div><div class="actions"><a class="btn" href="admin_dashboard.php">← Admin dashboard</a><button class="btn primary" onclick="window.print()">Print / Save PDF</button></div></div>
<section class="filters no-print"><form method="get"><div><label for="center">Health center</label><select id="center" name="center"><option value="">All health centers</option><?php foreach($centers as $c): ?><option value="<?= (int)$c['id']?>" <?= $selectedCenter===(int)$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></div><button class="btn primary" type="submit">Apply filter</button><a class="btn" href="low_stock_medicines_by_center_report.php">Reset</a></form></section>
<section class="stats"><div class="stat"><span>Low-stock medicine records</span><strong><?=number_format($totalLow)?></strong></div><div class="stat"><span>Centers with low-stock items</span><strong><?=number_format($totalCenters)?></strong></div><div class="stat"><span>Centers in report</span><strong><?=number_format(count($summary))?></strong></div></section>
<section class="card"><h2>Summary by health center</h2><div class="table-wrap"><table><thead><tr><th>Health center</th><th>Low-stock medicines</th><th>Quantity below threshold*</th></tr></thead><tbody><?php if(!$summary): ?><tr><td colspan="3" class="empty">No health centers found.</td></tr><?php else: foreach($summary as $s): ?><tr><td><?=h($s['name'])?></td><td><strong><?=number_format((int)$s['medicine_count'])?></strong></td><td><?=number_format((int)$s['units_below_threshold'])?></td></tr><?php endforeach; endif;?></tbody></table></div><p class="small">*Calculated as the sum of (reorder threshold − stock quantity) for low-stock items; this is an indicator, not a recommended purchase quantity.</p></section>
<section class="card"><h2>Low-stock medicine details</h2><div class="table-wrap"><table><thead><tr><th>Health center</th><th>Medicine</th><th>Current stock</th><th>Reorder threshold</th><th>Shortage to threshold</th><th>Expiration date</th><th>Status</th></tr></thead><tbody><?php if(!$details): ?><tr><td colspan="7" class="empty">No low-stock medicines match this filter.</td></tr><?php else: foreach($details as $m): $qty=(int)$m['stock_quantity']; $threshold=(int)$m['reorder_threshold']; ?><tr><td><?=h($m['center_name'])?></td><td><strong><?=h($m['medicine_name'])?></strong></td><td><?=number_format($qty)?> <?=h($m['unit']??'')?></td><td><?=number_format($threshold)?> <?=h($m['unit']??'')?></td><td><?=number_format(max(0,$threshold-$qty))?> <?=h($m['unit']??'')?></td><td><?=!empty($m['expiration_date'])?h($m['expiration_date']):'—'?></td><td><span class="pill <?=$qty<=0?'zero':''?>"><?=$qty<=0?'Out of stock':'Low stock'?></span></td></tr><?php endforeach; endif;?></tbody></table></div></section>
</main></body></html>
