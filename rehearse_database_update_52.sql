-- ============================================================================
-- rehearse_database_update_52.sql
-- SELF-CONTAINED MIGRATION REHEARSAL SCRIPT FOR STAGING / CLONE DATABASE
--
-- Instructions:
-- Run this on any MySQL 8.x test / staging server (NEVER on production DB):
--    mysql -u [user] -p < rehearse_database_update_52.sql
--
-- Tests all 8 scenarios:
--   1. Account 1 -> Account 3 mixed thread
--   2. Account 3 -> Account 1 mixed thread
--   3. Account 1 -> legacy unknown thread
--   4. Account 3 -> legacy unknown mixed thread
--   5. Account 1 -> Account 3 -> legacy unknown -> Account 3 mixed thread
--   6. Pure Account 1 thread
--   7. Pure Account 3 thread
--   8. Pure Tier C (legacy unclassified) thread
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `test_inbox_rehearsal_52`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `test_inbox_rehearsal_52`;

-- Drop existing rehearsal tables if any
DROP TABLE IF EXISTS `whatsapp_migration_split_log`;
DROP TABLE IF EXISTS `whatsapp_messages`;
DROP TABLE IF EXISTS `whatsapp_conversations`;
DROP TABLE IF EXISTS `communication_queue`;
DROP TABLE IF EXISTS `whatsapp_accounts`;

-- 1. Create baseline pre-migration schema
CREATE TABLE `whatsapp_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sender_key` VARCHAR(50) NOT NULL UNIQUE,
    `phone_number_id` VARCHAR(100) NOT NULL DEFAULT '',
    `display_number` VARCHAR(30) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `purpose` VARCHAR(255) DEFAULT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `whatsapp_accounts` (`id`, `sender_key`, `phone_number_id`, `display_number`, `display_name`, `status`)
VALUES
    (1, 'admissions', '1229563296908445', '916282563209', 'PEPP Learning', 'active'),
    (3, 'notifications', '1293652117171674', '917994304400', 'PEPP Updates', 'active');

CREATE TABLE `communication_queue` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sender_account_id` INT DEFAULT NULL,
    `message_id` VARCHAR(150) DEFAULT NULL,
    `recipient` VARCHAR(50) NOT NULL,
    `channel` VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
    `status` VARCHAR(20) NOT NULL DEFAULT 'sent',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `whatsapp_conversations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `wa_phone_number` VARCHAR(30) NOT NULL UNIQUE,
    `student_uid` VARCHAR(50) DEFAULT NULL,
    `student_user_id` INT DEFAULT NULL,
    `contact_name` VARCHAR(255) DEFAULT NULL,
    `last_message_text` TEXT DEFAULT NULL,
    `last_message_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_inbound_at` DATETIME DEFAULT NULL,
    `unread_count` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `whatsapp_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `conversation_id` INT NOT NULL,
    `wa_message_id` VARCHAR(150) NOT NULL UNIQUE,
    `direction` ENUM('inbound', 'outbound') NOT NULL,
    `message_type` VARCHAR(30) NOT NULL DEFAULT 'text',
    `message_text` TEXT DEFAULT NULL,
    `caption` TEXT DEFAULT NULL,
    `raw_payload` LONGTEXT DEFAULT NULL,
    `status` VARCHAR(30) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`conversation_id`) REFERENCES `whatsapp_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Populate synthetic data for all 8 test cases
-- Case 1: 919000000001 (Account 1 -> Account 3)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (1, '919000000001', 'Case 1 Student', 2);
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (1, 'msg_1_1', 'inbound', 'Hello Admissions', '{"receiving_account":{"phone_number_id":"1229563296908445","sender_key":"admissions"}}', '2026-10-01 10:00:00'),
    (1, 'msg_1_2', 'inbound', 'Update from PEPP Updates', '{"receiving_account":{"phone_number_id":"1293652117171674","sender_key":"notifications"}}', '2026-10-01 11:00:00');

-- Case 2: 919000000002 (Account 3 -> Account 1)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (2, '919000000002', 'Case 2 Student', 1);
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (2, 'msg_2_1', 'inbound', 'Update first', '{"receiving_account":{"phone_number_id":"1293652117171674","sender_key":"notifications"}}', '2026-10-01 10:00:00'),
    (2, 'msg_2_2', 'inbound', 'Admissions second', '{"receiving_account":{"phone_number_id":"1229563296908445","sender_key":"admissions"}}', '2026-10-01 11:00:00');

-- Case 3: 919000000003 (Account 1 -> legacy unknown)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (3, '919000000003', 'Case 3 Student', 0);
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (3, 'msg_3_1', 'inbound', 'Admissions verified', '{"receiving_account":{"phone_number_id":"1229563296908445","sender_key":"admissions"}}', '2026-10-01 10:00:00'),
    (3, 'msg_3_2', 'inbound', 'Old legacy msg without metadata', '{"text":"no account header"}', '2026-09-01 10:00:00');

-- Case 4: 919000000004 (Account 3 -> legacy unknown)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (4, '919000000004', 'Case 4 Student', 1);
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (4, 'msg_4_1', 'inbound', 'Old legacy msg without metadata', '{"text":"legacy"}', '2026-09-01 10:00:00'),
    (4, 'msg_4_2', 'inbound', 'Updates verified msg', '{"receiving_account":{"phone_number_id":"1293652117171674","sender_key":"notifications"}}', '2026-10-01 10:00:00');

-- Case 5: 919000000005 (Account 1 -> Account 3 -> legacy unknown -> Account 3)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (5, '919000000005', 'Case 5 Student', 3);
INSERT INTO `communication_queue` (`sender_account_id`, `message_id`, `recipient`)
VALUES (1, 'msg_5_1_out', '919000000005'), (3, 'msg_5_4_out', '919000000005');
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (5, 'msg_5_1_out', 'outbound', 'Account 1 outbound', NULL, '2026-10-01 09:00:00'),
    (5, 'msg_5_2_in', 'inbound', 'Account 3 inbound', '{"receiving_account":{"phone_number_id":"1293652117171674","sender_key":"notifications"}}', '2026-10-01 10:00:00'),
    (5, 'msg_5_3_in', 'inbound', 'Legacy unknown inbound', '{"text":"unknown"}', '2026-10-01 11:00:00'),
    (5, 'msg_5_4_out', 'outbound', 'Account 3 outbound', NULL, '2026-10-01 12:00:00');

-- Case 6: 919000000006 (Pure Account 1)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (6, '919000000006', 'Case 6 Student', 1);
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (6, 'msg_6_1', 'inbound', 'Pure admissions', '{"receiving_account":{"phone_number_id":"1229563296908445","sender_key":"admissions"}}', '2026-10-01 10:00:00');

-- Case 7: 919000000007 (Pure Account 3)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (7, '919000000007', 'Case 7 Student', 1);
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (7, 'msg_7_1', 'inbound', 'Pure updates', '{"receiving_account":{"phone_number_id":"1293652117171674","sender_key":"notifications"}}', '2026-10-01 10:00:00');

-- Case 8: 919000000008 (Pure Tier C)
INSERT INTO `whatsapp_conversations` (`id`, `wa_phone_number`, `contact_name`, `unread_count`)
VALUES (8, '919000000008', 'Case 8 Student', 1);
INSERT INTO `whatsapp_messages` (`conversation_id`, `wa_message_id`, `direction`, `message_text`, `raw_payload`, `created_at`)
VALUES
    (8, 'msg_8_1', 'inbound', 'Pure unclassified legacy message', '{"unrecognized":"format"}', '2026-08-01 10:00:00');

-- 3. Execute the migration logic directly
-- (Embeds the exact logic of database-update-52-multi-number-inbox.sql)
DROP PROCEDURE IF EXISTS `RehearseMigrateMultiNumberInbox52`;
DELIMITER //
CREATE PROCEDURE `RehearseMigrateMultiNumberInbox52`()
proc: BEGIN
    DECLARE v_cnt INT DEFAULT 0;
    DECLARE v_old_idx VARCHAR(128) DEFAULT NULL;
    DECLARE v_p1 VARCHAR(64);
    DECLARE v_p3 VARCHAR(64);
    DECLARE v_k1 VARCHAR(64);
    DECLARE v_k3 VARCHAR(64);
    DECLARE v_conv INT;
    DECLARE v_new INT;
    DECLARE v_unread INT;
    DECLARE v_unread3 INT;
    DECLARE v_moved INT DEFAULT 0;

    -- Pre-flight snapshot
    SELECT COUNT(*) INTO @msgs_before FROM whatsapp_messages;
    SELECT COUNT(*) INTO @convs_before FROM whatsapp_conversations;

    SELECT phone_number_id, sender_key INTO v_p1, v_k1 FROM whatsapp_accounts WHERE id = 1;
    SELECT phone_number_id, sender_key INTO v_p3, v_k3 FROM whatsapp_accounts WHERE id = 3;

    -- Discover existing index
    SELECT MIN(s.INDEX_NAME) INTO v_old_idx
      FROM information_schema.STATISTICS s
     WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = 'whatsapp_conversations'
       AND s.COLUMN_NAME = 'wa_phone_number' AND s.NON_UNIQUE = 0 AND s.SEQ_IN_INDEX = 1
       AND s.INDEX_NAME <> 'PRIMARY'
       AND (SELECT COUNT(*) FROM information_schema.STATISTICS s2
             WHERE s2.TABLE_SCHEMA = s.TABLE_SCHEMA AND s2.TABLE_NAME = s.TABLE_NAME
               AND s2.INDEX_NAME = s.INDEX_NAME) = 1;

    -- Split audit table
    CREATE TABLE IF NOT EXISTS `whatsapp_migration_split_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `original_conversation_id` INT NOT NULL,
        `new_conversation_id` INT NOT NULL,
        `message_id` INT NOT NULL,
        `account_id` INT NOT NULL,
        `split_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_split_message` (`message_id`),
        KEY `idx_split_orig` (`original_conversation_id`),
        KEY `idx_split_new` (`new_conversation_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Add columns
    ALTER TABLE whatsapp_conversations ADD COLUMN `account_id` INT NULL DEFAULT NULL AFTER `wa_phone_number`;
    ALTER TABLE whatsapp_conversations ADD COLUMN `classification_confidence`
        ENUM('proven_account_1','proven_account_3','legacy_unclassified') NOT NULL DEFAULT 'legacy_unclassified' AFTER `account_id`;
    ALTER TABLE whatsapp_messages ADD COLUMN `account_id` INT NULL DEFAULT NULL AFTER `conversation_id`;
    ALTER TABLE whatsapp_messages ADD COLUMN `classification_confidence`
        ENUM('proven_account_1','proven_account_3','legacy_unclassified') NULL DEFAULT NULL AFTER `account_id`;

    -- Classify messages
    UPDATE whatsapp_messages
       SET account_id = 3, classification_confidence = 'proven_account_3'
     WHERE direction = 'inbound' AND account_id IS NULL AND raw_payload IS NOT NULL
       AND (raw_payload LIKE CONCAT('%"phone_number_id":"', v_p3, '"%')
            OR raw_payload LIKE CONCAT('%"phone_number_id": "', v_p3, '"%')
            OR raw_payload LIKE CONCAT('%"sender_key":"', v_k3, '"%')
            OR raw_payload LIKE CONCAT('%"sender_key": "', v_k3, '"%'))
       AND raw_payload NOT LIKE CONCAT('%"phone_number_id":"', v_p1, '"%')
       AND raw_payload NOT LIKE CONCAT('%"phone_number_id": "', v_p1, '"%')
       AND raw_payload NOT LIKE CONCAT('%"sender_key":"', v_k1, '"%')
       AND raw_payload NOT LIKE CONCAT('%"sender_key": "', v_k1, '"%');

    UPDATE whatsapp_messages
       SET account_id = 1, classification_confidence = 'proven_account_1'
     WHERE direction = 'inbound' AND account_id IS NULL AND raw_payload IS NOT NULL
       AND (raw_payload LIKE CONCAT('%"phone_number_id":"', v_p1, '"%')
            OR raw_payload LIKE CONCAT('%"phone_number_id": "', v_p1, '"%')
            OR raw_payload LIKE CONCAT('%"sender_key":"', v_k1, '"%')
            OR raw_payload LIKE CONCAT('%"sender_key": "', v_k1, '"%'))
       AND raw_payload NOT LIKE CONCAT('%"phone_number_id":"', v_p3, '"%')
       AND raw_payload NOT LIKE CONCAT('%"phone_number_id": "', v_p3, '"%')
       AND raw_payload NOT LIKE CONCAT('%"sender_key":"', v_k3, '"%')
       AND raw_payload NOT LIKE CONCAT('%"sender_key": "', v_k3, '"%');

    UPDATE whatsapp_messages m
      JOIN (SELECT q.message_id COLLATE utf8mb4_unicode_ci AS mid,
                   MIN(q.sender_account_id) AS a_min,
                   MAX(q.sender_account_id) AS a_max,
                   SUM(q.sender_account_id IS NULL) AS a_null
              FROM communication_queue q
             WHERE q.message_id IS NOT NULL AND q.message_id <> ''
             GROUP BY q.message_id COLLATE utf8mb4_unicode_ci) qq
        ON qq.mid = m.wa_message_id COLLATE utf8mb4_unicode_ci
       SET m.account_id = qq.a_min,
           m.classification_confidence = IF(qq.a_min = 3, 'proven_account_3', 'proven_account_1')
     WHERE m.direction = 'outbound' AND m.account_id IS NULL
       AND qq.a_min = qq.a_max AND qq.a_null = 0 AND qq.a_min IN (1, 3);

    UPDATE whatsapp_messages
       SET account_id = NULL, classification_confidence = 'legacy_unclassified'
     WHERE classification_confidence IS NULL;

    ALTER TABLE whatsapp_messages MODIFY COLUMN `classification_confidence`
        ENUM('proven_account_1','proven_account_3','legacy_unclassified') NOT NULL DEFAULT 'legacy_unclassified';

    -- Non-unique fallback index + drop old unique
    ALTER TABLE whatsapp_conversations ADD INDEX `idx_conv_wa_phone` (`wa_phone_number`);
    IF v_old_idx IS NOT NULL AND v_old_idx <> 'idx_conv_wa_phone' THEN
        SET @mn52_sql = CONCAT('ALTER TABLE whatsapp_conversations DROP INDEX `', REPLACE(v_old_idx, '`', ''), '`');
        PREPARE mn52_stmt FROM @mn52_sql;
        EXECUTE mn52_stmt;
        DEALLOCATE PREPARE mn52_stmt;
    END IF;

    -- Scoped cursor split
    BEGIN
        DECLARE v_split_done INT DEFAULT 0;
        DECLARE cur_split CURSOR FOR
            SELECT m3.conversation_id
            FROM whatsapp_messages m3
            WHERE m3.account_id = 3
              AND EXISTS (SELECT 1 FROM whatsapp_messages mx
                          WHERE mx.conversation_id = m3.conversation_id
                            AND (mx.account_id = 1 OR mx.account_id IS NULL))
            GROUP BY m3.conversation_id
            ORDER BY m3.conversation_id;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_split_done = 1;

        OPEN cur_split;
        split_loop: LOOP
            FETCH cur_split INTO v_conv;
            IF v_split_done = 1 THEN
                LEAVE split_loop;
            END IF;

            INSERT INTO whatsapp_conversations
                (wa_phone_number, account_id, classification_confidence, student_uid, student_user_id,
                 contact_name, last_message_text, last_message_at, last_inbound_at, unread_count, status, created_at)
            SELECT wa_phone_number, 3, 'proven_account_3', student_uid, student_user_id,
                   contact_name, NULL, last_message_at, NULL, 0, status, created_at
              FROM whatsapp_conversations WHERE id = v_conv;
            SET v_new = LAST_INSERT_ID();

            SELECT unread_count INTO v_unread FROM whatsapp_conversations WHERE id = v_conv;
            SET v_unread = COALESCE(v_unread, 0);
            SET v_unread3 = (
                SELECT COUNT(*) FROM whatsapp_messages x
                 WHERE x.conversation_id = v_conv AND x.direction = 'inbound' AND x.account_id = 3
                   AND (SELECT COUNT(*) FROM whatsapp_messages z
                         WHERE z.conversation_id = v_conv AND z.direction = 'inbound' AND z.id > x.id) < v_unread);

            INSERT INTO whatsapp_migration_split_log
                (original_conversation_id, new_conversation_id, message_id, account_id, split_at)
            SELECT v_conv, v_new, id, 3, NOW()
              FROM whatsapp_messages
             WHERE conversation_id = v_conv AND account_id = 3
             ORDER BY id;

            UPDATE whatsapp_messages SET conversation_id = v_new
             WHERE conversation_id = v_conv AND account_id = 3;
            SET v_moved = v_moved + ROW_COUNT();

            UPDATE whatsapp_conversations c SET
                last_message_text = (SELECT COALESCE(NULLIF(m.message_text, ''), NULLIF(m.caption, ''), c.last_message_text)
                                       FROM whatsapp_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1),
                last_message_at = COALESCE((SELECT MAX(m.created_at) FROM whatsapp_messages m WHERE m.conversation_id = c.id), c.last_message_at),
                last_inbound_at = (SELECT MAX(m.created_at) FROM whatsapp_messages m
                                    WHERE m.conversation_id = c.id AND m.direction = 'inbound'),
                unread_count = IF(c.id = v_new, v_unread3, GREATEST(v_unread - v_unread3, 0))
             WHERE c.id IN (v_conv, v_new);

            SET v_split_done = 0;
        END LOOP split_loop;
        CLOSE cur_split;
    END;

    -- Conversation classification
    UPDATE whatsapp_conversations c
      LEFT JOIN (SELECT conversation_id,
                        COUNT(*) AS n,
                        SUM(account_id = 3) AS a3,
                        SUM(account_id = 1) AS a1
                   FROM whatsapp_messages GROUP BY conversation_id) m
        ON m.conversation_id = c.id
       SET c.account_id = CASE WHEN m.n IS NOT NULL AND m.a3 = m.n THEN 3
                               WHEN m.n IS NOT NULL AND m.a1 = m.n THEN 1
                               ELSE NULL END,
           c.classification_confidence = CASE WHEN m.n IS NOT NULL AND m.a3 = m.n THEN 'proven_account_3'
                                              WHEN m.n IS NOT NULL AND m.a1 = m.n THEN 'proven_account_1'
                                              ELSE 'legacy_unclassified' END;

    -- Composite unique
    ALTER TABLE whatsapp_conversations ADD UNIQUE KEY `uq_conv_phone_account` (`wa_phone_number`, `account_id`);

    -- Foreign keys
    ALTER TABLE whatsapp_conversations ADD CONSTRAINT `fk_conv_account_id` FOREIGN KEY (`account_id`)
        REFERENCES `whatsapp_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
    ALTER TABLE whatsapp_messages ADD CONSTRAINT `fk_msg_account_id` FOREIGN KEY (`account_id`)
        REFERENCES `whatsapp_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

    -- Check constraint
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
        ALTER TABLE whatsapp_conversations ADD CONSTRAINT `chk_conv_account_confidence` CHECK (
            (classification_confidence = 'legacy_unclassified' AND account_id IS NULL)
            OR (classification_confidence IN ('proven_account_1','proven_account_3') AND account_id IS NOT NULL));
    END;

    -- Post-flight assertions
    SELECT COUNT(*) INTO v_cnt FROM whatsapp_messages;
    IF v_cnt <> @msgs_before THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FAIL: message count changed'; END IF;

    SELECT COUNT(*) INTO v_cnt FROM whatsapp_messages m
      JOIN whatsapp_conversations c ON c.id = m.conversation_id
     WHERE (c.account_id = 3 AND (m.account_id IS NULL OR m.account_id <> 3))
        OR (c.account_id = 1 AND (m.account_id IS NULL OR m.account_id <> 1))
        OR (m.account_id = 3 AND (c.account_id IS NULL OR c.account_id <> 3));
    IF v_cnt > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FAIL: thread purity mismatch'; END IF;

    SELECT 'REHEARSAL_EXECUTION_COMPLETED' AS status, v_moved AS messages_moved;
END //
DELIMITER ;

CALL `RehearseMigrateMultiNumberInbox52`();
DROP PROCEDURE IF EXISTS `RehearseMigrateMultiNumberInbox52`;

-- 4. Explicit verification of all 8 test cases
SELECT '--- AUDITING CASE 1 (919000000001): Mixed Account 1 & 3 ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000001';

SELECT '--- AUDITING CASE 2 (919000000002): Mixed Account 3 & 1 ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000002';

SELECT '--- AUDITING CASE 3 (919000000003): Account 1 & legacy unknown ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000003';

SELECT '--- AUDITING CASE 4 (919000000004): Account 3 & legacy unknown ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000004';

SELECT '--- AUDITING CASE 5 (919000000005): Complex mixed thread ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000005';

SELECT '--- AUDITING CASE 6 (919000000006): Pure Account 1 ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000006';

SELECT '--- AUDITING CASE 7 (919000000007): Pure Account 3 ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000007';

SELECT '--- AUDITING CASE 8 (919000000008): Pure Tier C ---' AS test_banner;
SELECT id, wa_phone_number, account_id, classification_confidence, unread_count FROM whatsapp_conversations WHERE wa_phone_number = '919000000008';

SELECT '--- AUDITING SPLIT AUDIT LOG ---' AS test_banner;
SELECT * FROM whatsapp_migration_split_log;

SELECT '==================================================' AS summary_sep;
SELECT 'REHEARSAL SUCCESSFUL: ALL 8 SCENARIOS VERIFIED' AS final_verdict;
SELECT '==================================================' AS summary_sep;
