<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/AcrlSignInIdentityService.php';
require_once __DIR__ . '/../services/AcrlInstitutionIdentityService.php';

use app\models\User;
use app\services\AcrlInstitutionIdentityService;
use app\services\AcrlSignInIdentityService;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-sign-in-identity-test',
    'basePath' => dirname(__DIR__),
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:']],
]);
$app->errorHandler->unregister();
$db = $app->db;
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, smith_id TEXT UNIQUE, username TEXT, email TEXT NULL, first_name TEXT, last_name TEXT, affiliation TEXT, role TEXT, is_approved INTEGER)')->execute();
$db->createCommand()->batchInsert('users', ['smith_id', 'username', 'email', 'first_name', 'last_name', 'affiliation', 'role', 'is_approved'], [
    ['shared-uid', 'shared-uid', 'person@amherst.edu', 'Before', 'Name', 'staff', 'user', 1],
    ['smith-legacy', 'smith-legacy', null, 'Legacy', 'Person', 'staff', 'user', 0],
    ['fc-123', 'fc-user', 'old@smith.edu', 'Existing', 'User', 'staff', 'admin', 1],
])->execute();

$failures = [];
function acrlSignInIdentityCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

$service = new AcrlSignInIdentityService();
$resolved = $service->findOrCreate(null, 'shared-uid', 'PERSON@SMITH.EDU', 'Changed', 'Name', 'faculty');
acrlSignInIdentityCheck($resolved === null, 'A reused bare uid with another college email is refused.');
acrlSignInIdentityCheck((string)User::findOne(['smith_id' => 'shared-uid'])->email === 'person@amherst.edu', 'A refused uid collision does not update the other college account.');

$resolved = $service->findOrCreate(null, 'smith-legacy', 'legacy@smith.edu', 'Legacy', 'Person', 'staff');
acrlSignInIdentityCheck(is_array($resolved) && (int)$resolved['user']->id === 2 && !$resolved['isNewUser'], 'Missing fcIdNumber finds an existing matching Smith eppn account.');
acrlSignInIdentityCheck((string)User::findOne(2)->email === 'legacy@smith.edu', 'A legacy Smith account with no email is filled from eppn.');

$before = (string)User::findOne(3)->email;
acrlSignInIdentityCheck($service->findOrCreate(null, 'fc-user', null, 'Changed', 'User', 'staff') === null, 'Missing eppn is refused on the uid fallback path.');
acrlSignInIdentityCheck((string)User::findOne(3)->email === $before, 'Missing eppn makes no account update.');
acrlSignInIdentityCheck($service->findOrCreate(null, 'new-uid', 'new@smith.edu', 'New', 'Person', 'staff') === null, 'Missing fcIdNumber never creates an account from a new uid.');

$resolved = $service->findOrCreate('fc-123', 'fc-user', 'new@smith.edu', 'Updated', 'User', 'faculty');
acrlSignInIdentityCheck(is_array($resolved) && (int)$resolved['user']->id === 3 && !$resolved['isNewUser'], 'fcIdNumber remains the account key when supplied.');
acrlSignInIdentityCheck((string)User::findOne(3)->smith_id === 'fc-123' && (string)User::findOne(3)->role === 'admin', 'The fcIdNumber path preserves account identity and role.');

foreach ([
    ['a@smith.edu', 'SC'], ['a@AMHERST.EDU', 'AC'], ['a@hampshire.edu', 'HC'],
    ['a@mtholyoke.edu', 'MH'], ['a@umass.edu', 'UM'], ['a@smith.edu.example', null], [null, null],
] as $case) {
    acrlSignInIdentityCheck(AcrlInstitutionIdentityService::collegeForEmail($case[0]) === $case[1], 'Email domain maps exactly to its college.');
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL sign-in identity checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}
echo "PASS: ACRL sign-in identity resolution and institution domains\n";
