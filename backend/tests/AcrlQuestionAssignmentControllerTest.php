<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../controllers/AcrlAssignmentController.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentRepository.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentService.php';

use app\controllers\AcrlAssignmentController;
use app\models\User;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-question-assignment-controller-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'test-key'],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['class' => yii\web\User::class, 'identityClass' => User::class, 'enableSession' => false],
    ],
]);
$app->errorHandler->unregister();
$db = $app->db;
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, smith_id TEXT, username TEXT, first_name TEXT, last_name TEXT, role TEXT, is_approved INTEGER)')->execute();
$db->createCommand('CREATE TABLE acrl_survey_years (survey_year INTEGER PRIMARY KEY, status TEXT NOT NULL)')->execute();
$db->createCommand('CREATE TABLE acrl_questions (survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL DEFAULT \'\', section TEXT NOT NULL, prompt TEXT NOT NULL, survey_order INTEGER NOT NULL, PRIMARY KEY (survey_year, sheet, question_id, part))')->execute();
$db->createCommand('CREATE TABLE acrl_section_assignments (institution_code TEXT NOT NULL, survey_year INTEGER NOT NULL, section TEXT NOT NULL, user_id INTEGER NOT NULL, assigned_by INTEGER NULL)')->execute();
$db->createCommand('CREATE TABLE acrl_question_assignments (institution_code TEXT NOT NULL, survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL DEFAULT \'\', user_id INTEGER NOT NULL, assigned_by INTEGER NULL, assigned_at TEXT)')->execute();
$db->createCommand()->insert('users', ['id' => 9, 'smith_id' => 'acrl028-admin', 'username' => 'acrl028-admin', 'first_name' => 'Synthetic', 'last_name' => 'Admin', 'role' => 'admin', 'is_approved' => 1])->execute();
$db->createCommand()->insert('users', ['id' => 10, 'smith_id' => 'acrl028-editor', 'username' => 'acrl028-editor', 'first_name' => 'Synthetic', 'last_name' => 'Editor', 'role' => 'user', 'is_approved' => 1])->execute();
$db->createCommand()->insert('users', ['id' => 11, 'smith_id' => 'acrl028-unapproved', 'username' => 'acrl028-unapproved', 'first_name' => 'Synthetic', 'last_name' => 'Unapproved', 'role' => 'user', 'is_approved' => 0])->execute();
$db->createCommand()->insert('acrl_survey_years', ['survey_year' => 2026, 'status' => 'published'])->execute();
$db->createCommand()->insert('acrl_questions', [
    'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A',
    'section' => 'Circulation', 'prompt' => 'Synthetic question', 'survey_order' => 1,
])->execute();
$db->createCommand()->insert('acrl_section_assignments', [
    'institution_code' => 'SC', 'survey_year' => 2026, 'section' => 'Circulation', 'user_id' => 10, 'assigned_by' => 9,
])->execute();
$db->createCommand()->insert('acrl_question_assignments', [
    'institution_code' => 'SC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 10, 'assigned_by' => 9,
])->execute();
$db->createCommand()->insert('acrl_question_assignments', [
    'institution_code' => 'SC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 11, 'assigned_by' => 9,
])->execute();
$db->createCommand()->insert('acrl_question_assignments', [
    'institution_code' => 'SC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'removed', 'part' => '', 'user_id' => 10, 'assigned_by' => 9,
])->execute();

$controller = new AcrlAssignmentController('acrl-assignment', $app);
$failures = [];
function acrlQuestionAssignmentControllerCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

function acrlQuestionAssignmentControllerCall($controller, int $year, array $body, int $status, string $label, array &$failures): void
{
    if (!method_exists($controller, 'actionSaveQuestionAssignments')) {
        $failures[] = $label . ' cannot run because AcrlAssignmentController::actionSaveQuestionAssignments is missing.';
        return;
    }
    Yii::$app->request->bodyParams = $body;
    $_SERVER['QUERY_STRING'] = 'institution=SC';
    Yii::$app->request->setQueryParams(['institution' => 'SC']);
    Yii::$app->response->statusCode = 200;
    try {
        $response = $controller->actionSaveQuestionAssignments($year);
        if (Yii::$app->response->statusCode !== $status) {
            $failures[] = $label . ' must return HTTP ' . $status . '; got ' . Yii::$app->response->statusCode . '.';
        }
        if (!is_array($response) || !array_key_exists('error', $response)) {
            $failures[] = $label . ' must return the expected error payload.';
        }
    } catch (Throwable $error) {
        $failures[] = $label . ' threw ' . get_class($error) . ': ' . $error->getMessage();
    }
}

$cases = [
    ['missing sheet', 2026, ['questionId' => '60', 'part' => 'A', 'userIds' => []], 422],
    ['non-string sheet', 2026, ['sheet' => 1, 'questionId' => '60', 'part' => 'A', 'userIds' => []], 422],
    ['missing questionId', 2026, ['sheet' => 'survey', 'part' => 'A', 'userIds' => []], 422],
    ['non-string questionId', 2026, ['sheet' => 'survey', 'questionId' => 60, 'part' => 'A', 'userIds' => []], 422],
    ['missing part', 2026, ['sheet' => 'survey', 'questionId' => '60', 'userIds' => []], 422],
    ['non-string part', 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => null, 'userIds' => []], 422],
    ['underscore part sentinel', 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => '_', 'userIds' => []], 422],
    ['missing userIds', 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => ''], 422],
    ['non-array userIds', 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => '', 'userIds' => '1'], 422],
    ['unpublished year', 2030, ['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'userIds' => []], 404],
    ['unknown question', 2026, ['sheet' => 'survey', 'questionId' => '999', 'part' => '', 'userIds' => []], 404],
    ['unapproved user', 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'userIds' => [11]], 422],
    ['non-positive user id', 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'userIds' => [0]], 422],
];

foreach ($cases as $case) {
    list($label, $year, $body, $expectedStatus) = $case;
    $app->user->setIdentity(User::findOne(9));
    acrlQuestionAssignmentControllerCall($controller, $year, $body, $expectedStatus, $label, $failures);
}

$app->user->setIdentity(User::findOne(9));
$_SERVER['QUERY_STRING'] = 'institution=SC';
Yii::$app->request->setQueryParams(['institution' => 'SC']);
try {
    $assignmentList = $controller->actionListAssignments(2026);
    $expectedSections = [[
        'section' => 'Circulation',
        'assignees' => [['id' => 10, 'displayName' => 'Synthetic Editor']],
    ]];
    acrlQuestionAssignmentControllerCheck(($assignmentList['surveyYear'] ?? null) === 2026, 'GET acrl/years/<year>/assignments keeps surveyYear unchanged.');
    acrlQuestionAssignmentControllerCheck(($assignmentList['institution'] ?? null) === 'SC', 'GET acrl/years/<year>/assignments exposes the selected institution.');
    acrlQuestionAssignmentControllerCheck(($assignmentList['sections'] ?? null) === $expectedSections, 'GET acrl/years/<year>/assignments keeps the existing sections payload byte-for-byte.');
    acrlQuestionAssignmentControllerCheck(($assignmentList['questions'] ?? null) === [[
        'sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'section' => 'Circulation',
        'assignees' => [['id' => 10, 'displayName' => 'Synthetic Editor']],
    ]], 'GET acrl/years/<year>/assignments adds ordered live question assignments and ignores revoked and stale rows.');
} catch (Throwable $error) {
    acrlQuestionAssignmentControllerCheck(false, 'GET acrl/years/<year>/assignments threw ' . get_class($error) . ': ' . $error->getMessage());
}

$app->user->setIdentity(User::findOne(10));
acrlQuestionAssignmentControllerCall($controller, 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'userIds' => []], 403, 'approved non-admin', $failures);
$app->user->setIdentity(null);
acrlQuestionAssignmentControllerCall($controller, 2026, ['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'userIds' => []], 403, 'guest', $failures);

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL question assignment controller checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, " - {$failure}\n");
    exit(1);
}

echo "PASS: ACRL question assignment controller authorization and 404/422 responses\n";
