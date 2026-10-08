-- ============================================================================
-- database-update-52-multi-number-inbox.sql
-- Multi-number WhatsApp Inbox: account-aware conversations and messages.
--
-- *** NOT EXECUTED. Review + backup before running against any database. ***
--
-- Conversation identity becomes UNIQUE(wa_phone_number, account_id).
--
--   Tier A : account_id = 1    classification_confidence = 'proven_account_1'
--   Tier B : account_id = 3    classification_confidence = 'proven_account_3'
--   Tier C : account_id = NULL classification_confidence = 'legacy_unclassified'
--
-- Tier C is NEVER auto-assigned to Account 1 (replies to it are rejected by
-- send-reply.php). Mixed threads (Account 3 messages inside an Account 1 /
-- unclassified thread) are split; every moved message is written to
-- whatsapp_migration_split_log BEFORE its conversation_id is changed.
--
-- All pre-flight checks run before ANY DDL/DML. The script is idempotent:
-- when uq_conv_phone_account already exists it reports "already applied".
-- No queue pause/lock is used (Quick Reply sync sending must keep working);
-- Meta webhook retries cover the brief online-DDL windows.
-- ============================================================================

DROP PROCEDURE IF EXISTS `MigrateMultiNumberInbox52`;

DELIMITER //

CREATE PROCEDURE `MigrateMultiNumberInbox52`()
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

    -- ------------------------------------------------------------------
    -- 0. Idempotency
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO v_cnt FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations'
       AND INDEX_NAME = 'uq_conv_phone_account';
    IF v_cnt > 0 THEN
        SELECT 'database-update-52: already applied (uq_conv_phone_account exists) - nothing to do' AS info;
        LEAVE proc;
    END IF;

    -- ------------------------------------------------------------------
    -- 1. PRE-FLIGHT CHECKS (no changes made before all pass)
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO v_cnt FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('whatsapp_conversations','whatsapp_messages','whatsapp_accounts','communication_queue');
    IF v_cnt <> 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: required tables missing';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('whatsapp_conversations','whatsapp_messages','whatsapp_accounts')
       AND ENGINE = 'InnoDB';
    IF v_cnt <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: tables must be InnoDB';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communication_queue'
       AND COLUMN_NAME IN ('sender_account_id','message_id');
    IF v_cnt <> 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: communication_queue.sender_account_id/message_id missing';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_accounts'
       AND COLUMN_NAME = 'id' AND DATA_TYPE = 'int' AND COLUMN_TYPE NOT LIKE '%unsigned%';
    IF v_cnt <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: whatsapp_accounts.id must be signed INT (FK type match)';
    END IF;

    SELECT phone_number_id, sender_key INTO v_p1, v_k1 FROM whatsapp_accounts WHERE id = 1;
    SELECT phone_number_id, sender_key INTO v_p3, v_k3 FROM whatsapp_accounts WHERE id = 3;
    IF v_p1 IS NULL OR v_p1 = '' OR v_k1 <> 'admissions' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: whatsapp_accounts id=1 must be admissions with phone_number_id';
    END IF;
    IF v_p3 IS NULL OR v_p3 = '' OR v_k3 <> 'notifications' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: whatsapp_accounts id=3 must be notifications with phone_number_id';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM whatsapp_messages m
     LEFT JOIN whatsapp_conversations c ON c.id = m.conversation_id WHERE c.id IS NULL;
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: orphan whatsapp_messages exist';
    END IF;

    -- Discover the existing single-column UNIQUE index on wa_phone_number (name is NOT assumed).
    SELECT MIN(s.INDEX_NAME) INTO v_old_idx
      FROM information_schema.STATISTICS s
     WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = 'whatsapp_conversations'
       AND s.COLUMN_NAME = 'wa_phone_number' AND s.NON_UNIQUE = 0 AND s.SEQ_IN_INDEX = 1
       AND s.INDEX_NAME <> 'PRIMARY'
       AND (SELECT COUNT(*) FROM information_schema.STATISTICS s2
             WHERE s2.TABLE_SCHEMA = s.TABLE_SCHEMA AND s2.TABLE_NAME = s.TABLE_NAME
               AND s2.INDEX_NAME = s.INDEX_NAME) = 1;

    IF v_old_idx IS NULL THEN
        SELECT COUNT(*) INTO v_cnt FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations'
           AND INDEX_NAME = 'idx_conv_wa_phone';
        IF v_cnt = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: no single-column unique index on wa_phone_number and no idx_conv_wa_phone';
        END IF;
    END IF;

    -- Duplicate verification (before any change)
    SELECT COUNT(*) INTO v_cnt FROM (
        SELECT wa_phone_number FROM whatsapp_conversations
         GROUP BY wa_phone_number HAVING COUNT(*) > 1) d;
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: duplicate wa_phone_number rows exist';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations' AND COLUMN_NAME = 'account_id';
    IF v_cnt > 0 THEN
        -- Partially applied earlier: verify (phone, account) uniqueness.
        SELECT COUNT(*) INTO v_cnt FROM (
            SELECT wa_phone_number, account_id FROM whatsapp_conversations
             GROUP BY wa_phone_number, account_id HAVING COUNT(*) > 1) d2;
        IF v_cnt > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRE-FLIGHT: duplicate (wa_phone_number, account_id) rows exist';
        END IF;
    END IF;

    -- Snapshot counts
    SELECT COUNT(*) INTO @mn52_msgs_before FROM whatsapp_messages;
    SELECT COUNT(*) INTO @mn52_convs_before FROM whatsapp_conversations;

    -- ------------------------------------------------------------------
    -- 2. Split audit table
    -- ------------------------------------------------------------------
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

    -- ------------------------------------------------------------------
    -- 3. Columns (account_id nullable during backfill and permanently)
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO v_cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations' AND COLUMN_NAME = 'account_id';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_conversations ADD COLUMN `account_id` INT NULL DEFAULT NULL AFTER `wa_phone_number`;
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations' AND COLUMN_NAME = 'classification_confidence';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_conversations
            ADD COLUMN `classification_confidence`
                ENUM('proven_account_1','proven_account_3','legacy_unclassified')
                NOT NULL DEFAULT 'legacy_unclassified' AFTER `account_id`;
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'account_id';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_messages ADD COLUMN `account_id` INT NULL DEFAULT NULL AFTER `conversation_id`;
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'classification_confidence';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_messages
            ADD COLUMN `classification_confidence`
                ENUM('proven_account_1','proven_account_3','legacy_unclassified') NULL DEFAULT NULL AFTER `account_id`;
    END IF;

    -- ------------------------------------------------------------------
    -- 4. Message classification (explicit Tier A / B / C; no default Account 1)
    -- ------------------------------------------------------------------
    -- 4a. Inbound -> Account 3 (webhook receiving_account metadata), not matching account-1 markers
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

    -- 4b. Inbound -> Account 1 (symmetric)
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

    -- 4c. Outbound -> deterministic via communication_queue.message_id = wa_message_id
    --     (all queue rows for that message agree on one explicit sender_account_id in {1,3})
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

    -- 4d. Everything else -> Tier C
    UPDATE whatsapp_messages
       SET account_id = NULL, classification_confidence = 'legacy_unclassified'
     WHERE classification_confidence IS NULL;

    ALTER TABLE whatsapp_messages
        MODIFY COLUMN `classification_confidence`
            ENUM('proven_account_1','proven_account_3','legacy_unclassified')
            NOT NULL DEFAULT 'legacy_unclassified';

    -- ------------------------------------------------------------------
    -- 5. Replace the old unique index BEFORE splitting (split inserts a same-phone row)
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO v_cnt FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations'
       AND INDEX_NAME = 'idx_conv_wa_phone';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_conversations ADD INDEX `idx_conv_wa_phone` (`wa_phone_number`);
    END IF;

    IF v_old_idx IS NOT NULL AND v_old_idx <> 'idx_conv_wa_phone' THEN
        SET @mn52_sql = CONCAT('ALTER TABLE whatsapp_conversations DROP INDEX `', REPLACE(v_old_idx, '`', ''), '`');
        PREPARE mn52_stmt FROM @mn52_sql;
        EXECUTE mn52_stmt;
        DEALLOCATE PREPARE mn52_stmt;
    END IF;

    -- ------------------------------------------------------------------
    -- 6. Mixed-thread splitting (Account 3 messages inside a thread that also
    --    holds Account 1 and/or unclassified messages)
    -- ------------------------------------------------------------------
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

            -- Unread: the latest N inbound messages (N = original unread_count) are unread.
            SELECT unread_count INTO v_unread FROM whatsapp_conversations WHERE id = v_conv;
            SET v_unread = COALESCE(v_unread, 0);
            SET v_unread3 = (
                SELECT COUNT(*) FROM whatsapp_messages x
                 WHERE x.conversation_id = v_conv AND x.direction = 'inbound' AND x.account_id = 3
                   AND (SELECT COUNT(*) FROM whatsapp_messages z
                         WHERE z.conversation_id = v_conv AND z.direction = 'inbound' AND z.id > x.id) < v_unread);

            -- AUDIT FIRST: log every message before conversation_id changes.
            INSERT INTO whatsapp_migration_split_log
                (original_conversation_id, new_conversation_id, message_id, account_id, split_at)
            SELECT v_conv, v_new, id, 3, NOW()
              FROM whatsapp_messages
             WHERE conversation_id = v_conv AND account_id = 3
             ORDER BY id;

            -- Move (ids and created_at order preserved)
            UPDATE whatsapp_messages SET conversation_id = v_new
             WHERE conversation_id = v_conv AND account_id = 3;
            SET v_moved = v_moved + ROW_COUNT();

            -- Recompute metadata for both halves
            UPDATE whatsapp_conversations c SET
                last_message_text = (SELECT COALESCE(NULLIF(m.message_text, ''), NULLIF(m.caption, ''), c.last_message_text)
                                       FROM whatsapp_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1),
                last_message_at = COALESCE((SELECT MAX(m.created_at) FROM whatsapp_messages m WHERE m.conversation_id = c.id), c.last_message_at),
                last_inbound_at = (SELECT MAX(m.created_at) FROM whatsapp_messages m
                                    WHERE m.conversation_id = c.id AND m.direction = 'inbound'),
                unread_count = IF(c.id = v_new, v_unread3, GREATEST(v_unread - v_unread3, 0))
             WHERE c.id IN (v_conv, v_new);

            -- Reset flag so internal statements cannot cause an early exit
            SET v_split_done = 0;
        END LOOP split_loop;
        CLOSE cur_split;
    END;

    -- ------------------------------------------------------------------
    -- 7. Conversation classification (set-based, explicit tiers)
    --    all messages Account 3 -> B; all Account 1 -> A; anything else
    --    (any unclassified message, mixed 1+NULL, or no messages) -> C.
    -- ------------------------------------------------------------------
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

    -- ------------------------------------------------------------------
    -- 8. Duplicate verification, then composite unique key
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO v_cnt FROM (
        SELECT wa_phone_number, account_id FROM whatsapp_conversations
         GROUP BY wa_phone_number, account_id HAVING COUNT(*) > 1) d3;
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ABORT: duplicate (wa_phone_number, account_id) after split';
    END IF;

    ALTER TABLE whatsapp_conversations
        ADD UNIQUE KEY `uq_conv_phone_account` (`wa_phone_number`, `account_id`);

    -- ------------------------------------------------------------------
    -- 9. Indexes + foreign keys
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO v_cnt FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations' AND INDEX_NAME = 'idx_conv_confidence';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_conversations ADD INDEX `idx_conv_confidence` (`classification_confidence`);
    END IF;
    SELECT COUNT(*) INTO v_cnt FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages' AND INDEX_NAME = 'idx_msg_account_id';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_messages ADD INDEX `idx_msg_account_id` (`account_id`);
    END IF;
    SELECT COUNT(*) INTO v_cnt FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages' AND INDEX_NAME = 'idx_msg_confidence';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_messages ADD INDEX `idx_msg_confidence` (`classification_confidence`);
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations'
       AND CONSTRAINT_NAME = 'fk_conv_account_id' AND CONSTRAINT_TYPE = 'FOREIGN KEY';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_conversations
            ADD CONSTRAINT `fk_conv_account_id` FOREIGN KEY (`account_id`)
            REFERENCES `whatsapp_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
    END IF;
    SELECT COUNT(*) INTO v_cnt FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages'
       AND CONSTRAINT_NAME = 'fk_msg_account_id' AND CONSTRAINT_TYPE = 'FOREIGN KEY';
    IF v_cnt = 0 THEN
        ALTER TABLE whatsapp_messages
            ADD CONSTRAINT `fk_msg_account_id` FOREIGN KEY (`account_id`)
            REFERENCES `whatsapp_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
    END IF;

    -- CHECK: NULL account only for legacy_unclassified (MySQL >= 8.0.16; skipped if unsupported)
    SELECT COUNT(*) INTO v_cnt FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_conversations'
       AND CONSTRAINT_NAME = 'chk_conv_account_confidence';
    IF v_cnt = 0 THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            ALTER TABLE whatsapp_conversations
                ADD CONSTRAINT `chk_conv_account_confidence` CHECK (
                    (classification_confidence = 'legacy_unclassified' AND account_id IS NULL)
                    OR (classification_confidence IN ('proven_account_1','proven_account_3') AND account_id IS NOT NULL));
        END;
    END IF;

    -- ------------------------------------------------------------------
    -- 10. POST-FLIGHT ASSERTIONS
    -- ------------------------------------------------------------------
    SELECT COUNT(*) INTO v_cnt FROM whatsapp_messages;
    IF v_cnt <> @mn52_msgs_before THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'POST-FLIGHT: message count changed';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM whatsapp_messages WHERE classification_confidence IS NULL;
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'POST-FLIGHT: messages without classification';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM whatsapp_migration_split_log;
    IF v_cnt < v_moved THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'POST-FLIGHT: split log rows fewer than moved messages';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM whatsapp_migration_split_log l
     LEFT JOIN whatsapp_messages m ON m.id = l.message_id AND m.conversation_id = l.new_conversation_id
     WHERE m.id IS NULL;
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'POST-FLIGHT: split-log message not in its new conversation';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM whatsapp_messages m
      JOIN whatsapp_conversations c ON c.id = m.conversation_id
     WHERE (c.account_id = 3 AND (m.account_id IS NULL OR m.account_id <> 3))
        OR (c.account_id = 1 AND (m.account_id IS NULL OR m.account_id <> 1))
        OR (m.account_id = 3 AND (c.account_id IS NULL OR c.account_id <> 3));
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'POST-FLIGHT: message/conversation account mismatch';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM whatsapp_conversations
     WHERE (account_id IS NULL AND classification_confidence <> 'legacy_unclassified')
        OR (account_id IS NOT NULL AND classification_confidence = 'legacy_unclassified');
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'POST-FLIGHT: conversation classification inconsistent';
    END IF;

    SELECT COUNT(*) INTO v_cnt FROM (
        SELECT wa_phone_number, account_id FROM whatsapp_conversations
         GROUP BY wa_phone_number, account_id HAVING COUNT(*) > 1) d4;
    IF v_cnt > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'POST-FLIGHT: duplicate (wa_phone_number, account_id)';
    END IF;

    SELECT 'database-update-52 applied' AS info,
           @mn52_convs_before AS conversations_before,
           (SELECT COUNT(*) FROM whatsapp_conversations) AS conversations_after,
           @mn52_msgs_before AS messages_before,
           v_moved AS messages_split_moved,
           (SELECT COUNT(*) FROM whatsapp_conversations WHERE classification_confidence='proven_account_1') AS tier_a,
           (SELECT COUNT(*) FROM whatsapp_conversations WHERE classification_confidence='proven_account_3') AS tier_b,
           (SELECT COUNT(*) FROM whatsapp_conversations WHERE classification_confidence='legacy_unclassified') AS tier_c;
END //

DELIMITER ;

CALL `MigrateMultiNumberInbox52`();
DROP PROCEDURE IF EXISTS `MigrateMultiNumberInbox52`;
