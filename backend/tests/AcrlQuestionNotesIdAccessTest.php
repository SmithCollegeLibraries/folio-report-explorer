<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../controllers/AcrlController.php';

use app\controllers\AcrlController;
use app\models\User;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-question-notes-id-access-test',
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
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, smith_id TEXT, username TEXT, first_name TEXT, last_name TEXT, email TEXT, role TEXT, is_approved INTEGER)')->execute();
$db->createCommand('CREATE TABLE acrl_institution_members (user_id INTEGER, institution_code TEXT, role TEXT, granted_by TEXT, granted_at TEXT, PRIMARY KEY(user_id, institution_code))')->execute();
$db->createCommand('CREATE TABLE acrl_survey_years (survey_year INTEGER PRIMARY KEY, status TEXT NOT NULL)')->execute();
$db->createCommand('CREATE TABLE acrl_question_notes (id INTEGER PRIMARY KEY, institution_code TEXT NOT NULL, survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL, note TEXT NOT NULL, source TEXT NOT NULL, origin_year INTEGER NOT NULL, created_by TEXT, created_at TEXT, edited_by TEXT, edited_at TEXT)')->execute();
$db->createCommand()->batchInsert('users', ['id', 'smith_id', 'username', 'first_name', 'last_name', 'email', 'role', 'is_approved'], [
    [1, 'sc-admin', 'sc-admin', 'SC', 'Admin', 'admin@smith.edu', 'user', 1],
    [2, 'sc-member', 'sc-member', 'SC', 'Member', 'member@smith.edu', 'user', 1],
    [3, 'hc-admin', 'hc-admin', 'HC', 'Admin', 'admin@hampshire.edu', 'user', 1],
    [4, 'app-admin', 'app-admin', 'App', 'Admin', 'app-admin@smith.edu', 'admin', 1],
])->execute();
$db->createCommand()->batchInsert('acrl_institution_members', ['user_id', 'institution_code', 'role', 'granted_by', 'granted_at'], [
    [1, 'SC', 'admin', 4, '2026-11-13 12:00:00'],
    [2, 'SC', 'member', 4, '2026-11-13 12:00:00'],
    [3, 'HC', 'admin', 4, '2026-11-13 12:00:00'],
])->execute();
$db->createCommand()->insert('acrl_survey_years', ['survey_year' => 2026, 'status' => 'published'])->execute();
$db->createCommand()->batchInsert('acrl_question_notes', ['id', 'institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'note', 'source', 'origin_year'], [
    [8, 'SC', 2026, 'survey', '60', '', 'Original Smith note', 'Synthetic', 2026],
    [9, 'HC', 2026, 'survey', '60', '', 'Staged Hampshire note', 'Synthetic', 2026],
])->execute();
$controller = new AcrlController('acrl', $app);
$body = ['note' => 'Updated Smith note', 'source' => 'Synthetic', 'origin_year' => 2026];

$app->user->setIdentity(User::findOne(1));
Yii::$app->request->bodyParams = $body;
$allowed = $controller->actionEditQuestionNote(8);
if (Yii::$app->response->statusCode !== 200 || ($allowed['note'] ?? null) !== 'Updated Smith note') {
    fwrite(STDERR, "FAIL: an approved SC college admin can edit an SC note.\n");
    exit(1);
}

$app->user->setIdentity(User::findOne(2));
Yii::$app->response->statusCode = 200;
$memberDenied = $controller->actionEditQuestionNote(8);
if (Yii::$app->response->statusCode !== 404 || ($memberDenied['error'] ?? null) !== 'The collection note no longer exists.'
    || (string)$db->createCommand('SELECT note FROM acrl_question_notes WHERE id = 8')->queryScalar() !== 'Updated Smith note') {
    fwrite(STDERR, "FAIL: a member without admin rights cannot edit a note by ID.\n");
    exit(1);
}

$app->user->setIdentity(User::findOne(3));
Yii::$app->response->statusCode = 200;
$disabledDenied = $controller->actionEditQuestionNote(9);
if (Yii::$app->response->statusCode !== 404 || ($disabledDenied['error'] ?? null) !== 'The collection note no longer exists.') {
    fwrite(STDERR, "FAIL: a staged HC admin cannot reach a non-enabled HC note.\n");
    exit(1);
}
Yii::$app->response->statusCode = 200;
$crossCollegeDenied = $controller->actionEditQuestionNote(8);
if (Yii::$app->response->statusCode !== 404 || ($crossCollegeDenied['error'] ?? null) !== 'The collection note no longer exists.'
    || (string)$db->createCommand('SELECT note FROM acrl_question_notes WHERE id = 8')->queryScalar() !== 'Updated Smith note') {
    fwrite(STDERR, "FAIL: an HC college admin cannot change an SC note by ID.\n");
    exit(1);
}

$app->user->setIdentity(User::findOne(4));
Yii::$app->response->statusCode = 200;
$deleted = $controller->actionDeleteQuestionNote(8);
if (Yii::$app->response->statusCode !== 200 || ($deleted['institution'] ?? null) !== 'SC'
    || (int)$db->createCommand('SELECT COUNT(*) FROM acrl_question_notes WHERE id = 8')->queryScalar() !== 0) {
    fwrite(STDERR, "FAIL: an app admin can delete the note for its stored institution.\n");
    exit(1);
}

echo "PASS: ACRL note ID routes authorize by the stored institution before mutation\n";
