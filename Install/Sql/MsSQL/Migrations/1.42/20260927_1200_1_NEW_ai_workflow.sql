/*
 * Create table exf_ai_workflow and link autonomous configurations.
 *
 * Workflow aliases are unique within their app. The existing autonomous
 * agent_oid remains required for backward compatibility.
 *
 */
-- UP

IF OBJECT_ID(N'dbo.exf_ai_workflow', N'U') IS NULL
CREATE TABLE dbo.exf_ai_workflow (
  oid binary(16) NOT NULL,
  created_on datetime NOT NULL,
  modified_on datetime NOT NULL,
  created_by_user_oid binary(16),
  modified_by_user_oid binary(16),
  name nvarchar(100) NOT NULL,
  alias nvarchar(100) NOT NULL,
  app_oid binary(16) NOT NULL,
  prototype_class nvarchar(255) NOT NULL,
  config_uxon nvarchar(max),
  description nvarchar(max),
  CONSTRAINT PK_exf_ai_workflow PRIMARY KEY (oid),
  CONSTRAINT UQ_exf_ai_workflow_app_alias
    UNIQUE (app_oid, alias),
  INDEX IDX_dbo_exf_ai_workflow_app (app_oid),
  CONSTRAINT FK_dbo_exf_ai_workflow_app
    FOREIGN KEY (app_oid) REFERENCES dbo.exf_app (oid)
);

IF OBJECT_ID(N'dbo.exf_ai_autonomous', N'U') IS NOT NULL
  AND COL_LENGTH('dbo.exf_ai_autonomous', 'ai_workflow_oid') IS NULL
ALTER TABLE dbo.exf_ai_autonomous
  ADD ai_workflow_oid binary(16) NULL;

IF COL_LENGTH('dbo.exf_ai_autonomous', 'ai_workflow_oid') IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.exf_ai_autonomous')
      AND name = N'IDX_dbo_exf_ai_autonomous_workflow'
  )
CREATE INDEX IDX_dbo_exf_ai_autonomous_workflow
  ON dbo.exf_ai_autonomous (ai_workflow_oid);

IF COL_LENGTH('dbo.exf_ai_autonomous', 'ai_workflow_oid') IS NOT NULL
  AND NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID(N'dbo.exf_ai_autonomous')
      AND name = N'FK_dbo_exf_ai_autonomous_workflow'
  )
ALTER TABLE dbo.exf_ai_autonomous
  ADD CONSTRAINT FK_dbo_exf_ai_autonomous_workflow
    FOREIGN KEY (ai_workflow_oid)
    REFERENCES dbo.exf_ai_workflow (oid)
    ON DELETE SET NULL;

-- DOWN

IF EXISTS (
  SELECT 1
  FROM sys.foreign_keys
  WHERE parent_object_id = OBJECT_ID(N'dbo.exf_ai_autonomous')
    AND name = N'FK_dbo_exf_ai_autonomous_workflow'
)
ALTER TABLE dbo.exf_ai_autonomous
  DROP CONSTRAINT FK_dbo_exf_ai_autonomous_workflow;

IF EXISTS (
  SELECT 1
  FROM sys.indexes
  WHERE object_id = OBJECT_ID(N'dbo.exf_ai_autonomous')
    AND name = N'IDX_dbo_exf_ai_autonomous_workflow'
)
DROP INDEX IDX_dbo_exf_ai_autonomous_workflow
  ON dbo.exf_ai_autonomous;

IF COL_LENGTH('dbo.exf_ai_autonomous', 'ai_workflow_oid') IS NOT NULL
ALTER TABLE dbo.exf_ai_autonomous
  DROP COLUMN ai_workflow_oid;

-- Do not delete the table containing AI workflow configurations!