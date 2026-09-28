/*
 * Create table exf_ai_workflow and link autonomous configurations.
 *
 * Workflow aliases are unique within their app. The existing autonomous
 * agent_oid remains required for backward compatibility.
 *
 */
-- UP

CREATE TABLE IF NOT EXISTS exf_ai_workflow (
    oid                  uuid         NOT NULL,
    created_on           timestamp    NOT NULL,
    modified_on          timestamp    NOT NULL,
    created_by_user_oid  uuid,
    modified_by_user_oid uuid,
    name                 varchar(100) NOT NULL,
    alias                varchar(100) NOT NULL,
    app_oid              uuid         NOT NULL,
    prototype_class      varchar(255) NOT NULL,
    config_uxon          text,
    description          text,
    CONSTRAINT pk_exf_ai_workflow PRIMARY KEY (oid),
    CONSTRAINT uq_exf_ai_workflow_app_alias
        UNIQUE (app_oid, alias),
    CONSTRAINT fk_exf_ai_workflow_app
        FOREIGN KEY (app_oid) REFERENCES exf_app (oid)
);

CREATE INDEX IF NOT EXISTS idx_exf_ai_workflow_app
    ON exf_ai_workflow (app_oid);

ALTER TABLE exf_ai_autonomous
    ADD COLUMN IF NOT EXISTS ai_workflow_oid uuid;

CREATE INDEX IF NOT EXISTS idx_exf_ai_autonomous_workflow
    ON exf_ai_autonomous (ai_workflow_oid);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'exf_ai_autonomous'
          AND constraint_name = 'fk_exf_ai_autonomous_workflow'
          AND constraint_type = 'FOREIGN KEY'
    ) THEN
        ALTER TABLE exf_ai_autonomous
            ADD CONSTRAINT fk_exf_ai_autonomous_workflow
            FOREIGN KEY (ai_workflow_oid)
            REFERENCES exf_ai_workflow (oid)
            ON DELETE SET NULL;
    END IF;
END $$;

-- DOWN

ALTER TABLE exf_ai_autonomous
    DROP CONSTRAINT IF EXISTS fk_exf_ai_autonomous_workflow;

DROP INDEX IF EXISTS idx_exf_ai_autonomous_workflow;

ALTER TABLE exf_ai_autonomous
    DROP COLUMN IF EXISTS ai_workflow_oid;

-- Do not delete the table containing AI workflow configurations!