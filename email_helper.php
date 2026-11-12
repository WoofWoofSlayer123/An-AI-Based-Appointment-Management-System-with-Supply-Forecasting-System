
<?php
/**
 * CarePulse Email Helper
 * Project: Malabon District II Health Centers
 *
 * Requirements:
 *   composer require phpmailer/phpmailer
 *
 * Keep SMTP credentials private.
 */

require_once __DIR__ . '/notifications_helper.php';

/* =========================================
   EMAIL CONFIGURATION
   ========================================= */

if (!defined("MAIL_FROM")) {
    define("MAIL_FROM", "no-reply@carepulse.local");
}

if (!defined("MAIL_FROM_NAME")) {
    define("MAIL_FROM_NAME", "CarePulse - Malabon District II Health Centers");
}

if (!defined("MAIL_SMTP_HOST")) {
    define("MAIL_SMTP_HOST", "smtp.gmail.com");
}

if (!defined("MAIL_SMTP_PORT")) {
    define("MAIL_SMTP_PORT", 587);
}

/*
 * Replace these values with your Gmail address
 * and Google App Password.
 *
 * Never use your normal Gmail password here.
 */
if (!defined("MAIL_SMTP_USER")) {
    define("MAIL_SMTP_USER", "princevincent0123@gmail.com");
}

if (!defined("MAIL_SMTP_PASS")) {
    define("MAIL_SMTP_PASS", "vsjj ihqj ymhi shtg");
}

/*
 * Change CAPSTONE if your project folder has
 * a different name.
 */
if (!defined("APP_BASE_URL")) {
    define("APP_BASE_URL", "https://malabon-health-center-system.infinityfree.io/");
}

/* =========================================
   LOAD PHPMailer
   ========================================= */

$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

/* =========================================
   BUILD EMAIL HTML
   ========================================= */

function build_email_html(
    $message,
    $buttonUrl = null,
    $buttonLabel = null
) {
    $html = '<div style="font-family:Arial,sans-serif;'
          . 'max-width:520px;margin:auto;color:#131b2e;">';

    $html .= '<h2 style="color:#00685f;margin:0 0 12px;">'
           . 'CarePulse</h2>';

    $html .= '<p style="font-size:15px;line-height:1.6;">'
           . nl2br(htmlspecialchars(
               (string)$message,
               ENT_QUOTES,
               'UTF-8'
           ))
           . '</p>';

    if (!empty($buttonUrl)) {
        $safeUrl = htmlspecialchars(
            $buttonUrl,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeLabel = htmlspecialchars(
            $buttonLabel ?: 'Open CarePulse',
            ENT_QUOTES,
            'UTF-8'
        );

        $html .= '<p><a href="' . $safeUrl . '" '
               . 'style="display:inline-block;'
               . 'background:#00685f;color:#ffffff;'
               . 'padding:10px 20px;border-radius:8px;'
               . 'text-decoration:none;font-weight:bold;">'
               . $safeLabel
               . '</a></p>';
    }

    $html .= '<p style="font-size:12px;color:#6d7a77;">'
           . 'Malabon District II Health Centers. '
           . 'This is an automated message. '
           . 'Please do not reply to this email.'
           . '</p>';

    $html .= '</div>';

    return $html;
}

/* =========================================
   SEND EMAIL
   ========================================= */

function send_email($to, $subject, $htmlBody)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("CarePulse: Invalid recipient email address.");
        return false;
    }

    /*
     * Require configured SMTP and PHPMailer.
     * Avoid silently falling back to mail(), which
     * is commonly not configured in XAMPP.
     */
    if (
        !class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
        || MAIL_SMTP_USER === ""
        || MAIL_SMTP_PASS === ""
        || MAIL_SMTP_USER === "your-gmail@gmail.com"
        || MAIL_SMTP_PASS === "your-google-app-password"
    ) {
        error_log(
            "CarePulse: Email not sent. Configure PHPMailer and SMTP credentials."
        );
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = MAIL_SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_SMTP_USER;
        $mail->Password = MAIL_SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = MAIL_SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        /*
         * Gmail SMTP should use the authenticated
         * Gmail address as the sender.
         */
        $mail->setFrom(MAIL_SMTP_USER, MAIL_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(
            html_entity_decode(
                strip_tags(
                    str_replace(
                        ['<br>', '<br/>', '<br />', '</p>'],
                        "\n",
                        $htmlBody
                    )
                ),
                ENT_QUOTES,
                'UTF-8'
            )
        );

        return $mail->send();

    } catch (\Throwable $e) {
        /*
         * Log the technical error on the server.
         * Do not expose SMTP credentials or errors
         * to patients.
         */
        error_log(
            "CarePulse email sending failed: " . $e->getMessage()
        );

        return false;
    }
}

/* =========================================
   SEND NOTIFICATION + EMAIL
   ========================================= */

/**
 * Saves an in-system notification and attempts
 * to email the user associated with the username.
 *
 * The database notification is saved first.
 * Email failure does not stop appointment processing.
 */
function notify_with_email(
    $conn,
    $username,
    $message,
    $apptId = null,
    $buttonUrl = null,
    $buttonLabel = null,
    $subject = "CarePulse Notification"
) {
    /*
     * Save the website notification.
     */
    create_notification(
        $conn,
        $username,
        $message,
        $apptId
    );

    /*
     * Retrieve the recipient's email.
     */
    $stmt = $conn->prepare(
        "SELECT email FROM users WHERE username = ? LIMIT 1"
    );

    if (!$stmt) {
        error_log(
            "CarePulse: Failed to prepare email lookup: "
            . $conn->error
        );
        return false;
    }

    $stmt->bind_param("s", $username);

    if (!$stmt->execute()) {
        error_log(
            "CarePulse: Failed to retrieve recipient email."
        );
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (empty($row['email'])) {
        error_log(
            "CarePulse: No email address found for notification recipient."
        );
        return false;
    }

    /*
     * Append the base URL to the email button
     * only when a relative path is supplied.
     */
    if (!empty($buttonUrl) && !preg_match(
        '~^https?://~i',
        $buttonUrl
    )) {
        $buttonUrl = rtrim(APP_BASE_URL, '/')
                   . '/'
                   . ltrim($buttonUrl, '/');
    }

    /*
     * Send email. A false return value means
     * the email was not successfully handed off.
     */
    return send_email(
        $row['email'],
        $subject,
        build_email_html(
            $message,
            $buttonUrl,
            $buttonLabel
        )
    );
}
