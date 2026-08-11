<?php
session_start();
if (isset($_GET['check'])) {
    header('Content-Type: text/plain');
    echo "SESSION DATA:\n";
    print_r($_SESSION);
    exit;
}

$_SESSION['test_key'] = 'hello_session_world';
$cookieStr = session_name() . '=' . session_id() . ';';
session_write_close();

$ch = curl_init("https://pepplearning.in/admissions/debug-session-test.php?check=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
$res = curl_exec($ch);
curl_close($ch);

echo "CURL RESPONSE:\n" . $res;
exit;
