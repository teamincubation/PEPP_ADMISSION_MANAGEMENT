<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';
$stmt = $pdo->prepare("SELECT id, user_id, name, whatsapp_country_code, whatsapp_number, status FROM users ORDER BY id DESC LIMIT 5");
$stmt->execute();
print_r($stmt->fetchAll());
exit;
