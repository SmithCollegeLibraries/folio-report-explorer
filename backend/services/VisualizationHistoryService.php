<?php

namespace app\services;

/** Validates and persists editable historical visualization values. */
final class VisualizationHistoryService
{
    private const VERSION_CACHE_KEY = 'visualization_history_version_v1';
    private $db;
    private $cache;
    private $existingUnitCodes;

    public function __construct($db, $cache, array $existingUnitCodes)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->existingUnitCodes = [];
        foreach ($existingUnitCodes as $code) {
            if (is_string($code) && trim($code) !== '') $this->existingUnitCodes[] = trim($code);
        }
    }

    public function list(string $acqUnitCode): array
    {
        $rows = $this->db->createCommand(
            'SELECT id, acq_unit_code AS "acqUnitCode", kind, fiscal_year_end AS "fiscalYearEnd",'
                . ' total_amount AS "totalAmount", electronic_amount AS "electronicAmount",'
                . ' physical_amount AS "physicalAmount", note, updated_by AS "updatedBy", updated_at AS "updatedAt"'
                . ' FROM visualization_history WHERE acq_unit_code = :unitCode AND deleted_at IS NULL'
                . ' ORDER BY fiscal_year_end, kind',
            [':unitCode' => trim($acqUnitCode)]
        )->queryAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['fiscalYearEnd'] = (int)$row['fiscalYearEnd'];
            $row['totalAmount'] = round((float)$row['totalAmount'], 2);
            $row['electronicAmount'] = $row['electronicAmount'] === null ? null : round((float)$row['electronicAmount'], 2);
            $row['physicalAmount'] = $row['physicalAmount'] === null ? null : round((float)$row['physicalAmount'], 2);
            $row['updatedBy'] = $row['updatedBy'] === null ? null : (int)$row['updatedBy'];
            $row['updatedAt'] = (string)$row['updatedAt'];
        }
        unset($row);
        return $rows;
    }

    public function upsert(array $input, int $actorId): array
    {
        list($values, $errors) = $this->validatedValues($input);
        if (!empty($errors)) return ['errors' => $errors];

        $values['updated_by'] = $actorId;
        $values['updated_at'] = date('Y-m-d H:i:s');
        $values['deleted_at'] = null;
        $values['deleted_by'] = null;
        $this->db->createCommand()->upsert('visualization_history', $values, true)->execute();
        $this->bumpVersion();

        foreach ($this->list($values['acq_unit_code']) as $row) {
            if ($row['kind'] === $values['kind'] && $row['fiscalYearEnd'] === $values['fiscal_year_end']) return $row;
        }
        return ['errors' => ['history' => ['The saved historical figure could not be reloaded.']]];
    }

    public function delete(int $id, int $actorId): array
    {
        $updated = $this->db->createCommand()->update('visualization_history', [
            'deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => $actorId,
        ], ['id' => $id, 'deleted_at' => null])->execute();
        if ($updated < 1) return ['errors' => ['id' => ['The active historical figure was not found.']]];
        $this->bumpVersion();
        return ['deleted' => true];
    }

    public function version(): int
    {
        $version = $this->cache->get(self::VERSION_CACHE_KEY);
        return is_numeric($version) ? (int)$version : 0;
    }

    private function bumpVersion(): void
    {
        $this->cache->set(self::VERSION_CACHE_KEY, $this->version() + 1);
    }

    private function validatedValues(array $input): array
    {
        $errors = [];
        $unitCode = isset($input['acqUnitCode']) && is_string($input['acqUnitCode']) ? trim($input['acqUnitCode']) : '';
        if ($unitCode === '' || !in_array($unitCode, $this->existingUnitCodes, true)) $errors['acqUnitCode'][] = 'Choose an existing acquisitions unit.';

        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['purchases', 'budget'], true)) $errors['kind'][] = 'Choose purchases or budget.';

        $year = $input['fiscalYearEnd'] ?? null;
        if (is_int($year)) {
            $normalizedYear = $year;
        } elseif (is_string($year) && preg_match('/^\d+$/', $year)) {
            $normalizedYear = (int)$year;
        } else {
            $normalizedYear = 0;
        }
        if ($normalizedYear < 1990 || $normalizedYear > 2100) $errors['fiscalYearEnd'][] = 'Fiscal year must be between 1990 and 2100.';

        $total = null;
        if (!$this->readAmount($input['totalAmount'] ?? null, $total)) $errors['totalAmount'][] = 'Enter an amount from 0 to 999999999999.99.';
        $electronic = null;
        $physical = null;
        if ($kind === 'purchases') {
            if (!$this->readAmount($input['electronicAmount'] ?? null, $electronic)) $errors['electronicAmount'][] = 'Enter an amount from 0 to 999999999999.99.';
            if (!$this->readAmount($input['physicalAmount'] ?? null, $physical)) $errors['physicalAmount'][] = 'Enter an amount from 0 to 999999999999.99.';
            if ($total !== null && $electronic !== null && $physical !== null && $electronic + $physical > $total + 0.00001) {
                $errors['electronicAmount'][] = 'Electronic and physical amounts cannot exceed the total.';
            }
        }

        $note = $input['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            $errors['note'][] = 'Note must be text.';
        } elseif ($note !== null && $this->textLength($note) > 255) {
            $errors['note'][] = 'Note must be 255 characters or fewer.';
        }

        if (!empty($errors)) return [[], $errors];
        return [[
            'acq_unit_code' => $unitCode,
            'kind' => $kind,
            'fiscal_year_end' => $normalizedYear,
            'total_amount' => $total,
            'electronic_amount' => $electronic,
            'physical_amount' => $physical,
            'note' => $note,
        ], []];
    }

    private function readAmount($value, &$amount): bool
    {
        if (!(is_int($value) || is_float($value) || (is_string($value) && trim($value) !== '' && is_numeric($value)))) return false;
        if (is_bool($value)) return false;
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || $number > 999999999999.99) return false;
        $rounded = round($number, 2);
        if (abs($number - $rounded) > 0.00001) return false;
        $amount = $rounded;
        return true;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
