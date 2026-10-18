<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;
        if (!$clinicId) {
            return response()->json(['data' => [], 'meta' => ['unread_count' => 0]]);
        }

        $notifications = UserNotification::query()
            ->where('clinic_id', $clinicId)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(30);

        $notifications->getCollection()->transform(fn (UserNotification $notification) => [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at,
        ]);

        return response()->json([
            'data' => $notifications->items(),
            'links' => $notifications->linkCollection(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'unread_count' => UserNotification::query()
                    ->where('clinic_id', $clinicId)
                    ->where('user_id', $request->user()->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markRead(Request $request, UserNotification $notification)
    {
        if (
            (int) $notification->user_id !== (int) $request->user()->id
            || (int) $notification->clinic_id !== (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id)
        ) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['data' => ['id' => $notification->id, 'read' => true]]);
    }
}
