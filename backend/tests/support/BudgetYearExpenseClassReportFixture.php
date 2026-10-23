<?php

namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord
        {
            private $attributes = [];
            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value) { $this->attributes[$name] = $value; }
            public function hasAttribute($name) { return true; }
        }
    }
}

namespace {
    require_once __DIR__ . '/../../models/ReportTemplate.php';

    function budgetYearExpenseClassSkipWhitespace($sql, &$offset)
    {
        $length = strlen($sql);
        while ($offset < $length && ctype_space($sql[$offset])) {
            $offset++;
        }
    }

    function budgetYearExpenseClassSeedValues($sql)
    {
        $valuesStart = strpos($sql, 'VALUES (');
        if ($valuesStart === false) {
            throw new \RuntimeException('Could not find the report seed VALUES block in migration 051.');
        }

        $offset = $valuesStart + strlen('VALUES (');
        $length = strlen($sql);
        $values = [];
        while ($offset < $length) {
            budgetYearExpenseClassSkipWhitespace($sql, $offset);
            if ($offset >= $length) {
                break;
            }
            if ($sql[$offset] === ')') {
                return $values;
            }

            if ($sql[$offset] === "'") {
                $offset++;
                $value = '';
                $closed = false;
                while ($offset < $length) {
                    if ($sql[$offset] === "'") {
                        if ($offset + 1 < $length && $sql[$offset + 1] === "'") {
                            $value .= "'";
                            $offset += 2;
                            continue;
                        }
                        $offset++;
                        $closed = true;
                        break;
                    }
                    $value .= $sql[$offset];
                    $offset++;
                }
                if (!$closed) {
                    throw new \RuntimeException('The report seed contains an unterminated SQL string.');
                }
            } else {
                $valueStart = $offset;
                while ($offset < $length && $sql[$offset] !== ',' && $sql[$offset] !== ')') {
                    $offset++;
                }
                $value = trim(substr($sql, $valueStart, $offset - $valueStart));
                if ($value === '') {
                    throw new \RuntimeException('The report seed contains an empty SQL value.');
                }
            }

            $values[] = $value;
            budgetYearExpenseClassSkipWhitespace($sql, $offset);
            if ($offset >= $length) {
                break;
            }
            if ($sql[$offset] === ',') {
                $offset++;
                continue;
            }
            if ($sql[$offset] === ')') {
                return $values;
            }
            throw new \RuntimeException('Could not parse the next value in migration 051.');
        }

        throw new \RuntimeException('The migration 051 report seed is incomplete.');
    }

    function budgetYearExpenseClassReportFixture(): \app\models\ReportTemplate
    {
        $path = __DIR__ . '/../../../mysql/migrations/051_budget_year_expense_class_report.sql';
        $migration = file_get_contents($path);
        if (!is_string($migration)) {
            throw new \RuntimeException('Could not load the budget-year expense class report seed from migration 051.');
        }
        $values = budgetYearExpenseClassSeedValues($migration);
        if (count($values) !== 14
            || $values[0] !== '36'
            || $values[1] !== 'budget-year-expense-class'
            || $values[5] !== 'finance'
            || $values[8] !== 'composite'
            || $values[11] !== '100000'
            || $values[12] !== '1'
            || $values[13] !== 'manual') {
            throw new \RuntimeException('Migration 051 does not contain the expected budget-year expense class report seed.');
        }

        $report = new \app\models\ReportTemplate();
        $fields = [
            'id', 'slug', 'name', 'description', 'help_text', 'category', 'sql_template', 'parameters',
            'data_source', 'execution_config', 'composite_config', 'default_limit', 'is_active', 'created_by',
        ];
        foreach ($fields as $index => $field) {
            $value = $values[$index];
            $report->$field = strtoupper($value) === 'NULL' ? null : $value;
        }
        return $report;
    }
}
