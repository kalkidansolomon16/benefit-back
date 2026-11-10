<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $emailSubject }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f2f2;font-family:Arial,Helvetica,sans-serif;color:#0B0C10;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f2f2f2;padding:32px 12px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;">

        <!-- Header -->
        <tr>
          <td style="padding:28px 40px;border-bottom:1px solid #ececec;">
            <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#8BE000;vertical-align:middle;"></span>
            <span style="font-size:22px;font-weight:800;color:#0B0C10;vertical-align:middle;margin-left:6px;">FitAccess</span>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px 12px;">
            @if($highlight)
              <p style="margin:0 0 18px;font-size:19px;font-weight:700;line-height:1.4;color:#0B0C10;">{{ $highlight }}</p>
            @endif

            <h1 style="margin:0 0 18px;font-size:30px;line-height:1.2;font-weight:700;color:#0B0C10;">{{ $heading }}</h1>

            @if($recipientName)
              <p style="margin:0 0 14px;font-size:15px;line-height:1.7;">Dear <strong>{{ $recipientName }}</strong>,</p>
            @endif

            @foreach($paragraphs as $p)
              <p style="margin:0 0 14px;font-size:15px;line-height:1.7;">{!! nl2br(e($p)) !!}</p>
            @endforeach

            @if(!empty($details))
              <table cellpadding="0" cellspacing="0" width="100%" style="margin:10px 0 22px;border:1px solid #ececec;border-radius:12px;">
                @foreach($details as $label => $value)
                  <tr>
                    <td style="padding:10px 16px;font-size:14px;color:#555;width:42%;border-bottom:1px solid #f2f2f2;">{{ $label }}</td>
                    <td style="padding:10px 16px;font-size:14px;font-weight:600;border-bottom:1px solid #f2f2f2;">{{ $value }}</td>
                  </tr>
                @endforeach
              </table>
            @endif

            @if(!empty($credentials))
              <table cellpadding="0" cellspacing="0" width="100%" style="margin:10px 0 22px;background:#0B0C10;border-radius:14px;">
                @foreach($credentials as $label => $value)
                  <tr>
                    <td style="padding:12px 20px 4px;font-size:12px;color:#8BE000;text-transform:uppercase;letter-spacing:1px;">{{ $label }}</td>
                  </tr>
                  <tr>
                    <td style="padding:0 20px 12px;font-size:18px;font-weight:700;color:#ffffff;font-family:Consolas,Menlo,monospace;">{{ $value }}</td>
                  </tr>
                @endforeach
              </table>
            @endif

            @if(!empty($steps))
              <p style="margin:18px 0 10px;font-size:16px;font-weight:700;">What now?</p>
              <table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 18px;">
                @foreach($steps as $i => $step)
                  <tr>
                    <td valign="top" style="padding:6px 0;width:92px;font-size:15px;font-weight:700;white-space:nowrap;">
                      <span style="color:#72B800;">&#10004;</span> Step {{ $i + 1 }}:
                    </td>
                    <td valign="top" style="padding:6px 0;font-size:15px;line-height:1.6;">{{ $step }}</td>
                  </tr>
                @endforeach
              </table>
            @endif

            @if($buttonText && $buttonUrl)
              <table cellpadding="0" cellspacing="0" style="margin:8px 0 24px;">
                <tr>
                  <td style="background:#8BE000;border-radius:999px;padding:14px 30px;">
                    <a href="{{ $buttonUrl }}" style="color:#0B0C10;font-size:15px;font-weight:700;text-decoration:none;">{{ $buttonText }}</a>
                  </td>
                </tr>
              </table>
            @endif

            @if($closing)
              <p style="margin:0 0 6px;font-size:15px;line-height:1.7;">{{ $closing }}</p>
            @endif
            <p style="margin:16px 0 28px;font-size:15px;line-height:1.7;">Best regards,<br>FitAccess Team</p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#0B0C10;padding:22px 40px;">
            <p style="margin:0;font-size:12px;line-height:1.6;color:#cfcfcf;">
              FitAccess — the employee wellness benefit for Addis Ababa.<br>
              If you have any questions, simply reply to this email.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
