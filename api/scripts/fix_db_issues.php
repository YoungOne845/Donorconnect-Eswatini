<?php

require __DIR__ . '/../bootstrap.php';

use App\Core\Database;

try {
    $db = Database::connection();
    echo "Connected to database.\n";

    // 1. Inspect audit_logs columns
    $stmt = $db->query("DESCRIBE audit_logs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $idCol = null;
    foreach ($columns as $col) {
        if ($col['Field'] === 'id') {
            $idCol = $col;
            break;
        }
    }

    if (!$idCol) {
        echo "Error: audit_logs table or id column not found!\n";
        exit(1);
    }

    echo "Current 'id' column details:\n";
    print_r($idCol);

    if (strpos(strtolower($idCol['Extra']), 'auto_increment') === false) {
        echo "Found that 'id' is missing AUTO_INCREMENT. Proceeding to fix...\n";

        // Let's check if there are duplicate 0 values
        $stmt = $db->query("SELECT COUNT(*) as count FROM audit_logs WHERE id = 0");
        $zeroCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "Number of rows in audit_logs with id = 0: $zeroCount\n";

        if ($zeroCount > 0) {
            echo "Updating existing rows with id = 0 to avoid duplicates...\n";
            // We can temporarily disable foreign key checks, but audit_logs doesn't have child tables.
            // Let's fetch all rows, and update their id sequentially starting from 1, or let's use a temporary table/id update.
            // A simpler way: we can delete rows where id = 0, or update their id to a new max value.
            // Let's get the max ID
            $stmt = $db->query("SELECT MAX(id) as max_id FROM audit_logs");
            $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
            if ($maxId === 0) {
                $maxId = 1;
            }
            
            // Fetch all rows where id = 0 or duplicates
            $stmt = $db->query("SELECT * FROM audit_logs WHERE id = 0");
            $zeroRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $updateStmt = $db->prepare("UPDATE audit_logs SET id = :new_id WHERE id = 0 LIMIT 1");
            foreach ($zeroRows as $row) {
                $maxId++;
                $updateStmt->execute(['new_id' => $maxId]);
                echo "Updated a row with id = 0 to new id: $maxId\n";
            }
        }

        // Alter table to add AUTO_INCREMENT
        echo "Altering table to add AUTO_INCREMENT to id...\n";
        $db->exec("ALTER TABLE audit_logs MODIFY COLUMN id BIGINT UNSIGNED AUTO_INCREMENT");
        echo "Altered successfully!\n";

        // Double check details
        $stmt = $db->query("DESCRIBE audit_logs");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            if ($col['Field'] === 'id') {
                echo "New 'id' column details:\n";
                print_r($col);
                break;
            }
        }
    } else {
        echo "audit_logs.id already has AUTO_INCREMENT.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
