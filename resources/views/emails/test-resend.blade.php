<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KaBarangay Resend SMTP Test</title>
</head>
<body style="margin: 0; padding: 32px 16px; background: #f5f7f4; font-family: Arial, sans-serif; color: #243024;">
    <main style="max-width: 560px; margin: 0 auto; padding: 32px; background: #ffffff; border-radius: 12px;">
        <h1 style="margin-top: 0; color: #3f6f3d; font-size: 24px;">Resend SMTP test</h1>
        <p>This test email was sent by KaBarangay to verify the Resend SMTP integration.</p>
        <p>If you received this message, the configured connection successfully delivered this test email to your mailbox.</p>
        <p style="margin-bottom: 0; color: #687468; font-size: 12px;">Sent at {{ now()->toIso8601String() }}</p>
    </main>
</body>
</html>
