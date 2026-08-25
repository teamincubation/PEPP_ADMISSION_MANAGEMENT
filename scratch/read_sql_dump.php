<?php
$sql_file = __DIR__ . '/../u361910773_peppadmin.sql';

if (!file_exists($sql_file)) {
    die("SQL dump file not found at " . $sql_file . "\n");
}

echo "Reading SQL dump file...\n";
$content = file_get_contents($sql_file);

// 1. Extract INSERT INTO assessment_result_batches statements
echo "\n--- SEARCHING FOR assessment_result_batches INSERTS ---\n";
if (preg_match_all('/INSERT INTO `?assessment_result_batches`?[^;]+;/i', $content, $matches)) {
    foreach ($matches[0] as $match) {
        echo substr($match, 0, 1000) . "...\n\n";
    }
} else {
    echo "No inserts found for assessment_result_batches.\n";
}

// 2. Extract INSERT INTO study_plans statements
echo "\n--- SEARCHING FOR study_plans INSERTS ---\n";
if (preg_match_all('/INSERT INTO `?study_plans`?[^;]+;/i', $content, $matches)) {
    foreach ($matches[0] as $match) {
        echo substr($match, 0, 1000) . "...\n\n";
    }
} else {
    echo "No inserts found for study_plans.\n";
}

// 3. Extract INSERT INTO study_plan_activities statements
echo "\n--- SEARCHING FOR study_plan_activities INSERTS ---\n";
if (preg_match_all('/INSERT INTO `?study_plan_activities`?[^;]+;/i', $content, $matches)) {
    foreach ($matches[0] as $match) {
        echo substr($match, 0, 1000) . "...\n\n";
    }
} else {
    echo "No inserts found for study_plan_activities.\n";
}
