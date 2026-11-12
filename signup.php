<?php
include "connection.php";

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    $username = $_POST["username"];
    $email = $_POST["email"];
    $full_name = $_POST["full_name"];
    $mobile = $_POST["mobile"];
    $password_input = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];
    $password = password_hash($password_input, PASSWORD_DEFAULT);

    // Every account is 'patient' by default — never taken from user input.
    $role = "patient";

    // Staff/doctor claim — does NOT change role. It only gets logged for an
    // admin to review later.
    $is_staff = isset($_POST["is_staff"]) ? 1 : 0;
    $staff_position = $is_staff ? $_POST["position"] : null;
    $staff_claimed_center_id = $is_staff && !empty($_POST["center_id"]) ? $_POST["center_id"] : null;
    $staff_employee_id = $is_staff ? $_POST["employee_id"] : null;

    if ($password_input !== $confirm_password) {
        echo "<script>alert('Passwords do not match.'); window.location.href='homepage.php?view=register';</script>";
        exit;
    }
    if (strlen($password_input) < 8) {
        echo "<script>alert('Password must be at least 8 characters.'); window.location.href='homepage.php?view=register';</script>";
        exit;
    }
    if (!preg_match('/[0-9]/', $password_input)) {
        echo "<script>alert('Password must contain at least one number.'); window.location.href='homepage.php?view=register';</script>";
        exit;
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password_input)) {
        echo "<script>alert('Password must contain at least one special character.'); window.location.href='homepage.php?view=register';</script>";
        exit;
    }

    // Check username/email aren't already taken
    $checkSql = "SELECT id FROM users WHERE username = ? OR email = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ss", $username, $email);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        echo "<script>alert('Username or email is already taken.'); window.location.href='homepage.php?view=register';</script>";
        exit;
    }
    $checkStmt->close();

    $sql = "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Prepare Failed: " . $conn->error);
    }

    $stmt->bind_param("ssss", $username, $email, $password, $role);

    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        $stmt->close();

        // Patient ID is generated from the real inserted user ID, so it's
        // guaranteed unique and sequential — e.g. PT-00001, PT-00002 ...
        $patient_id = "PT-" . str_pad($user_id, 5, "0", STR_PAD_LEFT);

        $sql2 = "INSERT INTO patients
                    (username, full_name, patient_id, mobile, health_center_id,
                     pending_staff, staff_position, staff_claimed_center_id, staff_employee_id)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param(
            "ssssisss",
            $username,
            $full_name,
            $patient_id,
            $mobile,
            $is_staff,
            $staff_position,
            $staff_claimed_center_id,
            $staff_employee_id
        );
        $stmt2->execute();
        $stmt2->close();

        $staffNote = $is_staff
            ? '<p class="info-message">Your staff/doctor request has been submitted. An admin will review it and assign your role and health center — until then, your account works as a regular patient account.</p>'
            : '';

        echo '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>Account Created — Malabon District II Health Centers</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="homepagestyle.css">
        </head>
        <body>
 
        <div class="shell">
          <div class="brand">
            <div class="brand-mark"><span class="dot"></span>MALABON DISTRICT II</div>
            <div>
              <div class="brand-copy">
                <h1>Account created.</h1>
                <p>Save your Patient ID somewhere safe — you can sign in with it any time instead of your email.</p>
              </div>
              <div class="network" aria-hidden="true">
                <svg viewBox="0 0 340 220" fill="none">
                  <path d="M60 150 L150 90 L230 130 L280 60" stroke="rgba(220,238,232,0.35)" stroke-width="1.6" stroke-dasharray="3 6"/>
                  <path d="M150 90 L110 40" stroke="rgba(220,238,232,0.35)" stroke-width="1.6" stroke-dasharray="3 6"/>
                  <path d="M230 130 L245 185" stroke="rgba(220,238,232,0.35)" stroke-width="1.6" stroke-dasharray="3 6"/>
                  <g class="pulse"><circle cx="150" cy="90" r="7" fill="#E8A33D"/></g>
                  <path d="M150 90 m0 -16 c-9 0-16 7-16 16 c0 12 16 26 16 26 s16-14 16-26 c0-9-7-16-16-16z" fill="#E8A33D" opacity="0.15"/>
                  <rect x="146" y="86" width="8" height="2.2" fill="#0E4D46"/>
                  <rect x="149" y="83" width="2.2" height="8" fill="#0E4D46"/>
                  <circle cx="60" cy="150" r="5.5" fill="#DCEEE8"/><circle cx="110" cy="40" r="5.5" fill="#DCEEE8"/>
                  <circle cx="230" cy="130" r="5.5" fill="#DCEEE8"/><circle cx="280" cy="60" r="5.5" fill="#DCEEE8"/>
                  <circle cx="245" cy="185" r="5.5" fill="#DCEEE8"/>
                </svg>
              </div>
            </div>
            <div class="brand-foot">AI-Based Appointment Management &amp; Supply Forecasting</div>
          </div>
 
          <div class="panel">
            <h2 class="title">Account created</h2>
            <p class="sub">Sign in below to get started.</p>
 
            <div class="patient-id-callout">
              <div class="lbl">Your Patient ID</div>
              <div class="id">' . htmlspecialchars($patient_id) . '</div>
            </div>
 
            ' . $staffNote . '
 
            <form action="login.php" method="POST">
              <div class="field">
                <label for="si-identifier">Username, Email, or Patient ID</label>
                <input type="text" id="si-identifier" name="identifier" placeholder="e.g. juandelacruz, you@email.com, or PT-00001" required />
              </div>
              <div class="field">
                <label for="si-password">Password</label>
                <div class="password-wrapper">
                  <input type="password" id="si-password" name="password" placeholder="Enter your password" required />
                  <button type="button" class="toggle-password" data-target="si-password" aria-label="Show password">
                    <svg class="icon-eye" viewBox="0 0 24 24"><path d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/></svg>
                    <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;"><path d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z"/></svg>
                  </button>
                </div>
              </div>
              <div class="row-between">
                <label class="remember"><input type="checkbox" name="remember" /> Keep me signed in</label>
              </div>
              <button type="submit" class="btn-primary">Sign in</button>
            </form>
          </div>
        </div>
 
        <script>
          document.querySelectorAll(".toggle-password").forEach(function (btn) {
            btn.addEventListener("click", function () {
              var input = document.getElementById(btn.dataset.target);
              var showing = input.type === "text";
              input.type = showing ? "password" : "text";
              btn.querySelector(".icon-eye").style.display = showing ? "block" : "none";
              btn.querySelector(".icon-eye-off").style.display = showing ? "none" : "block";
              btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
            });
          });
        </script>
        </body>
        </html>
        ';
    } else {
        echo "Error: " . $stmt->error;
    }

    $conn->close();
}
?>