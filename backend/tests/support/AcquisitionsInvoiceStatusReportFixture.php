<?php

namespace {
    require_once __DIR__ . '/../../models/ReportTemplate.php';

    function acquisitionsInvoiceStatusReportSkipWhitespace($sql, &$offset)
    {
        $length = strlen($sql);
        while ($offset < $length && ctype_space($sql[$offset])) {
            $offset++;
        }
    }

    function acquisitionsInvoiceStatusReportSeedValues($sql)
    {
        $valuesStart = strpos($sql, 'VALUES (');
        if ($valuesStart === false) {
            throw new \RuntimeException('Could not find the report seed VALUES block in migration 049.');
        }

        $offset = $valuesStart + strlen('VALUES (');
        $length = strlen($sql);
        $values = [];
        while ($offset < $length) {
            acquisitionsInvoiceStatusReportSkipWhitespace($sql, $offset);
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
            acquisitionsInvoiceStatusReportSkipWhitespace($sql, $offset);
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
            throw new \RuntimeException('Could not parse the next value in the migration 049 seed.');
        }

        throw new \RuntimeException('The migration 049 seed VALUES block is incomplete.');
    }

    function acquisitionsInvoiceStatusReportFixture(): \app\models\ReportTemplate
    {
        $seed = file_get_contents(__DIR__ . '/../../../mysql/migrations/049_acquisitions_invoice_status_report.sql');
        if ($seed === false) {
            throw new \RuntimeException('Could not load the acquisitions invoice report seed from migration 049.');
        }
        $values = acquisitionsInvoiceStatusReportSeedValues($seed);
        if (count($values) !== 12
            || $values[0] !== 'acquisitions-invoice-status-report'
            || $values[4] !== 'acquisitions'
            || $values[7] !== 'folio'
            || $values[9] !== '100000'
            || $values[10] !== '1'
            || $values[11] !== 'manual') {
            throw new \RuntimeException('Migration 049 does not contain the expected acquisitions invoice report seed.');
        }

        $report = new \app\models\ReportTemplate();
        foreach ([
            'id' => 49,
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
        ] as $key => $value) {
            $report->$key = $value;
        }
        return $report;
    }
}
