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
    require_once __DIR__ . '/../controllers/AcrlAssignmentController.php';

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
    $assignmentController = new \app\controllers\AcrlAssignmentController('acrl-assignment', Yii::$app);
    $corsMethods = $controller->behaviors()['corsFilter']['cors']['Access-Control-Request-Method'] ?? [];
    foreach (['PUT', 'DELETE'] as $method) {
        acrlAccessAssert(in_array($method, $corsMethods, true), 'ACRL note management must support browser CORS ' . $method . ' requests.');
    }
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
        'accept-suggestions' => function () use ($controller) { return $controller->actionAcceptSuggestions(2025, 'Test%20Section'); },
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

    $assignmentActions = [
        'search-users' => function () use ($assignmentController) { return $assignmentController->actionSearchUsers(); },
        'list-assignments' => function () use ($assignmentController) { return $assignmentController->actionListAssignments(2026); },
        'save-assignments' => function () use ($assignmentController) { return $assignmentController->actionSaveAssignments(2026, 'Test%20Section'); },
        'copy-from-previous' => function () use ($assignmentController) { return $assignmentController->actionCopyFromPrevious(2026); },
    ];
    foreach ($assignmentActions as $name => $invoke) {
        acrlAccessAppUser(true, null);
        $response = $invoke();
        acrlAccessAssert(Yii::$app->response->statusCode === 403, $name . ' must return 403 for a guest.');
        acrlAccessAssert(($response['error'] ?? null) === 'Forbidden', $name . ' must use the stable forbidden response.');
        acrlAccessAppUser(false, new \app\models\User());
        \app\models\User::$approvedExists = true;
        \app\models\User::$adminExists = false;
        $response = $invoke();
        acrlAccessAssert(Yii::$app->response->statusCode === 403, $name . ' must return 403 for an approved non-admin.');
        acrlAccessAssert(($response['error'] ?? null) === 'Forbidden', $name . ' must deny approved non-admins.');
    }
    $assignmentAccess = $assignmentController->behaviors()['access']['rules'][0] ?? [];
    acrlAccessAssert(is_callable($assignmentAccess['matchCallback'] ?? null), 'Assignment routes must use a persisted-admin access rule.');
    acrlAccessAppUser(false, new \app\models\User());
    \app\models\User::$approvedExists = true;
    \app\models\User::$adminExists = true;
    acrlAccessAssert(call_user_func($assignmentAccess['matchCallback'], null, null), 'An approved administrator must match the assignment-route access rule.');
    \app\models\User::$adminExists = false;
    acrlAccessAssert(!call_user_func($assignmentAccess['matchCallback'], null, null), 'An approved non-admin must not match the assignment-route access rule.');

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
    acrlAccessAssert(in_array('entry', $userActions, true) && in_array('save-answer', $userActions, true) && in_array('section-status', $userActions, true) && in_array('accept-suggestions', $userActions, true), 'Entry, section-status, and suggestion routes must allow signed-in users.');
    foreach (['threshold', 'save-threshold', 'import-preview', 'import-confirm'] as $name) {
        acrlAccessAssert(in_array($name, $adminActions, true), $name . ' must use the persisted-admin rule.');
    }

    $detailsUserActions = ['question-details', 'add-question-note'];
    $detailsAdminActions = ['save-instructions', 'notes-import-preview', 'notes-import-confirm', 'edit-question-note', 'delete-question-note'];
    foreach ($detailsUserActions as $name) {
        acrlAccessAssert(in_array($name, $userActions, true), $name . ' must allow any approved signed-in user.');
    }
    foreach ($detailsAdminActions as $name) {
        acrlAccessAssert(in_array($name, $adminActions, true), $name . ' must use the persisted-admin rule.');
    }
    $detailsMethods = [
        'question-details' => function () use ($controller) { return $controller->actionQuestionDetails(2026); },
        'add-question-note' => function () use ($controller) { return $controller->actionAddQuestionNote(2026); },
        'save-instructions' => function () use ($controller) { return $controller->actionSaveInstructions(2026); },
        'notes-import-preview' => function () use ($controller) { return $controller->actionNotesImportPreview(2026); },
        'notes-import-confirm' => function () use ($controller) { return $controller->actionNotesImportConfirm(2026); },
        'edit-question-note' => function () use ($controller) { return $controller->actionEditQuestionNote(1); },
        'delete-question-note' => function () use ($controller) { return $controller->actionDeleteQuestionNote(1); },
    ];
    foreach ($detailsMethods as $name => $invoke) {
        $methodName = 'action' . str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
        acrlAccessAssert(method_exists($controller, $methodName), $name . ' action must be registered.');
        acrlAccessAppUser(true, null);
        $guestResponse = $invoke();
        acrlAccessAssert(Yii::$app->response->statusCode === 401 && ($guestResponse['error'] ?? null) === 'Unauthorized', $name . ' must reject guests.');
        if (in_array($name, $detailsAdminActions, true)) {
            acrlAccessAppUser(false, new \app\models\User());
            \app\models\User::$approvedExists = true;
            \app\models\User::$adminExists = false;
            \app\models\User::$queryExists = false;
            $regularUserResponse = $invoke();
            acrlAccessAssert(Yii::$app->response->statusCode === 403 && ($regularUserResponse['error'] ?? null) === 'Forbidden', $name . ' must reject non-admin users.');
        }
    }

    $routes = (string)file_get_contents(__DIR__ . '/../config/web.php');
    foreach ([
        "'GET acrl/years'",
        "'POST acrl/worksheets'",
        "'GET acrl/entry'",
        "'POST acrl/years/<year:\\\\d+>/answers'",
        "'POST acrl/years/<year:\\\\d+>/sections/<section:[^/]+>/status'",
        "'POST acrl/years/<year:\\\\d+>/sections/<section:[^/]+>/suggestions/accept'",
        "'GET acrl/threshold'",
        "'POST acrl/years/<year:\\\\d+>/import/preview'",
        "'POST acrl/years/<year:\\\\d+>/import/confirm'",
        "'POST acrl/years/<year:\\\\d+>/instructions'",
        "'GET acrl/years/<year:\\\\d+>/question-details'",
        "'POST acrl/years/<year:\\\\d+>/question-notes'",
        "'POST acrl/years/<year:\\\\d+>/notes/import/preview'",
        "'POST acrl/years/<year:\\\\d+>/notes/import/confirm'",
        "'PUT acrl/question-notes/<id:\\\\d+>'",
        "'DELETE acrl/question-notes/<id:\\\\d+>'",
    ] as $route) {
        acrlAccessAssert(strpos($routes, $route) !== false, 'Missing ACRL route ' . $route . '.');
    }
    acrlAccessAssert(strpos($routes, "'GET acrl/years/<year:") !== false && strpos($routes, "/review'") !== false, 'Missing ACRL review route.');
    acrlAccessAssert(strpos($routes, "'POST acrl/years/<year:") !== false && strpos($routes, "/links'") !== false, 'Missing ACRL decision route.');
    acrlAccessAssert(strpos($routes, "'POST acrl/years/<year:") !== false && strpos($routes, "/publish'") !== false, 'Missing ACRL publish route.');
    foreach ([
        "'GET acrl/approved-users'",
        "'GET acrl/years/<year:\\\\d+>/assignments'",
        "'POST acrl/years/<year:\\\\d+>/sections/<section:[^/]+>/assignments'",
        "'POST acrl/years/<year:\\\\d+>/assignments/copy-previous'",
    ] as $route) {
        acrlAccessAssert(strpos($routes, $route) !== false, 'Missing ACRL assignment route ' . $route . '.');
    }

    echo "PASS: ACRL editor/admin access rules and route registration\n";
}
