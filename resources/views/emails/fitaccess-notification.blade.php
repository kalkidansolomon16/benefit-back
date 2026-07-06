<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background:{{ $color }};padding:32px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:24px;font-weight:700;">⚡ FitAccess</h1>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:40px;">
            <h2 style="color:#111827;font-size:20px;margin:0 0 16px;">{{ $heading }}</h2>
            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 8px;">
              Dear <strong>{{ $recipientName }}</strong>,
            </p>
            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 28px;">
              {!! nl2br(e($message)) !!}
            </p>

            <!-- Button -->
            <table cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:{{ $color }};border-radius:8px;padding:12px 28px;">
                  <a href="{{ $buttonUrl }}" style="color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    {{ $buttonText }}
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f9fafb;padding:24px 40px;border-top:1px solid #e5e7eb;text-align:center;">
            <p style="color:#9ca3af;font-size:13px;margin:0;">
              This email was sent by FitAccess — Ethiopia's #1 Corporate Wellness Platform.<br>
              If you have questions, contact us at support@fitaccess.com
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
