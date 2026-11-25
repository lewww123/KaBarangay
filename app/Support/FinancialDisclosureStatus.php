<?php

namespace App\Support;

final class FinancialDisclosureStatus
{
    public const ALLOCATION_CATEGORIES = ['PS', 'MOOE', 'CO'];

    public const ALLOCATION_STATUSES = ['Active', 'Amended', 'Closed', 'Archived'];

    public const MANDATORY_FUND_ALIASES = [
        '20% Development Fund' => ['20% development fund', 'development fund', '20% development'],
        '5% LDRRMF' => ['5% ldrrmf', 'ldrrmf'],
        '10% SK Fund' => ['10% sk fund', 'sk fund', 'sk'],
        'GAD Fund' => ['gad fund', 'gad'],
    ];

    public const FUND_SOURCES = ['IRA', 'Share sa taxes', 'Sariling kita'];

    public const COLLECTION_STATUSES = ['Pending', 'Verified', 'Voided'];

    public const DISBURSEMENT_STATUSES = ['Draft', 'Approved', 'Released', 'Voided'];

    public const PUBLIC_EVENT_PREFIX = 'financial_disclosure.';

    public static function isCollectionStatus(mixed $status): bool
    {
        return is_string($status) && in_array($status, self::COLLECTION_STATUSES, true);
    }

    public static function isDisbursementStatus(mixed $status): bool
    {
        return is_string($status) && in_array($status, self::DISBURSEMENT_STATUSES, true);
    }
}
