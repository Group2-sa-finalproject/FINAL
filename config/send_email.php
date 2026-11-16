
<?php

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/email_config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendEmail($recipient, $subject, $body)
{
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($recipient);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();

        return true;

    } catch (Throwable $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        return false;
    }
}
