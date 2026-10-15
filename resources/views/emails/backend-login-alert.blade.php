<!DOCTYPE html>
<html lang="nb">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0;padding:0;background:#f0f2f7;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:520px;margin:24px auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe0ea;">
        <div style="background:#1f2937;padding:20px 28px;">
            <h1 style="margin:0;color:#ffffff;font-size:18px;">{{ $appName }} – innlogging i admin</h1>
        </div>
        <div style="padding:24px 28px;color:#333333;font-size:14px;line-height:1.6;">
            <p style="margin-top:0;">Noen logget nettopp inn i admin-panelet på {{ $host }}:</p>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <tr>
                    <td style="padding:6px 0;color:#5a6a8a;width:110px;">Bruker</td>
                    <td style="padding:6px 0;font-weight:bold;">{{ $userName }} ({{ $userEmail }})</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#5a6a8a;">Tid</td>
                    <td style="padding:6px 0;">{{ $time }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#5a6a8a;">IP-adresse</td>
                    <td style="padding:6px 0;">{{ $ip }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;color:#5a6a8a;">Nettleser</td>
                    <td style="padding:6px 0;">{{ $userAgent ?: 'Ukjent' }}</td>
                </tr>
                @if ($viaRemember)
                <tr>
                    <td style="padding:6px 0;color:#5a6a8a;">Merk</td>
                    <td style="padding:6px 0;">Innlogget via «husk meg»-cookie</td>
                </tr>
                @endif
            </table>
            <p style="margin-bottom:0;color:#5a6a8a;font-size:12px;">
                Var det ikke deg, bytt passord i admin-panelet med en gang.
            </p>
        </div>
    </div>
</body>
</html>
