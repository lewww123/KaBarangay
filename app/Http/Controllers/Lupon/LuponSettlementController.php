<?php

namespace App\Http\Controllers\Lupon;

use App\Http\Controllers\Controller;
use App\Models\LuponCase;
use App\Models\LuponSettlement;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LuponSettlementController extends Controller
{
    private const TYPES = ['Amicable Settlement', 'Arbitration Award', 'Resolution'];

    public function store(Request $request, LuponCase $case): RedirectResponse
    {
        $user = $request->user();

        if (! $user->staffAssignments()->where('department', 'lupon')->where('is_active', true)->exists()) {
            abort(403, 'Unauthorized.');
        }

        if ($case->barangay_id !== $user->barangay_id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'settlement_type' => ['required', 'string', 'in:' . implode(',', self::TYPES)],
            'settlement_date' => ['required', 'date'],
            'terms' => ['required', 'string', 'max:10000'],
        ]);

        $settlement = LuponSettlement::updateOrCreate(
            ['case_id' => $case->id],
            [
                'barangay_id' => $case->barangay_id,
                'settlement_type' => $validated['settlement_type'],
                'settlement_date' => $validated['settlement_date'],
                'terms' => $validated['terms'],
                'status' => 'Active',
                'recorded_by' => $user->id,
            ]
        );

        $case->update([
            'status' => 'Settled',
            'phase' => 'Closed',
        ]);

        AuditLogger::record(
            event: 'lupon_settlement.recorded',
            barangayId: $user->barangay_id,
            actorId: $user->id,
            entityType: 'lupon_case',
            entityId: $case->id,
            context: [
                'action' => 'settled',
                'entity_reference' => $case->case_number,
                'new_status' => 'Settled',
            ],
            ipAddress: $request->ip()
        );

        return redirect()->back()->with('success', 'Case settlement successfully recorded.');
    }
}
