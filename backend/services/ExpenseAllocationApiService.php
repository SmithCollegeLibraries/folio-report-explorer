<?php

namespace app\services;

use app\models\ExpenseAllocation;

require_once __DIR__ . '/ExpenseClassScopeService.php';
require_once __DIR__ . '/ExpenseAllocationService.php';

/** Handles the admin Local Data allocation API with college-scoped validation. */
final class ExpenseAllocationApiService
{
    public static function listRows(\PDO $pdo, $prefix, $fiscalYear = null): array
    {
        $prefix = self::prefix($prefix);
        if ($prefix === null) {
            return self::result(['error' => 'Choose a college before viewing allocations.'], 400);
        }
        return self::result([
            'items' => ExpenseAllocationService::listRows($pdo, $prefix, $fiscalYear),
            'years' => ExpenseAllocationService::availableYears($pdo, $prefix),
        ]);
    }

    public static function years(\PDO $pdo, $prefix): array
    {
        $prefix = self::prefix($prefix);
        if ($prefix === null) {
            return self::result(['error' => 'Choose a college before viewing allocations.'], 400);
        }
        return self::result(['years' => ExpenseAllocationService::availableYears($pdo, $prefix)]);
    }

    public static function upsert(array $body, $folioDb): array
    {
        $prefix = self::prefix($body['prefix'] ?? null);
        if ($prefix === null) {
            return self::result(['error' => 'Choose a college before managing allocations.'], 400);
        }
        $fiscalYear = (int) ($body['fiscalYear'] ?? 0);
        if ($fiscalYear < 1900 || $fiscalYear > 3000) {
            return self::result(['error' => 'Choose a valid fiscal year.'], 400);
        }

        $payload = [];
        if (!empty($body['pastedData'])) {
            foreach (self::parseBulkAllocationData($body['pastedData']) as $code => $amount) {
                $payload[] = ['expense_class_code' => $code, 'allocation_amount' => $amount];
            }
        } elseif (!empty($body['rows']) && is_array($body['rows'])) {
            $payload = $body['rows'];
        } elseif (isset($body['code'], $body['amount'])) {
            $payload[] = ['expense_class_code' => $body['code'], 'allocation_amount' => $body['amount']];
        }
        if (empty($payload)) {
            return self::result(['error' => 'No allocation data provided'], 400);
        }

        $codes = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                return self::result(['error' => 'Choose expense classes from the selected college.'], 400);
            }
            $codes[] = (string) ($row['expense_class_code'] ?? '');
        }
        try {
            ExpenseClassScopeService::assertExpenseClassesBelongToPrefix($folioDb, $prefix, $codes);
        } catch (\InvalidArgumentException $error) {
            return self::result(['error' => $error->getMessage()], 422);
        }

        $inserted = 0;
        $updated = 0;
        foreach ($payload as $row) {
            $code = strtoupper(trim((string) ($row['expense_class_code'] ?? '')));
            $model = ExpenseAllocation::findOne([
                'fiscal_year' => $fiscalYear,
                'expense_class_code' => $code,
            ]);
            if (!$model) {
                $model = new ExpenseAllocation();
                $model->fiscal_year = $fiscalYear;
                $model->expense_class_code = $code;
                $inserted++;
            } else {
                $updated++;
            }
            $model->allocation_amount = (float) ($row['allocation_amount'] ?? 0);
            if (!$model->save()) {
                return self::result(['error' => 'Validation failed', 'details' => $model->errors], 422);
            }
        }
        return self::result(['success' => true, 'inserted' => $inserted, 'updated' => $updated]);
    }

    public static function delete($id, $prefix, $folioDb): array
    {
        $prefix = self::prefix($prefix);
        if ($prefix === null) {
            return self::result(['error' => 'Choose a college before managing allocations.'], 400);
        }
        $model = ExpenseAllocation::findOne((int) $id);
        if (!$model) {
            return self::result(['error' => 'Allocation not found'], 404);
        }
        try {
            ExpenseClassScopeService::assertExpenseClassesBelongToPrefix(
                $folioDb,
                $prefix,
                [$model->expense_class_code]
            );
        } catch (\InvalidArgumentException $error) {
            return self::result(['error' => 'Allocation not found for the selected college.'], 404);
        }
        $model->delete();
        return self::result(['success' => true]);
    }

    public static function copyYear(array $body, \PDO $pdo, $folioDb): array
    {
        $prefix = self::prefix($body['prefix'] ?? null);
        if ($prefix === null) {
            return self::result(['error' => 'Choose a college before managing allocations.'], 400);
        }
        $targetYear = (int) ($body['fiscalYear'] ?? 0);
        if ($targetYear < 1900 || $targetYear > 3000) {
            return self::result(['error' => 'Choose a valid fiscal year.'], 400);
        }
        try {
            $sourceRows = ExpenseAllocationService::listRows($pdo, $prefix, $targetYear - 1);
            if (empty($sourceRows)) {
                return self::result(['error' => 'No rows found in previous fiscal year'], 404);
            }
            ExpenseClassScopeService::assertExpenseClassesBelongToPrefix(
                $folioDb,
                $prefix,
                array_column($sourceRows, 'expense_class_code')
            );
            $result = ExpenseAllocationService::copyFromPreviousYear($pdo, $targetYear, $prefix);
            return self::result(['success' => true] + $result);
        } catch (\InvalidArgumentException $error) {
            return self::result(['error' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return self::result(['error' => $error->getMessage()], 500);
        }
    }

    private static function prefix($value): ?string
    {
        try {
            return ExpenseClassScopeService::normalizePrefix($value);
        } catch (\InvalidArgumentException $error) {
            return null;
        }
    }

    private static function parseBulkAllocationData($pastedData): array
    {
        $lines = explode("\n", trim((string) $pastedData));
        $allocations = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s*\t+\s*|\s{2,}/', $line);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $code = strtoupper(trim((string) $parts[count($parts) - 1]));
            $amounts = [];
            foreach ($parts as $part) {
                if (preg_match('/\$?([0-9,]+\.?\d*)/', (string) $part, $matches)) {
                    $amounts[] = (float) str_replace([',', '$'], '', $matches[1]);
                }
            }
            if ($code !== '' && !empty($amounts)) {
                $allocations[$code] = count($amounts) > 1 ? $amounts[1] : $amounts[0];
            }
        }
        return $allocations;
    }

    private static function result(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
