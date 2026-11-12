<?php
include "connection.php";

// Which tab opens first — set by redirects from signup.php / login.php / forgot_password.php
$view = isset($_GET["view"]) && in_array($_GET["view"], ["signin", "register", "forgot"])
    ? $_GET["view"] : "signin";

// Pull health centers straight from the database for the staff dropdown
$centers = [];
$centerResult = $conn->query("SELECT id, name FROM health_centers ORDER BY name");
if ($centerResult) {
    while ($row = $centerResult->fetch_assoc()) {
        $centers[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Malabon District II Health Centers — Account Access</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="homepagestyle.css">
</head>

<body>

    <div class="shell">
        <div class="brand">
            <div class="brand-mark"><span class="dot"></span>MALABON DISTRICT II</div>
            <div>
                <div class="brand-copy">
                    <h1>One account for every health center in the district.</h1>
                    <p>Book appointments, manage records, and keep supplies stocked — across all participating barangay
                        health centers.</p>
                </div>
                <div class="network" aria-hidden="true">
                    <svg viewBox="0 0 340 220" fill="none">
                        <path d="M60 150 L150 90 L230 130 L280 60" stroke="rgba(220,238,232,0.35)" stroke-width="1.6"
                            stroke-dasharray="3 6" />
                        <path d="M150 90 L110 40" stroke="rgba(220,238,232,0.35)" stroke-width="1.6"
                            stroke-dasharray="3 6" />
                        <path d="M230 130 L245 185" stroke="rgba(220,238,232,0.35)" stroke-width="1.6"
                            stroke-dasharray="3 6" />
                        <g class="pulse">
                            <circle cx="150" cy="90" r="7" fill="#E8A33D" />
                        </g>
                        <path d="M150 90 m0 -16 c-9 0-16 7-16 16 c0 12 16 26 16 26 s16-14 16-26 c0-9-7-16-16-16z"
                            fill="#E8A33D" opacity="0.15" />
                        <rect x="146" y="86" width="8" height="2.2" fill="#0E4D46" />
                        <rect x="149" y="83" width="2.2" height="8" fill="#0E4D46" />
                        <circle cx="60" cy="150" r="5.5" fill="#DCEEE8" />
                        <circle cx="110" cy="40" r="5.5" fill="#DCEEE8" />
                        <circle cx="230" cy="130" r="5.5" fill="#DCEEE8" />
                        <circle cx="280" cy="60" r="5.5" fill="#DCEEE8" />
                        <circle cx="245" cy="185" r="5.5" fill="#DCEEE8" />
                    </svg>
                </div>
            </div>
            <div class="brand-foot">AI-Based Appointment Management &amp; Supply Forecasting</div>
        </div>

        <div class="panel">

            <div class="tabs" id="authTabs">
                <button type="button" class="tab-btn <?= $view === 'signin' ? 'active' : '' ?>" data-tab="signin">Sign
                    In</button>
                <button type="button" class="tab-btn <?= $view === 'register' ? 'active' : '' ?>"
                    data-tab="register">Register</button>
            </div>

            <!-- SIGN IN — posts directly to login.php -->
            <div class="view <?= $view === 'signin' ? 'active' : '' ?>" id="view-signin">
                <h2 class="title">Sign in</h2>
                <p class="sub">Access your patient or staff account.</p>
                <form action="login.php" method="POST">
                    <div class="field">
                        <label for="si-identifier">Username, Email, or Patient ID</label>
                        <input type="text" id="si-identifier" name="identifier"
                            placeholder="e.g. juandelacruz, you@email.com, or PT-00001" required />
                    </div>
                    <div class="field">
                        <label for="si-password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="si-password" name="password" placeholder="Enter your password"
                                required />
                            <button type="button" class="toggle-password" data-target="si-password"
                                aria-label="Show password">
                                <svg class="icon-eye" viewBox="0 0 24 24">
                                    <path
                                        d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z" />
                                </svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;">
                                    <path
                                        d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="row-between">
                        <label class="remember"><input type="checkbox" name="remember" /> Keep me signed in</label>
                        <button type="button" class="link-btn" data-tab="forgot">Forgot password?</button>
                    </div>
                    <button type="submit" class="btn-primary">Sign in</button>
                </form>
                <div class="foot-link">Don't have an account? <button type="button" data-tab="register">Register
                        here</button></div>
            </div>

            <!-- FORGOT PASSWORD — posts to forgot_password.php -->
            <div class="view <?= $view === 'forgot' ? 'active' : '' ?>" id="view-forgot">
                <h2 class="title">Reset your password</h2>
                <p class="sub">Enter the email on your account. We'll send you a reset code.</p>
                <form action="forgot_password.php" method="POST">
                    <div class="field">
                        <label for="fp-email">Email</label>
                        <input type="email" id="fp-email" name="email" placeholder="you@email.com" required />
                    </div>
                    <button type="submit" class="btn-primary">Send reset code</button>
                    <button type="button" class="btn-secondary" data-tab="signin">Back to sign in</button>
                </form>
            </div>

            <!-- REGISTER — posts directly to signup.php -->
            <div class="view <?= $view === 'register' ? 'active' : '' ?>" id="view-register">
                <h2 class="title">Create your account</h2>
                <p class="sub">All accounts start as a patient account.</p>
                <form action="signup.php" method="POST">
                    <div class="field-row">
                        <div class="field">
                            <label for="rg-username">Username</label>
                            <input type="text" id="rg-username" name="username" required />
                        </div>
                        <div class="field">
                            <label for="rg-fullname">Full name</label>
                            <input type="text" id="rg-fullname" name="full_name" placeholder="Juan Dela Cruz"
                                required />
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="rg-email">Email</label>
                            <input type="email" id="rg-email" name="email" placeholder="you@email.com" required />
                        </div>
                        <div class="field">
                            <label for="rg-mobile">Mobile number</label>
                            <input type="tel" id="rg-mobile" name="mobile" placeholder="09XXXXXXXXX" required />
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="rg-pass">Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="rg-pass" name="password" required />
                                <button type="button" class="toggle-password" data-target="rg-pass"
                                    aria-label="Show password">
                                    <svg class="icon-eye" viewBox="0 0 24 24">
                                        <path
                                            d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z" />
                                    </svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;">
                                        <path
                                            d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z" />
                                    </svg>
                                </button>
                            </div>
                            <ul class="pw-requirements" id="pwRequirements">
                                <li data-rule="length">At least 8 characters</li>
                                <li data-rule="number">Contains a number</li>
                                <li data-rule="special">Contains a special character</li>
                            </ul>
                        </div>
                        <div class="field">
                            <label for="rg-pass2">Confirm password</label>
                            <div class="password-wrapper">
                                <input type="password" id="rg-pass2" name="confirm_password" required />
                                <button type="button" class="toggle-password" data-target="rg-pass2"
                                    aria-label="Show password">
                                    <svg class="icon-eye" viewBox="0 0 24 24">
                                        <path
                                            d="M12 5c-5.5 0-9.5 4.2-11 7 1.5 2.8 5.5 7 11 7s9.5-4.2 11-7c-1.5-2.8-5.5-7-11-7zm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z" />
                                    </svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" style="display:none;">
                                        <path
                                            d="M3.3 2.3 2 3.6l3.3 3.3C3.5 8.4 2 10 1 12c1.5 2.8 5.5 7 11 7 1.9 0 3.6-.5 5.1-1.3l3.6 3.6 1.3-1.3L3.3 2.3zM12 16.5a4.5 4.5 0 0 1-4.5-4.5c0-.7.2-1.4.5-2l1.6 1.6a2.5 2.5 0 0 0 2.9 2.9l1.6 1.6c-.6.3-1.3.5-2.1.5zm.2-9c5.1.1 8.8 4 10.3 6.5-.6 1-1.5 2.2-2.7 3.3l-1.5-1.5c.8-.8 1.5-1.6 2-2.3-1.4-2.1-4.4-5-8.3-5-.5 0-1 0-1.4.1L9 8.9c1-.3 2-.4 3.2-.4z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="staff-block">
                        <div class="checkbox-row">
                            <input type="checkbox" id="rg-isStaff" name="is_staff" />
                            <label for="rg-isStaff">I am a health center staff member or doctor</label>
                        </div>
                        <p style="font-size:12px;color:var(--ink-soft);margin:0 0 4px 24px;">
                            This will still create a patient account first — an admin verifies and upgrades staff roles.
                        </p>
                        <div class="staff-fields" id="staffFields">
                            <div class="field">
                                <label for="rg-position">Position</label>
                                <select id="rg-position" name="position">
                                    <option value="">Select position</option>
                                    <option>Health Center Staff</option>
                                    <option>Doctor</option>
                                    <option>Admin Staff</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="rg-center">Health center you work at</label>
                                <select id="rg-center" name="center_id">
                                    <option value="">Select health center</option>
                                    <?php foreach ($centers as $c): ?>
                                        <option value="<?= htmlspecialchars($c['id']) ?>">
                                            <?= htmlspecialchars($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="rg-empId">Employee / staff ID <span class="hint">(if
                                        available)</span></label>
                                <input type="text" id="rg-empId" name="employee_id" placeholder="e.g. HC-2024-113" />
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Create account</button>
                </form>
                <div class="foot-link">Already have an account? <button type="button" data-tab="signin">Sign in</button>
                </div>
            </div>

        </div>
    </div>

    <script src="homepage.js"></script>
</body>

</html>