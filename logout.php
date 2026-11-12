<?php
session_start();
include "connection.php";

// Also revoke the "keep me signed in" token, if one exists, so a stolen
// cookie can't keep working after the user logs out.
if (!empty($_COOKIE["remember_token"])) {
    $parts = explode(":", $_COOKIE["remember_token"], 2);
    if (count($parts) === 2) {
        [$user_id, $rawToken] = $parts;
        $tokenHash = hash("sha256", $rawToken);
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND token_hash = ?");
        $stmt->bind_param("is", $user_id, $tokenHash);
        $stmt->execute();
        $stmt->close();
    }
    setcookie("remember_token", "", time() - 3600, "/");
}

$_SESSION = [];
session_destroy();

header("Location: homepage.php?view=signin");
exit;
?>
