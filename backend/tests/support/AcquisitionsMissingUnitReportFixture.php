<?php

function acquisitionsMissingUnitReportSeed(): array
{
    $migrationPath = __DIR__ . '/../../../mysql/migrations/053_acquisitions_feedback_report_filters.sql';
    if (!is_file($migrationPath)) {
        throw new RuntimeException('Migration 053 has not seeded the missing-acquisitions-unit report.');
    }

    $migration = (string) file_get_contents($migrationPath);
    $slugPosition = strpos($migration, "'orders-missing-acquisitions-unit'");
    $valuesPosition = $slugPosition === false ? false : strrpos(substr($migration, 0, $slugPosition), 'VALUES');
    $reportMigration = $valuesPosition === false ? '' : substr($migration, $valuesPosition);
    $values = acquisitionsMissingUnitReportSqlValues($reportMigration);
    if (count($values) !== 12 || $values[0] !== 'orders-missing-acquisitions-unit') {
        throw new RuntimeException('Could not parse the migration-053 missing-acquisitions-unit report seed.');
    }

    return [
        'slug' => 'orders-missing-acquisitions-unit',
        'name' => $values[1],
        'description' => $values[2],
        'help_text' => $values[3],
        'category' => $values[4],
        'sql_template' => $values[5],
        'parameters' => $values[6],
        'data_source' => $values[7],
        'execution_config' => $values[8],
        'default_limit' => (int) $values[9],
        'is_active' => (int) $values[10],
        'created_by' => $values[11],
    ];
}

function acquisitionsMissingUnitReportSqlValues(string $migration): array
{
    $valuesPosition = strpos($migration, 'VALUES');
    $openParenthesis = $valuesPosition === false ? false : strpos($migration, '(', $valuesPosition);
    if ($openParenthesis === false) {
        throw new RuntimeException('Could not locate the missing-acquisitions-unit report seed values.');
    }

    $values = [];
    $position = $openParenthesis + 1;
    $length = strlen($migration);
    while ($position < $length) {
        while ($position < $length && ctype_space($migration[$position])) {
            $position++;
        }

        if ($position >= $length || $migration[$position] === ')') {
            break;
        }

        if ($migration[$position] === "'") {
            $position++;
            $value = '';
            $closed = false;
            while ($position < $length) {
                if ($migration[$position] === "'" && $position + 1 < $length && $migration[$position + 1] === "'") {
                    $value .= "'";
                    $position += 2;
                    continue;
                }
                if ($migration[$position] === "'") {
                    $position++;
                    $closed = true;
                    break;
                }
                $value .= $migration[$position];
                $position++;
            }
            if (!$closed) {
                throw new RuntimeException('Unterminated string in the missing-acquisitions-unit report seed.');
            }
            $values[] = $value;
        } else {
            $valueStart = $position;
            while ($position < $length && $migration[$position] !== ',' && $migration[$position] !== ')') {
                $position++;
            }
            $values[] = trim(substr($migration, $valueStart, $position - $valueStart));
        }

        while ($position < $length && ctype_space($migration[$position])) {
            $position++;
        }
        if ($position < $length && $migration[$position] === ',') {
            $position++;
            continue;
        }
        if ($position < $length && $migration[$position] === ')') {
            break;
        }
        throw new RuntimeException('Malformed values in the missing-acquisitions-unit report seed.');
    }

    return $values;
}
