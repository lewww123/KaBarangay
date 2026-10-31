<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

trait PublishesPrograms
{
    protected function publishProgram(Request $request, array $attributes, ?Announcement $program = null): RedirectResponse
    {
        $input = $request->validate(['program_status' => ['sometimes', 'required', Rule::in(['draft', 'ongoing'])]]);
        $status = $input['program_status'] ?? 'ongoing';
        $user = $request->user();
        $attributes['target_audience'] = $user->hasActiveAssignment(['pwd'])
            ? 'pwd'
            : ($user->hasActiveAssignment(['senior']) ? 'senior_citizens' : ($attributes['target_audience'] ?? 'all'));

        DB::transaction(function () use ($request, $attributes, $program, $status): void {
            if ($program !== null) {
                $program = Announcement::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
                $this->ensureAnnouncementIsEditable($program);
            }
            $previousStatus = $program?->program_status;
            $values = [
                ...$attributes,
                'category' => 'Event',
                'program_status' => $status,
                'approval_status' => 'approved',
                'is_published' => $status === 'ongoing',
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ];
            if ($program === null) {
                $program = Announcement::create([
                    ...$values,
                    'barangay_id' => $request->user()->barangay_id,
                    'created_by' => $request->user()->id,
                ]);
            } else {
                $program->update($values);
            }
            $this->recordAnnouncementAudit(
                $program,
                $request->user()->id,
                $status === 'ongoing' ? 'program.published' : 'program.draft_saved',
                $previousStatus,
                $status,
                $request->ip(),
            );
        });

        return back()->with('success', $status === 'ongoing' ? 'Program published immediately.' : 'Program saved as draft.');
    }
}
