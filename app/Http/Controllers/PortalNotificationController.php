<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web portal notifications (HR, employee, partner): the bell and the pop-up
 * shown after sign-in. Same table the mobile app reads, so a notice is seen
 * wherever the person is signed in.
 */
class PortalNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Notification::where('user_id', $request->user()->id)
            ->when($request->boolean('unread_only'), fn ($q) => $q->where('is_read', false))
            ->latest()
            ->limit(min(100, max(1, (int) $request->query('limit', 30))))
            ->get();

        return response()->json([
            'data' => $items->map(fn (Notification $n) => [
                'id'         => $n->id,
                'title'      => $n->title,
                'body'       => $n->body,
                'kind'       => $n->data['kind'] ?? $n->type,
                'link'       => self::portalLink($n->data['link'] ?? null),
                'is_read'    => (bool) $n->is_read,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'unread' => Notification::where('user_id', $request->user()->id)->where('is_read', false)->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => Notification::where('user_id', $request->user()->id)->where('is_read', false)->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Stored links may be absolute frontend URLs; the SPA needs the path */
    private static function portalLink(?string $link): ?string
    {
        if (!$link) return null;
        $base = rtrim((string) config('app.frontend_url'), '/');
        if ($base && str_starts_with($link, $base)) {
            $link = substr($link, strlen($base)) ?: '/';
        }

        return str_starts_with($link, '/') ? $link : null;
    }
}
