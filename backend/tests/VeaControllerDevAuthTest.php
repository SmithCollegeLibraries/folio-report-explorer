<?php

defined('YII_ENV') or define('YII_ENV', 'dev');
require_once __DIR__ . '/support/VisualizationControllerTestSupport.php';
require_once __DIR__ . '/../controllers/VeaController.php';
require_once __DIR__ . '/../services/VeaGateZoneService.php';

use app\controllers\VeaController;
use app\services\VeaGateZoneService;

class VeaControllerDevTestService extends VeaGateZoneService
{
    public $calls = 0;
    public function __construct() {}
    public function status(): array
    {
        $this->calls++;
        return ['configured' => false, 'selectedZoneIds' => []];
    }
}

list($fixture) = visualizationControllerFixture();
Yii::$app->user->setIdentity(null);
Yii::$app->request->headers->remove('Authorization');
$service = new VeaControllerDevTestService();
$controller = new VeaController('vea', Yii::$app, [], $service);
list($status, $body) = visualizationInvoke($controller, 'status');
visualizationSame(200, $status, 'Dev mode permits the status route without a bearer token.');
visualizationSame(['configured' => false, 'selectedZoneIds' => []], $body, 'Status reports that Vea is not configured.');
visualizationSame(1, $service->calls, 'The request reaches the service only after the dev bypass.');
fwrite(STDOUT, "Vea controller dev auth: passed\n");
