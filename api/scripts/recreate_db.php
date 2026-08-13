<?php

require __DIR__ . '/../bootstrap.php';

use App\Core\Database;

try {
    $db = Database::connection();
    echo "Connected to MySQL database.\n";

    // Disable foreign keys temporarily
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Read the schema file
    $schemaPath = __DIR__ . '/../../database/schema.sql';
    if (!file_exists($schemaPath)) {
        throw new Exception("Schema file not found at: $schemaPath");
    }

    $sql = file_get_contents($schemaPath);
    echo "Loaded schema.sql (size: " . strlen($sql) . " bytes).\n";

    // Split SQL by query
    // A simple regex split on ";" followed by newline/whitespace.
    // This works well for schema.sql where queries are delimited by semicolon.
    $queries = preg_split('/;\s*$/m', $sql);

    echo "Executing schema statements...\n";
    $stmtCount = 0;
    foreach ($queries as $query) {
        $query = trim($query);
        if ($query === '') {
            continue;
        }

        try {
            $db->exec($query);
            $stmtCount++;
        } catch (PDOException $ex) {
            // Check if it's a minor error like dropping a view/table that doesn't exist
            if (strpos($ex->getMessage(), "Unknown table") !== false || strpos($ex->getMessage(), "Unknown view") !== false) {
                // Ignore drop warnings
                continue;
            }
            echo "Error executing statement #$stmtCount: " . $ex->getMessage() . "\n";
            echo "SQL: " . substr($query, 0, 150) . "...\n";
            throw $ex;
        }
    }

    echo "Successfully executed $stmtCount SQL schema statements.\n";

    // Apply the phone secondary migration if it isn't in schema
    echo "Applying phone_secondary column migration...\n";
    try {
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_secondary VARCHAR(20) NULL AFTER phone");
        echo "phone_secondary column checked/added.\n";
    } catch (Exception $ex) {
        echo "Note on phone_secondary: " . $ex->getMessage() . "\n";
    }

    // Enable foreign keys
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "Foreign key checks re-enabled.\n";

    // Verify a few tables to check primary keys
    foreach (['users', 'donor_profiles', 'donation_records'] as $table) {
        $stmt = $db->query("DESCRIBE $table");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $idCol = null;
        foreach ($cols as $c) {
            if ($c['Field'] === 'id') {
                $idCol = $c;
                break;
            }
        }
        echo "Table '$table' 'id' column: " . json_encode($idCol) . "\n";
    }

} catch (Exception $e) {
    echo "FATAL ERROR recreating database: " . $e->getMessage() . "\n";
    exit(1);
}
