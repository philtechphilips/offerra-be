<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $notifications = $request->user()->notifications()->latest()->limit(50)->get();
            return response()->json([
                'notifications' => $notifications,
                'unread_count' => $request->user()->unreadNotifications()->count()
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
