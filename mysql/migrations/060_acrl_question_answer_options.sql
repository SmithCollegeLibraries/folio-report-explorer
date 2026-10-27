SET @acrl_question_answer_options_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'acrl_questions'
      AND COLUMN_NAME = 'answer_options') = 0,
  'ALTER TABLE `acrl_questions` ADD COLUMN `answer_options` TEXT NULL AFTER `answer_type`',
  'SELECT 1'
);
PREPARE acrl_question_answer_options_stmt FROM @acrl_question_answer_options_ddl;
EXECUTE acrl_question_answer_options_stmt;
DEALLOCATE PREPARE acrl_question_answer_options_stmt;
