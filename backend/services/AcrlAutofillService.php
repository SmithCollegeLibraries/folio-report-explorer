<?php

namespace app\services;

use Yii;

/** Runs the approved, fixed ACRL suggestion queries against the read-only FOLIO connection. */
class AcrlAutofillService
{
    private const DEFINITIONS = [
        'survey|60|' => ['queryId' => 'acrl-60', 'column' => 'q60_physical_circulation', 'file' => 'acr003-60-81-82-circulation.sql', 'kind' => 'dated'],
        'survey|81|' => ['queryId' => 'acrl-81', 'column' => 'q81_consortial_lending', 'file' => 'acr003-60-81-82-circulation.sql', 'kind' => 'dated'],
        'survey|82|' => ['queryId' => 'acrl-82', 'column' => 'q82_consortial_borrowing', 'file' => 'acr003-60-81-82-circulation.sql', 'kind' => 'dated'],
        'survey|40|A' => ['queryId' => 'acrl-40A', 'column' => 'value', 'file' => 'acr003-40A-physical-titles.sql', 'kind' => 'snapshot'],
        'survey|40a|' => ['queryId' => 'acrl-40a', 'column' => 'value', 'file' => 'acr003-40a-physical-volumes.sql', 'kind' => 'snapshot'],
        'survey|40|B' => ['queryId' => 'acrl-40B', 'column' => 'value', 'file' => 'acr003-40B-digital-titles.sql', 'kind' => 'snapshot'],
        'survey|42|A' => ['queryId' => 'acrl-42A', 'column' => 'value', 'file' => 'acr003-42A-physical-media.sql', 'kind' => 'snapshot'],
        'survey|42|B' => ['queryId' => 'acrl-42B', 'column' => 'value', 'file' => 'acr003-42B-digital-media.sql', 'kind' => 'snapshot'],
        'survey|43|A' => ['queryId' => 'acrl-43A', 'column' => 'value', 'file' => 'acr003-43A-physical-serials.sql', 'kind' => 'snapshot'],
    ];

    private $repository;
    private $folioDb;
    private $clock;
    private $sqlDirectory;

    public function __construct($repository = null, $folioDb = null, $clock = null, $sqlDirectory = null)
    {
        $this->repository = $repository ?: new AcrlAnswerRepository();
        $this->folioDb = $folioDb ?: Yii::$app->folioDb;
        $this->clock = $clock ?: function () { return new \DateTimeImmutable('now'); };
        $this->sqlDirectory = $sqlDirectory ?: __DIR__ . '/../sql/acrl';
    }

    public function run(int $year, string $sheet, string $questionId, string $part): array
    {
        if ($year < 1) {
            throw new \InvalidArgumentException('Survey year must be a positive year.');
        }

        $key = $sheet . '|' . $questionId . '|' . $part;
        $definition = self::DEFINITIONS[$key] ?? null;
        if ($definition === null) {
            throw new \DomainException('No auto-fill query is defined for this question.');
        }

        $yearRow = $this->repository->year($year);
        if (!$yearRow || ($yearRow['status'] ?? null) !== 'published') {
            throw new \DomainException('The selected ACRL year is not published.');
        }
        if (!$this->repository->question($year, $sheet, $questionId, $part)) {
            throw new \DomainException('No auto-fill query is defined for this question.');
        }

        $now = call_user_func($this->clock);
        if (!$now instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('The auto-fill clock must return a date-time value.');
        }
        $fyStart = sprintf('%04d-07-01', $year - 1);
        $fyEnd = sprintf('%04d-07-01', $year);
        $params = $definition['kind'] === 'dated'
            ? [':fy_start' => $fyStart, ':fy_end' => $fyEnd]
            : [];
        $sqlPath = $this->sqlDirectory . '/' . $definition['file'];
        $sql = file_get_contents($sqlPath);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('The configured ACRL auto-fill query could not be loaded.');
        }
        $sql = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sql);
        if ($sql === null) {
            throw new \RuntimeException('The configured ACRL auto-fill query comments could not be removed.');
        }

        $transaction = $this->folioDb->beginTransaction();
        try {
            $this->folioDb->createCommand('SET TRANSACTION READ ONLY')->execute();
            $this->folioDb->createCommand('SET LOCAL statement_timeout = 150000')->execute();
            $lockKey = $year . '|' . $sheet . '|' . $questionId . '|' . $part;
            $locked = $this->folioDb->createCommand(
                'SELECT pg_try_advisory_xact_lock(1886221353, hashtext(:lock_key))',
                [':lock_key' => $lockKey]
            )->queryScalar();
            if (!$locked) {
                throw new AcrlAutofillBusyException('An auto-fill query is already running for this question.');
            }

            $row = $this->folioDb->createCommand($sql, $params)->queryOne();
            if (!is_array($row) || !array_key_exists($definition['column'], $row) || !is_numeric($row[$definition['column']])) {
                throw new \RuntimeException('The ACRL auto-fill query did not return its expected count.');
            }
            $value = (int)$row[$definition['column']];
            if ($value < 0) {
                throw new \RuntimeException('The ACRL auto-fill query returned an invalid count.');
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }

        return [
            'value' => $value,
            'queryId' => $definition['queryId'],
            'queryVersion' => 1,
            'ranAt' => $now->format(DATE_ATOM),
            'asOf' => $definition['kind'] === 'snapshot' ? $now->format('Y-m-d') : null,
            'fyStart' => $definition['kind'] === 'dated' ? $fyStart : null,
            'fyEnd' => $definition['kind'] === 'dated' ? $fyEnd : null,
            'kind' => $definition['kind'],
        ];
    }
}
