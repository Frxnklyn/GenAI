/*
 * Create table exf_ai_workflow and link autonomous configurations.
 *
 * Workflow aliases are unique within their app. The existing autonomous
 * agent_oid remains required for backward compatibility.
 *
 */
-- UP

CREATE TABLE IF NOT EXISTS `exf_ai_workflow` (
  `oid` binary(16) NOT NULL,
  `created_on` datetime NOT NULL,
  `modified_on` datetime NOT NULL,
  `created_by_user_oid` binary(16) DEFAULT NULL,
  `modified_by_user_oid` binary(16) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `alias` varchar(100) NOT NULL,
  `app_oid` binary(16) NOT NULL,
  `prototype_class` varchar(255) NOT NULL,
  `config_uxon` text,
  `description` mediumtext,
  PRIMARY KEY (`oid`) USING BTREE,
  UNIQUE KEY `uq_exf_ai_workflow_app_alias` (`app_oid`, `alias`),
  KEY `idx_exf_ai_workflow_app` (`app_oid`),
  CONSTRAINT `fk_exf_ai_workflow_app` FOREIGN KEY (`app_oid`)
    REFERENCES `exf_app` (`oid`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

SET @add_workflow_column_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
  ) AND NOT EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND column_name = 'ai_workflow_oid'
  ),
  'ALTER TABLE `exf_ai_autonomous` ADD COLUMN `ai_workflow_oid` binary(16) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @add_workflow_column_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_workflow_index_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND column_name = 'ai_workflow_oid'
  ) AND NOT EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND index_name = 'idx_exf_ai_autonomous_workflow'
  ),
  'CREATE INDEX `idx_exf_ai_autonomous_workflow` ON `exf_ai_autonomous` (`ai_workflow_oid`)',
  'SELECT 1'
);
PREPARE stmt FROM @add_workflow_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_workflow_fk_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND column_name = 'ai_workflow_oid'
  ) AND NOT EXISTS (
    SELECT 1
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND constraint_name = 'fk_exf_ai_autonomous_workflow'
      AND constraint_type = 'FOREIGN KEY'
  ),
  'ALTER TABLE `exf_ai_autonomous` ADD CONSTRAINT `fk_exf_ai_autonomous_workflow` FOREIGN KEY (`ai_workflow_oid`) REFERENCES `exf_ai_workflow` (`oid`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @add_workflow_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN

SET @drop_workflow_fk_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND constraint_name = 'fk_exf_ai_autonomous_workflow'
      AND constraint_type = 'FOREIGN KEY'
  ),
  'ALTER TABLE `exf_ai_autonomous` DROP FOREIGN KEY `fk_exf_ai_autonomous_workflow`',
  'SELECT 1'
);
PREPARE stmt FROM @drop_workflow_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_workflow_index_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND index_name = 'idx_exf_ai_autonomous_workflow'
  ),
  'DROP INDEX `idx_exf_ai_autonomous_workflow` ON `exf_ai_autonomous`',
  'SELECT 1'
);
PREPARE stmt FROM @drop_workflow_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_workflow_column_sql := IF(
  EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exf_ai_autonomous'
      AND column_name = 'ai_workflow_oid'
  ),
  'ALTER TABLE `exf_ai_autonomous` DROP COLUMN `ai_workflow_oid`',
  'SELECT 1'
);
PREPARE stmt FROM @drop_workflow_column_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Do not delete the table containing AI workflow configurations!