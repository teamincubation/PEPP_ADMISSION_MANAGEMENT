<?php
/**
 * PEPP ERP — FILTER FIELD LAYOUT & RESPONSIVE ALIGNMENT AUDIT TEST SUITE
 * 
 * Tests that filter and search controls on student-mentoring.php, studyplans.php,
 * and employee-management.php are correctly aligned horizontally for desktop
 * and responsive for medium/mobile screens.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$passed = 0;
$failed = 0;

function assert_true($condition, $message, $extra = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}" . ($extra ? " — {$extra}" : "") . "\n";
    }
}

echo "======================================================================\n";
echo "PEPP ERP — HORIZONTAL FILTER LAYOUT AUDIT TEST SUITE\n";
echo "======================================================================\n\n";

$theme_css = file_get_contents(__DIR__ . '/assets/css/admin-theme.css');
$mentoring_php = file_get_contents(__DIR__ . '/student-mentoring.php');
$studyplans_php = file_get_contents(__DIR__ . '/studyplans.php');
$employee_php = file_get_contents(__DIR__ . '/employee-management.php');

// ── TEST GROUP 1: Shared Theme CSS Utility Classes ────────────────────
echo "--- [TEST GROUP 1] Shared Theme CSS Utility Classes ---\n";

assert_true(
    strpos($theme_css, '.filter-toolbar') !== false,
    "1. admin-theme.css defines .filter-toolbar class"
);

assert_true(
    strpos($theme_css, 'display: flex') !== false && strpos($theme_css, 'flex-wrap: wrap') !== false,
    "2. admin-theme.css provides flex-wrap and horizontal display"
);

assert_true(
    strpos($theme_css, '@media (max-width: 768px)') !== false,
    "3. admin-theme.css contains responsive mobile breakpoint"
);

// ── TEST GROUP 2: student-mentoring.php Layout & Controls ─────────────
echo "\n--- [TEST GROUP 2] student-mentoring.php Layout & Filter Integrity ---\n";

assert_true(
    strpos($mentoring_php, 'id="student-search-input"') !== false,
    "4. student-mentoring.php preserves student search input"
);

assert_true(
    strpos($mentoring_php, 'id="filter-performance"') !== false,
    "5. student-mentoring.php preserves filter-performance select"
);

assert_true(
    strpos($mentoring_php, 'id="filter-streak"') !== false,
    "6. student-mentoring.php preserves filter-streak select"
);

assert_true(
    strpos($mentoring_php, 'id="filter-completed"') !== false,
    "7. student-mentoring.php preserves filter-completed select"
);

assert_true(
    strpos($mentoring_php, 'id="filter-pending"') !== false,
    "8. student-mentoring.php preserves filter-pending select"
);

assert_true(
    strpos($mentoring_php, 'id="filter-overdue"') !== false,
    "9. student-mentoring.php preserves filter-overdue select"
);

assert_true(
    strpos($mentoring_php, 'id="filter-attendance"') !== false,
    "10. student-mentoring.php preserves filter-attendance select"
);

assert_true(
    strpos($mentoring_php, 'resetStudentFilters()') !== false,
    "11. student-mentoring.php preserves resetStudentFilters() action"
);

assert_true(
    strpos($mentoring_php, 'width:100% !important') !== false && strpos($mentoring_php, 'width:auto !important') !== false,
    "12. student-mentoring.php overrides unconditional full-width with horizontal flex properties"
);

// ── TEST GROUP 3: studyplans.php Layout & Controls ────────────────────
echo "\n--- [TEST GROUP 3] studyplans.php Layout & Filter Integrity ---\n";

assert_true(
    strpos($studyplans_php, 'name="search"') !== false,
    "13. studyplans.php preserves search input"
);

assert_true(
    strpos($studyplans_php, 'name="status"') !== false,
    "14. studyplans.php preserves status select filter"
);

assert_true(
    strpos($studyplans_php, 'name="course_id"') !== false,
    "15. studyplans.php preserves course_id select filter"
);

assert_true(
    strpos($studyplans_php, 'Apply Filters') !== false,
    "16. studyplans.php preserves Apply Filters submit button"
);

assert_true(
    strpos($studyplans_php, 'href="studyplans.php"') !== false,
    "17. studyplans.php preserves Reset filter link"
);

assert_true(
    strpos($studyplans_php, 'flex:1; min-width:280px') !== false || strpos($studyplans_php, 'flex: 1') !== false || strpos($studyplans_php, 'flex:1') !== false,
    "18. studyplans.php filter form has horizontal flex sizing"
);

// ── TEST GROUP 4: employee-management.php Layout & Controls ───────────
echo "\n--- [TEST GROUP 4] employee-management.php Layout & Filter Integrity ---\n";

assert_true(
    strpos($employee_php, 'name="search"') !== false,
    "19. employee-management.php preserves staff search input"
);

assert_true(
    strpos($employee_php, 'name="status"') !== false,
    "20. employee-management.php preserves staff status select filter"
);

assert_true(
    strpos($employee_php, 'name="type"') !== false,
    "21. employee-management.php preserves staff type select filter"
);

assert_true(
    strpos($employee_php, 'name="link"') !== false,
    "22. employee-management.php preserves admin link select filter"
);

assert_true(
    strpos($employee_php, 'type="submit"') !== false && strpos($employee_php, 'Filter') !== false,
    "23. employee-management.php preserves Filter submit button"
);

assert_true(
    strpos($employee_php, 'href="?tab=employees"') !== false,
    "24. employee-management.php preserves Reset filters action"
);

assert_true(
    strpos($employee_php, 'filter-toolbar') !== false,
    "25. employee-management.php uses dedicated filter-toolbar container"
);

echo "\n======================================================================\n";
echo "HORIZONTAL FILTER LAYOUT AUDIT: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
