-- Add 'report' to the saved_queries.source ENUM so widget-added
-- queries (created by actionDashboardWidgetAdd) can be stored with
-- source = 'report'.
ALTER TABLE `saved_queries`
  MODIFY COLUMN `source` ENUM('builder', 'nl', 'report')
    DEFAULT 'builder'
    COMMENT 'Origin: query builder, AI, or widget gallery';
