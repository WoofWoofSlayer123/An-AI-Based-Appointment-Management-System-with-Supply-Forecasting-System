<?php
// Shared booking rules. The dashboard uses these to show hours to the patient,
// and process_appointment.php uses them to enforce the same rules on the server.

const SLOT_WINDOW_MINUTES = 120;  // capacity is counted per 2-hour window
const ONLINE_BOOKING_CAP  = 8;    // online bookings allowed per window
const WINDOW_TOTAL_CAP    = 10;   // 8 online + up to 2 walk-ins
const INACTIVE_STATUSES_SQL = "'Cancelled','Rejected','No-show','Completed'"; // these free up a slot

function hm_to_minutes($h, $m, $meridiem = "") {
    $h = (int) $h; $m = (int) $m;
    if ($meridiem) {
        $h = $h % 12;
        if (strtoupper($meridiem) === "PM") $h += 12;
    }
    return $h * 60 + $m;
}

// health_centers.operating_hours is free text (e.g. "Mon-Fri, 8:00 AM - 5:00 PM").
// Pull the first time range out of it. Falls back to 8:00 AM - 5:00 PM if it can't be read.
function parse_operating_hours($text) {
    $default = ["open" => 8 * 60, "close" => 17 * 60, "parsed" => false];
    if (!$text) return $default;

    $re = '/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?\s*(?:-|–|—|to)\s*(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?/i';
    if (!preg_match($re, $text, $m)) return $default;

    $m1 = $m[3] ?? "";
    $m2 = $m[6] ?? "";
    $close = hm_to_minutes($m[4], $m[5] ?? 0, $m2);
    $open  = hm_to_minutes($m[1], $m[2] ?? 0, $m1 ?: $m2);
    if (!$m1 && $m2 && $open >= $close) {
        $open = hm_to_minutes($m[1], $m[2] ?? 0, "AM"); // "8 - 5 PM" means 8 AM
    }
    if ($open >= $close) return $default;
    return ["open" => $open, "close" => $close, "parsed" => true];
}

// The 2-hour window (counted from opening time) that a given time falls into.
function slot_window($timeMinutes, $openMinutes) {
    $start = $openMinutes + intdiv($timeMinutes - $openMinutes, SLOT_WINDOW_MINUTES) * SLOT_WINDOW_MINUTES;
    return [$start, $start + SLOT_WINDOW_MINUTES];
}

function minutes_label($m) {
    return date("g:i A", strtotime(sprintf("%02d:%02d", intdiv($m, 60), $m % 60)));
}
