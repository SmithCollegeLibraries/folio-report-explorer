<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use app\models\User;
use Firebase\JWT\JWT;
use yii\web\Application;

putenv('JWT_SECRET=rpt008-allocation-test-secret');

new Application([
    'id' => 'user-allocation-colleges-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => ['cookieValidationKey' => 'test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['identityClass' => User::class, 'enableSession' => false],
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

$createUsersSql = <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    smith_id VARCHAR(50) UNIQUE,
    username VARCHAR(100),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    affiliation VARCHAR(100),
    email VARCHAR(255),
    role VARCHAR(20),
    is_approved INTEGER,
    receive_notifications INTEGER,
    default_campus VARCHAR(50),
    allocation_colleges VARCHAR(32) NULL,
    last_login DATETIME NULL,
    refresh_token VARCHAR(512) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
)
SQL;
Yii::$app->db->createCommand($createUsersSql)->execute();

Yii::$app->db->createCommand()->batchInsert(
    'users',
    ['id', 'smith_id', 'username', 'role', 'is_approved', 'receive_notifications', 'default_campus', 'allocation_colleges'],
    [
        [1, 'rpt008-admin', 'admin', 'admin', 1, 1, 'Smith College', null],
        [2, 'rpt008-editor', 'editor', 'user', 1, 1, 'Smith College', 'MH'],
        [3, 'rpt008-plain', 'plain', 'user', 1, 1, 'Smith College', null],
    ]
)->execute();

function userAllocationAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function userAllocationPayload($token)
{
    return JWT::decode($token, getenv('JWT_SECRET'), ['HS256']);
}

$admin = User::findOne(1);
$editor = User::findOne(2);
$plain = User::findOne(3);

userAllocationAssert(method_exists($editor, 'canEditAllocationsFor'), 'User exposes the shared allocation permission helper.');
userAllocationAssert($editor->canEditAllocationsFor('mh'), 'An allocation editor can edit their assigned college case-insensitively.');
userAllocationAssert(!$editor->canEditAllocationsFor('SC'), 'An allocation editor cannot edit another college.');
userAllocationAssert($editor->canEditAllocationsFor(), 'An allocation editor can enter the allocation routes when assigned at least one college.');
userAllocationAssert(!$plain->canEditAllocationsFor(), 'An ordinary user without colleges cannot enter allocation routes.');
userAllocationAssert($admin->canEditAllocationsFor('SC'), 'Administrators can edit every college.');
userAllocationAssert($admin->canEditAllocationsFor('ACRL'), 'Administrators retain unrestricted allocation access.');

$editor->allocation_colleges = 'sc, MH,SC';
userAllocationAssert($editor->validate(), 'Known allocation colleges pass User validation.');
userAllocationAssert($editor->allocation_colleges === 'MH,SC', 'User validation stores allocation colleges sorted and de-duplicated.');
$editor->allocation_colleges = 'MH,XX';
userAllocationAssert(!$editor->validate(), 'User validation rejects a college outside the scope whitelist.');

$editor = User::findOne(2);
$editor->allocation_colleges = 'MH';
$editor->save(false);
$userArray = $editor->toArray();
userAllocationAssert($userArray['allocationColleges'] === ['MH'], 'The user response contains allocation colleges as an array.');
$accessToken = $editor->generateAccessToken();
userAllocationAssert(
    userAllocationPayload($accessToken)->user->allocationColleges === ['MH'],
    'The access-token user payload contains allocation colleges as an array.'
);

$refreshToken = $editor->generateRefreshToken();
Yii::$app->request->setBodyParams(['refreshToken' => $refreshToken]);
$controller = new FolioQueryController('folio-query', Yii::$app);
$refreshResponse = $controller->actionAuthRefresh();
userAllocationAssert(
    userAllocationPayload($refreshResponse['accessToken'])->user->allocationColleges === ['MH'],
    'Refreshing an access token rebuilds the allocation-college claim.'
);
userAllocationAssert($refreshResponse['user']['allocationColleges'] === ['MH'], 'The refresh response user contains allocation colleges.');

Yii::$app->db->createCommand()->update('users', ['allocation_colleges' => 'SC'], ['id' => 2])->execute();
Yii::$app->request->setBodyParams(['refreshToken' => $refreshResponse['refreshToken']]);
$updatedRefreshResponse = $controller->actionAuthRefresh();
userAllocationAssert(
    userAllocationPayload($updatedRefreshResponse['accessToken'])->user->allocationColleges === ['SC'],
    'A database allocation change takes effect in the next refreshed access token.'
);

Yii::$app->user->setIdentity(User::findOne(1));
Yii::$app->request->setBodyParams(['allocationColleges' => ['SC', 'MH', 'SC']]);
Yii::$app->response->statusCode = 200;
$updatedUser = $controller->actionUserAllocationColleges(3);
userAllocationAssert($updatedUser['allocationColleges'] === ['MH', 'SC'], 'Admins can assign sorted, unique allocation colleges to a user.');
userAllocationAssert(User::findOne(3)->role === 'user', 'Allocation access does not change the user role.');

Yii::$app->request->setBodyParams(['allocationColleges' => ['ACRL']]);
Yii::$app->response->statusCode = 200;
$invalidUpdate = $controller->actionUserAllocationColleges(3);
userAllocationAssert(Yii::$app->response->statusCode === 422, 'The allocation-colleges route rejects values outside the whitelist.');
userAllocationAssert(isset($invalidUpdate['error']), 'The allocation-colleges route explains a rejected whitelist value.');
userAllocationAssert(User::findOne(3)->getAllocationColleges() === ['MH', 'SC'], 'Rejected allocation assignments leave the existing values unchanged.');

Yii::$app->user->setIdentity(User::findOne(2));
Yii::$app->request->setBodyParams(['allocationColleges' => ['SC']]);
Yii::$app->response->statusCode = 200;
$forbiddenUpdate = $controller->actionUserAllocationColleges(3);
userAllocationAssert(Yii::$app->response->statusCode === 403, 'Only an administrator can assign allocation colleges.');
userAllocationAssert($forbiddenUpdate['error'] === 'Forbidden', 'A forbidden allocation assignment has stable error copy.');

Yii::$app->user->setIdentity(User::findOne(3));
Yii::$app->request->setBodyParams(['allocationColleges' => ['SC']]);
Yii::$app->response->statusCode = 200;
$plainForbiddenUpdate = $controller->actionUserAllocationColleges(3);
userAllocationAssert(Yii::$app->response->statusCode === 403, 'An ordinary user cannot assign allocation colleges.');
userAllocationAssert($plainForbiddenUpdate['error'] === 'Forbidden', 'An ordinary user receives stable allocation assignment denial copy.');

fwrite(STDOUT, "User allocation-colleges test passed\n");
