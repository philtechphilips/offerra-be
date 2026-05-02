<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $user = $request->user();

            // Paginated mode: used by the full activity page.
            if ($request->query('paginate') === 'true' || $request->query('per_page')) {
                $perPage = (int) $request->query('per_page', 20);
                $page = (int) $request->query('page', 1);
                $filter = $request->query('filter');

                $query = $user->notifications();
                if ($filter === 'unread') {
                    $query = $user->unreadNotifications();
                } elseif (in_array($filter, ['info', 'success', 'warning', 'error'], true)) {
                    $query = $user->notifications()->where('data->type', $filter);
                }

                $paginated = $query->latest()->paginate($perPage, ['*'], 'page', $page);

                return response()->json([
                    'data' => $paginated->items(),
                    'unread_count' => $user->unreadNotifications()->count(),
                    'meta' => [
                        'current_page' => $paginated->currentPage(),
                        'last_page' => $paginated->lastPage(),
                        'per_page' => $paginated->perPage(),
                        'total' => $paginated->total(),
                        'has_more' => $paginated->hasMorePages(),
                    ],
                ]);
            }

            // Default mode (used by the navbar dropdown): latest 50 only.
            $notifications = $user->notifications()->latest()->limit(50)->get();
            return response()->json([
                'notifications' => $notifications,
                'unread_count' => $user->unreadNotifications()->count(),
            ]);
        }, 'NotificationController@index');
    }

    public function markAsRead(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
            $notification = $request->user()->notifications()->findOrFail($id);
            $notification->markAsRead();

            return response()->json(['message' => 'Notification marked as read']);
        }, 'NotificationController@markAsRead');
    }

    public function markAllAsRead(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $request->user()->unreadNotifications->markAsRead();
            return response()->json(['message' => 'All notifications marked as read']);
        }, 'NotificationController@markAllAsRead');
    }

    public function destroy(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
            $notification = $request->user()->notifications()->findOrFail($id);
            $notification->delete();

            return response()->json(['message' => 'Notification deleted']);
        }, 'NotificationController@destroy');
    }
}
