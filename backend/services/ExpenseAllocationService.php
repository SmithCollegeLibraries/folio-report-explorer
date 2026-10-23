<?php

namespace app\services;

require_once __DIR__ . '/ExpenseClassScopeService.php';

/** Provides college-scoped Local Data allocation reads and copy-year operations. */
final class ExpenseAllocationService
{
    public static function listRows(\PDO $pdo, $prefix, $fiscalYear = null): array
    {
        $prefix = ExpenseClassScopeService::normalizePrefix($prefix);
        $sql = 'SELECT id, fiscal_year, expense_class_code, allocation_amount'
            . ' FROM report_expense_allocations'
            . ' WHERE UPPER(TRIM(expense_class_code)) LIKE ?';
        $params = [$prefix . '%'];
        if ($fiscalYear !== null && $fiscalYear !== '') {
            $sql .= ' AND fiscal_year = ?';
            $params[] = (int) $fiscalYear;
        }
        $sql .= ' ORDER BY fiscal_year DESC, expense_class_code ASC';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function availableYears(\PDO $pdo, $prefix): array
    {
        $prefix = ExpenseClassScopeService::normalizePrefix($prefix);
        $statement = $pdo->prepare(
            'SELECT DISTINCT fiscal_year FROM report_expense_allocations'
                . ' WHERE UPPER(TRIM(expense_class_code)) LIKE ? ORDER BY fiscal_year DESC'
        );
        $statement->execute([$prefix . '%']);
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function copyFromPreviousYear(\PDO $pdo, $targetYear, $prefix): array
    {
        $prefix = ExpenseClassScopeService::normalizePrefix($prefix);
        $targetYear = (int) $targetYear;
        if ($targetYear < 1900 || $targetYear > 3000) {
            throw new \InvalidArgumentException('Choose a valid fiscal year.');
        }

        $sourceYear = $targetYear - 1;
        $transaction = $pdo->beginTransaction();
        try {
            $source = $pdo->prepare(
                'SELECT expense_class_code, allocation_amount FROM report_expense_allocations'
                    . ' WHERE fiscal_year = ? AND UPPER(TRIM(expense_class_code)) LIKE ?'
                    . ' ORDER BY expense_class_code'
            );
            $source->execute([$sourceYear, $prefix . '%']);
            $rows = $source->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) {
                $transaction ? $pdo->rollBack() : null;
                return ['copied' => 0, 'skipped' => 0, 'sourceYear' => $sourceYear, 'foundSource' => false];
            }

            $selectTarget = $pdo->prepare(
                'SELECT id FROM report_expense_allocations WHERE fiscal_year = ? AND expense_class_code = ?'
            );
            $insert = $pdo->prepare(
                'INSERT INTO report_expense_allocations (fiscal_year, expense_class_code, allocation_amount)'
                    . ' VALUES (?, ?, ?)'
            );
            $copied = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $selectTarget->execute([$targetYear, $row['expense_class_code']]);
                if ($selectTarget->fetchColumn() !== false) {
                    $skipped++;
                    continue;
                }
                $insert->execute([$targetYear, $row['expense_class_code'], $row['allocation_amount']]);
                $copied++;
            }
            $pdo->commit();
            return ['copied' => $copied, 'skipped' => $skipped, 'sourceYear' => $sourceYear, 'foundSource' => true];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
