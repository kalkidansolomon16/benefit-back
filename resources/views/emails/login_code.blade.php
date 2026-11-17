<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Your sign-in code — FitAccess</title>
    <style>
        body { margin: 0; padding: 0; background: #f4f5f0; font-family: Arial, Helvetica, sans-serif; }
        .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
        .header { background: #0B0C10; padding: 28px 40px; text-align: center; }
        .header h1 { margin: 0; color: #8BE000; font-size: 22px; letter-spacing: 0.5px; }
        .body { padding: 34px 40px; }
        .body p { color: #374151; font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
        .code-box { background: #F3FCE6; border: 2px dashed #8BE000; border-radius: 14px; padding: 24px; margin: 22px 0; text-align: center; }
        .code { font-size: 38px; font-weight: bold; letter-spacing: 10px; color: #0B0C10; font-family: 'Courier New', monospace; }
        .expires { font-size: 12px; color: #6b7280; margin-top: 8px; }
        .warning { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 14px 18px; font-size: 13px; color: #92400e; line-height: 1.5; }
        .footer { padding: 18px 40px; background: #fafaf7; border-top: 1px solid #eee; text-align: center; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header"><h1>FitAccess</h1></div>
        <div class="body">
            <p>Hello {{ $name }},</p>
            <p>Use this code to finish signing in to FitAccess:</p>

            <div class="code-box">
                <div class="code">{{ $code }}</div>
                <div class="expires">This code expires in {{ $minutes }} minutes and can be used once.</div>
            </div>

            <div class="warning">
                Never share this code with anyone, not even FitAccess staff.
                If you did not just try to sign in, change your password straight away.
            </div>
        </div>
        <div class="footer">&copy; {{ date('Y') }} FitAccess Ethiopia</div>
    </div>
</body>
</html>
