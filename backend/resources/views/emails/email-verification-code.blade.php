<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your email</title>
</head>
<body style="margin:0;background:#f7f4ee;color:#26352d;font-family:Arial,sans-serif;line-height:1.6;">
    <div style="max-width:560px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border:1px solid #e5e0d6;border-radius:16px;padding:32px;">
            <p style="margin:0 0 8px;color:#245140;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">DMD Farm Resort</p>
            <h1 style="margin:0 0 16px;font-size:26px;line-height:1.2;">Verify your email address</h1>
            <p style="margin:0 0 20px;">Hello {{ $user->first_name ?: $user->name }}, use this verification code to finish creating your customer account:</p>
            <p style="margin:0 0 20px;text-align:center;font-size:34px;font-weight:800;letter-spacing:10px;color:#245140;">{{ $code }}</p>
            <p style="margin:0;color:#66736b;font-size:14px;">This code expires in {{ $expiresInMinutes }} minutes. If you did not create this account, you can ignore this email.</p>
        </div>
    </div>
</body>
</html>
