<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>KaBarangay email verification</title></head>
<body style="background:#f5f7f4;padding:24px;font-family:Arial,sans-serif;color:#243024">
    <main style="max-width:520px;margin:auto;background:white;padding:32px;border-radius:12px">
        <h1 style="font-size:22px;color:#3f6f3d">Verify your email address</h1>
        <p>Enter this six-digit code in your KaBarangay Resident Registration form:</p>
        <p style="font-size:32px;font-weight:bold;letter-spacing:8px">{{ $verificationCode }}</p>
        <p>This code expires in <strong>10 minutes</strong> and can be used once. Email verification is required before you submit your registration.</p>
        <p style="font-size:12px;color:#687468">If you did not request this code, you can ignore this email.</p>
    </main>
</body>
</html>
