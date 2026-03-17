-- Migration 016: Per-user expense class monitoring
-- Stores each instruction librarian's selected expense-class codes so the
-- dashboard can display a filtered budget-vs-actual summary card for them.

CREATE TABLE IF NOT EXISTS `user_expense_monitors` (
  `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`             INT(11)      NOT NULL,
  `expense_class_code`  VARCHAR(20)  NOT NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_code` (`user_id`, `expense_class_code`),
  CONSTRAINT `fk_expense_monitors_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
