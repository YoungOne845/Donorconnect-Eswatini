<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;

final class SmsService
{
    public function send(?int $userId, string $phone, string $message): array
    {
        $driver = strtolower((string) Env::get('SMS_DRIVER', 'log'));
        $providerMessageId = null;
        $status = 'queued';
        $error = null;

        if ($driver === 'twilio') {
            [$status, $providerMessageId, $error] = $this->sendViaTwilio($phone, $message);
        } else {
            $status = 'sent';
            $this->writeToLog($phone, $message);
        }

        $db = Database::connection();
        $statement = $db->prepare(
            "INSERT INTO sms_logs (user_id, phone, message, provider, provider_message_id, status, error_message, sent_at)
             VALUES (:user_id, :phone, :message, :provider, :provider_message_id, :status, :error_message, :sent_at)"
        );
        $statement->execute([
            'user_id' => $userId,
            'phone' => $phone,
            'message' => $message,
            'provider' => $driver,
            'provider_message_id' => $providerMessageId,
            'status' => $status,
            'error_message' => $error,
            'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ]);

        return ['status' => $status, 'provider_message_id' => $providerMessageId, 'error' => $error];
    }

    private function sendViaTwilio(string $phone, string $message): array
    {
        $sid = (string) Env::get('TWILIO_ACCOUNT_SID', '');
        $token = (string) Env::get('TWILIO_AUTH_TOKEN', '');
        $from = (string) Env::get('TWILIO_FROM_NUMBER', '');
        if ($sid === '' || $token === '' || $from === '') {
            return ['failed', null, 'Twilio credentials are not configured.'];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$sid}:{$token}",
            CURLOPT_POSTFIELDS => http_build_query(['To' => $phone, 'From' => $from, 'Body' => $message]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['failed', null, $curlError !== '' ? $curlError : "Twilio returned HTTP {$httpCode}."];
        }

        $payload = json_decode($response, true);
        return ['sent', $payload['sid'] ?? null, null];
    }

    private function writeToLog(string $phone, string $message): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory . '/sms.log';
        file_put_contents($path, sprintf("[%s] %s | %s\n", date('c'), $phone, $message), FILE_APPEND | LOCK_EX);
    }
}
