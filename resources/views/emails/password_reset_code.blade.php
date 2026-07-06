<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Password Reset — FitAccess</title>
    <style>
        body { margin: 0; padding: 0; background: #f4f4f4; font-family: Arial, sans-serif; }
        .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: #4CD964; padding: 32px 40px; text-align: center; }
        .header h1 { margin: 0; color: #ffffff; font-size: 22px; letter-spacing: 1px; }
        .header p  { margin: 6px 0 0; color: rgba(255,255,255,0.85); font-size: 13px; }
        .body { padding: 36px 40px; }
        .body p { color: #444; font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
        .code-box { background: #f0fdf4; border: 2px dashed #4CD964; border-radius: 12px; padding: 24px; margin: 24px 0; text-align: center; }
        .code-box .code { font-size: 36px; font-weight: bold; letter-spacing: 8px; color: #1a9e38; font-family: monospace; }
        .code-box .expires { font-size: 12px; color: #888; margin-top: 8px; }
        .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin: 20px 0; font-size: 13px; color: #92400e; }
        .footer { padding: 20px 40px; background: #f9f9f9; border-top: 1px solid #eee; text-align: center; font-size: 12px; color: #aaa; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>FIT-ACCESS</h1>
            <p>Partner Staff Portal</p>
        </div>

        <div class="body">
            <p>Hello {{ $name }},</p>
            <p>We received a request to reset the password for your FitAccess Partner account.
               Enter the code below in the app to set a new password.</p>

            <div class="code-box">
                <div class="code">{{ $resetCode }}</div>
                <div class="expires">This code expires in 1 hour</div>
            </div>

            <div class="warning">
                ⚠️ If you did not request a password reset, please ignore this email.
                Your password will not change unless you enter this code in the app.
            </div>

            <p>Open the <strong>FitAccess Partner App</strong>, tap
               <em>"Forgot Password?"</em> on the login screen, and enter
               the email address your gym owner used to invite you, then enter
               this code when prompted.</p>

            <p>Need help? Contact us at
               <a href="mailto:support@fitaccess.et">support@fitaccess.et</a>.</p>

            <p>The FitAccess Team</p>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} FitAccess Ethiopia. All rights reserved.
        </div>
    </div>
</body>
</html>
