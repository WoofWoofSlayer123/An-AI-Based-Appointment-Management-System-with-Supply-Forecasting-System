
<?php
session_start();

require_once "connection.php";
require_once "auth_check.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$username = $_SESSION["username"] ?? null;
$role = $_SESSION["role"] ?? "";

// Redirect according to the logged-in user's role.
function redirectWithMessage(
    string $tab,
    string $type,
    string $message
): void {
    $role = $_SESSION["role"] ?? "";

    $dashboard = match ($role) {
        "doctor" => "doctor_dashboard.php",
        "staff"  => "staff_dashboard.php",
        "admin"  => "admin_dashboard.php",
        default  => "patient_dashboard.php"
    };

    header(
        "Location: " . $dashboard .
        "?tab=" . urlencode($tab) .
        "&type=" . urlencode($type) .
        "&msg=" . urlencode($message)
    );
    exit;
}

if (!is_string($username) || trim($username) === "") {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirectWithMessage("account", "error", "Invalid request method.");
}

// CSRF validation: the dashboard must generate this token before rendering.
$sessionToken = $_SESSION["csrf_token"] ?? "";
$submittedToken = $_POST["csrf_token"] ?? "";

if (
    !is_string($sessionToken) ||
    $sessionToken === "" ||
    !is_string($submittedToken) ||
    !hash_equals($sessionToken, $submittedToken)
) {
    redirectWithMessage(
        "account",
        "error",
        "Session validation failed. Refresh the page and try again."
    );
}

$action = $_POST["action"] ?? "";

if (!is_string($action)) {
    redirectWithMessage("account", "error", "Invalid request.");
}

try {
    /*
     * CONTACT DETAILS
     * Updates users.email and patients.mobile.
     */
    if ($action === "contact") {
        $email = $_POST["email"] ?? "";
        $mobile = $_POST["mobile"] ?? "";

        if (!is_string($email) || !is_string($mobile)) {
            redirectWithMessage("account", "error", "Invalid contact details.");
        }

        $email = trim($email);
        $mobile = trim($mobile);

        if (
            $email === "" ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 254
        ) {
            redirectWithMessage(
                "account",
                "error",
                "Please enter a valid email address."
            );
        }

        if (
            $mobile !== "" &&
            !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)
        ) {
            redirectWithMessage(
                "account",
                "error",
                "Please enter a valid mobile number."
            );
        }

        // Check that the email is not used by another username.
        $stmt = $conn->prepare(
            "SELECT username FROM users
             WHERE email = ? AND username <> ?
             LIMIT 1"
        );
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $stmt->store_result();

        $emailExists = $stmt->num_rows > 0;
        $stmt->close();

        if ($emailExists) {
            redirectWithMessage(
                "account",
                "error",
                "That email is already in use by another account."
            );
        }

        $conn->begin_transaction();

        try {
            // Make sure the account exists.
            $stmt = $conn->prepare(
                "SELECT username FROM users WHERE username = ? LIMIT 1"
            );
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();

            $userExists = $stmt->num_rows > 0;
            $stmt->close();

            if (!$userExists) {
                throw new RuntimeException("User account was not found.");
            }

            $stmt = $conn->prepare(
                "UPDATE users SET email = ? WHERE username = ?"
            );
            $stmt->bind_param("ss", $email, $username);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "UPDATE patients SET mobile = ? WHERE username = ?"
            );
            $stmt->bind_param("ss", $mobile, $username);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        redirectWithMessage(
            "account",
            "success",
            "Contact details updated successfully."
        );
    }

    /*
     * CHANGE PASSWORD
     * Requires password_hash() to have been used for stored passwords.
     */
    if ($action === "password") {
        $current = $_POST["current_password"] ?? "";
        $newPass = $_POST["new_password"] ?? "";
        $confirmPass = $_POST["confirm_password"] ?? "";

        if (
            !is_string($current) ||
            !is_string($newPass) ||
            !is_string($confirmPass) ||
            $current === "" ||
            $newPass === "" ||
            $confirmPass === ""
        ) {
            redirectWithMessage(
                "account",
                "error",
                "Please complete all password fields."
            );
        }

        if (!hash_equals($newPass, $confirmPass)) {
            redirectWithMessage(
                "account",
                "error",
                "The new passwords do not match."
            );
        }

        if (strlen($newPass) < 8) {
            redirectWithMessage(
                "account",
                "error",
                "The new password must be at least 8 characters."
            );
        }

        if (strlen($newPass) > 4096) {
            redirectWithMessage(
                "account",
                "error",
                "The new password is too long."
            );
        }

        if (!preg_match('/[0-9]/', $newPass)) {
            redirectWithMessage(
                "account",
                "error",
                "The new password must contain at least one number."
            );
        }

        if (!preg_match('/[^A-Za-z0-9]/', $newPass)) {
            redirectWithMessage(
                "account",
                "error",
                "The new password must contain at least one special character."
            );
        }

        $stmt = $conn->prepare(
            "SELECT password FROM users
             WHERE username = ? LIMIT 1"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($storedHash);

        $userFound = $stmt->fetch();
        $stmt->close();

        if (
            !$userFound ||
            !is_string($storedHash) ||
            $storedHash === ""
        ) {
            redirectWithMessage(
                "account",
                "error",
                "Unable to verify your account."
            );
        }

        if (!password_verify($current, $storedHash)) {
            redirectWithMessage(
                "account",
                "error",
                "Your current password is incorrect."
            );
        }

        if (password_verify($newPass, $storedHash)) {
            redirectWithMessage(
                "account",
                "error",
                "Your new password must differ from your current password."
            );
        }

        $hashed = password_hash($newPass, PASSWORD_DEFAULT);

        if ($hashed === false) {
            redirectWithMessage(
                "account",
                "error",
                "Unable to update your password. Please try again."
            );
        }

        $stmt = $conn->prepare(
            "UPDATE users SET password = ? WHERE username = ?"
        );
        $stmt->bind_param("ss", $hashed, $username);
        $stmt->execute();

        $updated = $stmt->affected_rows;
        $stmt->close();

        // Zero affected rows can mean the stored hash was unchanged.
        // The password has already been checked as different above.
        if ($updated === 0) {
            $stmt = $conn->prepare(
                "SELECT password FROM users WHERE username = ? LIMIT 1"
            );
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($checkHash);
            $stmt->fetch();
            $stmt->close();

            if (!is_string($checkHash) || !password_verify($newPass, $checkHash)) {
                redirectWithMessage(
                    "account",
                    "error",
                    "The password could not be saved."
                );
            }
        }

        // Rotate the token after changing the password.
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

        redirectWithMessage(
            "account",
            "success",
            "Password updated successfully."
        );
    }

    /*
     * PATIENT PROFILE
     */
    if ($action === "profile") {
        if ($role !== "patient") {
            redirectWithMessage(
                "account",
                "error",
                "You are not authorized to update this profile."
            );
        }

        $dob = $_POST["date_of_birth"] ?? "";
        $gender = $_POST["gender"] ?? "";
        $address = $_POST["address"] ?? "";
        $ecName = $_POST["emergency_contact_name"] ?? "";
        $ecNumber = $_POST["emergency_contact_number"] ?? "";

        foreach ([$dob, $gender, $address, $ecName, $ecNumber] as $value) {
            if (!is_string($value)) {
                redirectWithMessage("profile", "error", "Invalid profile data.");
            }
        }

        $dob = trim($dob);
        $gender = trim($gender);
        $address = trim($address);
        $ecName = trim($ecName);
        $ecNumber = trim($ecNumber);

        if (
            $dob === "" ||
            $gender === "" ||
            $address === "" ||
            $ecName === "" ||
            $ecNumber === ""
        ) {
            redirectWithMessage(
                "profile",
                "error",
                "Please complete all required profile fields."
            );
        }

        $date = DateTimeImmutable::createFromFormat("!Y-m-d", $dob);
        $dateErrors = DateTimeImmutable::getLastErrors();

        $validDate = $date !== false
            && $date->format("Y-m-d") === $dob
            && (
                $dateErrors === false ||
                (
                    $dateErrors["warning_count"] === 0 &&
                    $dateErrors["error_count"] === 0
                )
            );

        if (!$validDate || $dob > date("Y-m-d")) {
            redirectWithMessage(
                "profile",
                "error",
                "Please enter a valid date of birth."
            );
        }

        if (!in_array($gender, ["Male", "Female", "Other"], true)) {
            redirectWithMessage("profile", "error", "Please select a valid gender.");
        }

        if (
            strlen($address) > 255 ||
            strlen($ecName) > 150 ||
            !preg_match('/^[0-9+\-\s()]{7,20}$/', $ecNumber)
        ) {
            redirectWithMessage(
                "profile",
                "error",
                "Please check the address and emergency contact fields."
            );
        }

        $stmt = $conn->prepare(
            "UPDATE patients
             SET date_of_birth = ?, gender = ?, address = ?,
                 emergency_contact_name = ?, emergency_contact_number = ?
             WHERE username = ?"
        );
        $stmt->bind_param(
            "ssssss",
            $dob,
            $gender,
            $address,
            $ecName,
            $ecNumber,
            $username
        );
        $stmt->execute();
        $stmt->close();

        redirectWithMessage(
            "profile",
            "success",
            "Profile updated successfully."
        );
    }

    redirectWithMessage("account", "error", "Invalid account update action.");

} catch (Throwable $e) {
    error_log(
        "update_account.php error for user " .
        $username . ": " . $e->getMessage()
    );

    redirectWithMessage(
        $action === "profile" ? "profile" : "account",
        "error",
        "An error occurred while saving your changes. Check the server error log."
    );
}
