<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';
    echo "ADMINS:\n";
    $stmt = $pdo->prepare("SELECT * FROM admins");
    $stmt->execute();
    print_r($stmt->fetchAll());
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage();
}
exit;
