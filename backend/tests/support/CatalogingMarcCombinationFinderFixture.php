<?php

namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord
        {
            private $attributes = [];
            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value): void { $this->attributes[$name] = $value; }
            public function hasAttribute($name): bool { return true; }
        }
    }
}

namespace {
    require_once __DIR__ . '/../../models/ReportTemplate.php';

    function marcCombinationFixtureSqlValues(string $sql): array
    {
        $insert = strpos($sql, 'INSERT INTO `report_templates`');
        $selectAt = $insert === false ? false : strpos($sql, "\nSELECT\n", $insert);
        if ($selectAt === false) {
            return [];
        }

        $index = $selectAt + strlen("\nSELECT\n");
        $length = strlen($sql);
        $values = [];
        while ($index < $length) {
            while ($index < $length && ctype_space($sql[$index])) {
                $index++;
            }
            if ($index >= $length || substr($sql, $index, 9) === 'FROM DUAL') {
                break;
            }

            if ($sql[$index] === "'") {
                $index++;
                $value = '';
                while ($index < $length) {
                    if ($sql[$index] === "'" && isset($sql[$index + 1]) && $sql[$index + 1] === "'") {
                        $value .= "'";
                        $index += 2;
                        continue;
                    }
                    if ($sql[$index] === "'") {
                        $index++;
                        break;
                    }
                    $value .= $sql[$index++];
                }
                $values[] = $value;
            } else {
                $start = $index;
                while ($index < $length && $sql[$index] !== ',' && substr($sql, $index, 9) !== 'FROM DUAL') {
                    $index++;
                }
                $values[] = trim(substr($sql, $start, $index - $start));
            }

            while ($index < $length && ctype_space($sql[$index])) {
                $index++;
            }
            if ($index < $length && $sql[$index] === ',') {
                $index++;
            } elseif ($index >= $length || substr($sql, $index, 9) !== 'FROM DUAL') {
                return [];
            }
        }
        return $values;
    }

    function marcCombinationFixtureSeed(?string $migrationPath = null): array
    {
        $path = $migrationPath ?: __DIR__ . '/../../../mysql/migrations/068_marc_field_combination_finder.sql';
        $values = marcCombinationFixtureSqlValues((string) @file_get_contents($path));
        if (count($values) !== 12) {
            throw new \RuntimeException('MARC field combination finder seed is missing or malformed.');
        }
        return [
            'slug' => $values[0],
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

    function marcCombinationFixtureReport(array $overrides = []): \app\models\ReportTemplate
    {
        $report = new \app\models\ReportTemplate();
        foreach (array_merge(marcCombinationFixtureSeed(), $overrides) as $attribute => $value) {
            $report->$attribute = $value;
        }
        return $report;
    }

    function marcCombinationFixtureParameterNames(): array
    {
        $names = ['locationIds', 'locationBasis', 'combine'];
        for ($slot = 1; $slot <= 5; $slot++) {
            foreach ([
                'marcTag', 'occurrenceCondition', 'firstIndicator', 'secondIndicator',
                'subfieldCode', 'contentRule', 'searchValue', 'caseExact',
            ] as $field) {
                $names[] = 'c' . $slot . '_' . $field;
            }
        }
        return $names;
    }

    function marcCombinationFixtureInputs(array $overrides = []): array
    {
        $inputs = [
            'locationIds' => '11111111-1111-4111-8111-111111111111',
            'locationBasis' => 'effective_item',
            'combine' => 'all',
        ];
        $conditionDefaults = [
            'marcTag' => '',
            'occurrenceCondition' => 'has',
            'firstIndicator' => 'any',
            'secondIndicator' => 'any',
            'subfieldCode' => '',
            'contentRule' => 'any',
            'searchValue' => '',
            'caseExact' => 'false',
        ];
        for ($slot = 1; $slot <= 5; $slot++) {
            foreach ($conditionDefaults as $field => $default) {
                $inputs['c' . $slot . '_' . $field] = $default;
            }
        }
        $inputs['c1_marcTag'] = '655';
        return array_merge($inputs, $overrides);
    }

    function marcCombinationFixtureSqliteSql(string $sql): string
    {
        $sql = str_replace('AS MATERIALIZED', 'AS', $sql);
        $sql = str_replace(
            ['"Title" NULLS LAST', '"Instance HRID" NULLS LAST', '"Instance UUID" NULLS LAST'],
            ['("Title" IS NULL), "Title"', '("Instance HRID" IS NULL), "Instance HRID"', '("Instance UUID" IS NULL), "Instance UUID"'],
            $sql
        );
        $sql = str_replace(
            "location.id = ANY(string_to_array(:locationIds, ',')::uuid[])",
            "instr(',' || :locationIds || ',', ',' || location.id || ',') > 0",
            $sql
        );
        $sql = preg_replace(
            "/STRING_AGG\\(\\s*DISTINCT (.*?),\\s*'; ' ORDER BY .*?\\)/s",
            'GROUP_CONCAT(DISTINCT $1)',
            $sql
        );
        $sql = str_replace(
            "STRING_AGG(result_value, '; ' ORDER BY first_occurrence, first_subfield)",
            "GROUP_CONCAT(result_value, '; ')",
            $sql
        );
        $sql = str_replace(
            'WHERE content_rank = 1) distinct_contents) ordered_contents',
            'WHERE content_rank = 1 ORDER BY first_occurrence, first_subfield) distinct_contents) ordered_contents',
            $sql
        );
        $sql = str_replace(['CHR(92)', 'STRPOS(', 'LEFT(', 'CHAR_LENGTH('], ['CHAR(92)', 'INSTR(', 'LEFT_FN(', 'LENGTH('], $sql);
        $sql = preg_replace('/\bCHR\((\d+)\)/', 'CHAR($1)', $sql);
        $sql = preg_replace('/SUBSTRING\\((:[A-Za-z][A-Za-z0-9_]*) FROM 6\\)/', 'SUBSTR($1, 6)', $sql);
        $sql = str_replace([" ~ '", ' ~ ('], [" REGEXP '", ' REGEXP ('], $sql);
        $sql = str_replace(['NULL::text', 'NULL::integer'], ['CAST(NULL AS TEXT)', 'CAST(NULL AS INTEGER)'], $sql);
        $sql = str_replace('id::text AS id', 'id AS id', $sql);
        if (stripos($sql, 'MATERIALIZED') !== false
            || stripos($sql, 'STRING_AGG(') !== false
            || preg_match('/GROUP_CONCAT\\s*\\([^)]*\\bORDER\\s+BY\\b/i', $sql) === 1) {
            throw new \RuntimeException('SQLite translation left syntax unsupported by SQLite 3.27.');
        }
        return $sql;
    }
}
