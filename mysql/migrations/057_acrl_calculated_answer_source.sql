SET @acrl057_drop_answer_source = IF(
    EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answers'
            AND CONSTRAINT_NAME = 'chk_acrl_answer_source'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_acrl_answer_source'
            AND LOWER(CHECK_CLAUSE) LIKE '%calculated%'
    ),
    'ALTER TABLE acrl_answers DROP CONSTRAINT chk_acrl_answer_source',
    'SELECT 1'
);
PREPARE acrl057_statement FROM @acrl057_drop_answer_source;
EXECUTE acrl057_statement;
DEALLOCATE PREPARE acrl057_statement;

SET @acrl057_add_answer_source = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answers'
            AND CONSTRAINT_NAME = 'chk_acrl_answer_source'
    ),
    'ALTER TABLE acrl_answers ADD CONSTRAINT chk_acrl_answer_source CHECK (source IN (''manual'', ''import'', ''calculated''))',
    'SELECT 1'
);
PREPARE acrl057_statement FROM @acrl057_add_answer_source;
EXECUTE acrl057_statement;
DEALLOCATE PREPARE acrl057_statement;

SET @acrl057_drop_history_old_source = IF(
    EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history'
            AND CONSTRAINT_NAME = 'chk_acrl_history_old_source'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_acrl_history_old_source'
            AND LOWER(CHECK_CLAUSE) LIKE '%calculated%'
    ),
    'ALTER TABLE acrl_answer_history DROP CONSTRAINT chk_acrl_history_old_source',
    'SELECT 1'
);
PREPARE acrl057_statement FROM @acrl057_drop_history_old_source;
EXECUTE acrl057_statement;
DEALLOCATE PREPARE acrl057_statement;

SET @acrl057_add_history_old_source = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history'
            AND CONSTRAINT_NAME = 'chk_acrl_history_old_source'
    ),
    'ALTER TABLE acrl_answer_history ADD CONSTRAINT chk_acrl_history_old_source CHECK (old_source IS NULL OR old_source IN (''manual'', ''import'', ''calculated''))',
    'SELECT 1'
);
PREPARE acrl057_statement FROM @acrl057_add_history_old_source;
EXECUTE acrl057_statement;
DEALLOCATE PREPARE acrl057_statement;

SET @acrl057_drop_history_new_source = IF(
    EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history'
            AND CONSTRAINT_NAME = 'chk_acrl_history_new_source'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_acrl_history_new_source'
            AND LOWER(CHECK_CLAUSE) LIKE '%calculated%'
    ),
    'ALTER TABLE acrl_answer_history DROP CONSTRAINT chk_acrl_history_new_source',
    'SELECT 1'
);
PREPARE acrl057_statement FROM @acrl057_drop_history_new_source;
EXECUTE acrl057_statement;
DEALLOCATE PREPARE acrl057_statement;

SET @acrl057_add_history_new_source = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history'
            AND CONSTRAINT_NAME = 'chk_acrl_history_new_source'
    ),
    'ALTER TABLE acrl_answer_history ADD CONSTRAINT chk_acrl_history_new_source CHECK (new_source IN (''manual'', ''import'', ''calculated''))',
    'SELECT 1'
);
PREPARE acrl057_statement FROM @acrl057_add_history_new_source;
EXECUTE acrl057_statement;
DEALLOCATE PREPARE acrl057_statement;
