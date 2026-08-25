<?php
require_once 'includes/auth.php';
require_permission('studyplans');
require_once 'config/database.php';

$plan_id = (int)($_GET['id'] ?? 0);
$plan = null;
$assigned = [];
$activities_json = '[]';

if ($plan_id > 0) {
    // Fetch existing plan
    $stmt = $pdo->prepare("SELECT * FROM study_plans WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch();
    if (!$plan) {
        die("<h3>Study plan not found</h3>");
    }

    // Fetch assignments
    $stmt_assign = $pdo->prepare("SELECT * FROM study_plan_assignments WHERE study_plan_id = ?");
    $stmt_assign->execute([$plan_id]);
    $assigned = $stmt_assign->fetchAll();

    // Fetch activities (non-deleted only)
    $stmt_act = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 ORDER BY activity_date ASC, sort_order ASC");
    $stmt_act->execute([$plan_id]);
    $activities = $stmt_act->fetchAll();
    $activities_json = json_encode($activities);
}

// Fetch active courses
$courses = $pdo->query("SELECT * FROM pepp_courses WHERE status = 'active' ORDER BY course_name ASC")->fetchAll();
// Fetch active academic years
$years = $pdo->query("SELECT year FROM academic_years WHERE status = 'active' ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
// Fetch active campaign forms
$campaign_forms = $pdo->query("SELECT * FROM campaign_forms WHERE status = 'published' ORDER BY title ASC")->fetchAll();

// Predefined activity types
$default_types = [
    'Read Material' => ['icon' => 'fa-book-open', 'color' => '#3b82f6', 'badge' => 'Read'],
    'Watch Live Session' => ['icon' => 'fa-video', 'color' => '#ef4444', 'badge' => 'Live'],
    'Watch Recorded Session' => ['icon' => 'fa-play', 'color' => '#8b5cf6', 'badge' => 'Recorded'],
    'Attend Mock Test' => ['icon' => 'fa-file-signature', 'color' => '#f59e0b', 'badge' => 'Mock'],
    'Attend Mega Test' => ['icon' => 'fa-trophy', 'color' => '#ec4899', 'badge' => 'Mega'],
    'Attend Weekly Test' => ['icon' => 'fa-calendar-check', 'color' => '#06b6d4', 'badge' => 'Weekly'],
    'Practice Test' => ['icon' => 'fa-dumbbell', 'color' => '#10b981', 'badge' => 'Practice'],
    'Previous Year Questions' => ['icon' => 'fa-history', 'color' => '#64748b', 'badge' => 'PYQ'],
    'Daily Quiz' => ['icon' => 'fa-circle-question', 'color' => '#f43f5e', 'badge' => 'Quiz'],
    'Live WhatsApp Quiz' => ['icon' => 'fa-whatsapp', 'color' => '#22c55e', 'badge' => 'WA Quiz'],
    'Group Discussion' => ['icon' => 'fa-comments', 'color' => '#0ea5e9', 'badge' => 'GD'],
    'Meet the Scholar Session' => ['icon' => 'fa-graduation-cap', 'color' => '#d946ef', 'badge' => 'Scholar'],
    'Offline Session' => ['icon' => 'fa-building-columns', 'color' => '#84cc16', 'badge' => 'Offline'],
    'Assignment' => ['icon' => 'fa-file-pen', 'color' => '#f97316', 'badge' => 'Assignment'],
    'Revision' => ['icon' => 'fa-rotate', 'color' => '#059669', 'badge' => 'Revision'],
    'Self-Assessment' => ['icon' => 'fa-clipboard-user', 'color' => '#7c3aed', 'badge' => 'Assessment'],
    'Doubt Clearing Session' => ['icon' => 'fa-lightbulb', 'color' => '#eab308', 'badge' => 'Doubt'],
    'Reading Assignment' => ['icon' => 'fa-book', 'color' => '#0891b2', 'badge' => 'Reading'],
    'Video Lecture' => ['icon' => 'fa-circle-play', 'color' => '#b91c1c', 'badge' => 'Lecture'],
    'PDF Material' => ['icon' => 'fa-file-pdf', 'color' => '#dc2626', 'badge' => 'PDF'],
    'Workbook Activity' => ['icon' => 'fa-folder-open', 'color' => '#475569', 'badge' => 'Workbook']
];

// Fetch custom activity types
$custom_types = $pdo->query("SELECT * FROM study_plan_custom_types ORDER BY name ASC")->fetchAll();
$all_types = $default_types;
foreach ($custom_types as $ct) {
    $all_types[$ct['name']] = ['icon' => $ct['icon'], 'color' => $ct['color'], 'badge' => $ct['badge'] ?: $ct['name']];
}

// Fetch pre-set chapters for datalist suggestion
$preset_chapters = [];
try {
    $preset_chapters = $pdo->query("
        SELECT * FROM study_plan_chapters
        ORDER BY chapter_code ASC, chapter_name ASC
    ")->fetchAll();
} catch (Exception $e) {}

$page_title = $plan_id > 0 ? "Visual Study Plan Designer" : "Create Study Plan";
$page_sub = "Visually design, schedule, theme, and assign study plans with real-time preview";
$active_page = 'studyplans';

$extra_head = '
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<style>
    .designer-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        height: calc(100vh - 160px);
        margin-top: 1rem;
    }
    .designer-panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 16px;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .panel-header-sticky {
        padding: 1.2rem;
        border-bottom: 1px solid var(--border);
        background: var(--card-bg);
        z-index: 10;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .panel-body-scrollable {
        flex: 1;
        overflow-y: auto;
        padding: 1.2rem;
    }
    .designer-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 1.5px solid var(--border);
        margin-bottom: 1.2rem;
    }
    .designer-tab {
        padding: 8px 16px;
        font-weight: 700;
        color: var(--text-muted);
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.2s;
        font-size: 0.88rem;
    }
    .designer-tab.active {
        color: var(--accent);
        border-bottom-color: var(--accent);
    }
    .day-container {
        background: var(--input-bg);
        border: 1.5px solid var(--border);
        border-radius: 12px;
        margin-bottom: 1rem;
        overflow: hidden;
    }
    .day-header {
        background: var(--border);
        padding: 10px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 700;
        color: var(--text-main);
    }
    .activities-list {
        min-height: 50px;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .activity-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px 12px;
        cursor: grab;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s;
    }
    .activity-card:hover {
        border-color: var(--accent);
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }
    /* Layout styling for Live Preview */
    .preview-phone-frame {
        width: 100%;
        height: 100%;
        border-radius: 12px;
        border: 1px solid var(--border);
        background: var(--bg);
        color: var(--text-main);
        overflow-y: auto;
        padding: 1rem;
        transition: all 0.3s ease;
    }

    /* ── BASE TIMELINE LAYOUT STYLES ── */
    .preview-phone-frame .timeline-wrapper {
        position: relative;
        padding-left: 1rem;
        border-left: 2px solid var(--border);
        margin-left: 10px;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        margin-top: 1rem;
    }
    .preview-phone-frame .timeline-day-node {
        position: relative;
    }
    .preview-phone-frame .timeline-badge {
        position: absolute;
        left: -21px;
        top: 2px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: var(--accent);
        border: 2px solid var(--card-bg);
        box-shadow: 0 0 0 3px var(--accent-soft);
    }
    .preview-phone-frame .timeline-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        transition: all 0.25s ease;
    }
    .preview-phone-frame .timeline-date-label {
        font-family: var(--header-font);
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--accent);
        text-transform: uppercase;
        margin-bottom: 8px;
    }
    .preview-phone-frame .activity-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 0;
        border-bottom: 1px solid var(--border);
    }
    .preview-phone-frame .activity-item:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    /* ── CUSTOM THEME STYLES (PREVIEW & STUDENT SIDE) ── */
    .theme-default {
        --accent: #E8980C;
        --accent-hover: #d2860a;
        --accent-soft: rgba(232, 152, 12, 0.08);
        --bg: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border: #e2e8f0;
    }
    .theme-cyber {
        --accent: #10b981;
        --accent-hover: #059669;
        --accent-soft: rgba(16, 185, 129, 0.12);
        --bg: #090d16;
        --card-bg: #111827;
        --text-main: #f3f4f6;
        --text-muted: #9ca3af;
        --border: #374151;
        font-family: monospace !important;
    }
    .theme-cyber .timeline-card {
        box-shadow: 0 0 10px rgba(16, 185, 129, 0.15);
    }
    .theme-cyber .timeline-badge {
        box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
    }
    .theme-sunset {
        --accent: #f97316;
        --accent-hover: #ea580c;
        --accent-soft: rgba(249, 115, 22, 0.12);
        --bg: #fff7ed;
        --card-bg: #ffffff;
        --text-main: #431407;
        --text-muted: #9a3412;
        --border: #fed7aa;
    }
    .theme-sunset .timeline-card {
        border-radius: 16px;
    }
    .theme-minimal {
        --accent: #475569;
        --accent-hover: #334155;
        --accent-soft: rgba(75, 85, 99, 0.08);
        --bg: #fafafa;
        --card-bg: #ffffff;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --border: #e5e7eb;
    }
    .theme-royal {
        --accent: #8b5cf6;
        --accent-hover: #7c3aed;
        --accent-soft: rgba(139, 92, 246, 0.1);
        --bg: #faf5ff;
        --card-bg: #ffffff;
        --text-main: #2e1065;
        --text-muted: #6d28d9;
        --border: #e9d5ff;
    }
    .theme-ocean {
        --accent: #0ea5e9;
        --accent-hover: #0284c7;
        --accent-soft: rgba(14, 165, 233, 0.1);
        --bg: #f0f9ff;
        --card-bg: #ffffff;
        --text-main: #0c4a6e;
        --text-muted: #0284c7;
        --border: #bae6fd;
    }
    .theme-midnight {
        --accent: #f43f5e;
        --accent-hover: #e11d48;
        --accent-soft: rgba(244, 63, 94, 0.2);
        --bg: #030712;
        --card-bg: #0f172a;
        --text-main: #f8fafc;
        --text-muted: #94a3b8;
        --border: #1e293b;
    }
    .theme-midnight .timeline-card {
        background: rgba(15, 23, 42, 0.6) !important;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1.5px solid rgba(255, 255, 255, 0.08);
    }
    .theme-midnight .activity-item {
        border-bottom: 1px solid #1e293b;
    }

    /* ── CUSTOM VISUAL LAYOUTS (PREVIEW & STUDENT SIDE) ── */
    /* 1. Card Layout */
    .layout-card .timeline-wrapper {
        border-left: none !important;
        padding-left: 0 !important;
        margin-left: 0 !important;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }
    .layout-card .timeline-day-node {
        padding: 0 !important;
    }
    .layout-card .timeline-badge {
        display: none !important;
    }
    .layout-card .timeline-card {
        border-radius: 18px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        border: 1.5px solid var(--border);
    }

    /* 2. Journey Layout */
    .layout-journey .timeline-wrapper {
        border-left: 3px dashed var(--accent) !important;
        padding-left: 1.8rem !important;
        margin-left: 14px !important;
        gap: 2rem;
        display: flex;
        flex-direction: column;
    }
    .layout-journey .timeline-day-node {
        position: relative;
    }
    .layout-journey .timeline-badge {
        left: -39px;
        top: 6px;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: var(--accent);
        border: 3px solid var(--card-bg);
        box-shadow: 0 0 0 3px var(--accent-soft);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .layout-journey .timeline-badge::after {
        content: "★";
        color: #fff;
        font-size: 0.65rem;
        font-weight: 700;
        display: block;
        line-height: 1;
        margin-top: -1px;
    }
    .layout-journey .timeline-card {
        border-radius: 20px;
        background: var(--card-bg);
        border: 1.5px solid var(--border);
        padding: 16px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.02);
    }

    /* 3. Magazine Layout */
    .layout-magazine .timeline-wrapper {
        border-left: none !important;
        padding-left: 0 !important;
        margin-left: 0 !important;
        gap: 2.5rem;
        display: flex;
        flex-direction: column;
    }
    .layout-magazine .timeline-day-node {
        padding: 0 !important;
    }
    .layout-magazine .timeline-badge {
        display: none !important;
    }
    .layout-magazine .timeline-card {
        border: none !important;
        border-top: 3px solid var(--text-main) !important;
        border-radius: 0 !important;
        padding: 16px 0 !important;
        background: transparent !important;
        box-shadow: none !important;
    }
    .layout-magazine .timeline-date-label {
        font-family: var(--header-font);
        font-size: 1.25rem !important;
        font-weight: 800;
        color: var(--text-main);
        text-transform: none;
        letter-spacing: -0.5px;
        margin-bottom: 12px;
    }
    .layout-magazine .activity-item {
        padding: 12px 0;
        border-bottom: 1.5px solid var(--border);
    }
    .layout-magazine .activity-item:last-child {
        border-bottom: none;
    }
    /* ── Modern Visibility & Access Rules Select Boxes ── */
    .access-rules-container {
        border: 1.5px solid #e2e8f0 !important;
        padding: 20px !important;
        border-radius: 16px !important;
        margin-top: 14px !important;
        background: #ffffff !important;
        box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.04) !important;
    }
    .access-rules-card {
        background: #ffffff !important;
        border: 1.5px solid #e2e8f0 !important;
        border-radius: 14px !important;
        padding: 14px !important;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.02) !important;
        transition: all 0.2s ease-in-out !important;
    }
    .access-rules-card:hover {
        border-color: #cbd5e1 !important;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04) !important;
    }
    .access-rules-header {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        margin-bottom: 12px !important;
        padding-bottom: 8px !important;
        border-bottom: 1.5px solid #f1f5f9 !important;
    }
    .access-rules-title {
        font-size: 0.8rem !important;
        font-weight: 700 !important;
        color: #1e293b !important;
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
        margin: 0 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.4px !important;
    }
    .access-rules-title i {
        color: #4f46e5 !important;
        font-size: 0.9rem !important;
    }
    .access-rules-badge {
        font-size: 0.72rem !important;
        font-weight: 700 !important;
        padding: 3px 10px !important;
        border-radius: 20px !important;
        background: #f1f5f9 !important;
        color: #64748b !important;
        border: 1px solid #e2e8f0 !important;
        transition: all 0.2s ease !important;
    }
    .modern-scroll-box {
        max-height: 150px !important;
        overflow-y: auto !important;
        padding-right: 4px !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
    }
    .modern-scroll-box::-webkit-scrollbar {
        width: 5px !important;
    }
    .modern-scroll-box::-webkit-scrollbar-track {
        background: #f1f5f9 !important;
        border-radius: 10px !important;
    }
    .modern-scroll-box::-webkit-scrollbar-thumb {
        background: #cbd5e1 !important;
        border-radius: 10px !important;
    }
    .modern-scroll-box::-webkit-scrollbar-thumb:hover {
        background: #94a3b8 !important;
    }
    .modern-check-item {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 10px !important;
        padding: 9px 12px !important;
        border-radius: 10px !important;
        border: 1.5px solid #f1f5f9 !important;
        background: #f8fafc !important;
        cursor: pointer !important;
        transition: all 0.2s ease !important;
        margin: 0 !important;
        text-align: left !important;
        width: 100% !important;
        box-sizing: border-box !important;
        position: relative !important;
    }
    .modern-check-item:hover {
        background: #f1f5f9 !important;
        border-color: #cbd5e1 !important;
        transform: translateY(-1px);
    }
    .modern-check-item.active {
        background: #eff6ff !important;
        border-color: #93c5fd !important;
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.08) !important;
    }
    .modern-check-item input[type="checkbox"] {
        width: 16px !important;
        height: 16px !important;
        accent-color: #2563eb !important;
        cursor: pointer !important;
        margin: 0 !important;
        flex-shrink: 0 !important;
    }
    .modern-check-text {
        font-size: 0.82rem !important;
        font-weight: 600 !important;
        color: #334155 !important;
        text-align: left !important;
        margin: 0 !important;
        text-transform: none !important;
        line-height: 1.3 !important;
        flex: 1 !important;
        user-select: none !important;
    }
    .modern-check-item.active .modern-check-text {
        color: #1e40af !important;
        font-weight: 700 !important;
    }
    @media (max-width: 1024px) {
        .designer-container {
            grid-template-columns: 1fr;
            height: auto;
        }
    }
</style>
';

include 'includes/admin_nav.php';
?>

<div class="designer-container">
    <!-- Left Configuration & Designer Tools Panel -->
    <div class="designer-panel">
        <div class="panel-header-sticky">
            <div style="display:flex; align-items:center; gap:8px;">
                <a href="javascript:void(0)" onclick="confirmBack()" class="btn btn-outline btn-sm" style="padding: 4px 8px; font-weight:700; text-decoration:none;"><i class="fas fa-arrow-left"></i> Back</a>
                <h3 style="font-weight: 800; font-size: 1rem; display: flex; align-items: center; gap: 4px; margin: 0;">
                    <i class="fas fa-screwdriver-wrench" style="color:var(--accent);"></i> Designer
                </h3>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <span id="autosave-indicator" style="display:none; font-size:0.75rem; font-weight:600; align-items:center; gap:4px;"></span>
                <button type="button" class="btn btn-outline btn-sm" onclick="triggerImport()"><i class="fas fa-file-import"></i> Bulk Import</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveStudyPlan()"><i class="fas fa-floppy-disk"></i> Save Plan</button>
            </div>
        </div>

        <div class="panel-body-scrollable">
            <div class="designer-tabs">
                <div class="designer-tab active" onclick="switchDesignerTab('settings')">Branding &amp; Settings</div>
                <div class="designer-tab" onclick="switchDesignerTab('activities')">Daily Activities</div>
                <div class="designer-tab" onclick="switchDesignerTab('templates')">Save Template</div>
            </div>

            <!-- Tab Content: Settings -->
            <div id="tab-settings" class="designer-tab-content">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div class="field full" style="margin-bottom: 8px;">
                        <label style="font-weight: 700; display: block; margin-bottom: 6px;">Study Plan Status</label>
                        <div style="display:inline-flex; background:#e2e8f0; padding:4px; border-radius:10px; gap:4px;">
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; font-size:0.8rem; padding:6px 16px; border-radius:8px; margin:0; transition:all 0.2s;" id="label-status-draft">
                                <input type="radio" name="plan_status" value="draft" id="status-draft" onchange="updateStatusToggle()" style="display:none;" <?php echo ($plan['status'] ?? 'draft') === 'draft' ? 'checked' : ''; ?>>
                                <i class="fas fa-file-signature"></i> Draft
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; font-size:0.8rem; padding:6px 16px; border-radius:8px; margin:0; transition:all 0.2s;" id="label-status-published">
                                <input type="radio" name="plan_status" value="published" id="status-published" onchange="updateStatusToggle()" style="display:none;" <?php echo ($plan['status'] ?? '') === 'published' ? 'checked' : ''; ?>>
                                <i class="fas fa-circle-check"></i> Publish
                            </label>
                        </div>
                    </div>
                    <div class="field full">
                        <label>Study Plan Title <span class="req">*</span></label>
                        <input type="text" id="plan-title" value="<?php echo htmlspecialchars($plan['title'] ?? 'Psychology Exam Crack Plan'); ?>" oninput="updateLivePreview()">
                    </div>
                    <div class="field full">
                        <label>Academic Year <span class="req">*</span></label>
                        <select id="plan-year" onchange="updateLivePreview()">
                            <option value="">- Choose Year -</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($plan['academic_year'] ?? '') === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="plan-course" value="">
                    </div>
                    <div class="field" style="grid-column: span 2; margin-bottom: 8px;">
                        <label style="font-weight: 700; display: block; margin-bottom: 6px;">Plan Scheduling Type</label>
                        <div style="display:flex; gap:16px; align-items:center;">
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:600;">
                                <input type="radio" name="plan_type" value="date_wise" id="type-date-wise" onchange="togglePlanTypeView()" <?php echo ($plan['plan_type'] ?? 'date_wise') === 'date_wise' ? 'checked' : ''; ?>>
                                Date Wise (Start/End Calendar Dates)
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:600;">
                                <input type="radio" name="plan_type" value="day_wise" id="type-day-wise" onchange="togglePlanTypeView()" <?php echo ($plan['plan_type'] ?? 'date_wise') === 'day_wise' ? 'checked' : ''; ?>>
                                Day Count Wise (Total number of days)
                            </label>
                        </div>
                    </div>

                    <div class="field" id="date-wise-start-wrap">
                        <label>Start Date <span class="req">*</span></label>
                        <input type="date" id="plan-start" value="<?php echo $plan['start_date'] ?? date('Y-m-d'); ?>" onchange="regenerateDatesPreview()">
                    </div>
                    <div class="field" id="date-wise-end-wrap">
                        <label>End Date <span class="req">*</span></label>
                        <input type="date" id="plan-end" value="<?php echo $plan['end_date'] ?? date('Y-m-d', strtotime('+7 days')); ?>" onchange="regenerateDatesPreview()">
                    </div>

                    <div class="field" id="day-wise-days-wrap" style="display:none;">
                        <label>Total Number of Days <span class="req">*</span></label>
                        <input type="number" id="plan-total-days" min="1" max="365" value="<?php echo $plan['total_days'] ?? 7; ?>" onchange="regenerateDatesPreview()">
                    </div>

                    <div class="field">
                        <label>Theme Style</label>
                        <select id="plan-theme" onchange="updateLivePreview()">
                            <option value="default">PEPP Amber Style</option>
                            <option value="cyber">Cyberpunk Emerald</option>
                            <option value="sunset">Sunset Orange Gradient</option>
                            <option value="minimal">Crisp Minimal Gray</option>
                            <option value="royal">Royal Amethyst Style</option>
                            <option value="ocean">Ocean Breeze Style</option>
                            <option value="midnight">Midnight Aurora Dark Mode</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Visual Layout</label>
                        <select id="plan-layout" onchange="updateLivePreview()">
                            <option value="timeline">Timeline View</option>
                            <option value="card">Card View Grid</option>
                            <option value="journey">PEPP Monthly Journey Layout</option>
                            <option value="magazine">Magazine Style Layout</option>
                        </select>
                    </div>
                </div>

                <div class="field full">
                    <label>Description (Optional)</label>
                    <textarea id="plan-desc" rows="3" oninput="updateLivePreview()"><?php echo htmlspecialchars($plan['description'] ?? ''); ?></textarea>
                </div>

                <div class="field full">
                    <label>Branding Quote / Motivational Quote</label>
                    <input type="text" id="plan-quote" placeholder="e.g. Success is the sum of small efforts repeated day in and day out!" value="Commit to your dreams and execute every day!" oninput="updateLivePreview()">
                </div>

                <div class="field full access-rules-container">
                    <label style="font-weight: 800; font-size: 0.95rem; color: #0f172a; display: flex; align-items: center; gap: 8px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;"><i class="fas fa-lock" style="color:#4f46e5;"></i> Visibility &amp; Access Rules</label>
                    <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 16px; line-height: 1.4;">Select who can access this study plan via the public link. They will authenticate using their registered email.</p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="access-rules-card">
                            <div class="access-rules-header">
                                <span class="access-rules-title"><i class="fas fa-graduation-cap"></i> Enrolled Courses (PEPP Students)</span>
                                <span class="access-rules-badge" id="courses-count-badge">0 Selected</span>
                            </div>
                            <div class="modern-scroll-box">
                                <?php foreach ($courses as $c):
                                    $isChecked = false;
                                    foreach ($assigned as $a) {
                                        if ($a['assignment_type'] === 'course' && $a['assigned_value'] === $c['course_name']) {
                                            $isChecked = true; break;
                                        }
                                    }
                                ?>
                                    <label for="ac-<?php echo $c['id']; ?>" class="modern-check-item <?php echo $isChecked ? 'active' : ''; ?>">
                                        <input type="checkbox" name="access_courses[]" value="<?php echo htmlspecialchars($c['course_name']); ?>" id="ac-<?php echo $c['id']; ?>" <?php echo $isChecked ? 'checked' : ''; ?> onchange="toggleCheckItemStyle(this)">
                                        <span class="modern-check-text"><?php echo htmlspecialchars($c['course_name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="access-rules-card">
                            <div class="access-rules-header">
                                <span class="access-rules-title"><i class="fab fa-wpforms"></i> Registered in Custom Forms</span>
                                <span class="access-rules-badge" id="forms-count-badge">0 Selected</span>
                            </div>
                            <div class="modern-scroll-box">
                                <?php foreach ($campaign_forms as $f):
                                    $isChecked = false;
                                    foreach ($assigned as $a) {
                                        if ($a['assignment_type'] === 'form' && $a['assigned_value'] === (string)$f['id']) {
                                            $isChecked = true; break;
                                        }
                                    }
                                ?>
                                    <label for="af-<?php echo $f['id']; ?>" class="modern-check-item <?php echo $isChecked ? 'active' : ''; ?>">
                                        <input type="checkbox" name="access_forms[]" value="<?php echo htmlspecialchars($f['id']); ?>" id="af-<?php echo $f['id']; ?>" <?php echo $isChecked ? 'checked' : ''; ?> onchange="toggleCheckItemStyle(this)">
                                        <span class="modern-check-text"><?php echo htmlspecialchars($f['title']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Activities Builder -->
            <div id="tab-activities" class="designer-tab-content" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div style="font-size:0.82rem; font-weight:700; color:var(--text-muted);">Schedules by Date</div>
                    <button class="btn btn-sm btn-secondary" onclick="addCustomActivityField()"><i class="fas fa-plus"></i> Add Activity</button>
                </div>
                <div id="activities-dates-wrapper">
                    <!-- Loaded dynamically via JS -->
                </div>
            </div>

            <!-- Tab Content: Templates Save -->
            <div id="tab-templates" class="designer-tab-content" style="display:none;">
                <div style="background:rgba(232,152,12,0.06); padding:1rem; border-radius:12px; border:1px dashed var(--border); margin-bottom:1.2rem;">
                    <p style="font-size:0.85rem; line-height:1.5; color:var(--text-muted); margin:0;">
                        Save this study plan configuration as a reusable template. You can quickly select templates while designing study plans for other courses later.
                    </p>
                </div>
                <div class="field full">
                    <label>Is Reusable Template?</label>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                        <input type="checkbox" id="plan-template" value="1" <?php echo ($plan['is_template'] ?? 0) ? 'checked' : ''; ?>>
                        <span style="font-size:0.85rem; font-weight:700; color:var(--text-main);">Flag as a reusable template</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Live Interactive Preview Panel -->
    <div class="designer-panel">
        <div class="panel-header-sticky">
            <h3 style="font-weight: 800; font-size: 1rem; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-circle-play" style="color:#22c55e;"></i> Real-Time Live Preview
            </h3>
            <span class="badge green" style="font-weight:700; font-size:0.75rem;">Interactive Mock</span>
        </div>

        <div class="panel-body-scrollable" style="background:#f1f5f9;">
            <div id="live-preview-wrapper" class="preview-phone-frame">
                <!-- Live rendering of themes and layouts via Javascript -->
            </div>
        </div>
    </div>
</div>

<!-- Modal: Delete Confirmation Warning -->
<div class="modal-backdrop" id="delete-warning-modal">
    <div class="modal" style="max-width:450px; padding:1.5rem; border-radius: 16px;">
        <div style="text-align:center; font-size:3rem; color:#ef4444; margin-bottom:12px;" id="delete-warning-icon">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 style="font-weight:800; font-size:1.2rem; margin-bottom:8px; color: #1e293b; text-align:center;" id="delete-warning-title">Delete Activity</h3>
        <div style="color:#64748b; font-size:0.9rem; margin-bottom:20px; text-align:center; line-height: 1.5;" id="delete-warning-message">
            Are you sure you want to delete this activity?
        </div>
        <div style="margin-bottom:15px; display:none;" id="delete-reason-container">
            <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:4px;">Reason for Deletion:</label>
            <input type="text" id="delete-reason-input" class="form-control" style="width:100%; border:1px solid #cbd5e1; padding:6px; border-radius:6px; font-size:0.85rem;" placeholder="e.g., Task no longer needed" value="Admin deleted">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn-outline" onclick="closeModal('delete-warning-modal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-delete-btn" style="background:#ef4444; border-color:#ef4444; color:#fff;" onclick="executeDeleteActivity()"><i class="fas fa-trash"></i> Delete Activity</button>
        </div>
    </div>
</div>

<!-- Modal: Excel/CSV Bulk Import -->
<div class="modal-backdrop" id="import-modal">
    <div class="modal" style="max-width:540px;">
        <div class="modal-head">
            <h3><i class="fas fa-file-csv" style="color:var(--accent);"></i> Bulk Import Activities</h3>
            <button class="modal-close" onclick="closeModal('import-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.85rem; color:var(--text-muted); line-height:1.5; margin-bottom:1rem;">
                Select a CSV file containing study activities. We will auto-map columns such as Date, Topic, Title, and Faculty.
            </p>
            <div class="field full">
                <label>Upload CSV File</label>
                <input type="file" id="import-file" accept=".csv" class="form-input">
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="closeModal('import-modal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="processBulkImport()"><i class="fas fa-upload"></i> Process Import</button>
        </div>
    </div>
</div>

<!-- Modal: Delete Confirmation Warning -->
<div class="modal-backdrop" id="delete-warning-modal">
    <div class="modal" style="max-width:450px; padding:1.5rem; border-radius: 16px;">
        <div style="text-align:center; font-size:3rem; color:#ef4444; margin-bottom:12px;" id="delete-warning-icon">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 style="font-weight:800; font-size:1.2rem; margin-bottom:8px; color: #1e293b; text-align:center;" id="delete-warning-title">Delete Activity</h3>
        <div style="color:#64748b; font-size:0.9rem; margin-bottom:20px; text-align:center; line-height: 1.5;" id="delete-warning-message">
            Are you sure you want to delete this activity?
        </div>
        <div style="margin-bottom:15px; display:none;" id="delete-reason-container">
            <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:4px;">Reason for Deletion:</label>
            <input type="text" id="delete-reason-input" class="form-control" style="width:100%; border:1px solid #cbd5e1; padding:6px; border-radius:6px; font-size:0.85rem;" placeholder="e.g., Task no longer needed" value="Admin deleted">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn-outline" onclick="closeModal('delete-warning-modal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-delete-btn" style="background:#ef4444; border-color:#ef4444; color:#fff;" onclick="executeDeleteActivity()"><i class="fas fa-trash"></i> Delete Activity</button>
        </div>
    </div>
</div>

<!-- Modal: Add/Edit Activity -->
<div class="modal-backdrop" id="activity-modal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-head">
            <h3><i class="fas fa-calendar-plus" style="color:var(--accent);"></i> Study Activity Details</h3>
            <button class="modal-close" onclick="closeModal('activity-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="display:grid; grid-template-columns:1fr; gap:12px;">
            <input type="hidden" id="act-edit-index">
            <input type="hidden" id="act-edit-date">

            <div class="field">
                <label>Activity Title <span class="req">*</span></label>
                <input type="text" id="act-title" value="Read Material">
            </div>

            <div class="field">
                <label>Activity Type</label>
                <select id="act-type">
                    <?php foreach ($all_types as $t_name => $t_conf): ?>
                        <option value="<?php echo htmlspecialchars($t_name); ?>"><?php echo htmlspecialchars($t_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="field">
                    <label>Chapter</label>
                    <input type="text" id="act-chapter" list="preset-chapters-list" placeholder="Select pre-set chapter or type new..." autocomplete="off">
                    <datalist id="preset-chapters-list">
                        <?php foreach ($preset_chapters as $pc): ?>
                            <option value="<?php echo htmlspecialchars($pc['chapter_name']); ?>">
                                <?php echo htmlspecialchars(($pc['chapter_code'] ? '[' . $pc['chapter_code'] . '] ' : '') . $pc['chapter_name'] . ($pc['course_name'] ? ' (' . $pc['course_name'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="field">
                    <label>Subject</label>
                    <input type="text" id="act-subject" placeholder="e.g. Psychology">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="field">
                    <label>Topic</label>
                    <input type="text" id="act-topic" placeholder="e.g. Memory Models">
                </div>
                <div class="field">
                    <label>Subtopic</label>
                    <input type="text" id="act-subtopic" placeholder="e.g. Atkinson-Shiffrin">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="field">
                    <label>Faculty</label>
                    <input type="text" id="act-faculty" placeholder="e.g. Dr. Anand">
                </div>
                <div class="field">
                    <label>Mentor</label>
                    <input type="text" id="act-mentor" placeholder="e.g. Prof. Priya">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="field">
                    <label>Duration (mins)</label>
                    <input type="number" id="act-duration" value="60">
                </div>
                <div class="field">
                    <label>Difficulty</label>
                    <select id="act-difficulty">
                        <option value="easy">Easy</option>
                        <option value="medium" selected>Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label>Resource Links / URLs</label>
                <input type="text" id="act-resources" placeholder="e.g. https://pepplearning.in/materials/pdf1">
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="closeModal('activity-modal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveActivityRow()"><i class="fas fa-check"></i> Apply Activity</button>
        </div>
    </div>
</div>

<!-- Modal: Delete Confirmation Warning -->
<div class="modal-backdrop" id="delete-warning-modal">
    <div class="modal" style="max-width:450px; padding:1.5rem; border-radius: 16px;">
        <div style="text-align:center; font-size:3rem; color:#ef4444; margin-bottom:12px;" id="delete-warning-icon">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 style="font-weight:800; font-size:1.2rem; margin-bottom:8px; color: #1e293b; text-align:center;" id="delete-warning-title">Delete Activity</h3>
        <div style="color:#64748b; font-size:0.9rem; margin-bottom:20px; text-align:center; line-height: 1.5;" id="delete-warning-message">
            Are you sure you want to delete this activity?
        </div>
        <div style="margin-bottom:15px; display:none;" id="delete-reason-container">
            <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:4px;">Reason for Deletion:</label>
            <input type="text" id="delete-reason-input" class="form-control" style="width:100%; border:1px solid #cbd5e1; padding:6px; border-radius:6px; font-size:0.85rem;" placeholder="e.g., Task no longer needed" value="Admin deleted">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn-outline" onclick="closeModal('delete-warning-modal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-delete-btn" style="background:#ef4444; border-color:#ef4444; color:#fff;" onclick="executeDeleteActivity()"><i class="fas fa-trash"></i> Delete Activity</button>
        </div>
    </div>
</div>

<!-- Modal: Exit Confirmation -->
<div class="modal-backdrop" id="exit-confirm-modal">
    <div class="modal" style="max-width:400px; text-align:center; padding:1.5rem; border-radius: 16px;">
        <div style="font-size:3rem; color:#f59e0b; margin-bottom:12px;"><i class="fas fa-triangle-exclamation"></i></div>
        <h3 style="font-weight:800; font-size:1.2rem; margin-bottom:8px; color: #1e293b;">Unsaved Changes</h3>
        <p style="color:#64748b; font-size:0.85rem; margin-bottom:20px;">You have unsaved changes in your study plan. What would you like to do?</p>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <button type="button" class="btn btn-primary" style="background:#10b981; border-color:#10b981;" onclick="saveAndExit()"><i class="fas fa-floppy-disk"></i> Save Changes &amp; Exit</button>
            <button type="button" class="btn btn-soft-red" style="background:#fef2f2; border-color:#fca5a5; color:#ef4444; font-weight:700;" onclick="exitWithoutSaving()"><i class="fas fa-trash-can"></i> Discard Changes &amp; Exit</button>
            <button type="button" class="btn btn-outline" onclick="closeModal('exit-confirm-modal')">Cancel (Stay on Page)</button>
        </div>
    </div>
</div>

<!-- Modal: Delete Confirmation Warning -->
<div class="modal-backdrop" id="delete-warning-modal">
    <div class="modal" style="max-width:450px; padding:1.5rem; border-radius: 16px;">
        <div style="text-align:center; font-size:3rem; color:#ef4444; margin-bottom:12px;" id="delete-warning-icon">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 style="font-weight:800; font-size:1.2rem; margin-bottom:8px; color: #1e293b; text-align:center;" id="delete-warning-title">Delete Activity</h3>
        <div style="color:#64748b; font-size:0.9rem; margin-bottom:20px; text-align:center; line-height: 1.5;" id="delete-warning-message">
            Are you sure you want to delete this activity?
        </div>
        <div style="margin-bottom:15px; display:none;" id="delete-reason-container">
            <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:4px;">Reason for Deletion:</label>
            <input type="text" id="delete-reason-input" class="form-control" style="width:100%; border:1px solid #cbd5e1; padding:6px; border-radius:6px; font-size:0.85rem;" placeholder="e.g., Task no longer needed" value="Admin deleted">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn-outline" onclick="closeModal('delete-warning-modal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-delete-btn" style="background:#ef4444; border-color:#ef4444; color:#fff;" onclick="executeDeleteActivity()"><i class="fas fa-trash"></i> Delete Activity</button>
        </div>
    </div>
</div>

<!-- Modal: Stale Version Conflict Warning -->
<div class="modal-backdrop" id="stale-warning-modal">
    <div class="modal" style="max-width:450px; padding:1.5rem; border-radius: 16px;">
        <div style="text-align:center; font-size:3rem; color:#f59e0b; margin-bottom:12px;">
            <i class="fas fa-arrows-rotate"></i>
        </div>
        <h3 style="font-weight:800; font-size:1.2rem; margin-bottom:8px; color: #1e293b; text-align:center;">Version Conflict</h3>
        <div style="color:#64748b; font-size:0.9rem; margin-bottom:20px; text-align:center; line-height: 1.5;">
            This study plan was updated by another administrator.<br><br>
            Your changes were not saved to prevent overwriting the latest updates.<br><br>
            Please reload the study plan and review the latest version before making further changes.
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
            <button type="button" class="btn btn-primary" style="background:#f59e0b; border-color:#f59e0b; color:#fff; font-weight:700;" onclick="location.reload()"><i class="fas fa-arrows-rotate"></i> Reload Latest Version</button>
            <button type="button" class="btn btn-outline" onclick="closeModal('stale-warning-modal')">Cancel</button>
        </div>
    </div>
</div>

<script>
    var studyPlanId = <?php echo $plan_id; ?>;
    var studyPlanVersion = <?php echo (int)($plan['version'] ?? 1); ?>;
    var activities = <?php echo $activities_json; ?>;
    var predefinedTypes = <?php echo json_encode($all_types); ?>;
    var allChapters = <?php echo json_encode($preset_chapters); ?>;
    var courseNameToIdMap = {
        <?php foreach ($courses as $c): ?>
            <?php echo json_encode($c['course_name']); ?>: <?php echo $c['id']; ?>,
        <?php endforeach; ?>
    };

    function updateStatusToggle() {
        var isDraft = document.getElementById('status-draft').checked;
        var lblDraft = document.getElementById('label-status-draft');
        var lblPublished = document.getElementById('label-status-published');

        if (isDraft) {
            lblDraft.style.background = '#64748b';
            lblDraft.style.color = '#ffffff';
            lblPublished.style.background = 'transparent';
            lblPublished.style.color = '#64748b';
        } else {
            lblDraft.style.background = 'transparent';
            lblDraft.style.color = '#64748b';
            lblPublished.style.background = '#10b981';
            lblPublished.style.color = '#ffffff';
        }
    }

    function populateChapterDatalist() {
        var selectedCourseIds = [];
        document.querySelectorAll('input[name="access_courses[]"]:checked').forEach(function(el) {
            var cid = courseNameToIdMap[el.value];
            if (cid) selectedCourseIds.push(String(cid));
        });

        var datalist = document.getElementById('preset-chapters-list');
        if (!datalist) return;
        datalist.innerHTML = '';

        var addedNames = new Set();
        allChapters.forEach(function(ch) {
            var chCourseIds = ch.course_id ? ch.course_id.split(',') : [];
            var matches = chCourseIds.some(id => selectedCourseIds.includes(id));
            if (matches && !addedNames.has(ch.chapter_name)) {
                addedNames.add(ch.chapter_name);
                var opt = document.createElement('option');
                opt.value = ch.chapter_name;
                opt.textContent = (ch.chapter_code ? '[' + ch.chapter_code + '] ' : '') + ch.chapter_name;
                datalist.appendChild(opt);
            }
        });
    }

    function toggleCheckItemStyle(cb) {
        var label = cb.closest('.modern-check-item');
        if (label) {
            if (cb.checked) {
                label.classList.add('active');
            } else {
                label.classList.remove('active');
            }
        }
        updateAccessCounts();
    }

    function updateAccessCounts() {
        var coursesCount = document.querySelectorAll('input[name="access_courses[]"]:checked').length;
        var formsCount = document.querySelectorAll('input[name="access_forms[]"]:checked').length;

        var cBadge = document.getElementById('courses-count-badge');
        if (cBadge) {
            cBadge.textContent = coursesCount + ' Selected';
            if (coursesCount > 0) {
                cBadge.style.background = '#e0e7ff';
                cBadge.style.color = '#3730a3';
                cBadge.style.borderColor = '#c7d2fe';
            } else {
                cBadge.style.background = '#f1f5f9';
                cBadge.style.color = '#64748b';
                cBadge.style.borderColor = '#e2e8f0';
            }
        }

        var fBadge = document.getElementById('forms-count-badge');
        if (fBadge) {
            fBadge.textContent = formsCount + ' Selected';
            if (formsCount > 0) {
                fBadge.style.background = '#e0e7ff';
                fBadge.style.color = '#3730a3';
                fBadge.style.borderColor = '#c7d2fe';
            } else {
                fBadge.style.background = '#f1f5f9';
                fBadge.style.color = '#64748b';
                fBadge.style.borderColor = '#e2e8f0';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (studyPlanId > 0) {
            // Pre-select theme/layout
            document.getElementById('plan-theme').value = "<?php echo $plan['theme'] ?? 'default'; ?>";
            document.getElementById('plan-layout').value = "<?php echo $plan['layout'] ?? 'timeline'; ?>";

            // Set plan type state
            var pType = "<?php echo $plan['plan_type'] ?? 'date_wise'; ?>";
            if (pType === 'day_wise') {
                document.getElementById('type-day-wise').checked = true;
            } else {
                document.getElementById('type-date-wise').checked = true;
            }

            // Set status state
            var pStatus = "<?php echo $plan['status'] ?? 'draft'; ?>";
            if (pStatus === 'published') {
                document.getElementById('status-published').checked = true;
            } else {
                document.getElementById('status-draft').checked = true;
            }
        }

        togglePlanTypeView();
        populateChapterDatalist();
        updateStatusToggle();
        updateAccessCounts();

        // Bind change listeners to detect unsaved settings changes
        ['plan-title', 'plan-desc', 'plan-quote'].forEach(id => {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', markUnsavedChanges);
        });
        ['plan-year', 'plan-course', 'plan-theme', 'plan-layout', 'plan-template', 'plan-start', 'plan-end'].forEach(id => {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', markUnsavedChanges);
        });

        // Bind status radio buttons to detect unsaved settings changes
        document.querySelectorAll('input[name="plan_status"]').forEach(r => {
            r.addEventListener('change', function() {
                markUnsavedChanges();
                updateStatusToggle();
            });
        });

        // Bind access courses change listeners to update chapters and mark unsaved
        document.querySelectorAll('input[name="access_courses[]"]').forEach(cb => {
            cb.addEventListener('change', function() {
                markUnsavedChanges();
                populateChapterDatalist();
            });
        });
    });

    function togglePlanTypeView() {
        var isDateWise = document.getElementById('type-date-wise').checked;
        var startInput = document.getElementById('plan-start').value;
        var endInput = document.getElementById('plan-end').value;

        if (isDateWise) {
            document.getElementById('date-wise-start-wrap').style.display = 'block';
            document.getElementById('date-wise-end-wrap').style.display = 'block';
            document.getElementById('day-wise-days-wrap').style.display = 'none';

            // Shifting from Day Wise back to Date Wise:
            // Translate relative placeholder dates starting with 2000-01-01 back to calendar dates
            if (startInput) {
                var baseDate = new Date(startInput);
                activities.forEach(function(act) {
                    if (act.activity_date && act.activity_date.startsWith('2000-')) {
                        var dummyD = new Date(act.activity_date);
                        var offsetD = new Date('2000-01-01');
                        var diffT = dummyD - offsetD;
                        var diffDays = Math.floor(diffT / (1000 * 60 * 60 * 24));
                        if (diffDays < 0) diffDays = 0;

                        var newD = new Date(baseDate);
                        newD.setDate(newD.getDate() + diffDays);
                        var yyyy = newD.getFullYear();
                        var mm = String(newD.getMonth() + 1).padStart(2, '0');
                        var dd = String(newD.getDate()).padStart(2, '0');
                        act.activity_date = yyyy + '-' + mm + '-' + dd;
                        act.day_number = diffDays + 1;
                    }
                });
            }
        } else {
            document.getElementById('date-wise-start-wrap').style.display = 'none';
            document.getElementById('date-wise-end-wrap').style.display = 'none';
            document.getElementById('day-wise-days-wrap').style.display = 'block';

            // Shifting from Date Wise to Day Count Wise:
            // 1. Auto-calculate total days and set total number of days input
            if (startInput && endInput) {
                var startD = new Date(startInput);
                var endD = new Date(endInput);
                var diffTime = Math.abs(endD - startD);
                var totalDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                if (totalDays > 0) {
                    document.getElementById('plan-total-days').value = totalDays;
                }
            }

            // 2. Translate current calendar dates to relative dates starting from 2000-01-01
            if (startInput) {
                var baseDate = new Date(startInput);
                activities.forEach(function(act) {
                    if (act.activity_date && !act.activity_date.startsWith('2000-')) {
                        var actD = new Date(act.activity_date);
                        var diffT = actD - baseDate;
                        var diffDays = Math.floor(diffT / (1000 * 60 * 60 * 24));
                        if (diffDays < 0) diffDays = 0;

                        var newD = new Date('2000-01-01');
                        newD.setDate(newD.getDate() + diffDays);
                        var yyyy = newD.getFullYear();
                        var mm = String(newD.getMonth() + 1).padStart(2, '0');
                        var dd = String(newD.getDate()).padStart(2, '0');
                        act.activity_date = yyyy + '-' + mm + '-' + dd;
                        act.day_number = diffDays + 1;
                    }
                });
            }
        }
        regenerateDatesPreview();
    }

    function switchDesignerTab(tab) {
        document.querySelectorAll('.designer-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.designer-tab-content').forEach(c => c.style.display = 'none');

        event.target.classList.add('active');
        document.getElementById('tab-' + tab).style.display = 'block';
    }

    function regenerateDatesPreview() {
        var isDateWise = document.getElementById('type-date-wise').checked;
        var startInput, endInput, totalDays;

        if (isDateWise) {
            startInput = document.getElementById('plan-start').value;
            endInput = document.getElementById('plan-end').value;
            if (!startInput || !endInput) return;
        } else {
            totalDays = parseInt(document.getElementById('plan-total-days').value) || 7;
            startInput = '2000-01-01';
            var endD = new Date('2000-01-01');
            endD.setDate(endD.getDate() + totalDays - 1);
            var yyyy = endD.getFullYear();
            var mm = String(endD.getMonth() + 1).padStart(2, '0');
            var dd = String(endD.getDate()).padStart(2, '0');
            endInput = yyyy + '-' + mm + '-' + dd;
        }

        var start = new Date(startInput);
        var end = new Date(endInput);
        var wrapper = document.getElementById('activities-dates-wrapper');
        wrapper.innerHTML = '';

        var curr = new Date(start);
        var dayNum = 1;

        while (curr <= end) {
            var yyyy = curr.getFullYear();
            var mm = String(curr.getMonth() + 1).padStart(2, '0');
            var dd = String(curr.getDate()).padStart(2, '0');
            var dateStr = yyyy + '-' + mm + '-' + dd;

            var dayLabel = "Day " + String(dayNum).padStart(2, '0');
            if (isDateWise) {
                dayLabel += " (" + curr.toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) + ")";
            }

            var dayContainer = document.createElement('div');
            dayContainer.className = 'day-container';
            dayContainer.id = 'day-group-' + dateStr;

            var dayHeader = document.createElement('div');
            dayHeader.className = 'day-header';
            dayHeader.innerHTML = '<span><i class="fas fa-calendar-day" style="color:var(--accent);"></i> ' + dayLabel + '</span>' +
                                  '<button class="btn btn-sm btn-secondary" style="padding:2px 8px; font-size:0.72rem;" onclick="addActivityToDate(\'' + dateStr + '\', ' + dayNum + ')"><i class="fas fa-plus"></i> Add Item</button>';

            var activitiesList = document.createElement('div');
            activitiesList.className = 'activities-list';
            activitiesList.id = 'list-' + dateStr;
            activitiesList.setAttribute('data-date', dateStr);
            activitiesList.setAttribute('data-day', dayNum);

            dayContainer.appendChild(dayHeader);
            dayContainer.appendChild(activitiesList);
            wrapper.appendChild(dayContainer);

            // Initialize SortableJS drag and drop
            new Sortable(activitiesList, {
                group: 'activities-shared',
                animation: 150,
                onEnd: function (evt) {
                    reindexSortOrder();
                }
            });

            curr.setDate(curr.getDate() + 1);
            dayNum++;
        }

        // Populate existing items
        renderActivitiesList();
        updateLivePreview();
    }

    function renderActivitiesList() {
        // Clear all days
        document.querySelectorAll('.activities-list').forEach(l => l.innerHTML = '');

        activities.forEach(function(act, index) {
            var targetList = document.getElementById('list-' + act.activity_date);
            if (targetList) {
                var card = document.createElement('div');
                card.className = 'activity-card';
                card.setAttribute('data-index', index);
                if (act.id) {
                    card.setAttribute('data-activity-id', act.id);
                }
                if (act.activity_uid) {
                    card.setAttribute('data-activity-uid', act.activity_uid);
                }

                var typeConf = predefinedTypes[act.activity_type] || {icon: 'fa-book-open', color: '#64748b'};

                card.innerHTML = '<div style="display:flex; align-items:center; gap:8px;">' +
                                    '<i class="fas ' + typeConf.icon + '" style="color:' + typeConf.color + '; font-size:1.1rem; width:20px;"></i>' +
                                    '<div>' +
                                        '<div style="font-size:0.85rem; font-weight:700; color:var(--text-main);">' + (act.activity_title || 'Self Study') + '</div>' +
                                        '<div style="font-size:0.75rem; color:var(--text-muted);">' + (act.subject || 'Academics') + ' · ' + (act.chapter || 'Intro') + '</div>' +
                                    '</div>' +
                                 '</div>' +
                                 '<div style="display:flex; gap:6px;">' +
                                    '<button class="btn btn-sm btn-outline" style="padding:2px 6px;" title="Edit" onclick="editActivityRow(' + index + ')"><i class="fas fa-pencil"></i></button>' +
                                    '<button class="btn btn-sm btn-outline" style="padding:2px 6px;" title="Clone / Duplicate" onclick="cloneActivityRow(' + index + ')"><i class="fas fa-copy"></i></button>' +
                                    '<button class="btn btn-sm btn-soft-red" id="act-delete-btn-' + index + '" style="padding:2px 6px;" title="Delete" onclick="deleteActivityRow(' + index + ')"><i class="fas fa-trash"></i></button>' +
                                 '</div>';
                targetList.appendChild(card);
            }
        });
    }

    function reindexSortOrder() {
        var updatedActivities = [];
        document.querySelectorAll('.activities-list').forEach(function(list) {
            var dateStr = list.getAttribute('data-date');
            var dayNum = parseInt(list.getAttribute('data-day'));

            var cards = list.querySelectorAll('.activity-card');
            cards.forEach(function(card, order) {
                var oldIndex = parseInt(card.getAttribute('data-index'));
                var act = activities[oldIndex];

                act.activity_date = dateStr;
                act.day_number = dayNum;
                act.sort_order = order;
                updatedActivities.push(act);
            });
        });

        activities = updatedActivities;
        renderActivitiesList();
        updateLivePreview();
        autoSaveActivities();
    }

    function addActivityToDate(dateStr, dayNum) {
        document.getElementById('act-edit-index').value = "-1";
        document.getElementById('act-edit-date').value = dateStr;

        document.getElementById('act-title').value = 'Read Study Material';
        document.getElementById('act-chapter').value = '';
        document.getElementById('act-subject').value = '';
        document.getElementById('act-topic').value = '';
        document.getElementById('act-subtopic').value = '';
        document.getElementById('act-faculty').value = '';
        document.getElementById('act-mentor').value = '';
        document.getElementById('act-duration').value = '60';
        document.getElementById('act-difficulty').value = 'medium';
        document.getElementById('act-resources').value = '';

        openModal('activity-modal');
    }

    function editActivityRow(index) {
        var act = activities[index];
        document.getElementById('act-edit-index').value = index;
        document.getElementById('act-edit-date').value = act.activity_date;

        document.getElementById('act-title').value = act.activity_title;
        document.getElementById('act-type').value = act.activity_type;
        document.getElementById('act-chapter').value = act.chapter || '';
        document.getElementById('act-subject').value = act.subject || '';
        document.getElementById('act-topic').value = act.topic || '';
        document.getElementById('act-subtopic').value = act.subtopic || '';
        document.getElementById('act-faculty').value = act.faculty || '';
        document.getElementById('act-mentor').value = act.mentor || '';
        document.getElementById('act-duration').value = act.estimated_duration || '60';
        document.getElementById('act-difficulty').value = act.difficulty_level || 'medium';
        document.getElementById('act-resources').value = act.resource_links || '';

        openModal('activity-modal');
    }

    function saveActivityRow() {
        var index = parseInt(document.getElementById('act-edit-index').value);
        var dateStr = document.getElementById('act-edit-date').value;

        var actObj = {
            activity_date: dateStr,
            day_number: 1,
            sort_order: 0,
            activity_title: document.getElementById('act-title').value,
            activity_type: document.getElementById('act-type').value,
            chapter: document.getElementById('act-chapter').value,
            subject: document.getElementById('act-subject').value,
            topic: document.getElementById('act-topic').value,
            subtopic: document.getElementById('act-subtopic').value,
            faculty: document.getElementById('act-faculty').value,
            mentor: document.getElementById('act-mentor').value,
            estimated_duration: parseInt(document.getElementById('act-duration').value) || 60,
            difficulty_level: document.getElementById('act-difficulty').value,
            resource_links: document.getElementById('act-resources').value,
            priority: 'medium'
        };

        if (index >= 0) {
            // Keep permanent database ID and UID stable across edits
            if (activities[index].id) actObj.id = activities[index].id;
            if (activities[index].activity_uid) actObj.activity_uid = activities[index].activity_uid;
            activities[index] = actObj;
        } else {
            activities.push(actObj);
        }

        closeModal('activity-modal');
        renderActivitiesList();
        updateLivePreview();
        autoSaveActivities();
    }

    var deleteIndex = -1;
    var deleteToken = '';
    var deleteExpectedCount = 0;

    function deleteActivityRow(index) {
        var act = activities[index];

        // If it's a new unsaved activity, remove it locally immediately
        if (!act.id || act.id <= 0) {
            if (confirm('Delete this new study activity?')) {
                activities.splice(index, 1);
                renderActivitiesList();
                updateLivePreview();
                autoSaveActivities();
            }
            return;
        }

        deleteIndex = index;

        // Set loading state on the trigger delete button
        var deleteBtn = document.getElementById('act-delete-btn-' + index);
        if (deleteBtn) {
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        var formData = new FormData();
        formData.append('activity_id', act.id);

        fetch('api/studyplans-api.php?action=check_activity_delete', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (deleteBtn) {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
            }

            if (data.success) {
                deleteToken = data.confirmation_token;
                deleteExpectedCount = data.student_count;

                var titleEl = document.getElementById('delete-warning-title');
                var msgEl = document.getElementById('delete-warning-message');
                var iconEl = document.getElementById('delete-warning-icon');
                var confirmBtn = document.getElementById('confirm-delete-btn');
                var reasonContainer = document.getElementById('delete-reason-container');
                var reasonInput = document.getElementById('delete-reason-input');

                reasonInput.value = 'Admin deleted';

                if (data.student_count > 0) {
                    titleEl.innerText = 'Delete Activity — Student Data Warning';
                    iconEl.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
                    iconEl.style.color = '#ef4444';
                    msgEl.innerHTML = `⚠️ <strong>${data.student_count}</strong> student(s) have completed this activity.<br><br>The activity will be removed from the active study plan, but historical student completion records will be preserved for reporting.<br><br>This action cannot be automatically undone.`;
                    reasonContainer.style.display = 'block';
                } else {
                    titleEl.innerText = 'Delete Activity';
                    iconEl.innerHTML = '<i class="fas fa-circle-question"></i>';
                    iconEl.style.color = '#3b82f6';
                    msgEl.innerHTML = 'Are you sure you want to delete this activity?<br><br>No student completion data has been recorded yet.';
                    reasonContainer.style.display = 'block';
                }

                openModal('delete-warning-modal');
            } else {
                alert(data.message || 'Error checking activity deletion.');
            }
        })
        .catch(err => {
            if (deleteBtn) {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
            }
            console.error(err);
            alert('Connection error occurred while checking activity.');
        });
    }

    function executeDeleteActivity() {
        if (deleteIndex < 0 || !deleteToken) return;

        var confirmBtn = document.getElementById('confirm-delete-btn');
        var reasonInput = document.getElementById('delete-reason-input');

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

        var formData = new FormData();
        formData.append('activity_id', activities[deleteIndex].id);
        formData.append('confirmation_token', deleteToken);
        formData.append('expected_count', deleteExpectedCount);
        formData.append('deletion_reason', reasonInput.value);
        formData.append('version', studyPlanVersion);

        fetch('api/studyplans-api.php?action=delete_activity', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-trash"></i> Delete Activity';

            if (data.success) {
                if (data.version) {
                    studyPlanVersion = data.version;
                }
                activities.splice(deleteIndex, 1);
                renderActivitiesList();
                updateLivePreview();
                closeModal('delete-warning-modal');

                deleteIndex = -1;
                deleteToken = null;
                deleteExpectedCount = 0;
            } else if (data.error_code === 'STALE_STUDY_PLAN') {
                closeModal('delete-warning-modal');
                openModal('stale-warning-modal');
            } else if (data.count_changed) {
                alert(data.message);
                // Re-open warning dialog to get the fresh token & count
                closeModal('delete-warning-modal');
                deleteActivityRow(deleteIndex);
            } else {
                alert(data.message || 'Error deleting activity.');
            }
        })
        .catch(err => {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-trash"></i> Delete Activity';
            console.error(err);
            alert('Connection error occurred while deleting activity.');
        });
    }

    function cloneActivityRow(index) {
        var act = activities[index];
        var cloned = JSON.parse(JSON.stringify(act));

        // Strip out database ID and permanent UID so the clone represents a brand-new task
        delete cloned.id;
        delete cloned.activity_uid;

        // Insert right after the original item
        activities.splice(index + 1, 0, cloned);
        renderActivitiesList();
        updateLivePreview();
        autoSaveActivities();
    }

    function triggerImport() {
        openModal('import-modal');
    }

    function processBulkImport() {
        var fileEl = document.getElementById('import-file');
        if (!fileEl.files || fileEl.files.length === 0) {
            alert('Please select a file first.');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'import_activities');
        formData.append('file', fileEl.files[0]);
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');

        fetch('api/studyplans-api.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Populate parsed list
                activities = activities.concat(data.parsed);
                closeModal('import-modal');
                reindexSortOrder();
            } else {
                alert('Import Errors: \n' + (data.errors ? data.errors.join('\n') : data.message));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Bulk import server connection error.');
        });
    }

    function updateLivePreview() {
        var wrapper = document.getElementById('live-preview-wrapper');
        var theme = document.getElementById('plan-theme').value;
        var layout = document.getElementById('plan-layout').value;

        var title = document.getElementById('plan-title').value;
        var desc = document.getElementById('plan-desc').value;
        var quote = document.getElementById('plan-quote').value;

        // Reset classes
        wrapper.className = 'preview-phone-frame theme-' + theme + ' layout-' + layout;

        var html = '<div style="padding:10px; border-bottom:1px solid var(--border); margin-bottom:16px; display:flex; align-items:center; gap:8px;">' +
                        '<div style="width:36px; height:36px; border-radius:50%; background:var(--accent-soft); display:flex; align-items:center; justify-content:center; color:var(--accent); font-weight:800;">P</div>' +
                        '<div>' +
                            '<h4 style="margin:0; font-size:0.9rem; font-weight:800; color:var(--text-main);">' + title + '</h4>' +
                            '<small style="color:var(--text-muted); font-size:0.75rem;">PEPP Journey Plan</small>' +
                        '</div>' +
                   '</div>';

        if (quote) {
            html += '<div style="background:var(--accent-soft); border-left:4px solid var(--accent); padding:10px; border-radius:4px; font-style:italic; font-size:0.8rem; margin-bottom:16px; color:var(--text-main);">' +
                        '"' + quote + '"' +
                    '</div>';
        }

        if (activities.length === 0) {
            html += '<div style="text-align:center; padding:3rem 0; color:var(--text-muted); font-size:0.85rem;"><i class="fas fa-calendar-day" style="font-size:2rem; margin-bottom:6px; display:block;"></i>No schedules added yet. Use designer panel to populate items.</div>';
        } else {
            // Group by date
            var grouped = {};
            activities.forEach(function(act) {
                if (!grouped[act.activity_date]) grouped[act.activity_date] = [];
                grouped[act.activity_date].push(act);
            });

            var isDateWise = document.getElementById('type-date-wise').checked;

            html += '<div class="timeline-wrapper">';
            Object.keys(grouped).forEach(function(date) {
                var items = grouped[date];
                var dateLabel = date;
                if (!isDateWise) {
                    var dayNum = (items && items[0] && items[0].day_number) ? items[0].day_number : 1;
                    dateLabel = "Day " + String(dayNum).padStart(2, '0');
                } else {
                    try {
                        var dObj = new Date(date);
                        dateLabel = dObj.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
                    } catch(e) {}
                }

                html += '<div class="timeline-day-node">' +
                            '<div class="timeline-badge"></div>' +
                            '<div class="timeline-card">' +
                                '<div class="timeline-date-label">' + dateLabel + '</div>' +
                                '<div style="display:flex; flex-direction:column; gap:8px; margin-bottom:10px;">';

                items.forEach(function(it) {
                    var conf = predefinedTypes[it.activity_type] || {icon: 'fa-book-open', color: '#64748b'};
                    html += '<div class="activity-item" style="display:flex; align-items:center; justify-content:space-between; gap:10px; border-bottom:1px solid var(--border); padding:8px 0;">' +
                                '<div style="display:flex; align-items:center; gap:8px; flex:1;">' +
                                    '<div style="background:' + conf.color + '; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; flex-shrink:0;">' +
                                        '<i class="fas ' + conf.icon + '" style="font-size:0.85rem;"></i>' +
                                    '</div>' +
                                    '<div>' +
                                        '<div style="font-size:0.8rem; font-weight:700; color:var(--text-main);">' + it.activity_title + '</div>' +
                                        '<div style="font-size:0.7rem; color:var(--text-muted);">' + (it.subject || 'General') + ' · ' + (it.chapter || 'Academics') + '</div>' +
                                    '</div>' +
                                '</div>' +
                                '<span style="margin-left:auto; background:var(--accent-soft); border-radius:4px; font-size:0.65rem; font-weight:700; padding:2px 6px; color:var(--accent); flex-shrink:0;">' + (it.estimated_duration || 60) + 'm</span>' +
                            '</div>';
                });

                html += '</div></div></div>';
            });
            html += '</div>';
        }

        wrapper.innerHTML = html;
    }

    function saveStudyPlan() {
        var title = document.getElementById('plan-title').value;
        var year = document.getElementById('plan-year').value;

        if (!title || !year) {
            alert('Study Plan Title and Academic Year are required.');
            return;
        }

        var assignments = [];
        document.querySelectorAll('input[name="access_courses[]"]:checked').forEach(function(el) {
            assignments.push({ type: 'course', value: el.value });
        });
        document.querySelectorAll('input[name="access_forms[]"]:checked').forEach(function(el) {
            assignments.push({ type: 'form', value: el.value });
        });

        var isDateWise = document.getElementById('type-date-wise').checked;
        var startInput = isDateWise ? document.getElementById('plan-start').value : '2000-01-01';
        var endInput = '2000-01-01';
        var totalDays = parseInt(document.getElementById('plan-total-days').value) || 7;

        if (isDateWise) {
            endInput = document.getElementById('plan-end').value;
        } else {
            var endD = new Date('2000-01-01');
            endD.setDate(endD.getDate() + totalDays - 1);
            var yyyy = endD.getFullYear();
            var mm = String(endD.getMonth() + 1).padStart(2, '0');
            var dd = String(endD.getDate()).padStart(2, '0');
            endInput = yyyy + '-' + mm + '-' + dd;
        }

        var planData = {
            id: studyPlanId,
            version: studyPlanVersion,
            title: title,
            academic_year: year,
            course_id: document.getElementById('plan-course').value,
            description: document.getElementById('plan-desc').value,
            theme: document.getElementById('plan-theme').value,
            layout: document.getElementById('plan-layout').value,
            plan_type: isDateWise ? 'date_wise' : 'day_wise',
            total_days: isDateWise ? null : totalDays,
            start_date: startInput,
            end_date: endInput,
            is_template: document.getElementById('plan-template').checked ? 1 : 0,
            status: document.getElementById('status-published').checked ? 'published' : 'draft',
            assignments: assignments,
            custom_settings: {
                quote: document.getElementById('plan-quote').value
            }
        };

        // Save plan first, then save activities list
        fetch('api/studyplans-api.php?action=save_plan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(planData)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                var newPlanId = data.plan_id;
                if (data.version) {
                    studyPlanVersion = data.version;
                }
                // Save activities
                fetch('api/studyplans-api.php?action=save_activities', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        study_plan_id: newPlanId,
                        version: studyPlanVersion,
                        activities: activities
                    })
                })
                .then(r2 => r2.json())
                .then(data2 => {
                    if (data2.success) {
                        hasUnsavedChanges = false;
                        alert('Study Plan & all schedules saved successfully!');
                        window.location.href = 'studyplans.php';
                    } else if (data2.error_code === 'STALE_STUDY_PLAN') {
                        openModal('stale-warning-modal');
                    } else if (data2.requires_assessment_confirm) {
                        if (confirm('⚠️ Assessment Results Warning\n\n' + data2.message + '\n\nClick OK to proceed or Cancel to abort.')) {
                            fetch('api/studyplans-api.php?action=save_activities', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ study_plan_id: newPlanId, version: studyPlanVersion, activities: activities, confirm_activity_replace: true })
                            }).then(r3 => r3.json()).then(data3 => {
                                if (data3.success) { hasUnsavedChanges = false; alert('Study Plan & all schedules saved successfully!'); window.location.href = 'studyplans.php'; }
                                else if (data3.error_code === 'STALE_STUDY_PLAN') { openModal('stale-warning-modal'); }
                                else { alert('Failed to save activities: ' + data3.message); }
                            });
                        }
                    } else {
                        alert('Failed to save activities: ' + data2.message);
                    }
                });
            } else if (data.error_code === 'STALE_STUDY_PLAN') {
                openModal('stale-warning-modal');
            } else {
                alert('Save failed: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Server connection error.');
        });
    }

    /* ── AUTOSAVE & EXIT CONFIRMATION LOGIC ── */
    var hasUnsavedChanges = false;

    function markUnsavedChanges() {
        hasUnsavedChanges = true;
    }

    function autoSaveActivities() {
        if (studyPlanId <= 0) {
            hasUnsavedChanges = true;
            return;
        }

        var indicator = document.getElementById('autosave-indicator');
        if (indicator) {
            indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Auto-saving...';
            indicator.style.color = '#f59e0b';
            indicator.style.display = 'inline-flex';
        }

        fetch('api/studyplans-api.php?action=save_activities', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                study_plan_id: studyPlanId,
                version: studyPlanVersion,
                activities: activities
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (data.version) {
                    studyPlanVersion = data.version;
                }
                // Map the newly assigned database IDs & UIDs back to the local array
                if (data.activities && activities.length === data.activities.length) {
                    for (var i = 0; i < activities.length; i++) {
                        activities[i].id = data.activities[i].id;
                        activities[i].activity_uid = data.activities[i].activity_uid;
                    }
                    renderActivitiesList();
                }
                if (indicator) {
                    indicator.innerHTML = '<i class="fas fa-circle-check"></i> Changes saved';
                    indicator.style.color = '#10b981';
                    setTimeout(function() {
                        if (indicator.innerHTML.includes('Changes saved')) {
                            indicator.style.display = 'none';
                        }
                    }, 3000);
                }
                hasUnsavedChanges = false;
            } else if (data.error_code === 'STALE_STUDY_PLAN') {
                if (indicator) {
                    indicator.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Conflict: Stale Version';
                    indicator.style.color = '#f59e0b';
                }
                openModal('stale-warning-modal');
                hasUnsavedChanges = true;
            } else if (data.requires_assessment_confirm) {
                if (indicator) { indicator.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Confirmation needed'; indicator.style.color = '#f59e0b'; }
                if (confirm('⚠️ Assessment Results Warning\n\n' + data.message + '\n\nClick OK to proceed or Cancel to abort.')) {
                    fetch('api/studyplans-api.php?action=save_activities', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ study_plan_id: studyPlanId, version: studyPlanVersion, activities: activities, confirm_activity_replace: true })
                    }).then(r2 => r2.json()).then(d2 => {
                        if (d2.success) {
                            if (d2.version) {
                                studyPlanVersion = d2.version;
                            }
                            if (d2.activities && activities.length === d2.activities.length) {
                                for (var i = 0; i < activities.length; i++) {
                                    activities[i].id = d2.activities[i].id;
                                    activities[i].activity_uid = d2.activities[i].activity_uid;
                                }
                                renderActivitiesList();
                            }
                            if (indicator) { indicator.innerHTML = '<i class="fas fa-circle-check"></i> Changes saved'; indicator.style.color = '#10b981'; }
                            hasUnsavedChanges = false;
                        } else if (d2.error_code === 'STALE_STUDY_PLAN') {
                            if (indicator) { indicator.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Conflict: Stale Version'; indicator.style.color = '#f59e0b'; }
                            openModal('stale-warning-modal');
                            hasUnsavedChanges = true;
                        }
                        else { if (indicator) { indicator.innerHTML = '<i class="fas fa-circle-xmark"></i> Save failed'; indicator.style.color = '#ef4444'; } }
                    });
                } else { if (indicator) { indicator.innerHTML = '<i class="fas fa-ban"></i> Save cancelled'; indicator.style.color = '#ef4444'; } }
            } else {
                if (indicator) {
                    indicator.innerHTML = '<i class="fas fa-circle-xmark"></i> Save failed';
                    indicator.style.color = '#ef4444';
                }
                hasUnsavedChanges = true;
            }
        })
        .catch(err => {
            console.error(err);
            if (indicator) {
                indicator.innerHTML = '<i class="fas fa-circle-xmark"></i> Connection error';
                indicator.style.color = '#ef4444';
            }
            hasUnsavedChanges = true;
        });
    }

    function confirmBack() {
        if (hasUnsavedChanges) {
            openModal('exit-confirm-modal');
        } else {
            window.location.href = 'studyplans.php';
        }
    }

    function saveAndExit() {
        closeModal('exit-confirm-modal');
        saveStudyPlan();
    }

    function exitWithoutSaving() {
        hasUnsavedChanges = false;
        window.location.href = 'studyplans.php';
    }

    window.addEventListener('beforeunload', function (e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave without saving?';
            return e.returnValue;
        }
    });
</script>

<?php include 'includes/admin_footer.php'; ?>
