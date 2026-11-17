/*
 * Add persisted cost to workflow run steps
 *
 * Existing agent steps are backfilled from their conversation messages.
 * Existing workflow steps receive the sum of all non-workflow descendants,
 * so nested workflows are rolled up without counting costs twice.
 *
 * @author GitHub Copilot
 */
-- UP

-- MySQL commits DDL even if a later statement fails; allow installation retries.
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'exf_ai_workflow_run_step'
    AND column_name = 'cost'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE `exf_ai_workflow_run_step` ADD `cost` decimal(18,6) NOT NULL DEFAULT 0 AFTER `duration_ms`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `exf_ai_workflow_run_step` step
LEFT JOIN (
  SELECT `ai_conversation_oid`, SUM(`cost`) AS `cost`
  FROM `exf_ai_message`
  GROUP BY `ai_conversation_oid`
) conversation_cost
  ON conversation_cost.`ai_conversation_oid` = step.`ai_conversation_oid`
SET step.`cost` = COALESCE(conversation_cost.`cost`, 0);

-- Keep the CTE inside a SELECT: MariaDB does not support WITH ... UPDATE.
UPDATE `exf_ai_workflow_run_step` workflow
LEFT JOIN (
  WITH RECURSIVE step_descendants AS (
    SELECT `parent_step_oid` AS `ancestor_oid`, `oid` AS `descendant_oid`
    FROM `exf_ai_workflow_run_step`
    WHERE `parent_step_oid` IS NOT NULL

    UNION ALL

    SELECT parent.`parent_step_oid`, tree.`descendant_oid`
    FROM step_descendants tree
    INNER JOIN `exf_ai_workflow_run_step` parent
      ON parent.`oid` = tree.`ancestor_oid`
    WHERE parent.`parent_step_oid` IS NOT NULL
  )
  SELECT tree.`ancestor_oid`, SUM(descendant.`cost`) AS `cost`
  FROM step_descendants tree
  INNER JOIN `exf_ai_workflow_run_step` descendant
    ON descendant.`oid` = tree.`descendant_oid`
  WHERE descendant.`node_type` <> 'workflow'
  GROUP BY tree.`ancestor_oid`
) workflow_costs
  ON workflow_costs.`ancestor_oid` = workflow.`oid`
SET workflow.`cost` = COALESCE(workflow_costs.`cost`, 0)
WHERE workflow.`node_type` = 'workflow';

-- DOWN

-- Keep the cost column because it contains workflow audit history.
