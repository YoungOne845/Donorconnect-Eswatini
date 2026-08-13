<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Identity;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\SmsService;

final class NotificationController
{
    public function index(Request $request): never
    {
        $user = App::auth()->requireUser();
        $db = Database::connection();
        $statement = $db->prepare(
            'SELECT id, notification_type, title, message, action_url, delivery_channel, delivery_status, is_read, sent_at, read_at, created_at
             FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 100'
        );
        $statement->execute(['user_id' => $user['id']]);
        Response::success('Notifications loaded.', $statement->fetchAll());
    }

    public function markRead(Request $request): never
    {
        $user = App::auth()->requireUser();
        $id = (int) $request->param('id');
        $db = Database::connection();
        $statement = $db->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = :id AND user_id = :user_id");
        $statement->execute(['id' => $id, 'user_id' => $user['id']]);
        if ($statement->rowCount() === 0) {
            $exists = $db->prepare('SELECT id FROM notifications WHERE id = :id AND user_id = :user_id');
            $exists->execute(['id' => $id, 'user_id' => $user['id']]);
            if (!$exists->fetch()) throw new HttpException(404, 'Notification not found.');
        }
        Response::success('Notification marked as read.');
    }

    public function markAllRead(Request $request): never
    {
        $user = App::auth()->requireUser();
        $statement = Database::connection()->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE user_id = :user_id AND is_read = 0");
        $statement->execute(['user_id' => $user['id']]);
        Response::success('All notifications marked as read.', ['updated' => $statement->rowCount()]);
    }

    public function delete(Request $request): never
    {
        $user = App::auth()->requireUser();
        $id = (int) $request->param('id');
        $db = Database::connection();
        $statement = $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :user_id");
        $statement->execute(['id' => $id, 'user_id' => $user['id']]);
        if ($statement->rowCount() === 0) {
            throw new HttpException(404, 'Notification not found.');
        }
        Response::success('Notification deleted.');
    }

    public function deleteAll(Request $request): never
    {
        $user = App::auth()->requireUser();
        $statement = Database::connection()->prepare("DELETE FROM notifications WHERE user_id = :user_id");
        $statement->execute(['user_id' => $user['id']]);
        Response::success('All notifications deleted.', ['deleted' => $statement->rowCount()]);
    }

    public function unreadCount(Request $request): never
    {
        $user = App::auth()->requireUser();
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0'
        );
        $statement->execute(['user_id' => $user['id']]);
        Response::success('Unread count loaded.', ['count' => (int) $statement->fetchColumn()]);
    }

    public function recent(Request $request): never
    {
        $user = App::auth()->requireUser();
        $statement = Database::connection()->prepare(
            'SELECT id, notification_type, title, message, action_url, is_read, created_at
             FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 8'
        );
        $statement->execute(['user_id' => $user['id']]);
        Response::success('Recent notifications loaded.', $statement->fetchAll());
    }


    public function testSms(Request $request): never
    {
        $operator = App::auth()->requireRoles(['hospital','staff','admin']);
        $data = $request->json();

        (new Validator())
            ->required($data, ['phone','message'])
            ->string($data, 'phone', 8, 20)
            ->string($data, 'message', 3, 320)
            ->validate();

        $phone = Identity::phone((string) $data['phone']);
        if (!Identity::validEswatiniPhone($phone)) {
            throw new HttpException(422, 'Enter a valid Eswatini mobile number.', ['phone' => 'Use a number like 76123456 or +26876123456.']);
        }

        $message = trim((string) $data['message']);
        $result = (new SmsService())->send((int) $operator['id'], $phone, $message);

        if (($result['status'] ?? '') !== 'sent') {
            throw new HttpException(
                502,
                'SMS was not delivered by the configured provider. Check api/.env Twilio settings and SMS logs.',
                ['sms' => $result['error'] ?? 'Unknown SMS provider error.']
            );
        }

        Response::success('Real SMS sent.', [
            'phone' => $phone,
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'status' => $result['status'],
        ]);
    }

}
