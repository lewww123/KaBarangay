<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Shared conditional validation for the Clerk, Senior and PWD announcement
 * forms.
 *
 * An announcement must carry an event date/time and a location when it is
 * categorised as an "Event", or whenever attendance will be recorded against
 * it (requires_attendance). Ordinary informational notices remain valid
 * without either field.
 */
trait ValidatesAnnouncementEventDetails
{
    /**
     * Whether the current request must supply event_start_at and location.
     */
    protected function eventDetailsRequired(Request $request): bool
    {
        return $request->input('category') === 'Event'
            || $request->boolean('requires_attendance');
    }
}
