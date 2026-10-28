<?php

namespace app\services;

use Yii;

/** Runs the approved, fixed ACRL suggestion queries against the read-only FOLIO connection. */
class AcrlAutofillService
{
    private const DEFINITIONS = [
        'survey|60|' => [
            'queryId' => 'acrl-60', 'version' => 1, 'column' => 'q60_physical_circulation', 'file' => 'acr003-60-81-82-circulation.sql', 'kind' => 'dated',
            'summary' => [
                'Counts Smith-owned items held at Smith College or the Five College Repository.',
                'Uses the q60_physical_circulation result column.',
                'Includes Book, Journal, Serial, Newspaper, Score, Thesis/Dissertation, Government Publication, Microform, DVD/Blu-ray, Audio CD, CD-ROM, Videocassette, LP Phonorecord, Audiocassette and Film; other material types are left out.',
                'Counts loans checked out at a Smith service point and returned at Smith or still checked out; the survey\'s fiscal year, 1 July to 30 June.',
                'Counts one row per checkout; renewals are not filtered, and loans outside these campus and service-point rules are left out.',
            ],
        ],
        'survey|81|' => [
            'queryId' => 'acrl-81', 'version' => 1, 'column' => 'q81_consortial_lending', 'file' => 'acr003-60-81-82-circulation.sql', 'kind' => 'dated',
            'summary' => [
                'Counts Smith-owned items held at Smith College or the Five College Repository.',
                'Uses the q81_consortial_lending result column.',
                'Includes Book, Journal, Serial, Newspaper, Score, Thesis/Dissertation, Government Publication, Microform, DVD/Blu-ray, Audio CD, CD-ROM, Videocassette, LP Phonorecord, Audiocassette and Film; other material types are left out.',
                'Counts loans checked out at a non-Smith service point; the survey\'s fiscal year, 1 July to 30 June.',
                'Counts one row per checkout; renewals are not filtered, and Smith service-point loans and loans outside these campuses are left out.',
            ],
        ],
        'survey|82|' => [
            'queryId' => 'acrl-82', 'version' => 1, 'column' => 'q82_consortial_borrowing', 'file' => 'acr003-60-81-82-circulation.sql', 'kind' => 'dated',
            'summary' => [
                'Counts partner-owned items held outside Smith College and the Five College Repository.',
                'Uses the q82_consortial_borrowing result column.',
                'Includes Book, Journal, Serial, Newspaper, Score, Thesis/Dissertation, Government Publication, Microform, DVD/Blu-ray, Audio CD, CD-ROM, Videocassette, LP Phonorecord, Audiocassette and Film; other material types are left out.',
                'Counts loans checked out at a Smith service point; the survey\'s fiscal year, 1 July to 30 June.',
                'Counts one row per checkout; renewals are not filtered, and Smith-owned items and loans outside Smith service points are left out.',
            ],
        ],
        'survey|40|A' => [
            'queryId' => 'acrl-40A', 'version' => 2, 'column' => 'value', 'file' => 'acr003-40A-physical-titles.sql', 'kind' => 'snapshot',
            'summary' => [
                'Counts distinct physical book titles at Smith College and the Five College Repository.',
                'Includes items with status Awaiting pickup, In transit, Checked out, Available, Restricted or Paged.',
                'Includes Archival material, Book, Government Publication, Score and Thesis/Dissertation material types; other campuses, statuses and material types are left out.',
                'This is a current snapshot with no date range.',
                'Changed in v2 (2026-10): Five College Repository titles are now included, as in past reporting; v1 counted the Smith campus only.',
            ],
        ],
        'survey|40a|' => [
            'queryId' => 'acrl-40a', 'version' => 3, 'column' => 'value', 'file' => 'acr003-40a-physical-volumes.sql', 'kind' => 'snapshot',
            'summary' => [
                'Books, scores, theses and other volumes, including serial volumes cataloged as monographs, at Smith College and the Five College Repository.',
                'Includes items with status Awaiting pickup, In transit, Checked out, Available, Restricted or Paged.',
                'Includes Archival material, Book, Government Publication, Score, Supplement and Thesis/Dissertation material types; other material types are left out.',
                'Journal, Newspaper and Serial items are counted only when their instance is cataloged as a single unit (monograph).',
                'Online and microform locations are left out. This is a current snapshot with no date range.',
                'Changed in v3 (2026-10): serial items count only when cataloged as monographs; v2 (2026-10) counted all journals, serials and newspapers; FY2023–FY2025 used v1 (books, scores, theses, archival material and government publications; no location exclusions).',
            ],
        ],
        'survey|40|B' => [
            'queryId' => 'acrl-40B', 'version' => 1, 'column' => 'value', 'file' => 'acr003-40B-digital-titles.sql', 'kind' => 'snapshot',
            'summary' => [
                'Counts distinct FOLIO digital book titles at Smith College and the Five College Repository.',
                'Includes items with status Awaiting pickup, In transit, Checked out, Available, Restricted or Paged.',
                'Includes E-Book, E-Book Package, E-Score and E-Thesis/Dissertation material types; other campuses, statuses and material types are left out.',
                'EDS custom-catalog counts are outside FOLIO and are entered separately. This is a current snapshot with no date range.',
            ],
        ],
        'survey|42|A' => [
            'queryId' => 'acrl-42A', 'version' => 1, 'column' => 'value', 'file' => 'acr003-42A-physical-media.sql', 'kind' => 'snapshot',
            'summary' => [
                'Counts distinct physical media titles at Smith College and the Five College Repository.',
                'Includes items with status Awaiting pickup, In transit, Checked out, Available, Restricted or Paged.',
                'Includes Audio CD, Audiocassette, CD-ROM, DVD/Blu-ray, Film, Image, LP Phonorecord, Map, Microform, Video Game and Videocassette material types; other campuses, statuses and material types are left out.',
                'This is a current snapshot with no date range.',
            ],
        ],
        'survey|42|B' => [
            'queryId' => 'acrl-42B', 'version' => 1, 'column' => 'value', 'file' => 'acr003-42B-digital-media.sql', 'kind' => 'snapshot',
            'summary' => [
                'Counts distinct FOLIO digital media titles at Smith College and the Five College Repository.',
                'Includes items with status Awaiting pickup, In transit, Checked out, Available, Restricted or Paged.',
                'Includes Data File, Streaming Audio and Streaming Video material types; other campuses, statuses and material types are left out.',
                'EDS custom-catalog counts are outside FOLIO and are entered separately. This is a current snapshot with no date range.',
            ],
        ],
        'survey|43|A' => [
            'queryId' => 'acrl-43A', 'version' => 1, 'column' => 'value', 'file' => 'acr003-43A-physical-serials.sql', 'kind' => 'snapshot',
            'summary' => [
                'Counts distinct physical serial titles at Smith College and the Five College Repository.',
                'Includes items with status Awaiting pickup, In transit, Checked out, Available, Restricted or Paged.',
                'Includes Journal, Newspaper and Serial material types; other campuses, statuses and material types are left out.',
                'This is a current snapshot with no date range.',
            ],
        ],
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
        $sql = $this->loadSql($definition);

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
            'queryVersion' => $definition['version'],
            'ranAt' => $now->format(DATE_ATOM),
            'asOf' => $definition['kind'] === 'snapshot' ? $now->format('Y-m-d') : null,
            'fyStart' => $definition['kind'] === 'dated' ? $fyStart : null,
            'fyEnd' => $definition['kind'] === 'dated' ? $fyEnd : null,
            'kind' => $definition['kind'],
        ];
    }

    public function methods(): array
    {
        $methods = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            list($sheet, $questionId, $part) = explode('|', $key);
            $methods[] = [
                'sheet' => $sheet,
                'questionId' => $questionId,
                'part' => $part,
                'queryId' => $definition['queryId'],
                'queryVersion' => $definition['version'],
                'kind' => $definition['kind'],
                'summary' => $definition['summary'],
                'sql' => $this->loadSql($definition),
            ];
        }
        return $methods;
    }

    private function loadSql(array $definition): string
    {
        $sqlPath = $this->sqlDirectory . '/' . $definition['file'];
        $sql = file_get_contents($sqlPath);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('The configured ACRL auto-fill query could not be loaded.');
        }
        $sql = preg_replace('/^[ \t]*--[^\r\n]*(?:\r?\n|$)/m', '', $sql);
        if ($sql === null) {
            throw new \RuntimeException('The configured ACRL auto-fill query comments could not be removed.');
        }
        return $sql;
    }
}
