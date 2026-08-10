<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = 'superadmin';
$_SESSION['admin_role']      = 'super_admin';

ob_start();
include 'cards.php';
$htmlCards = ob_get_clean();

echo "cards.php length: " . strlen($htmlCards) . "\n";
if (strpos($htmlCards, 'Card Templates') !== false || strpos($htmlCards, 'Card') !== false) {
    echo "SUCCESS: cards.php rendered perfectly!\n";
} else {
    echo "ERROR in cards.php rendering\n";
}
