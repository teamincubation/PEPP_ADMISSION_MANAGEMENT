<?php
// Template Mapping Diagnostic Script
$_SERVER['SERVER_NAME'] = 'localhost'; // Ensure SQLite connection
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/communication/CommunicationHelper.php';

try {
    echo "=== Running Template Mappings Diagnostics ===\n\n";

    // Ensure mock tables exist for diagnostics
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS communication_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT,
            template_name TEXT UNIQUE,
            language TEXT,
            status TEXT,
            category TEXT,
            meta_data TEXT,
            updated_at TEXT
        );
        CREATE TABLE IF NOT EXISTS communication_event_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_name TEXT UNIQUE,
            template_name TEXT,
            parameter_mappings TEXT,
            updated_at TEXT
        );
    ");

    // Let's seed some mock mappings in SQLite memory DB for testing diagnostics if empty
    $countTpl = (int)$pdo->query("SELECT COUNT(*) FROM communication_templates")->fetchColumn();
    if ($countTpl === 0) {
        echo "SQLite DB empty. Seeding mock templates and mappings for diagnostic verification...\n";
        
        $tpl1Meta = [
            'components' => [
                ['type' => 'BODY', 'text' => 'Dear {{1}}, welcome to {{2}}. Fee: {{3}}']
            ]
        ];
        $pdo->prepare("INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data) VALUES ('whatsapp', 'pepp_admission_approved', 'en', 'approved', 'utility', ?)")->execute([json_encode($tpl1Meta)]);
        $pdo->prepare("INSERT INTO communication_event_mappings (event_name, template_name, parameter_mappings) VALUES ('student_approval', 'pepp_admission_approved', ?)")->execute([json_encode([
            1 => ['type' => 'variable', 'value' => 'student_name'],
            2 => ['type' => 'variable', 'value' => 'current_course_name'],
            3 => ['type' => 'variable', 'value' => 'registration_fee']
        ])]);

        $tpl2Meta = [
            'components' => [
                ['type' => 'BODY', 'text' => 'Reminder: {{1}} due on {{2}}']
            ]
        ];
        $pdo->prepare("INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data) VALUES ('whatsapp', 'pepp_installment_reminder', 'en', 'approved', 'utility', ?)")->execute([json_encode($tpl2Meta)]);
        $pdo->prepare("INSERT INTO communication_event_mappings (event_name, template_name, parameter_mappings) VALUES ('installment_reminder', 'pepp_installment_reminder', ?)")->execute([json_encode([
            1 => ['type' => 'variable', 'value' => 'student_name'],
            2 => ['type' => 'variable', 'value' => 'invalid_key_test'] // Invalid key for test
        ])]);
    }

    $variables = CommunicationHelper::getERPVariables();

    $stmt = $pdo->query("SELECT * FROM communication_templates");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unmappedCountTotal = 0;
    $invalidCountTotal = 0;

    foreach ($templates as $tpl) {
        $tplName = $tpl['template_name'];
        echo "Template: {$tplName}\n";

        // Count Meta Variables by finding {{number}} in components
        $meta = json_decode($tpl['meta_data'], true) ?: [];
        $metaVars = [];
        if (isset($meta['components']) && is_array($meta['components'])) {
            foreach ($meta['components'] as $comp) {
                if (isset($comp['text'])) {
                    preg_match_all('/\{\{(\d+)\}\}/', $comp['text'], $matches);
                    if (!empty($matches[1])) {
                        foreach ($matches[1] as $m) {
                            $metaVars[] = (int)$m;
                        }
                    }
                }
            }
        }
        $metaCount = empty($metaVars) ? 0 : max($metaVars);
        echo "  Meta Variables (Positions): {$metaCount}\n";

        // Fetch mapping
        $stmtMap = $pdo->prepare("SELECT * FROM communication_event_mappings WHERE template_name = ? LIMIT 1");
        $stmtMap->execute([$tplName]);
        $mapping = $stmtMap->fetch(PDO::FETCH_ASSOC);

        $mappedCount = 0;
        $unmappedCount = 0;
        $invalidCount = 0;
        $mappedKeys = [];

        $pMappings = [];
        if ($mapping && !empty($mapping['parameter_mappings'])) {
            $pMappings = json_decode($mapping['parameter_mappings'], true) ?: [];
        }

        for ($i = 1; $i <= $metaCount; $i++) {
            if (isset($pMappings[$i])) {
                $mapType = $pMappings[$i]['type'] ?? 'variable';
                $mapVal = $pMappings[$i]['value'] ?? '';

                if ($mapType === 'custom') {
                    $mappedCount++;
                    $mappedKeys[$i] = "Custom Text ('{$mapVal}')";
                } elseif (!empty($mapVal)) {
                    if (array_key_exists($mapVal, $variables)) {
                        $mappedCount++;
                        $mappedKeys[$i] = $mapVal;
                    } else {
                        $invalidCount++;
                        $mappedKeys[$i] = "{$mapVal} (INVALID)";
                    }
                } else {
                    $unmappedCount++;
                    $mappedKeys[$i] = "Not Mapped";
                }
            } else {
                $unmappedCount++;
                $mappedKeys[$i] = "Not Mapped";
            }
        }

        echo "  Mapped: {$mappedCount}\n";
        echo "  Unmapped: {$unmappedCount}\n";
        echo "  Invalid: {$invalidCount}\n";
        echo "  Mappings:\n";
        foreach ($mappedKeys as $pos => $key) {
            echo "    {{{$pos}}} -> {$key}\n";
        }
        echo "\n";

        $unmappedCountTotal += $unmappedCount;
        $invalidCountTotal += $invalidCount;
    }

    if ($unmappedCountTotal > 0 || $invalidCountTotal > 0) {
        echo "⚠️ DIAGNOSTIC WARNING: Found unmapped or invalid parameters in templates mappings.\n";
    } else {
        echo "✓ DIAGNOSTIC SUCCESS: All template mappings are 100% complete and valid.\n";
    }

} catch (Exception $e) {
    echo "❌ Diagnostic Exception: " . $e->getMessage() . "\n";
}
