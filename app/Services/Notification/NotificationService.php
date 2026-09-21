<?php

namespace App\Services\Notification;

use Illuminate\Http\Request;

interface NotificationService
{
    public function saveDeviceToken(Request $request);
    public function notifications(Request $request);
    public function unreadCount(Request $request);
    public function markAsRead(Request $request, int $notificationId);
    public function markAllAsRead(Request $request);
    public function saveNotification(int $userId, string $title, string $body);
    public function sendNotification(int $userId, string $title, string $body);
}

