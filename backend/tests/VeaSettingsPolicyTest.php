<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../controllers/FolioQueryController.php';

use app\controllers\FolioQueryController;
use app\services\SettingsService;
use yii\web\Application;

function veaSettingsAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class VeaSettingsTestFolioDb
{
    public $closes = 0;
    public function close() { $this->closes++; }
}

$directory = sys_get_temp_dir() . '/vea-settings-' . uniqid('', true);
mkdir($directory);
mkdir($directory . '/app-data');
mkdir($directory . '/app-data/data');
$settingsPath = $directory . '/settings.json';
SettingsService::setFilePathForTests($settingsPath);
$app = new Application([
    'id' => 'vea-settings-policy-test',
    'basePath' => $directory . '/app-data',
    'components' => [
        'request' => ['cookieValidationKey' => 'synthetic-test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
    ],
]);
Yii::setAlias('@app', $directory . '/app-data');
foreach (['table_mapping_cache.json', 'column_cache.json', 'subtable_cache.json'] as $cacheFile) {
    veaSettingsAssert(
        strpos(Yii::getAlias('@app/data/' . $cacheFile), $directory . '/app-data/data/') === 0,
        'The settings test must resolve cache files only inside its temporary app directory.'
    );
    file_put_contents($directory . '/app-data/data/' . $cacheFile, '{}');
}
$folioDb = new VeaSettingsTestFolioDb();
Yii::$app->set('folioDb', $folioDb);
Yii::$app->request->setBodyParams([
    'pg_host' => 'synthetic-host',
    'vea_gate_zone_ids' => ['synthetic-zone'],
    'VEA_CLIENT_ID' => 'synthetic-client-id',
    'VEA_CLIENT_SECRET' => 'synthetic-client-secret',
]);
$controller = new FolioQueryController('folio-query', Yii::$app);
$display = $controller->actionSettingsSave();
SettingsService::setFilePathForTests($settingsPath);
$saved = SettingsService::load();

veaSettingsAssert(isset($saved['pg_host']) && $saved['pg_host'] === 'synthetic-host', 'An allowed ordinary setting still saves.');
foreach (['vea_gate_zone_ids', 'VEA_CLIENT_ID', 'VEA_CLIENT_SECRET'] as $key) {
    veaSettingsAssert(!array_key_exists($key, $saved), 'Ordinary settings save must reject ' . $key . '.');
    veaSettingsAssert(!array_key_exists($key, $display), 'Settings display must omit ' . $key . '.');
}
veaSettingsAssert($folioDb->closes === 1, 'Ordinary settings retain their existing connection refresh behavior.');
foreach (['table_mapping_cache.json', 'column_cache.json', 'subtable_cache.json'] as $cacheFile) {
    veaSettingsAssert(!file_exists($directory . '/app-data/data/' . $cacheFile), 'The test clears only its temporary app cache.');
}

unlink($settingsPath);
rmdir($directory . '/app-data/data');
rmdir($directory . '/app-data');
rmdir($directory);
fwrite(STDOUT, "Vea settings policy: passed\n");
