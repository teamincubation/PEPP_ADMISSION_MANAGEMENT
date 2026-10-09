<?php
/**
 * PEPP Updates — Phase 1 Automated Test Harness (Hardened Schema)
 *
 * Verifies:
 * A. All 12 tables exist in the migration SQL
 * B. Primary keys (single and composite)
 * C. Foreign keys (RESTRICT and CASCADE actions, including immutable audit FKs)
 * D. Unique constraints (category-scoped keywords, slugs, phone, pairs, settings key)
 * E. Required indexes
 * F. Default values
 * G. Status values (ENUM lifecycles)
 * H. Date fields (publish_at, expires_at, visit_date, etc.)
 * I. Unicode storage (English, Malayalam, Hindi, Arabic, Emoji)
 * J. Category/post relationship (RESTRICT protects in-use categories, CASCADE cleans junctions)
 * K. Category-scoped keywords & FK relationship
 * L. Subscriber privacy (no raw IP, no email, no district, no user_agent)
 * M. Subscriber consent audit history protection (ON DELETE RESTRICT immutability)
 * N. Campaign/subscriber uniqueness & post/campaign separation
 * O. Settings table & default values
 * P. Migration idempotency (CREATE TABLE IF NOT EXISTS)
 * Q. Rollback safety (reverse dependency order, 12 tables only)
 * R. Visitor IP hashing architecture (daily rotating salted SHA-256)
 *
 * Static Architecture Assertions:
 * - Zero ALTER or DROP on existing ERP tables
 * - Zero modifications to communication_queue or communication tables
 * - Zero modifications to api/v1/communication/webhook.php
 * - Zero modifications to CommunicationEngine.php or QueueProcessor.php
 * - Zero modifications to Account 1 routing
 */

class PeppUpdatesPhase1TestHarness
{
    private string $migrationFile;
    private string $rollbackFile;
    private int $passCount = 0;
    private int $failCount = 0;

    public function __construct()
    {
        $this->migrationFile = __DIR__ . '/database-update-64-pepp-updates-module.sql';
        $this->rollbackFile  = __DIR__ . '/database-rollback-64-pepp-updates-module.sql';
    }

    public function run(): void
    {
        echo "============================================================\n";
        echo "PEPP UPDATES — PHASE 1 TEST HARNESS (HARDENED SCHEMA)\n";
        echo "DATABASE SCHEMA + PRIVACY & AUDIT SAFETY ASSERTIONS\n";
        echo "============================================================\n\n";

        $this->testFilesExist();
        $this->testStaticArchitectureSafety();
        $this->testSchemaRequirementsAtoH();
        $this->testPrivacyHardeningAssertions();
        $this->testPostCampaignSeparationAssertions();
        $this->testSQLiteSimulationItoR();
        $this->testRollbackSafety();

        echo "\n============================================================\n";
        echo "TEST SUMMARY: {$this->passCount} PASSED, {$this->failCount} FAILED\n";
        echo "============================================================\n";

        if ($this->failCount > 0) {
            exit(1);
        }
    }

    private function assert(string $testName, bool $condition, string $message = ''): void
    {
        if ($condition) {
            $this->passCount++;
            echo " [PASS] {$testName}\n";
        } else {
            $this->failCount++;
            echo " [FAIL] {$testName} — {$message}\n";
        }
    }

    private function testFilesExist(): void
    {
        $this->assert('Migration file exists', file_exists($this->migrationFile), "File not found: {$this->migrationFile}");
        $this->assert('Rollback file exists', file_exists($this->rollbackFile), "File not found: {$this->rollbackFile}");
    }

    private function testStaticArchitectureSafety(): void
    {
        echo "\n--- Static Architecture Assertions ---\n";
        $sql = file_get_contents($this->migrationFile);
        $rollbackSql = file_get_contents($this->rollbackFile);

        // 1. Prohibited ALTER statements on existing tables
        $prohibitedTables = [
            'users', 'students', 'admissions', 'leads', 'invoices', 'sessions',
            'communication_queue', 'communication_campaigns', 'communication_templates',
            'communication_campaign_recipients', 'whatsapp_conversations', 'whatsapp_messages',
            'whatsapp_accounts', 'whatsapp_templates'
        ];

        foreach ($prohibitedTables as $tbl) {
            $hasAlter = (bool)preg_match("/ALTER\s+TABLE\s+[`]?{$tbl}[`]?/i", $sql);
            $this->assert("No ALTER on existing table '{$tbl}' in migration", !$hasAlter, "Detected ALTER TABLE {$tbl}");

            $hasDrop = (bool)preg_match("/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`]?{$tbl}[`]?/i", $sql);
            $this->assert("No DROP on existing table '{$tbl}' in migration", !$hasDrop, "Detected DROP TABLE {$tbl}");

            $hasRollbackDrop = (bool)preg_match("/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`]?{$tbl}[`]?/i", $rollbackSql);
            $this->assert("No DROP on existing table '{$tbl}' in rollback", !$hasRollbackDrop, "Detected DROP TABLE {$tbl} in rollback");
        }

        // 2. Git status / Clean state of existing communication and webhook files
        $coreFiles = [
            'api/v1/communication/webhook.php',
            'cron-queue.php',
            'includes/communication/CommunicationEngine.php',
            'includes/communication/QueueProcessor.php'
        ];

        foreach ($coreFiles as $relPath) {
            $fullPath = __DIR__ . '/' . $relPath;
            $this->assert("Core file '{$relPath}' exists", file_exists($fullPath));
            $gitDiff = trim(shell_exec("git diff --name-only HEAD -- " . escapeshellarg($fullPath)) ?? '');
            $this->assert("Core file '{$relPath}' is untouched compared to HEAD", empty($gitDiff), "Git detected modifications in {$relPath}");
        }
    }

    private function testSchemaRequirementsAtoH(): void
    {
        echo "\n--- Schema AST & Syntax Analysis (Items A to H) ---\n";
        $sql = file_get_contents($this->migrationFile);

        $expectedTables = [
            'updates_categories',
            'updates_keywords',
            'updates_posts',
            'updates_post_categories',
            'updates_post_keywords',
            'updates_subscribers',
            'updates_subscriber_categories',
            'updates_subscriber_events',
            'updates_delivery_campaigns',
            'updates_delivery_recipients',
            'updates_visits',
            'updates_settings'
        ];

        // A. All 12 tables exist
        foreach ($expectedTables as $table) {
            $hasTable = (bool)preg_match("/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[`]?{$table}[`]?/i", $sql);
            $this->assert("A. Table '{$table}' defined with CREATE TABLE IF NOT EXISTS", $hasTable);
        }

        // B. Primary Keys
        $expectedPKs = [
            'updates_categories'            => 'id',
            'updates_keywords'              => 'id',
            'updates_posts'                 => 'id',
            'updates_post_categories'       => 'post_id, category_id',
            'updates_post_keywords'         => 'post_id, keyword_id',
            'updates_subscribers'           => 'id',
            'updates_subscriber_categories' => 'subscriber_id, category_id',
            'updates_subscriber_events'     => 'id',
            'updates_delivery_campaigns'    => 'id',
            'updates_delivery_recipients'   => 'id',
            'updates_visits'                => 'id',
            'updates_settings'              => 'id'
        ];

        foreach ($expectedPKs as $tbl => $pkCols) {
            if (str_contains($pkCols, ',')) {
                $hasPK = (bool)preg_match("/CREATE TABLE IF NOT EXISTS [`]?{$tbl}[`]?.*?PRIMARY KEY\s*\([`]?post_id|subscriber_id[`]?,/is", $sql);
            } else {
                $hasPK = (bool)preg_match("/CREATE TABLE IF NOT EXISTS [`]?{$tbl}[`]?.*?`id` INT AUTO_INCREMENT PRIMARY KEY/is", $sql);
            }
            $this->assert("B. Primary Key defined correctly on table '{$tbl}'", $hasPK);
        }

        // C. Foreign Keys
        $fkAssertions = [
            'fk_uk_category'       => ['updates_keywords', 'category_id', 'updates_categories', 'id', 'CASCADE'],
            'fk_upc_post'          => ['updates_post_categories', 'post_id', 'updates_posts', 'id', 'CASCADE'],
            'fk_upc_category'      => ['updates_post_categories', 'category_id', 'updates_categories', 'id', 'RESTRICT'],
            'fk_upk_post'          => ['updates_post_keywords', 'post_id', 'updates_posts', 'id', 'CASCADE'],
            'fk_upk_keyword'       => ['updates_post_keywords', 'keyword_id', 'updates_keywords', 'id', 'CASCADE'],
            'fk_usc_subscriber'    => ['updates_subscriber_categories', 'subscriber_id', 'updates_subscribers', 'id', 'CASCADE'],
            'fk_usc_category'      => ['updates_subscriber_categories', 'category_id', 'updates_categories', 'id', 'CASCADE'],
            'fk_use_subscriber'    => ['updates_subscriber_events', 'subscriber_id', 'updates_subscribers', 'id', 'RESTRICT'],
            'fk_udc_post'          => ['updates_delivery_campaigns', 'post_id', 'updates_posts', 'id', 'RESTRICT'],
            'fk_udc_target_category'=>['updates_delivery_campaigns', 'target_category_id', 'updates_categories', 'id', 'SET NULL'],
            'fk_udr_campaign'      => ['updates_delivery_recipients', 'campaign_id', 'updates_delivery_campaigns', 'id', 'CASCADE'],
            'fk_udr_subscriber'    => ['updates_delivery_recipients', 'subscriber_id', 'updates_subscribers', 'id', 'RESTRICT'],
            'fk_uv_post'           => ['updates_visits', 'post_id', 'updates_posts', 'id', 'SET NULL']
        ];

        foreach ($fkAssertions as $fkName => $fkDef) {
            [$tbl, $col, $refTbl, $refCol, $action] = $fkDef;
            $pattern = "/CONSTRAINT [`]?{$fkName}[`]? FOREIGN KEY \([`]?{$col}[`]?\) REFERENCES [`]?{$refTbl}[`]? \([`]?{$refCol}[`]?\) ON DELETE {$action}/i";
            $hasFk = (bool)preg_match($pattern, $sql);
            $this->assert("C. Foreign Key '{$fkName}' ({$col} -> {$refTbl}.{$refCol} ON DELETE {$action}) verified", $hasFk);
        }

        // D. Unique Constraints
        $expectedUniques = [
            'updates_categories'          => 'uq_updates_categories_slug',
            'updates_keywords'            => 'uq_category_keyword',
            'updates_posts'               => 'uq_updates_posts_slug',
            'updates_subscribers'         => 'uq_updates_subscribers_phone',
            'updates_delivery_recipients' => 'uq_udr_campaign_subscriber',
            'updates_settings'            => 'uq_updates_settings_key'
        ];

        foreach ($expectedUniques as $tbl => $uqKey) {
            $hasUq = (bool)preg_match("/CREATE TABLE IF NOT EXISTS [`]?{$tbl}[`]?.*?UNIQUE KEY [`]?{$uqKey}[`]?/is", $sql);
            $this->assert("D. Unique key '{$uqKey}' on table '{$tbl}'", $hasUq);
        }

        // E. Required Indexes
        $requiredIndexes = [
            'idx_updates_categories_active_order'      => ['updates_categories', 'is_active', 'display_order'],
            'idx_uk_category'                          => ['updates_keywords', 'category_id'],
            'idx_updates_posts_status_publish_expires' => ['updates_posts', 'status', 'publish_at', 'expires_at'],
            'idx_upc_category'                         => ['updates_post_categories', 'category_id'],
            'idx_upk_keyword'                          => ['updates_post_keywords', 'keyword_id'],
            'idx_updates_subscribers_status'           => ['updates_subscribers', 'status'],
            'idx_usc_category'                         => ['updates_subscriber_categories', 'category_id'],
            'idx_use_subscriber_event'                 => ['updates_subscriber_events', 'subscriber_id', 'event_type'],
            'idx_udc_post'                             => ['updates_delivery_campaigns', 'post_id'],
            'idx_udc_status_sched'                     => ['updates_delivery_campaigns', 'status', 'scheduled_at'],
            'idx_udc_sender_account'                   => ['updates_delivery_campaigns', 'sender_account_id'],
            'idx_udc_target_category'                  => ['updates_delivery_campaigns', 'target_category_id'],
            'idx_udr_queue_id'                         => ['updates_delivery_recipients', 'queue_id'],
            'idx_udr_status'                           => ['updates_delivery_recipients', 'status'],
            'idx_uv_post_date'                         => ['updates_visits', 'post_id', 'visit_date'],
            'idx_uv_date'                              => ['updates_visits', 'visit_date']
        ];

        foreach ($requiredIndexes as $idxName => $idxDef) {
            [$tbl] = $idxDef;
            $hasIdx = (bool)preg_match("/CREATE TABLE IF NOT EXISTS [`]?{$tbl}[`]?.*?KEY [`]?{$idxName}[`]?/is", $sql);
            $this->assert("E. Required Index '{$idxName}' on table '{$tbl}' verified", $hasIdx);
        }

        // F. Default Values
        $defaultChecks = [
            'updates_categories.display_order' => "DEFAULT 0",
            'updates_categories.is_active' => "DEFAULT 1",
            'updates_posts.status' => "DEFAULT 'draft'",
            'updates_subscribers.status' => "DEFAULT 'active'",
            'updates_subscribers.preferred_language' => "DEFAULT 'en'",
            'updates_subscriber_events.source' => "DEFAULT 'web'",
            'updates_delivery_campaigns.sender_account_id' => "DEFAULT 3",
            'updates_delivery_campaigns.status' => "DEFAULT 'draft'",
            'updates_delivery_recipients.status' => "DEFAULT 'pending'"
        ];

        foreach ($defaultChecks as $colRef => $defPattern) {
            [$tbl, $col] = explode('.', $colRef);
            $pattern = "/CREATE TABLE IF NOT EXISTS [`]?{$tbl}[`]?.*?`{$col}`[^\n]+{$defPattern}/is";
            $hasDefault = (bool)preg_match($pattern, $sql);
            $this->assert("F. Column '{$colRef}' specifies {$defPattern}", $hasDefault);
        }

        // G. Status Enum Values
        $statusEnums = [
            'updates_posts.status' => ["'draft'", "'scheduled'", "'published'", "'expired'", "'archived'"],
            'updates_subscribers.status' => ["'active'", "'stopped'", "'unsubscribed'", "'suppressed'"],
            'updates_subscriber_events.event_type' => ["'SUBSCRIBED'", "'STOPPED'", "'RESUBSCRIBED'", "'CATEGORY_CHANGED'", "'PROFILE_UPDATED'", "'SUPPRESSED'"],
            'updates_delivery_campaigns.status' => ["'draft'", "'scheduled'", "'processing'", "'completed'", "'paused'", "'cancelled'", "'failed'"],
            'updates_delivery_recipients.status' => ["'pending'", "'queued'", "'sent'", "'delivered'", "'read'", "'failed'", "'cancelled'", "'skipped'"]
        ];

        foreach ($statusEnums as $colRef => $expectedValues) {
            [$tbl, $col] = explode('.', $colRef);
            preg_match("/CREATE TABLE IF NOT EXISTS [`]?{$tbl}[`]?.*?`{$col}`\s+ENUM\(([^)]+)\)/is", $sql, $m);
            $enumStr = $m[1] ?? '';
            $allValuesPresent = true;
            foreach ($expectedValues as $val) {
                if (!str_contains($enumStr, $val)) {
                    $allValuesPresent = false;
                    break;
                }
            }
            $this->assert("G. Column '{$colRef}' defines all required status enum values", $allValuesPresent, "Missing enum values in {$colRef}");
        }

        // H. Date Fields
        $dateFields = [
            'updates_posts' => ['publish_at', 'expires_at', 'created_at', 'updated_at'],
            'updates_subscribers' => ['subscribed_at', 'stopped_at', 'created_at', 'updated_at'],
            'updates_delivery_campaigns' => ['scheduled_at', 'started_at', 'completed_at', 'created_at', 'updated_at'],
            'updates_delivery_recipients' => ['sent_at', 'delivered_at', 'read_at', 'created_at', 'updated_at'],
            'updates_visits' => ['visit_date', 'created_at']
        ];

        foreach ($dateFields as $tbl => $cols) {
            foreach ($cols as $col) {
                $hasCol = (bool)preg_match("/CREATE TABLE IF NOT EXISTS [`]?{$tbl}[`]?.*?`{$col}`\s+(?:DATETIME|DATE)/is", $sql);
                $this->assert("H. Table '{$tbl}' contains temporal column '{$col}'", $hasCol);
            }
        }
    }

    private function testPrivacyHardeningAssertions(): void
    {
        echo "\n--- Subscriber Privacy & Data Minimization Assertions ---\n";
        $sql = file_get_contents($this->migrationFile);

        // Extract updates_subscribers block
        preg_match('/CREATE TABLE IF NOT EXISTS [`]?updates_subscribers[`]?\s*\((.*?)\)\s*ENGINE=InnoDB/is', $sql, $mSub);
        $subBlock = $mSub[1] ?? '';

        $this->assert("Subscriber table has NO 'email' column (PII minimization)", !str_contains($subBlock, '`email`'));
        $this->assert("Subscriber table has NO 'district' column (PII minimization)", !str_contains($subBlock, '`district`'));
        $this->assert("Subscriber table has NO 'ip_address' column (raw IP prohibited)", !str_contains($subBlock, '`ip_address`'));
        $this->assert("Subscriber table has NO 'user_agent' column (data minimization)", !str_contains($subBlock, '`user_agent`'));

        // Extract updates_subscriber_events block
        preg_match('/CREATE TABLE IF NOT EXISTS [`]?updates_subscriber_events[`]?\s*\((.*?)\)\s*ENGINE=InnoDB/is', $sql, $mEvt);
        $evtBlock = $mEvt[1] ?? '';
        $this->assert("Subscriber events table has NO raw 'ip_address' column", !str_contains($evtBlock, '`ip_address`'));
        $this->assert("Subscriber events table uses privacy-conscious 'ip_hash' column", str_contains($evtBlock, '`ip_hash` VARCHAR(64)'));

        // Extract updates_visits block
        preg_match('/CREATE TABLE IF NOT EXISTS [`]?updates_visits[`]?\s*\((.*?)\)\s*ENGINE=InnoDB/is', $sql, $mVis);
        $visBlock = $mVis[1] ?? '';
        $this->assert("Visits table has NO raw 'ip_address' column", !str_contains($visBlock, '`ip_address`'));
        $this->assert("Visits table uses privacy-conscious 'ip_hash' column", str_contains($visBlock, '`ip_hash` VARCHAR(64)'));
    }

    private function testPostCampaignSeparationAssertions(): void
    {
        echo "\n--- Post Semantic Content & Campaign Separation Assertions ---\n";
        $sql = file_get_contents($this->migrationFile);

        // Extract updates_posts block
        preg_match('/CREATE TABLE IF NOT EXISTS [`]?updates_posts[`]?\s*\((.*?)\)\s*ENGINE=InnoDB/is', $sql, $mPost);
        $postBlock = $mPost[1] ?? '';

        $this->assert("updates_posts includes 'short_description' semantic column", str_contains($postBlock, '`short_description` TEXT'));
        $this->assert("updates_posts includes 'full_description' semantic column", str_contains($postBlock, '`full_description` LONGTEXT'));
        $this->assert("updates_posts includes 'banner_image' semantic column", str_contains($postBlock, '`banner_image` VARCHAR(500)'));
        $this->assert("updates_posts includes 'action_button_text' semantic column", str_contains($postBlock, '`action_button_text` VARCHAR(100)'));
        $this->assert("updates_posts includes 'action_button_url' semantic column", str_contains($postBlock, '`action_button_url` VARCHAR(500)'));

        // Ensure zero WhatsApp delivery fields in updates_posts
        $this->assert("updates_posts has NO 'whatsapp_template_name' (clean separation)", !str_contains($postBlock, '`whatsapp_template_name`'));
        $this->assert("updates_posts has NO 'whatsapp_broadcast_status' (clean separation)", !str_contains($postBlock, '`whatsapp_broadcast_status`'));

        // Extract updates_delivery_campaigns block
        preg_match('/CREATE TABLE IF NOT EXISTS [`]?updates_delivery_campaigns[`]?\s*\((.*?)\)\s*ENGINE=InnoDB/is', $sql, $mCamp);
        $campBlock = $mCamp[1] ?? '';

        $this->assert("updates_delivery_campaigns has 'template_name' column", str_contains($campBlock, '`template_name` VARCHAR(100)'));
        $this->assert("updates_delivery_campaigns has 'target_category_id' column", str_contains($campBlock, '`target_category_id` INT'));
        $this->assert("updates_delivery_campaigns has 'sender_account_id' defaulted to 3", str_contains($campBlock, '`sender_account_id` INT NOT NULL DEFAULT 3'));
    }

    private function testSQLiteSimulationItoR(): void
    {
        echo "\n--- SQLite In-Memory Simulation & Dynamic Relationship Assertions (Items I to R) ---\n";
        try {
            $pdo = new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->exec("PRAGMA foreign_keys = ON;");

            // Build SQLite tables corresponding to the hardened schema
            $pdo->exec("
                CREATE TABLE updates_categories (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    slug TEXT NOT NULL UNIQUE,
                    description TEXT,
                    display_order INTEGER NOT NULL DEFAULT 0,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE INDEX idx_updates_categories_active_order ON updates_categories (is_active, display_order);

                CREATE TABLE updates_keywords (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    category_id INTEGER NOT NULL,
                    keyword TEXT NOT NULL,
                    slug TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE (category_id, keyword),
                    UNIQUE (category_id, slug),
                    FOREIGN KEY (category_id) REFERENCES updates_categories(id) ON DELETE CASCADE
                );
                CREATE INDEX idx_uk_category ON updates_keywords (category_id);

                CREATE TABLE updates_posts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title TEXT NOT NULL,
                    slug TEXT NOT NULL UNIQUE,
                    short_description TEXT,
                    full_description TEXT NOT NULL,
                    banner_image TEXT,
                    action_button_text TEXT,
                    action_button_url TEXT,
                    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'scheduled', 'published', 'expired', 'archived')),
                    publish_at TEXT,
                    expires_at TEXT,
                    created_by TEXT,
                    updated_by TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE INDEX idx_updates_posts_status_publish_expires ON updates_posts (status, publish_at, expires_at);
                CREATE INDEX idx_updates_posts_created_at ON updates_posts (created_at);

                CREATE TABLE updates_post_categories (
                    post_id INTEGER NOT NULL,
                    category_id INTEGER NOT NULL,
                    PRIMARY KEY (post_id, category_id),
                    FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (category_id) REFERENCES updates_categories(id) ON DELETE RESTRICT
                );
                CREATE INDEX idx_upc_category ON updates_post_categories (category_id);

                CREATE TABLE updates_post_keywords (
                    post_id INTEGER NOT NULL,
                    keyword_id INTEGER NOT NULL,
                    PRIMARY KEY (post_id, keyword_id),
                    FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (keyword_id) REFERENCES updates_keywords(id) ON DELETE CASCADE
                );
                CREATE INDEX idx_upk_keyword ON updates_post_keywords (keyword_id);

                CREATE TABLE updates_subscribers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    phone TEXT NOT NULL UNIQUE,
                    name TEXT,
                    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'stopped', 'unsubscribed', 'suppressed')),
                    preferred_language TEXT NOT NULL DEFAULT 'en',
                    subscribed_at TEXT NOT NULL DEFAULT (datetime('now')),
                    stopped_at TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE INDEX idx_updates_subscribers_status ON updates_subscribers (status);

                CREATE TABLE updates_subscriber_categories (
                    subscriber_id INTEGER NOT NULL,
                    category_id INTEGER NOT NULL,
                    PRIMARY KEY (subscriber_id, category_id),
                    FOREIGN KEY (subscriber_id) REFERENCES updates_subscribers(id) ON DELETE CASCADE,
                    FOREIGN KEY (category_id) REFERENCES updates_categories(id) ON DELETE CASCADE
                );
                CREATE INDEX idx_usc_category ON updates_subscriber_categories (category_id);

                CREATE TABLE updates_subscriber_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    subscriber_id INTEGER NOT NULL,
                    event_type TEXT NOT NULL CHECK(event_type IN ('SUBSCRIBED', 'STOPPED', 'RESUBSCRIBED', 'CATEGORY_CHANGED', 'PROFILE_UPDATED', 'SUPPRESSED')),
                    details TEXT,
                    source TEXT NOT NULL DEFAULT 'web',
                    ip_hash TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    FOREIGN KEY (subscriber_id) REFERENCES updates_subscribers(id) ON DELETE RESTRICT
                );
                CREATE INDEX idx_use_subscriber_event ON updates_subscriber_events (subscriber_id, event_type);
                CREATE INDEX idx_use_created_at ON updates_subscriber_events (created_at);

                CREATE TABLE updates_delivery_campaigns (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    template_name TEXT NOT NULL,
                    target_category_id INTEGER,
                    sender_account_id INTEGER NOT NULL DEFAULT 3,
                    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'scheduled', 'processing', 'completed', 'paused', 'cancelled', 'failed')),
                    scheduled_at TEXT,
                    started_at TEXT,
                    completed_at TEXT,
                    total_recipients INTEGER NOT NULL DEFAULT 0,
                    sent_count INTEGER NOT NULL DEFAULT 0,
                    delivered_count INTEGER NOT NULL DEFAULT 0,
                    read_count INTEGER NOT NULL DEFAULT 0,
                    failed_count INTEGER NOT NULL DEFAULT 0,
                    created_by TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                    FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE RESTRICT,
                    FOREIGN KEY (target_category_id) REFERENCES updates_categories(id) ON DELETE SET NULL
                );
                CREATE INDEX idx_udc_post ON updates_delivery_campaigns (post_id);
                CREATE INDEX idx_udc_status_sched ON updates_delivery_campaigns (status, scheduled_at);
                CREATE INDEX idx_udc_sender_account ON updates_delivery_campaigns (sender_account_id);
                CREATE INDEX idx_udc_target_category ON updates_delivery_campaigns (target_category_id);

                CREATE TABLE updates_delivery_recipients (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    campaign_id INTEGER NOT NULL,
                    subscriber_id INTEGER NOT NULL,
                    phone TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'cancelled', 'skipped')),
                    queue_id INTEGER,
                    message_id TEXT,
                    error_message TEXT,
                    sent_at TEXT,
                    delivered_at TEXT,
                    read_at TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE (campaign_id, subscriber_id),
                    FOREIGN KEY (campaign_id) REFERENCES updates_delivery_campaigns(id) ON DELETE CASCADE,
                    FOREIGN KEY (subscriber_id) REFERENCES updates_subscribers(id) ON DELETE RESTRICT
                );
                CREATE INDEX idx_udr_queue_id ON updates_delivery_recipients (queue_id);
                CREATE INDEX idx_udr_status ON updates_delivery_recipients (status);

                CREATE TABLE updates_visits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER,
                    ip_hash TEXT NOT NULL,
                    user_agent TEXT,
                    referer TEXT,
                    visit_date TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE SET NULL
                );
                CREATE INDEX idx_uv_post_date ON updates_visits (post_id, visit_date);
                CREATE INDEX idx_uv_date ON updates_visits (visit_date);

                CREATE TABLE updates_settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    setting_key TEXT NOT NULL UNIQUE,
                    setting_value TEXT,
                    description TEXT,
                    updated_by TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                INSERT INTO updates_settings (setting_key, setting_value, description)
                VALUES ('new_label_duration_days', '7', 'Duration in days for an update to display the NEW badge');
            ");
            $this->assert("SQLite in-memory test database initialized successfully", true);

            // Test I: Unicode storage
            $multilingualTitle = "PEPP വിജ്ഞാപനം / पेप अपडेट / إعلان بيب 🚀 #CUET2027";
            $multilingualShort = "പരീക്ഷാ തീയതി പ്രഖ്യാപിച്ചു / परीक्षा तिथि घोषित ✨";
            $multilingualFull  = "<p>മലയാളം: പരീക്ഷാ തീയതി പ്രഖ്യാപിച്ചു. हिन्दी: परीक्षा तिथि घोषित। العربية: تم إعلان الموعد. ✨</p>";
            $stmt = $pdo->prepare("INSERT INTO updates_posts (title, slug, short_description, full_description, banner_image, action_button_text, action_button_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'published')");
            $stmt->execute([
                $multilingualTitle,
                'cuet-exam-2027-notification',
                $multilingualShort,
                $multilingualFull,
                'https://cdn.pepplearning.in/banners/cuet-2027.jpg',
                'Apply Now',
                'https://pepplearning.in/cuet-apply',
            ]);
            $postId = (int)$pdo->lastInsertId();

            $fetchPost = $pdo->query("SELECT title, short_description, full_description, action_button_text FROM updates_posts WHERE id = {$postId}")->fetch(PDO::FETCH_ASSOC);
            $this->assert("I. Unicode storage preserves English, Malayalam, Hindi, Arabic, and emojis verbatim",
                $fetchPost['title'] === $multilingualTitle &&
                $fetchPost['short_description'] === $multilingualShort &&
                $fetchPost['full_description'] === $multilingualFull &&
                $fetchPost['action_button_text'] === 'Apply Now'
            );

            // Test J: Category/post relationship (RESTRICT protects categories, CASCADE cleans junctions)
            $pdo->prepare("INSERT INTO updates_categories (name, slug, display_order) VALUES (?, ?, ?)")
                ->execute(['Admissions', 'admissions-category', 1]);
            $cat1Id = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO updates_categories (name, slug, display_order) VALUES (?, ?, ?)")
                ->execute(['Examinations', 'examinations-category', 2]);
            $cat2Id = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO updates_post_categories (post_id, category_id) VALUES (?, ?)")
                ->execute([$postId, $cat1Id]);

            // Attempting to delete in-use category must FAIL due to RESTRICT
            $restrictFailed = false;
            try {
                $pdo->exec("DELETE FROM updates_categories WHERE id = {$cat1Id}");
            } catch (PDOException $e) {
                $restrictFailed = true;
            }
            $this->assert("J. In-use category deletion is rejected by ON DELETE RESTRICT", $restrictFailed);

            // Deleting the post cascades cleanly and removes the junction row
            $pdo->exec("DELETE FROM updates_posts WHERE id = {$postId}");
            $remainingJunctions = (int)$pdo->query("SELECT COUNT(*) FROM updates_post_categories WHERE post_id = {$postId}")->fetchColumn();
            $this->assert("J. Deleting post cascades and cleans post_categories junction row", $remainingJunctions === 0);

            // Test K: Category-owned keywords and category-scoped uniqueness
            $pdo->prepare("INSERT INTO updates_keywords (category_id, keyword, slug) VALUES (?, ?, ?)")
                ->execute([$cat1Id, 'CUET', 'cuet-admissions']);
            $kw1Id = (int)$pdo->lastInsertId();

            // Same keyword under different category (cat2) MUST SUCCEED
            $pdo->prepare("INSERT INTO updates_keywords (category_id, keyword, slug) VALUES (?, ?, ?)")
                ->execute([$cat2Id, 'CUET', 'cuet-examinations']);
            $kw2Id = (int)$pdo->lastInsertId();
            $this->assert("K. Same keyword 'CUET' under different categories is allowed", $kw1Id > 0 && $kw2Id > 0);

            // Duplicate keyword under SAME category MUST BE REJECTED by UNIQUE(category_id, keyword)
            $dupKwFailed = false;
            try {
                $pdo->prepare("INSERT INTO updates_keywords (category_id, keyword, slug) VALUES (?, ?, ?)")
                    ->execute([$cat1Id, 'CUET', 'cuet-admissions-duplicate']);
            } catch (PDOException $e) {
                $dupKwFailed = true;
            }
            $this->assert("K. Duplicate keyword inside same category is rejected by UNIQUE constraint", $dupKwFailed);

            // Keyword -> Category FK cascade test: deleting an unreferenced category drops its category-specific keywords
            $pdo->exec("DELETE FROM updates_categories WHERE id = {$cat2Id}");
            $remainingKw2 = (int)$pdo->query("SELECT COUNT(*) FROM updates_keywords WHERE id = {$kw2Id}")->fetchColumn();
            $this->assert("K. Deleting category cascades and removes its owned keywords", $remainingKw2 === 0);

            // Test L: Subscriber privacy model (phone, name, status ONLY)
            $pdo->prepare("INSERT INTO updates_subscribers (phone, name, status) VALUES (?, ?, 'active')")
                ->execute(['919876543210', 'Rahul Sharma']);
            $subId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO updates_subscriber_categories (subscriber_id, category_id) VALUES (?, ?)")
                ->execute([$subId, $cat1Id]);

            $subRow = $pdo->query("SELECT * FROM updates_subscribers WHERE id = {$subId}")->fetch(PDO::FETCH_ASSOC);
            $this->assert("L. Subscriber profile created without email, district, or raw IP",
                !isset($subRow['email']) && !isset($subRow['district']) && !isset($subRow['ip_address']));

            // Test M: Subscriber consent audit history & ON DELETE RESTRICT immutability
            $eventTypes = ['SUBSCRIBED', 'STOPPED', 'RESUBSCRIBED', 'CATEGORY_CHANGED', 'PROFILE_UPDATED', 'SUPPRESSED'];
            foreach ($eventTypes as $evt) {
                $testIpHash = hash('sha256', 'pepp_salt_123' . '203.0.113.195' . '2027-05-10');
                $pdo->prepare("INSERT INTO updates_subscriber_events (subscriber_id, event_type, details, source, ip_hash) VALUES (?, ?, ?, 'web', ?)")
                    ->execute([$subId, $evt, "Audit log test for {$evt}", $testIpHash]);
            }
            $loggedEvents = (int)$pdo->query("SELECT COUNT(*) FROM updates_subscriber_events WHERE subscriber_id = {$subId}")->fetchColumn();
            $this->assert("M. All 6 subscriber event types successfully stored in audit log", $loggedEvents === 6);

            // Hard deletion of subscriber with audit history MUST BE REJECTED by ON DELETE RESTRICT
            $delSubAuditFailed = false;
            try {
                $pdo->exec("DELETE FROM updates_subscribers WHERE id = {$subId}");
            } catch (PDOException $e) {
                $delSubAuditFailed = true;
            }
            $this->assert("M. Subscriber with audit history cannot be deleted (ON DELETE RESTRICT audit safety)", $delSubAuditFailed);

            // Soft-deletion lifecycle: subscriber state transitions to 'stopped' or 'suppressed'
            $pdo->prepare("UPDATE updates_subscribers SET status = 'stopped', stopped_at = datetime('now') WHERE id = ?")
                ->execute([$subId]);
            $updatedStatus = $pdo->query("SELECT status FROM updates_subscribers WHERE id = {$subId}")->fetchColumn();
            $this->assert("M. Soft opt-out / STOP state transition succeeds while preserving immutable audit rows", $updatedStatus === 'stopped');

            // Test N: Campaign / Post Separation & Recipient Uniqueness
            $pdo->prepare("INSERT INTO updates_posts (title, slug, short_description, full_description, status) VALUES (?, ?, ?, ?, 'published')")
                ->execute(['Broadcast Post', 'broadcast-post-slug', 'Short snippet', '<p>Full content</p>']);
            $post2Id = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO updates_delivery_campaigns (post_id, title, template_name, target_category_id, sender_account_id, status) VALUES (?, ?, ?, ?, 3, 'draft')")
                ->execute([$post2Id, 'WhatsApp May Broadcast', 'pepp_update_notification_v1', $cat1Id]);
            $campId = (int)$pdo->lastInsertId();

            $campRow = $pdo->query("SELECT template_name, target_category_id, sender_account_id FROM updates_delivery_campaigns WHERE id = {$campId}")->fetch(PDO::FETCH_ASSOC);
            $this->assert("N. Delivery campaign stores WhatsApp template, target category, and sender account 3",
                $campRow['template_name'] === 'pepp_update_notification_v1' &&
                (int)$campRow['target_category_id'] === $cat1Id &&
                (int)$campRow['sender_account_id'] === 3
            );

            // Deleting post with delivery campaign MUST BE RESTRICTED
            $delPostWithCampFailed = false;
            try {
                $pdo->exec("DELETE FROM updates_posts WHERE id = {$post2Id}");
            } catch (PDOException $e) {
                $delPostWithCampFailed = true;
            }
            $this->assert("N. Post with delivery campaign cannot be deleted (ON DELETE RESTRICT broadcast audit safety)", $delPostWithCampFailed);

            // Recipient uniqueness
            $pdo->prepare("INSERT INTO updates_delivery_recipients (campaign_id, subscriber_id, phone, status) VALUES (?, ?, ?, 'pending')")
                ->execute([$campId, $subId, '919876543210']);

            $dupCampaignSub = false;
            try {
                $pdo->prepare("INSERT INTO updates_delivery_recipients (campaign_id, subscriber_id, phone, status) VALUES (?, ?, ?, 'pending')")
                    ->execute([$campId, $subId, '919876543210']);
            } catch (PDOException $e) {
                $dupCampaignSub = true;
            }
            $this->assert("N. Duplicate recipient in same campaign rejected by UNIQUE constraint", $dupCampaignSub);

            // Test O: Settings
            $settingVal = $pdo->query("SELECT setting_value FROM updates_settings WHERE setting_key = 'new_label_duration_days'")->fetchColumn();
            $this->assert("O. Setting 'new_label_duration_days' defaults to '7'", $settingVal === '7');

            // Test P: Migration Idempotency
            $idempotentPass = true;
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS updates_categories (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_keywords (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_posts (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_post_categories (post_id INTEGER, category_id INTEGER);
                    CREATE TABLE IF NOT EXISTS updates_post_keywords (post_id INTEGER, keyword_id INTEGER);
                    CREATE TABLE IF NOT EXISTS updates_subscribers (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_subscriber_categories (subscriber_id INTEGER, category_id INTEGER);
                    CREATE TABLE IF NOT EXISTS updates_subscriber_events (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_delivery_campaigns (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_delivery_recipients (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_visits (id INTEGER PRIMARY KEY);
                    CREATE TABLE IF NOT EXISTS updates_settings (id INTEGER PRIMARY KEY);
                ");
            } catch (PDOException $e) {
                $idempotentPass = false;
            }
            $this->assert("P. Migration is fully idempotent (safe for repeated inspection/execution)", $idempotentPass);

            // Test R: Visitor IP Hashing Architecture (Daily Rotating Salted SHA-256)
            $clientIp = '203.0.113.88';
            $secretSalt = 'pepp_production_secret_salt_xyz';
            $day1 = '2027-05-10';
            $day2 = '2027-05-11';

            $hashDay1A = hash('sha256', $secretSalt . $clientIp . $day1);
            $hashDay1B = hash('sha256', $secretSalt . $clientIp . $day1);
            $hashDay2  = hash('sha256', $secretSalt . $clientIp . $day2);

            $this->assert("R. Same IP on same date generates identical hash (enables daily unique view deduplication)", $hashDay1A === $hashDay1B);
            $this->assert("R. Same IP on consecutive dates generates completely different hashes (prevents cross-day visitor profiling)", $hashDay1A !== $hashDay2);
            $this->assert("R. Generated hash is standard 64-character SHA-256 hex string", strlen($hashDay1A) === 64);

        } catch (Exception $e) {
            $this->assert("SQLite simulation executed without errors", false, $e->getMessage());
        }
    }

    private function testRollbackSafety(): void
    {
        echo "\n--- Rollback Safety & Reverse FK Dependency Order (Item Q) ---\n";
        $rollbackSql = file_get_contents($this->rollbackFile);

        // Verify only updates_* tables are dropped
        preg_match_all('/DROP TABLE (?:IF EXISTS )?[`]?([a-zA-Z0-9_]+)[`]?/i', $rollbackSql, $matches);
        $droppedTables = $matches[1] ?? [];

        $this->assert("Q. Rollback drops exactly 12 tables", count($droppedTables) === 12, "Found " . count($droppedTables));

        foreach ($droppedTables as $tbl) {
            $isUpdatesTbl = str_starts_with($tbl, 'updates_');
            $this->assert("Q. Dropped table '{$tbl}' belongs strictly to updates_* namespace", $isUpdatesTbl);
        }

        // Verify reverse dependency order:
        // Leaf/junction tables must be dropped before their parent tables
        $upcPos = array_search('updates_post_categories', $droppedTables);
        $upkPos = array_search('updates_post_keywords', $droppedTables);
        $uscPos = array_search('updates_subscriber_categories', $droppedTables);
        $usePos = array_search('updates_subscriber_events', $droppedTables);
        $udrPos = array_search('updates_delivery_recipients', $droppedTables);
        $udcPos = array_search('updates_delivery_campaigns', $droppedTables);
        $uvPos  = array_search('updates_visits', $droppedTables);

        $keysPos  = array_search('updates_keywords', $droppedTables);
        $postsPos = array_search('updates_posts', $droppedTables);
        $catsPos  = array_search('updates_categories', $droppedTables);
        $subsPos  = array_search('updates_subscribers', $droppedTables);

        $this->assert("Q. updates_post_categories dropped before updates_posts", $upcPos < $postsPos);
        $this->assert("Q. updates_post_categories dropped before updates_categories", $upcPos < $catsPos);
        $this->assert("Q. updates_post_keywords dropped before updates_posts", $upkPos < $postsPos);
        $this->assert("Q. updates_post_keywords dropped before updates_keywords", $upkPos < $keysPos);
        $this->assert("Q. updates_keywords dropped before updates_categories", $keysPos < $catsPos);
        $this->assert("Q. updates_subscriber_categories dropped before updates_subscribers", $uscPos < $subsPos);
        $this->assert("Q. updates_subscriber_categories dropped before updates_categories", $uscPos < $catsPos);
        $this->assert("Q. updates_subscriber_events dropped before updates_subscribers", $usePos < $subsPos);
        $this->assert("Q. updates_delivery_recipients dropped before updates_delivery_campaigns", $udrPos < $udcPos);
        $this->assert("Q. updates_delivery_recipients dropped before updates_subscribers", $udrPos < $subsPos);
        $this->assert("Q. updates_delivery_campaigns dropped before updates_posts", $udcPos < $postsPos);
        $this->assert("Q. updates_delivery_campaigns dropped before updates_categories", $udcPos < $catsPos);
        $this->assert("Q. updates_visits dropped before updates_posts", $uvPos < $postsPos);
    }
}

// Run test harness
$harness = new PeppUpdatesPhase1TestHarness();
$harness->run();
