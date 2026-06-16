<?php
// Simple diagnostic test for installment payment page
echo "<!DOCTYPE html>";
echo "<html><head><title>Test - Installment Payments</title></head><body>";
echo "<h1>PHP Test - Installment Payment Management</h1>";
echo "<p>If you can see this message, PHP is working correctly.</p>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Test database connection
try {
    $host = 'localhost';
    $dbname = 'pepp_learning';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}

// Test session
session_start();
echo "<p>Session ID: " . session_id() . "</p>";

echo "<hr>";
echo "<p><a href='phpinstalmentpaymentupdate.php'>Try accessing the main installment payment page</a></p>";
echo "</body></html>";
?>
