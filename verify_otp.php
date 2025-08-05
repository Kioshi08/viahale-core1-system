<?php
session_start();
require 'db.php';

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

if (isset($_POST['verify'])) {
    $email = $_SESSION['email'];
    $entered_otp = $_POST['otp'];

    $stmt = $conn->prepare("SELECT otp, otp_expiry FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $otp = $row['otp'];
        $otp_expiry = $row['otp_expiry'];

        if ($otp === $entered_otp && strtotime($otp_expiry) > time()) {
            // OTP is valid

            // ✅ Set session for OTP verification and activity timeout
            $_SESSION['otp_verified'] = true;
            $_SESSION['last_activity'] = time();

            // Clear OTP fields (optional for security)
            $clear = $conn->prepare("UPDATE users SET otp = NULL, otp_expiry = NULL WHERE email = ?");
            $clear->bind_param("s", $email);
            $clear->execute();

            // Redirect to success page
            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid or expired OTP.";
        }
    } else {
        $error = "User not found.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify OTP – ViaHale</title>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque&family=Poppins:wght@400;600&family=Quicksand:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(to bottom right, #6532C9, #9A66FF);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .otp-card {
            background: #fff;
            color: #4311A5;
            padding: 40px;
            border-radius: 24px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .otp-card h2 {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 26px;
            text-align: center;
            margin-bottom: 10px;
        }

        .otp-card p {
            font-size: 14px;
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 12px;
            font-family: 'Quicksand', sans-serif;
            font-size: 16px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #9A66FF;
        }

        .verify-btn {
            width: 100%;
            padding: 12px;
            background-color: #4311A5;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .verify-btn:hover {
            background-color: #6532C9;
        }

        .resend-link {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
        }

        .resend-link a {
            color: #6532C9;
            text-decoration: none;
            font-weight: 600;
        }

        .resend-link a:hover {
            text-decoration: underline;
        }

        .error-message {
            color: red;
            text-align: center;
            font-size: 14px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="otp-card">
        <h2>OTP Verification</h2>
        <p>Enter the 6-digit code sent to your email address to continue.</p>

        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <input type="text" name="otp" maxlength="6" placeholder="Enter OTP" required>
            </div>
            <button type="submit" name="verify" class="verify-btn">VERIFY CODE</button>
        </form>

        <div class="resend-link">
            Didn't get it? <a href="resend_otp.php">Resend OTP</a>
        </div>
    </div>
</body>
</html>

