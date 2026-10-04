<?php
/**
 * Audit: faculty event-mapping reconciliation + end-to-end payload chain.
 * Uses an in-memory SQLite DB; never contacts Meta or sends messages.
 */
declare(strict_types=1);

putenv("PEPP_USE_SQLITE=1");
$_ENV['PEPP_USE_SQLITE'] = '1';

require_once __DIR__ . '/includes/communication/CommunicationHelper.php';
require_once __DIR__ . '/includes/communication/FacultySessionNotificationService.php';
require_once __DIR__ . '/includes/communication/FacultyMappingReconciler.php';

$passed = 0; $failed = 0;
function t(bool $c, string $name, string $d = ''): void {
    global $passed, $failed;
    if ($c) { $passed++; echo "  [PASS] $name" . ($d ? ": $d" : '') . "\n"; }
    else    { $failed++; echo "  [FAIL] $name" . ($d ? ": $d" : '') . "\n"; }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("
    CREATE TABLE admin_settings (setting_name TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE communication_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, channel TEXT DEFAULT 'whatsapp', template_name TEXT, language TEXT DEFAULT 'en', status TEXT, meta_data TEXT);
    CREATE TABLE communication_event_mappings (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT UNIQUE, template_name TEXT, parameter_mappings TEXT);
");

$body = function (int $n, ?string $btnUrl = null): string {
    $text = 'Body ' . implode(' ', array_map(fn($i) => '{{' . $i . '}}', range(1, $n)));
    $comps = [['type' => 'BODY', 'text' => $text]];
    if ($btnUrl) {
        $comps[] = ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Go', 'url' => $btnUrl]]];
    }
    return json_encode(['components' => $comps]);
};
// Live Meta shape: 6 / 2 / 5+URL / 1+URL / 4
$meta = [
    'faculty_session_scheduled' => [$body(6), 'approved'],
    'faculty_session_reminder'  => [$body(2), 'approved'],
    'faculty_session_start'     => [$body(5, 'https://meet.google.com/{{1}}'), 'pending'],
    'faculty_session_start_now' => [$body(1, 'https://meet.google.com/{{1}}'), 'approved'],
    'faculty_session_cancelled' => [$body(4), 'approved'],
];
$insT = $pdo->prepare("INSERT INTO communication_templates (template_name, status, meta_data) VALUES (?,?,?)");
foreach ($meta as $n => [$m, $s]) { $insT->execute([$n, $s, $m]); }

// Stale mappings exactly as seen on production (database-update-55)
$V = fn($a) => json_encode(array_map(fn($v) => ['type' => 'variable', 'value' => $v], array_combine(range(1, count($a)), $a)));
$stale = [
    'faculty_session_scheduled' => $V(['faculty_name','session_topic','session_type','session_datetime','session_courses','session_duration']),
    'faculty_session_reminder'  => $V(['faculty_name','session_topic','session_datetime','session_duration']),
    'faculty_session_start'     => $V(['faculty_name','session_topic','session_datetime','session_duration']),
    'faculty_session_start_now' => $V(['faculty_name','session_topic']),
    'faculty_session_cancelled' => $V(['faculty_name','session_datetime','session_topic']),
];
$insM = $pdo->prepare("INSERT INTO communication_event_mappings (event_name, template_name, parameter_mappings) VALUES (?,?,?)");
foreach ($stale as $e => $j) { $insM->execute([$e, $e, $j]); }
// Unrelated rows that must never change
$unrelated = [
    ['student_registration', 'pepp_admission_received', $V(['student_name','course_name','application_id'])],
    ['payment_receipt', 'pepp_payment_receipt', $V(['student_name','payment_amount'])],
    ['installment_reminder', 'pepp_installment_reminder', $V(['student_name','installment_number'])],
    ['birthday_greeting', 'pepp_birthday_greeting', $V(['student_name','claim_url'])],
];
foreach ($unrelated as $r) { $insM->execute($r); }
$snapUnrelated = $pdo->query("SELECT * FROM communication_event_mappings WHERE event_name NOT LIKE 'faculty_session_%' ORDER BY id")->fetchAll();
$tplSnap = $pdo->query("SELECT * FROM communication_templates ORDER BY id")->fetchAll();

echo "== Dry-run plan ==\n";
$rec = new FacultyMappingReconciler($pdo);
$plan = $rec->plan();
t(count($plan) === 5, 'plan covers exactly five events');
t(count(array_filter($plan, fn($p) => $p['ok'])) === 5, 'all five pass Meta-index cross-check');
t(count(array_filter($plan, fn($p) => $p['changed'])) === 5, 'all five detected as stale');
t(json_encode($pdo->query("SELECT parameter_mappings FROM communication_event_mappings WHERE event_name LIKE 'faculty_session_%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)) === json_encode(array_values($stale)), 'dry-run wrote nothing');

echo "\n== Apply ==\n";
$res = $rec->apply();
t($res['success'] === true && count($res['updated']) === 5, 'apply succeeds, 5 rows updated', json_encode($res['updated']));
t(strpos((string)$res['backup_key'], 'faculty_event_mapping_backup_') === 0, 'backup key created');
$bk = json_decode((string)$pdo->query("SELECT setting_value FROM admin_settings WHERE setting_name='" . $res['backup_key'] . "'")->fetchColumn(), true);
t(is_array($bk) && count($bk) === 5, 'backup holds the five original rows');
t(array_column($bk, 'parameter_mappings', 'event_name') == $stale, 'backup values equal the original stale values');

$expected = [
    'faculty_session_scheduled' => ['faculty_name','session_type','session_topic','session_datetime','session_courses','session_duration'],
    'faculty_session_reminder'  => ['faculty_name','session_datetime'],
    'faculty_session_start'     => ['faculty_name','session_datetime','session_topic','session_courses','session_duration'],
    'faculty_session_start_now' => ['faculty_name'],
    'faculty_session_cancelled' => ['faculty_name','session_datetime','session_topic','session_courses'],
];
foreach ($expected as $ev => $vars) {
    $row = $pdo->query("SELECT * FROM communication_event_mappings WHERE event_name='$ev'")->fetch();
    $pm = json_decode($row['parameter_mappings'], true);
    $got = array_map(fn($x) => $x['value'], $pm);
    ksort($got);
    t(array_values($got) === $vars && array_keys($pm) == range(1, count($vars)) && $row['template_name'] === $ev, "$ev mapping = " . count($vars) . ' params in Meta order');
    $idx = CommunicationHelper::getTemplateParameterDefinition($meta[$ev][0])['body']['indexes'];
    t($idx === range(1, count($vars)), "$ev mapping indexes == Meta body indexes");
}
t($pdo->query("SELECT * FROM communication_event_mappings WHERE event_name NOT LIKE 'faculty_session_%' ORDER BY id")->fetchAll() == $snapUnrelated, 'unrelated (student/payment/installment/birthday) mappings unchanged');
t($pdo->query("SELECT * FROM communication_templates ORDER BY id")->fetchAll() == $tplSnap, 'communication_templates untouched (faculty_session_start still pending)');
t($pdo->query("SELECT status FROM communication_templates WHERE template_name='faculty_session_start'")->fetchColumn() === 'pending', 'faculty_session_start status remains pending');

echo "\n== Idempotency / safety ==\n";
$res2 = $rec->apply();
t($res2['success'] && $res2['updated'] === [] && $res2['backup_key'] === null, 'second apply is a no-op (no extra backup)');
// Meta drift guard
$pdo->exec("UPDATE communication_templates SET meta_data='" . $body(3) . "' WHERE template_name='faculty_session_reminder'");
$pdo->exec("UPDATE communication_event_mappings SET parameter_mappings='{}' WHERE event_name='faculty_session_cancelled'");
$res3 = (new FacultyMappingReconciler($pdo))->apply();
t($res3['success'] === false && strpos((string)$res3['error'], 'differ from canonical') !== false, 'refuses to write when synced Meta differs from canonical map');
t($pdo->query("SELECT parameter_mappings FROM communication_event_mappings WHERE event_name='faculty_session_cancelled'")->fetchColumn() === '{}', 'blocked apply changed nothing');
$pdo->exec("UPDATE communication_templates SET meta_data='" . $body(2) . "' WHERE template_name='faculty_session_reminder'");

echo "\n== Restore ==\n";
$rr = $rec->restore($res['backup_key']);
t($rr['success'] === true, 'restore succeeds');
t(array_column($pdo->query("SELECT event_name, parameter_mappings FROM communication_event_mappings WHERE event_name LIKE 'faculty_session_%'")->fetchAll(), 'parameter_mappings', 'event_name') == $stale, 'restore returns original values');
t(($rec->restore('evil_key')['success'] ?? true) === false, 'restore rejects non-backup keys');

echo "\n== Service payload chain (no sends) ==\n";
$svc = new FacultySessionNotificationService($pdo, (new ReflectionClass(CommunicationEngine::class))->newInstanceWithoutConstructor());
$ctx = ['faculty_name'=>'N','session_type'=>'QPD','session_topic'=>'T','session_datetime'=>'D','session_courses'=>'C','session_duration'=>'1 hour'];
$expPayload = [
    'faculty_session_scheduled' => ['N','QPD','T','D','C','1 hour'],
    'faculty_session_reminder'  => ['N','D'],
    'faculty_session_start'     => ['N','D','T','C','1 hour'],
    'faculty_session_start_now' => ['N'],
    'faculty_session_cancelled' => ['N','D','T','C'],
];
foreach ($expPayload as $tpl => $vals) {
    t($svc->resolveTemplateParameters($tpl, $ctx) === $vals, "$tpl payload " . count($vals) . ' params in order');
}
t($svc->extractMeetButtonParam('https://meet.google.com/abc-defg-hij') === 'abc-defg-hij', 'Meet CTA param is the code only (fills https://meet.google.com/{{1}})');
t(strpos($svc->extractMeetButtonParam('https://meet.google.com/abc-defg-hij'), 'http') === false, 'full URL not passed as a param');

echo "\n------------------------------------------------\nPassed: $passed  Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
