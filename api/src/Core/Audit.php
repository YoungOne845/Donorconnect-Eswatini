<?php

declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(
        string $action,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        mixed $oldValues = null,
        mixed $newValues = null,
        ?Request $request = null
    ): void {
        $db = Database::connection();
        $user = App::auth()->user();
        $statement = $db->prepare(
            "INSERT INTO audit_logs
             (user_id, action, entity_type, entity_id, description, old_values, new_values, ip_address, user_agent)
             VALUES (:user_id, :action, :entity_type, :entity_id, :description, :old_values, :new_values, :ip_address, :user_agent)"
        );
        $statement->execute([
            'user_id' => $user['id'] ?? null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $request?->ip() ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => $request?->userAgent() ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }
}
