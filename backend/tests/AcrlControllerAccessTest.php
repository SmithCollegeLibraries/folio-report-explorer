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
        public static $approvedExists = false;
        public static $adminExists = false;

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
        private $conditions = [];
        public function where(array $conditions) { $this->conditions = $conditions; return $this; }
        public function exists()
        {
            if (isset($this->conditions['role'])) return \app\models\User::$adminExists || \app\models\User::$queryExists;
            return \app\models\User::$approvedExists || \app\models\User::$queryExists;
        }
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

    $newActions = [
        'entry' => function () use ($controller) { return $controller->actionEntry(); },
        'save-answer' => function () use ($controller) { return $controller->actionSaveAnswer(2025); },
        'section-status' => function () use ($controller) { return $controller->actionSectionStatus(2025, 'Test%20Section'); },
        'threshold' => function () use ($controller) { return $controller->actionThreshold(); },
        'save-threshold' => function () use ($controller) { return $controller->actionSaveThreshold(); },
        'import-preview' => function () use ($controller) { return $controller->actionImportPreview(2025); },
        'import-confirm' => function () use ($controller) { return $controller->actionImportConfirm(2025); },
    ];
    foreach ($newActions as $name => $invoke) {
        acrlAccessAppUser(true, null);
        $response = $invoke();
        acrlAccessAssert(Yii::$app->response->statusCode === 401, $name . ' must return 401 to a guest.');
        acrlAccessAssert(($response['error'] ?? null) === 'Unauthorized', $name . ' must use the stable unauthorized response.');
    }

    acrlAccessAppUser(false, new \app\models\User());
    \app\models\User::$approvedExists = true;
    \app\models\User::$adminExists = false;
    $denied = $controller->actionThreshold();
    acrlAccessAssert(Yii::$app->response->statusCode === 403 && ($denied['error'] ?? null) === 'Forbidden', 'A non-admin must receive 403 on threshold routes.');

    foreach ($actions as $name => $invoke) {
        acrlAccessAppUser(true, null);
        $response = $invoke();
        acrlAccessAssert(Yii::$app->response->statusCode === 403, $name . ' must return 403 for a guest in dev/test mode.');
        acrlAccessAssert(($response['error'] ?? null) === 'Forbidden', $name . ' must deny guests with the stable response.');
    }

    acrlAccessAppUser(false, new \app\models\User());
    $behaviors = $controller->behaviors();
    $adminActions = $behaviors['access']['rules'][1]['actions'] ?? [];
    foreach (array_keys($actions) as $name) {
        acrlAccessAssert(in_array($name, $adminActions, true), $name . ' must be covered by the framework admin access rule.');
    }
    $userActions = $behaviors['access']['rules'][0]['actions'] ?? [];
    acrlAccessAssert(in_array('entry', $userActions, true) && in_array('save-answer', $userActions, true) && in_array('section-status', $userActions, true), 'Entry and section-status routes must allow signed-in users.');
    foreach (['threshold', 'save-threshold', 'import-preview', 'import-confirm'] as $name) {
        acrlAccessAssert(in_array($name, $adminActions, true), $name . ' must use the persisted-admin rule.');
    }

    $routes = (string)file_get_contents(__DIR__ . '/../config/web.php');
    foreach ([
        "'GET acrl/years'",
        "'POST acrl/worksheets'",
        "'GET acrl/entry'",
        "'POST acrl/years/<year:\\\\d+>/answers'",
        "'POST acrl/years/<year:\\\\d+>/sections/<section:[^/]+>/status'",
        "'GET acrl/threshold'",
        "'POST acrl/years/<year:\\\\d+>/import/preview'",
        "'POST acrl/years/<year:\\\\d+>/import/confirm'",
    ] as $route) {
        acrlAccessAssert(strpos($routes, $route) !== false, 'Missing ACRL route ' . $route . '.');
    }
    acrlAccessAssert(strpos($routes, "'GET acrl/years/<year:") !== false && strpos($routes, "/review'") !== false, 'Missing ACRL review route.');
    acrlAccessAssert(strpos($routes, "'POST acrl/years/<year:") !== false && strpos($routes, "/links'") !== false, 'Missing ACRL decision route.');
    acrlAccessAssert(strpos($routes, "'POST acrl/years/<year:") !== false && strpos($routes, "/publish'") !== false, 'Missing ACRL publish route.');

    echo "PASS: ACRL editor/admin access rules and route registration\n";
}
