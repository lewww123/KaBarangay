<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Models\ResidentInboxNotification;
use App\Support\ResidentContext;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    private function notifications()
    {
        $resident = ResidentContext::get();

        return ResidentInboxNotification::query()->where('resident_id', $resident->id)
            ->where('barangay_id', $resident->barangay_id);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'items' => $this->notifications()->latest('id')->limit(30)
                ->get(['id', 'title', 'message', 'url', 'read_at', 'created_at']),
            'unread_count' => $this->notifications()->whereNull('read_at')->count(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function read(string $id): JsonResponse
    {
        $notification = $this->notifications()->findOrFail($id);
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function readAll(): JsonResponse
    {
        $this->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
