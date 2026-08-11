<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'tempadmin';
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['last_activity'] = time();
$cookieStr = session_name() . '=' . session_id() . ';';
session_write_close();

$url = "https://pepplearning.in/admissions/api/v1/communication/fetch-conversations.php?filter=all&search=&v=" . time();
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
curl_setopt($ch, CURLOPT_HEADER, true);
$res = curl_exec($ch);
curl_close($ch);

echo "RESPONSE:\n" . $res . "\n";
exit;
