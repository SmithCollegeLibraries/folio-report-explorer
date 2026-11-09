<?php

function giftReportsMigrationDefinitions(): array
{
    $path = __DIR__ . '/../../../mysql/migrations/070_gift_reports.sql';
    if (!is_file($path)) {
        throw new RuntimeException('Migration 070 is not present.');
    }
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Migration 070 is empty.');
    }

    $insert = stripos($source, 'INSERT INTO report_templates');
    $columnsStart = strpos($source, '(', $insert);
    $columnsEnd = strpos($source, ')', $columnsStart);
    $columns = array_map('trim', explode(',', substr($source, $columnsStart + 1, $columnsEnd - $columnsStart - 1)));
    $valuesStart = stripos($source, 'VALUES', $columnsEnd) + strlen('VALUES');
    $length = strlen($source);
    $index = $valuesStart;
    $definitions = [];

    while ($index < $length) {
        while ($index < $length && (ctype_space($source[$index]) || $source[$index] === ',')) {
            $index++;
        }
        if ($index >= $length || $source[$index] !== '(') {
            break;
        }
        $index++;
        $values = [];
        $value = '';
        $quoted = false;
        while ($index < $length) {
            $char = $source[$index];
            if ($quoted) {
                if ($char === "'" && $index + 1 < $length && $source[$index + 1] === "'") {
                    $value .= "'";
                    $index += 2;
                    continue;
                }
                if ($char === "'") {
                    $quoted = false;
                } else {
                    $value .= $char;
                }
            } elseif ($char === "'") {
                $quoted = true;
            } elseif ($char === ',') {
                $values[] = giftReportMigrationValue($value);
                $value = '';
            } elseif ($char === ')') {
                $values[] = giftReportMigrationValue($value);
                $value = '';
                $index++;
                break;
            } else {
                $value .= $char;
            }
            $index++;
        }
        if (count($values) !== count($columns)) {
            throw new RuntimeException('Migration 070 report seed does not match its column list.');
        }
        $definition = array_combine($columns, $values);
        if (!is_array($definition) || empty($definition['slug'])) {
            throw new RuntimeException('Migration 070 report seed has no slug.');
        }
        $definition['composite_config'] = null;
        $definitions[$definition['slug']] = $definition;
    }

    return $definitions;
}

function giftReportMigrationValue(string $value)
{
    $value = trim($value);
    if (strtoupper($value) === 'NULL') {
        return null;
    }
    if (is_numeric($value)) {
        return (int) $value;
    }
    return $value;
}
