<?php
include "connection.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);

    $checkSql = "SELECT id FROM users WHERE email = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows === 0) {
        echo "<script>alert('No account found with that email.'); window.location.href='homepage.php?view=forgot';</script>";
        exit;
    }
    $checkStmt->close();

    $code = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
    $expiresAt = date("Y-m-d H:i:s", strtotime("+15 minutes"));

    $sql = "INSERT INTO password_resets (email, code, expires_at) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $email, $code, $expiresAt);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    // TODO before going live: send $code to $email via a real mail service
    // (e.g. PHPMailer + SMTP). Right now it's just shown on screen so you
    // can test the flow end-to-end.

    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Reset Code Sent — Malabon District II Health Centers</title>
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
            <h1>Almost there.</h1>
            <p>Enter the code below along with your new password to finish resetting your account.</p>
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
        <h2 class="title">Reset your password</h2>
        <p class="sub">Enter the code and choose a new password.</p>
 
        <div class="msg info">
          In a real deployment this code would be emailed to you.
          <b>Dev mode</b> — your code is <code>' . htmlspecialchars($code) . '</code> (valid 15 minutes).
        </div>
 
        <form action="reset_password.php" method="POST">
          <input type="hidden" name="email" value="' . htmlspecialchars($email) . '">
 
          <div class="field">
            <label for="code">Reset code <span class="hint">(6 digits)</span></label>
            <input type="text" id="code" name="code" maxlength="6" placeholder="000000" required>
          </div>
 
          <div class="field">
            <label for="new_password">New password</label>
            <div class="password-wrapper">
              <input type="password" id="new_password" name="new_password" required>
              <button type="button" class="toggle-password" data-target="new_password" aria-label="Show password">
                <svg class="icon-eye" viewBox="0 0 24 24"><path d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;"><path d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z"/></svg>
              </button>
            </div>
            <ul class="pw-requirements" id="pwRequirements">
              <li data-rule="length">At least 8 characters</li>
              <li data-rule="number">Contains a number</li>
              <li data-rule="special">Contains a special character</li>
            </ul>
          </div>
 
          <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <div class="password-wrapper">
              <input type="password" id="confirm_password" name="confirm_password" required>
              <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password">
                <svg class="icon-eye" viewBox="0 0 24 24"><path d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/></svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;"><path d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z"/></svg>
              </button>
            </div>
          </div>
 
          <button type="submit" class="btn-primary">Reset password</button>
        </form>
        <div class="foot-link"><a href="homepage.php?view=signin">Back to sign in</a></div>
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
 
      var newPass = document.getElementById("new_password");
      var requirementsList = document.getElementById("pwRequirements");
      var rules = {
        length: function (v) { return v.length >= 8; },
        number: function (v) { return /[0-9]/.test(v); },
        special: function (v) { return /[^A-Za-z0-9]/.test(v); }
      };
      newPass.addEventListener("input", function () {
        var value = newPass.value;
        requirementsList.querySelectorAll("li[data-rule]").forEach(function (li) {
          li.classList.toggle("met", rules[li.dataset.rule](value));
        });
      });
 
      document.querySelector("form").addEventListener("submit", function (e) {
        var value = newPass.value;
        var valid = value.length >= 8 && /[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value);
        if (!valid) {
          e.preventDefault();
          newPass.focus();
        }
      });
    </script>
    </body>
    </html>
    ';
}
?>