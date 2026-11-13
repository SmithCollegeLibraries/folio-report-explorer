<?php

namespace app\services;

/** Runs validated, read-only visualization queries and shares unit-scoped SQL rules. */
final class VisualizationFolioQueryService
{
    private $db;
    private $validator;

    public function __construct($db, $validator = null)
    {
        $this->db = $db;
        $this->validator = $validator;
    }

    public function read(array $queries): array
    {
        $transaction = $this->db->beginTransaction();
        try {
            $this->db->createCommand('SET TRANSACTION READ ONLY')->execute();
            $this->db->createCommand("SET LOCAL statement_timeout = '30s'")->execute();
            $results = [];
            foreach ($queries as $query) {
                list($sql, $params) = $query;
                if ($this->validator !== null) {
                    $this->validator->validateSafety($sql);
                    $this->validator->validateTablePolicy($sql);
                } else {
                    SqlBuilderService::validateSafety($sql);
                    SqlBuilderService::validateTablePolicy($sql);
                }
                $results[] = $this->db->createCommand($sql, $params)->queryAll();
            }
            $transaction->commit();
            return $results;
        } catch (\Throwable $error) {
            if ($transaction->getIsActive()) $transaction->rollBack();
            throw $error;
        }
    }

    public static function contextSql(): string
    {
        return <<<'SQL'
SELECT au.id AS "id", TRIM(au.name) AS "code", TRIM(au.name) AS "name", fy.id AS "fiscalYearId",
       fy.period_start::date AS "periodStart", fy.period_end::date AS "periodEnd", EXTRACT(YEAR FROM fy.period_end::date)::int AS "fiscalYearEnd",
       (fy.period_start AT TIME ZONE 'UTC')::date AS "utcPeriodStart",
       (fy.period_end AT TIME ZONE 'UTC')::date AS "utcPeriodEnd",
       EXTRACT(YEAR FROM (fy.period_end AT TIME ZONE 'UTC')::date)::int AS "utcFiscalYearEnd"
FROM orders.acquisitions_unit__t au LEFT JOIN finance.fiscal_year__t fy
  ON fy.series = TRIM(au.name) || 'FY'
WHERE au.id = :unitId AND NOT au.is_deleted ORDER BY fy.period_end
SQL;
    }

    public static function unitFundsCte(): string
    {
        return 'unit_funds AS (SELECT DISTINCT fau.id AS fund_id, f.code AS fund_code FROM finance.fund__t__acq_unit_ids fau'
            . ' JOIN finance.fund__t f ON f.id = fau.id JOIN selected_unit au ON au.id = fau.acq_unit_ids)';
    }

    public static function paymentAmountExpression(string $distributionAlias = 'fd'): string
    {
        return "COALESCE(CASE WHEN LOWER(COALESCE({$distributionAlias}.fund_distributions__distribution_type, '')) = 'amount'"
            . " THEN {$distributionAlias}.fund_distributions__value ELSE COALESCE({$distributionAlias}.total, 0)"
            . " * COALESCE({$distributionAlias}.fund_distributions__value, 0) / 100.0 END, 0)";
    }
}
