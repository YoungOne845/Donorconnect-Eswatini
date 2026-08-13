<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class NotificationService
{
    public function __construct(private readonly SmsService $sms = new SmsService())
    {
    }

    public function create(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?int $requestId = null,
        ?int $campaignId = null,
        bool $sendSms = false
    ): int {
        $db = Database::connection();
        $statement = $db->prepare(
            "INSERT INTO notifications
             (user_id, request_id, campaign_id, notification_type, title, message, action_url, delivery_channel, delivery_status, sent_at)
             VALUES (:user_id, :request_id, :campaign_id, :type, :title, :message, :action_url, 'web', 'sent', NOW())"
        );
        $statement->execute([
            'user_id' => $userId,
            'request_id' => $requestId,
            'campaign_id' => $campaignId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
        ]);
        $notificationId = (int) $db->lastInsertId();

        if ($sendSms) {
            $userStatement = $db->prepare('SELECT phone FROM users WHERE id = :id LIMIT 1');
            $userStatement->execute(['id' => $userId]);
            $phone = $userStatement->fetchColumn();
            if (is_string($phone) && $phone !== '') {
                $this->sms->send($userId, $phone, $message);
            }
        }

        return $notificationId;
    }
}
