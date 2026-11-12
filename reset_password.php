<?php
include "connection.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);
    $code = trim($_POST["code"]);
    $new_password = $_POST["new_password"];
    $confirm_password = $_POST["confirm_password"];

    if ($new_password !== $confirm_password) {
        echo "<script>alert('Passwords do not match.'); window.location.href='homepage.php?view=forgot';</script>";
        exit;
    }
    if (strlen($new_password) < 8) {
        echo "<script>alert('Password must be at least 8 characters.'); window.location.href='homepage.php?view=forgot';</script>";
        exit;
    }
    if (!preg_match('/[0-9]/', $new_password)) {
        echo "<script>alert('Password must contain at least one number.'); window.location.href='homepage.php?view=forgot';</script>";
        exit;
    }
    if (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
        echo "<script>alert('Password must contain at least one special character.'); window.location.href='homepage.php?view=forgot';</script>";
        exit;
    }

    $sql = "SELECT * FROM password_resets WHERE email = ? AND code = ? AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo "<script>alert('That code is invalid or has expired. Please request a new one.'); window.location.href='homepage.php?view=forgot';</script>";
        exit;
    }
    $stmt->close();

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $updateStmt->bind_param("ss", $hashed, $email);
    $updateStmt->execute();
    $updateStmt->close();

    $cleanupStmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $cleanupStmt->bind_param("s", $email);
    $cleanupStmt->execute();
    $cleanupStmt->close();
    $conn->close();

    echo "<script>alert('Password reset successfully. Please log in with your new password.'); window.location.href='homepage.php?view=signin';</script>";
}
?>