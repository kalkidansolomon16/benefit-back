<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminNotificationController extends Controller
{
    /** List all notifications, newest first */
    public function index(Request $request): JsonResponse
    {
        $query = AdminNotification::latest('created_at');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->limit(50)->get()->map(fn($n) => [
            'id'         => $n->id,
            'type'       => $n->type,
            'title'      => $n->title,
            'message'    => $n->message,
            'data'       => $n->data,
            'read_at'    => $n->read_at,
            'is_unread'  => $n->isUnread(),
            'created_at' => $n->created_at,
        ]);

        return response()->json([
            'data'         => $notifications,
            'unread_count' => AdminNotification::whereNull('read_at')->count(),
        ]);
    }

    /** Mark a single notification as read */
    public function markRead(AdminNotification $adminNotification): JsonResponse
    {
        $adminNotification->update(['read_at' => now()]);

        return response()->json([
            'message'      => 'Notification marked as read.',
            'unread_count' => AdminNotification::whereNull('read_at')->count(),
        ]);
    }

    /** Mark all notifications as read */
    public function markAllRead(): JsonResponse
    {
        AdminNotification::whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.', 'unread_count' => 0]);
    }

    /** Unread count only (lightweight poll) */
    public function unreadCount(): JsonResponse
    {
        return response()->json(['unread_count' => AdminNotification::whereNull('read_at')->count()]);
    }
}
