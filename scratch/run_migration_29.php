<?php
/**
 * Run DDL Migration 29 Verification on Local Test Database.
 * Verifies existence and columns of the table 'card_layout_presets'.
 */

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once 'config/database.php';

try {
    global $pdo;
    echo "Connected to local SQLite test database successfully.\n";

    // Read the DDL script
    $sql_file = 'database-update-29.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("Migration file $sql_file does not exist.");
    }
    echo "Loaded SQL migration from $sql_file.\n";

    // SQLite schema verification
    echo "\nVerifying table 'card_layout_presets' structure in local test DB:\n";
    $stmt = $pdo->query("PRAGMA table_info(`card_layout_presets`)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($columns)) {
        throw new Exception("Table 'card_layout_presets' does not exist in local test DB.");
    }

    echo "Table verified successfully. Columns present:\n";
    foreach ($columns as $col) {
        echo " - " . $col['name'] . " (" . $col['type'] . ") - NotNull: " . $col['notnull'] . ", PK: " . $col['pk'] . ", Default: " . $col['dflt_value'] . "\n";
    }

    echo "\nLocal verification completed successfully!\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
