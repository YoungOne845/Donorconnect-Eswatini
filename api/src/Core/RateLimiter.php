<?php

declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    public static function hit(string $action, string $identity, int $maxAttempts, int $windowMinutes): void
    {
        $db = Database::connection();
        $cutoff = (new \DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');

        $cleanup = $db->prepare('DELETE FROM rate_limit_events WHERE occurred_at < :cutoff');
        $cleanup->execute(['cutoff' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s')]);

        $count = $db->prepare(
            'SELECT COUNT(*) FROM rate_limit_events WHERE action_key = :action AND identity_key = :identity AND occurred_at >= :cutoff'
        );
        $count->execute(['action' => $action, 'identity' => hash('sha256', $identity), 'cutoff' => $cutoff]);
        if ((int) $count->fetchColumn() >= $maxAttempts) {
            throw new HttpException(429, 'Too many attempts. Please wait before trying again.');
        }

        $insert = $db->prepare('INSERT INTO rate_limit_events (action_key, identity_key) VALUES (:action, :identity)');
        $insert->execute(['action' => $action, 'identity' => hash('sha256', $identity)]);
    }
}
