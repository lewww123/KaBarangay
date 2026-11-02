<?php

namespace App\Support;

final class LuponCatalog
{
    public const CASE_TYPES = ['Property / Boundary Dispute', 'Noise / Disturbance', 'Debt / Money Dispute', 'Damage to Property', 'Threat / Harassment', 'Family / Neighbor Dispute', 'Other'];

    public const EVIDENCE_RULES = ['nullable', 'array', 'max:5'];

    public const FILE_RULES = ['file', 'mimes:pdf,jpg,jpeg,png,webp,docx', 'max:5120'];
}
