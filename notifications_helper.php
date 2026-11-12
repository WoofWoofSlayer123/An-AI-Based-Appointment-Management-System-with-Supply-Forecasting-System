<?php
// Include wherever a notification needs to be created — process_appointment.php
// today, and eventually a staff/admin appointment-review page too, since
// confirming/rejecting an appointment should notify the patient the same way.

function create_notification($conn, $username, $message, $appointment_id = null) {
    $stmt = $conn->prepare(
        "INSERT INTO notifications (username, appointment_id, message) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("sis", $username, $appointment_id, $message);
    $stmt->execute();
    $stmt->close();
}
?>
