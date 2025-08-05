<?php
session_start();
require 'db.php';
require 'vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = hash('sha256', $_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $otp = rand(100000, 999999);
        $otp_expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        $update = $conn->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE username = ?");
        $update->bind_param("sss", $otp, $otp_expiry, $username);
        $update->execute();

        // Send OTP
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'pyketyson42@gmail.com';
            $mail->Password = 'wbcv upuz dlqf umgu'; // use Gmail app password
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('pyketyson42@gmail.com', 'OTP Login System');
            $mail->addAddress($user['email']);

            $mail->isHTML(true);
            $mail->Subject = 'Your OTP Code';
            $mail->Body    = "Hello $username,<br><br>Your OTP code is: <strong>$otp</strong><br>This code will expire in 5 minutes.";
            

            $mail->send();

            $_SESSION['username'] = $username;
            $_SESSION['email'] = $user['email'];
            header("Location: verify_otp.php");
            exit();
        } catch (Exception $e) {
            $error = "OTP could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ViaHale Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque&family=Poppins:wght@400;600&family=Quicksand:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(to right, #4311A5, #6532C9);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: #fff;
        }

        .login-container {
            background: #fff;
            color: #4311A5;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
        }

        .login-container h2 {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 28px;
            text-align: center;
            margin-bottom: 10px;
        }

        .login-container p {
            font-size: 14px;
            text-align: center;
            margin-bottom: 25px;
            color: #777;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #ccc;
            font-size: 14px;
            font-family: 'Quicksand', sans-serif;
        }

        .form-group input:focus {
            outline: none;
            border-color: #9A66FF;
        }

        .login-btn {
            width: 100%;
            background: #6532C9;
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .login-btn:hover {
            background: #4311A5;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            margin-top: 25px;
            color: #aaa;
        }

        .footer a {
            color: #9A66FF;
            text-decoration: none;
            margin: 0 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Welcome back, Admin!</h2>
        <p>Please enter your credentials to access the dashboard.</p>

        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" required placeholder="Enter your username">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" required placeholder="Enter your password">
            </div>
            <button type="submit" class="login-btn" name="login">LOGIN ➤</button>
        </form>

        <div class="footer">
            <span>BCP Capstone</span> |
            <a href="#">Privacy Policy</a>
            <span style="float: right;">Need Help?</span>
        </div>
    </div>
</body>
</html>

