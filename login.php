<?php
session_start();
include "connection.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifier = trim($_POST["identifier"]);
    $password = $_POST["password"];
    $remember = isset($_POST["remember"]);

    // Figure out how to look the user up: Patient ID, email, or username.
    if (preg_match('/^PT-\d+$/i', $identifier)) {
        $sql = "SELECT users.*, patients.full_name, patients.patient_id,
                       patients.mobile, patients.health_center_id, patients.pending_staff
                FROM users
                JOIN patients ON patients.username = users.username
                WHERE patients.patient_id = ?";
    } elseif (strpos($identifier, "@") !== false) {
        $sql = "SELECT users.*, patients.full_name, patients.patient_id,
                       patients.mobile, patients.health_center_id, patients.pending_staff
                FROM users
                LEFT JOIN patients ON patients.username = users.username
                WHERE users.email = ?";
    } else {
        $sql = "SELECT users.*, patients.full_name, patients.patient_id,
                       patients.mobile, patients.health_center_id, patients.pending_staff
                FROM users
                LEFT JOIN patients ON patients.username = users.username
                WHERE users.username = ?";
    }

    $identifierParam = strtoupper($identifier) === $identifier ? strtoupper($identifier) : $identifier;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();

        if (password_verify($password, $row["password"])) {
            // Set session values
            $_SESSION["username"] = $row["username"];
            $_SESSION["user_id"] = $row["id"];
            $_SESSION["role"] = $row["role"];
            $_SESSION["full_name"] = $row["full_name"] ?: $row["username"];
            $_SESSION["patient_id"] = $row["patient_id"];
            $_SESSION["health_center_id"] = $row["health_center_id"];
            $_SESSION["pending_staff"] = (bool) $row["pending_staff"];

            // "Keep me signed in" — issue a long-lived cookie holding a random
            // token; only its hash is stored in the database, never the raw token.
            if ($remember) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash("sha256", $rawToken);
                $expiresAt = date("Y-m-d H:i:s", strtotime("+30 days"));

                $tokenStmt = $conn->prepare(
                    "INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)"
                );
                $tokenStmt->bind_param("iss", $row["id"], $tokenHash, $expiresAt);
                $tokenStmt->execute();
                $tokenStmt->close();

                setcookie(
                    "remember_token",
                    $row["id"] . ":" . $rawToken,
                    [
                        "expires" => strtotime("+30 days"),
                        "path" => "/",
                        "httponly" => true,
                        "samesite" => "Lax",
                        // "secure" => true, // enable once served over HTTPS
                    ]
                );
            }

            // Redirect based on role
            if ($row["role"] === "admin") {
                header("Location: admin_dashboard.php");
            } elseif ($row["role"] === "patient") {
                header("Location: patient_dashboard.php");
            } else {
                // staff or doctor
                header("Location: staff_dashboard.php");
            }
            exit;
        } else {
            echo "<script>alert('Incorrect password.'); window.location.href='homepage.php?view=signin';</script>";
        }
    } else {
        echo "<script>alert('No account found with that username, email, or Patient ID.'); window.location.href='homepage.php?view=signin';</script>";
    }

    $stmt->close();
    $conn->close();
}
?>