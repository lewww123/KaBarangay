Hello {{ $residentName }},

@if ($decisionType === 'approved')
Your KaBarangay Resident registration has been approved. Your portal account is active. You can access the Resident portal here:

{{ $accessUrl }}
@elseif ($decisionType === 'correction')
Your KaBarangay registration needs corrections before it can be reviewed again. Please sign in to your secure application page to submit the requested changes:

{{ $correctionInstructions }}

{{ $accessUrl }}
@elseif ($decisionType === 'rejected')
Your KaBarangay registration was not approved.

@if ($emailSafeReason)
Explanation: {{ $emailSafeReason }}
@endif
@endif

KaBarangay
