<?php

namespace yii\web {
    class Controller
    {
        public function __construct($id = null, $module = null, $config = []) {}
        public function behaviors() { return []; }
    }

    class Response
    {
        public const FORMAT_JSON = 'json';
    }
}

namespace app\models {
    class User
    {
        public static $queryExists = false;

        public static function find()
        {
            return new \AcrlControllerAccessUserQuery();
        }

        public function getId() { return 1; }
    }
}

namespace {
    if (!defined('YII_ENV')) {
        define('YII_ENV', 'test');
    }

    class Yii
    {
        public static $app;
    }

    final class AcrlControllerAccessUserQuery
    {
        public function where(array $conditions) { return $this; }
        public function exists() { return \app\models\User::$queryExists; }
    }

    require_once __DIR__ . '/../controllers/traits/PersistedAdminAccessTrait.php';
    require_once __DIR__ . '/../controllers/AcrlController.php';

    function acrlAccessAssert($condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function acrlAccessAppUser($guest, $identity): void
    {
        Yii::$app = (object)[
            'user' => (object)['isGuest' => $guest, 'identity' => $identity],
            'response' => (object)['statusCode' => 200, 'data' => null, 'format' => null],
        ];
    }

    $controller = new \app\controllers\AcrlController('acrl', Yii::$app);
    $actions = [
        'years' => function () use ($controller) { return $controller->actionYears(); },
        'upload' => function () use ($controller) { return $controller->actionUpload(); },
        'review' => function () use ($controller) { return $controller->actionReview(2026); },
        'save-links' => function () use ($controller) { return $controller->actionSaveLinks(2026); },
        'publish' => function () use ($controller) { return $controller->actionPublish(2026); },
    ];

    foreach ($actions as $name => $invoke) {
        acrlAccessAppUser(false, new \app\models\User());
        \app\models\User::$queryExists = false;
        $response = $invoke();
        acrlAccessAssert(Yii::$app->response->statusCode === 403, $name . ' must return 403 for a non-administrator.');
        acrlAccessAssert(($response['error'] ?? null) === 'Forbidden', $name . ' must keep the stable forbidden response.');
    }

    foreach ($actions as $name => $invoke) {
        acrlAccessAppUser(true, null);
        $response = $invoke();
        acrlAccessAssert(Yii::$app->response->statusCode === 403, $name . ' must return 403 for a guest in dev/test mode.');
        acrlAccessAssert(($response['error'] ?? null) === 'Forbidden', $name . ' must deny guests with the stable response.');
    }

    acrlAccessAppUser(false, new \app\models\User());
    $behaviors = $controller->behaviors();
    $adminActions = $behaviors['access']['rules'][0]['actions'] ?? [];
    foreach (array_keys($actions) as $name) {
        acrlAccessAssert(in_array($name, $adminActions, true), $name . ' must be covered by the framework admin access rule.');
    }

    $routes = (string)file_get_contents(__DIR__ . '/../config/web.php');
    foreach ([
        "'GET acrl/years'",
        "'POST acrl/worksheets'",
    ] as $route) {
        acrlAccessAssert(strpos($routes, $route) !== false, 'Missing ACRL route ' . $route . '.');
    }
    acrlAccessAssert(strpos($routes, "'GET acrl/years/<year:") !== false && strpos($routes, "/review'") !== false, 'Missing ACRL review route.');
    acrlAccessAssert(strpos($routes, "'POST acrl/years/<year:") !== false && strpos($routes, "/links'") !== false, 'Missing ACRL decision route.');
    acrlAccessAssert(strpos($routes, "'POST acrl/years/<year:") !== false && strpos($routes, "/publish'") !== false, 'Missing ACRL publish route.');

    echo "PASS: every ACRL route denies non-admins and guests\n";
}
