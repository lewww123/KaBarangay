<!doctype html>
<html lang="en">
<body style="margin:0;background:#f5f7f4;font-family:Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px;">
        <tr><td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #d8e3d4;border-radius:12px;overflow:hidden;">
                <tr><td style="padding:28px;background:#3f6f3d;color:#ffffff;"><strong style="font-size:20px;">KaBarangay Registration Approved</strong></td></tr>
                <tr><td style="padding:28px;line-height:1.55;">
                    <p>Hello {{ $residentName }},</p>
                    <p>Your Resident registration has been approved. Your online account is now active.</p>
                    <p><strong>Barangay Resident ID:</strong> {{ $residentNumber }}<br>
                    <strong>Approval date:</strong> {{ $approvedAt }}</p>
                    <p>Use your account to access barangay services, request certificates, and view your resident information.</p>
                    <p><a href="{{ $accessUrl }}" style="display:inline-block;padding:10px 16px;background:#3f6f3d;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;">Access Resident Portal</a></p>
                    <p style="margin-bottom:8px;"><strong>Your Resident QR Code</strong></p>
                    <img src="{{ $message->embedData($qrCodePng, 'resident-qr-code.png', 'image/png') }}" width="240" height="240" alt="Resident QR Code" style="display:block;width:240px;height:240px;border:0;">
                    <p style="font-size:12px;color:#6b7280;">Keep this QR code private. It verifies your active Resident credential when scanned.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
