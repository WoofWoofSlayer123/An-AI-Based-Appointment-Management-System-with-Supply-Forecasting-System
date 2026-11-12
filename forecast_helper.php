<?php
// Include wherever a supply forecast is needed. Pass a health_center_id for
// a center-scoped view (staff), or omit it for district-wide (admin).
//
// Returns an array of medicines, each with:
//   id, name, unit, stock_quantity, reorder_threshold, center_name,
//   daily_rate (float or null), days_remaining (int or null),
//   status ('critical' | 'soon' | 'fine' | 'insufficient')
//
// Model: linear regression (least squares) fitted over the last 14 days of
// daily prescription totals, projected forward to today's trend level —
// not a flat average. A flat 30-day average was tested against a simulated
// flu-spike pattern and underreacted to it (diluted a real spike across
// three weeks of quiet baseline); a 14-day regression tracked it far more
// closely while staying more stable than a bare 7-day average. Needs at
// least 3 prescription events in the 14-day window to produce a number —
// fewer than that, and a precise-looking forecast would just be noise.

/**
 * Calculate the projected daily medicine consumption using
 * least-squares linear regression over the supplied daily values.
 */
if (!function_exists('fit_trend_rate')) {
    function fit_trend_rate($values)
    {
        $n = count($values);

        if ($n === 0) {
            return 0;
        }

        $flatRate = array_sum($values) / $n;

        $sumX = $sumY = $sumXY = $sumX2 = 0;

        foreach ($values as $x => $y) {
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);

        if ($denominator == 0) {
            return $flatRate;
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $n;
        $projected = ($slope * ($n - 1)) + $intercept;

        // Prevent a steep downward trend from reducing consumption to zero.
        return max($projected, $flatRate * 0.25);
    }
}
function get_medicine_forecast($conn, $centerId = null) {
    $windowDays = 14;

    // Medicines + event count in the window (for the insufficient-data check)
    $sql = "
        SELECT medicines.id, medicines.name, medicines.unit, medicines.stock_quantity,
               medicines.reorder_threshold, health_centers.name AS center_name,
               COALESCE(p.event_count, 0) AS event_count
        FROM medicines
        JOIN health_centers ON health_centers.id = medicines.health_center_id
        LEFT JOIN (
            SELECT medicine_id, COUNT(*) AS event_count
            FROM prescriptions
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($windowDays - 1) . " DAY)
            GROUP BY medicine_id
        ) p ON p.medicine_id = medicines.id
    ";
    if ($centerId) $sql .= " WHERE medicines.health_center_id = ? ";
    $sql .= " ORDER BY medicines.name";

    $stmt = $conn->prepare($sql);
    if ($centerId) $stmt->bind_param("i", $centerId);
    $stmt->execute();
    $medicineRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($medicineRows)) return [];

    $medicineIds = array_column($medicineRows, "id");

    // Daily consumption per medicine, for the regression fit. Days with no
    // prescriptions simply won't appear here — filled in as 0 below.
    $placeholders = implode(",", array_fill(0, count($medicineIds), "?"));
    $types = str_repeat("i", count($medicineIds));
    $dailySql = "
        SELECT medicine_id, DATE(created_at) AS day, SUM(quantity) AS qty
        FROM prescriptions
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($windowDays - 1) . " DAY)
              AND medicine_id IN ($placeholders)
        GROUP BY medicine_id, DATE(created_at)
    ";
    $stmt = $conn->prepare($dailySql);
    $stmt->bind_param($types, ...$medicineIds);
    $stmt->execute();
    $dailyRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Build a zero-filled 14-day series per medicine: index 0 = 13 days ago, 13 = today.
    $series = [];
    foreach ($medicineIds as $id) $series[$id] = array_fill(0, $windowDays, 0);
    foreach ($dailyRows as $row) {
        $dayOffset = (int) ((strtotime(date("Y-m-d")) - strtotime($row["day"])) / 86400);
        $index = $windowDays - 1 - $dayOffset;
        if (isset($series[$row["medicine_id"]][$index])) {
            $series[$row["medicine_id"]][$index] = (int) $row["qty"];
        }
    }

    // Least-squares linear regression: y = slope*x + intercept, over x = 0..13.

    $forecast = [];
    foreach ($medicineRows as $row) {
        $eventCount = (int) $row["event_count"];

        if ($eventCount < 3) {
            $dailyRate = null;
            $daysRemaining = null;
            $status = "insufficient";
        } else {
            $dailyRate = fit_trend_rate($series[$row["id"]]);
            $daysRemaining = $dailyRate > 0 ? (int) floor($row["stock_quantity"] / $dailyRate) : null;
            if ($daysRemaining === null) {
                $status = "insufficient";
            } elseif ($daysRemaining < 7) {
                $status = "critical";
            } elseif ($daysRemaining < 14) {
                $status = "soon";
            } else {
                $status = "fine";
            }
        }

        $forecast[] = [
            "id" => $row["id"],
            "name" => $row["name"],
            "unit" => $row["unit"],
            "stock_quantity" => (int) $row["stock_quantity"],
            "reorder_threshold" => (int) $row["reorder_threshold"],
            "center_name" => $row["center_name"],
            "daily_rate" => $dailyRate,
            "days_remaining" => $daysRemaining,
            "status" => $status,
        ];
    }

    // Sort so what's about to run out is always at the top. Insufficient-data
    // rows sort last, since there's nothing urgent to say about them yet.
    usort($forecast, function ($a, $b) {
        if ($a["days_remaining"] === null && $b["days_remaining"] === null) return 0;
        if ($a["days_remaining"] === null) return 1;
        if ($b["days_remaining"] === null) return -1;
        return $a["days_remaining"] <=> $b["days_remaining"];
    });

    return $forecast;
}
?>
