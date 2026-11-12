<?php
// Include this at the very top of any protected page (dashboard.php,
// admin_dashboard.php, staff_dashboard.php) BEFORE any HTML output.
//
// Usage:
//   session_start();
//   include "connection.php";
//   include "auth_check.php";
//
// If there's no active session but a valid "remember me" cookie exists,
// this restores the session. If neither is present, it redirects to login.

// Tell the browser never to cache this page. Without this, clicking "back"
// after logout can show a stale copy straight from the browser's cache
// (bfcache) without ever re-checking the session on the server.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"]) && !empty($_COOKIE["remember_token"])) {
    $parts = explode(":", $_COOKIE["remember_token"], 2);
    if (count($parts) === 2) {
        [$cookie_user_id, $rawToken] = $parts;
        $tokenHash = hash("sha256", $rawToken);

        $sql = "SELECT * FROM remember_tokens WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $cookie_user_id, $tokenHash);
        $stmt->execute();
        $tokenResult = $stmt->get_result();

        if ($tokenResult && $tokenResult->num_rows > 0) {
            $userSql = "SELECT users.*, patients.full_name, patients.patient_id,
                               patients.health_center_id, patients.pending_staff
                        FROM users
                        LEFT JOIN patients ON patients.username = users.username
                        WHERE users.id = ?";
            $userStmt = $conn->prepare($userSql);
            $userStmt->bind_param("i", $cookie_user_id);
            $userStmt->execute();
            $userResult = $userStmt->get_result();

            if ($userResult && $userResult->num_rows > 0) {
                $row = $userResult->fetch_assoc();
                $_SESSION["username"] = $row["username"];
                $_SESSION["user_id"] = $row["id"];
                $_SESSION["role"] = $row["role"];
                $_SESSION["full_name"] = $row["full_name"] ?: $row["username"];
                $_SESSION["patient_id"] = $row["patient_id"];
                $_SESSION["health_center_id"] = $row["health_center_id"];
                $_SESSION["pending_staff"] = (bool) $row["pending_staff"];
            }
            $userStmt->close();
        }
        $stmt->close();
    }
}

// Still no session after checking the cookie? Not logged in — send them back.
if (!isset($_SESSION["user_id"])) {
    header("Location: homepage.php?view=signin");
    exit;
}
?>