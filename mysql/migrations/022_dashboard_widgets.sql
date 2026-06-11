-- Migration 022: Opt-in dashboard widget catalog
-- Introduces two tables:
--   dashboard_widget_templates  — admin-managed catalog of pre-canned widgets
--   user_dashboard_widgets      — per-user record of which widgets have been added

-- ── Widget template catalog ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dashboard_widget_templates (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,
    description         TEXT         NULL,
    category            VARCHAR(50)  NOT NULL DEFAULT 'other'
                            COMMENT 'acquisitions | finance | inventory | other',
    icon                VARCHAR(50)  NOT NULL DEFAULT 'BarChart3'
                            COMMENT 'Lucide icon name used by the frontend',
    widget_type         ENUM('report', 'budget_monitor') NOT NULL DEFAULT 'report',
    report_template_id  INT          NULL
                            COMMENT 'FK to report_templates; NULL for non-report widget types',
    default_params      JSON         NULL
                            COMMENT 'Key-value overrides applied on top of report template defaults when adding widget',
    sort_order          INT          NOT NULL DEFAULT 0,
    is_enabled          TINYINT(1)   NOT NULL DEFAULT 1,
    created_by          INT          NULL,
    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_dwt_type    (widget_type),
    INDEX idx_dwt_enabled (is_enabled),
    INDEX idx_dwt_sort    (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Per-user widget preferences ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_dashboard_widgets (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT NOT NULL,
    widget_template_id   INT NOT NULL,
    -- For 'report' type widgets a SavedQuery is created and pinned on behalf of
    -- the user so it flows through the existing DashboardCard machinery.
    saved_query_id       INT NULL
                            COMMENT 'FK to saved_queries; NULL for budget_monitor widgets',
    added_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_widget (user_id, widget_template_id),
    INDEX idx_udw_user_id   (user_id),
    INDEX idx_udw_template  (widget_template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed the initial widget catalog ──────────────────────────────────────────
-- These are the reports we recommend surfacing as dashboard widgets.
-- Admins can add more after deployment.

INSERT INTO dashboard_widget_templates
    (id, name, description, category, icon, widget_type, report_template_id, default_params, sort_order, is_enabled)
VALUES
    -- Budget Monitor: custom widget driving the ExpenseMonitorCard UI
    (1,
     'Budget Monitor',
     'Track expense-class payments, encumbrances, and remaining balances at a glance. Choose which expense class codes to monitor after adding.',
     'finance',
     'DollarSign',
     'budget_monitor',
     NULL,
     NULL,
     10,
     1),

    -- Report: Budget by Material Type (report_templates.id = 1)
    (2,
     'Budget by Material Type',
     'Summarizes acquisitions expenditures by material type for the current fiscal year, broken down by order type (One-Time, Standing Order, Serial).',
     'acquisitions',
     'BarChart3',
     'report',
     1,
     '{"materialType": ""}',
     20,
     1),

    -- Report: Material Category by Fiscal Year (report_templates.id = 5)
    (3,
     'Material Category by Fiscal Year',
     'Breaks down spending into Electronic, Physical, and Other categories across fiscal years. Useful for tracking format trends over time.',
     'acquisitions',
     'TrendingUp',
     'report',
     5,
     NULL,
     30,
     1),

    -- Report: Fund Allocation by Fiscal Year (report_templates.id = 6)
    (4,
     'Fund Allocations',
     'Shows total allocated budget amounts per fiscal year for a selected fiscal year series.',
     'finance',
     'PiggyBank',
     'report',
     6,
     NULL,
     40,
     1),

    -- Report: Expense Class Summary (report_templates.id = 12)
    (5,
     'Expense Class Summary',
     'Total paid amounts grouped by expense class and material type for the current fiscal year.',
     'finance',
     'Receipt',
     'report',
     12,
     NULL,
     50,
     1),

    -- Report: Item Count by Library (report_templates.id = 8)
    (6,
     'Item Count by Library',
     'Counts items grouped by library. Optionally filter by library name and item status.',
     'inventory',
     'Library',
     'report',
     8,
     NULL,
     60,
     1),

    -- Report: Item Count by Location (report_templates.id = 9)
    (7,
     'Item Count by Location',
     'Counts items grouped by effective location. Optionally filter by library and item status.',
     'inventory',
     'MapPin',
     'report',
     9,
     NULL,
     70,
     1);
