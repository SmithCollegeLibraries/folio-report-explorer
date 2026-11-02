-- Durable monotonic version used in visualization result cache keys.
CREATE TABLE IF NOT EXISTS visualization_history_version (
    id TINYINT NOT NULL,
    version BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO visualization_history_version (id, version) VALUES (1, 0);
