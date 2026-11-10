CREATE TABLE IF NOT EXISTS vea_gate_zones (
  zone_id VARCHAR(64) NOT NULL,
  sort_order INT NOT NULL,
  PRIMARY KEY (zone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
