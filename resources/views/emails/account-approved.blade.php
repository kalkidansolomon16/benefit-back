<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { background: #16a34a; padding: 32px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; }
        .body { padding: 32px; color: #374151; }
        .body p { line-height: 1.6; margin: 0 0 16px; }
        .btn { display: inline-block; background: #16a34a; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; margin-top: 8px; }
        .footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>FitAccess</h1>
        </div>
        <div class="body">
            <p>Dear <strong>{{ $name }}</strong>,</p>
            <p>We are pleased to inform you that your FitAccess account has been verified and approved by our admin team.</p>
            <p><strong>Your account is now active. You can log in using your registered email and password.</strong></p>
            <p>
                <a href="{{ $loginUrl }}" class="btn">Login to Your Account</a>
            </p>
            <p>If you have any questions, please contact your HR team or our support.</p>
            <p>Welcome to FitAccess!</p>
        </div>
        <div class="footer">
            <p>This email was sent to {{ $email }}. If you did not register for FitAccess, please ignore this email.</p>
        </div>
    </div>
</body>
</html>
