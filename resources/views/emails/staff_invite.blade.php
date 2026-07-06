<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Staff Invite — FitAccess</title>
    <style>
        body { margin: 0; padding: 0; background: #f4f4f4; font-family: Arial, sans-serif; }
        .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: #4CD964; padding: 32px 40px; text-align: center; }
        .header h1 { margin: 0; color: #ffffff; font-size: 22px; letter-spacing: 1px; }
        .header p  { margin: 6px 0 0; color: rgba(255,255,255,0.85); font-size: 13px; }
        .body { padding: 36px 40px; }
        .body p { color: #444; font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
        .cred-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px 24px; margin: 24px 0; }
        .cred-box p { margin: 0 0 8px; font-size: 14px; color: #555; }
        .cred-box p:last-child { margin: 0; }
        .cred-box strong { color: #1a1a1a; font-size: 15px; }
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
            <p>Hello,</p>
            <p>
                You have been added as a staff member at <strong>{{ $gymName }}</strong> on the
                FitAccess Partner Platform. You can now log in to the Partner App using the
                credentials below.
            </p>

            <div class="cred-box">
                <p>Email address<br><strong>{{ $email }}</strong></p>
                @if($tempPassword)
                <p>Temporary password<br><strong>{{ $tempPassword }}</strong></p>
                @endif
            </div>

            @if($tempPassword)
            <div class="warning">
                ⚠️ You will be asked to set a new password the first time you sign in.
                Please do not share these credentials with anyone.
            </div>
            @endif

            <p>
                Download the <strong>FitAccess Partner App</strong>, open it, and sign in
                with your email{{ $tempPassword ? ' and the temporary password above' : ' and your existing password' }}.
            </p>

            <p>If you were not expecting this invitation, please ignore this email or
            contact us at <a href="mailto:support@fitaccess.et">support@fitaccess.et</a>.</p>

            <p>Welcome to the team!<br><strong>The FitAccess Team</strong></p>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} FitAccess Ethiopia. All rights reserved.
        </div>
    </div>
</body>
</html>
