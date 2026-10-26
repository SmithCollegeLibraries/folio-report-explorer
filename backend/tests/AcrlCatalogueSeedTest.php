<?php

function acrlSeedAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (getenv('ACRL_REQUIRE_MYSQL_TESTS') !== '1') {
    fwrite(STDOUT, "SKIP: set ACRL_REQUIRE_MYSQL_TESTS=1 to check migration 054 in its throwaway MySQL database\n");
    exit(0);
}

$host = getenv('MYSQL_HOST') ?: 'mysql';
$port = getenv('MYSQL_PORT') ?: '3306';
$database = getenv('MYSQL_DATABASE') ?: 'folio_reports';
$username = getenv('MYSQL_USER') ?: 'folio_app';
$password = getenv('MYSQL_PASSWORD') ?: 'folio_app_pass';
$pdo = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$expected = [
    2021 => [68, 4, 79, 4],
    2022 => [67, 4, 78, 4],
    2023 => [69, 5, 79, 5],
    2024 => [69, 9, 79, 9],
    2025 => [69, 15, 79, 15],
];
$years = $pdo->query('SELECT survey_year, status, survey_prompt_count, trends_prompt_count, survey_item_count, trends_item_count FROM acrl_survey_years ORDER BY survey_year')->fetchAll(PDO::FETCH_ASSOC);
acrlSeedAssert(count($years) === count($expected), 'Migration 054 must seed exactly 2021–2025 and leave 2026 for PDF upload.');
foreach ($years as $year) {
    $number = (int)$year['survey_year'];
    acrlSeedAssert(isset($expected[$number]), 'Unexpected seeded ACRL survey year ' . $number . '.');
    acrlSeedAssert($year['status'] === 'published', 'Historical catalogue years must be published.');
    $actual = [
        (int)$year['survey_prompt_count'],
        (int)$year['trends_prompt_count'],
        (int)$year['survey_item_count'],
        (int)$year['trends_item_count'],
    ];
    acrlSeedAssert($actual === $expected[$number], 'Prompt and part counts must match the prep catalogue for ' . $number . '.');
}

foreach ([2022, 2023, 2024, 2025] as $year) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM acrl_question_links l '
        . 'JOIN acrl_questions previous_question ON previous_question.survey_year = l.previous_year AND previous_question.sheet = l.sheet '
        . 'AND previous_question.question_id = l.previous_question_id AND previous_question.part = l.previous_part '
        . 'JOIN acrl_questions current_question ON current_question.survey_year = l.current_year AND current_question.sheet = l.sheet '
        . 'AND current_question.question_id = l.current_question_id AND current_question.part = l.current_part '
        . 'WHERE l.previous_year = :previous_year AND l.current_year = :current_year '
        . 'AND l.decision = \'approved\' AND l.decided_by = \'seed\''
    );
    $statement->execute([':previous_year' => $year - 1, ':current_year' => $year]);
    acrlSeedAssert((int)$statement->fetchColumn() > 0, 'Adjacent historical links must be seeded as approved for ' . ($year - 1) . '→' . $year . '.');
}

$false79Match = $pdo->prepare(
    'SELECT COUNT(*) FROM acrl_question_links l '
    . 'WHERE l.previous_year = 2021 AND l.previous_question_id = \'79b\' '
    . 'AND l.current_year = 2022 AND l.current_question_id = \'79c\''
);
$false79Match->execute();
acrlSeedAssert((int)$false79Match->fetchColumn() === 0, 'Historical links must not equate the unrelated 79b and 79c prompts.');

echo "PASS: ACRL historical prompt, part, and reviewed-link seeds match the prep catalogue\n";
