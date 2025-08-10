<?php
session_start();
require 'vendor/autoload.php';
require 'includes/db.php'; // your DB connection
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if the session has the user
if (!isset($_SESSION['username']) || !isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$email = $_SESSION['email'];
$otp = rand(100000, 999999);
$otp_expiration = date("Y-m-d H:i:s", strtotime("+5 minutes"));

// Update OTP in the database
$stmt = $conn->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE username = ?");
$stmt->bind_param("sss", $otp, $otp_expiration, $username);
$stmt->execute();
$stmt->close();

// Send OTP email
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'pyketyson42@gmail.com';
    $mail->Password   = 'wbcv upuz dlqf umgu'; // app password
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('pyketyson42@gmail.com', 'OTP System');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'Your New OTP Code';
    $mail->Body    = "Hello $username,<br><br>Your new OTP code is: <strong>$otp</strong><br>This code will expire in 5 minutes.";

    $mail->send();

    $_SESSION['otp'] = $otp;
    $_SESSION['otp_expiration'] = $otp_expiration;

    echo "<script>alert('A new OTP has been sent to your email.'); window.location.href='verify_otp.php';</script>";
} catch (Exception $e) {
    echo "OTP could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
?>
