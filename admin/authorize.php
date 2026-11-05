<?php

/**
 * Shibboleth → JWT authorization endpoint.
 *
 * This file must be placed under an Apache directory protected by Shibboleth:
 *
 *   <Location /folio-report-explorer/admin>
 *       AuthType shibboleth
 *       ShibRequireSession On
 *       Require valid-user
 *   </Location>
 *
 * Flow:
 *   1. User navigates here (redirected by frontend when no JWT present)
 *   2. Shibboleth authenticates the user (if not already)
 *   3. This script reads Shibboleth attributes, finds/creates the user
 *   4. Generates JWT access + refresh tokens
 *   5. Redirects back to the frontend with tokens
 *
 * Shibboleth attributes expected:
 *   - fcIdNumber   (institution ID)
 *   - uid          (username)
 *   - givenName    (first name)
 *   - sn           (surname)
 *   - fcPersonAffiliation (faculty, staff, student, etc.)
 *   - eppn         (email / eduPersonPrincipalName)
 */

// ── Bootstrap Yii2 for DB access ──────────────────────────────────

$envFile = dirname(__DIR__) . '/backend/config/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

defined('YII_DEBUG') or define('YII_DEBUG', getenv('YII_DEBUG') === 'true');
defined('YII_ENV') or define('YII_ENV', getenv('YII_ENV') ?: 'production');

require dirname(__DIR__) . '/backend/vendor/autoload.php';
require dirname(__DIR__) . '/backend/vendor/yiisoft/yii2/Yii.php';

$config = require dirname(__DIR__) . '/backend/config/web.php';

// Create application (don't run it — we just need DB access)
new \yii\web\Application($config);

use app\models\User;
use app\services\AcrlInviteMailer;
use app\services\AcrlInviteService;
use app\services\AcrlQuestionAssignmentRepository;
use app\services\AcrlSectionAssignmentRepository;

// ── Read Shibboleth attributes ────────────────────────────────────
// Attributes may come as environment variables or HTTP headers depending
// on Apache/Shibboleth configuration. Check both.

$smithId     = getShibAttr('fcIdNumber');
$uid         = getShibAttr('uid');
$username    = $uid;
$firstName   = getShibAttr('givenName');
$lastName    = getShibAttr('sn');
$affiliation = getShibAttr('fcPersonAffiliation');
$email       = getShibAttr('eppn');

// Also check REMOTE_USER as fallback for username
if (!$username) {
    $username = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : null;
}

// Must have at least a Smith ID or username
if (!$smithId && !$username) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body>';
    echo '<h1>Authentication Failed</h1>';
    echo '<p>Could not retrieve your identity from Shibboleth. ';
    echo 'Please contact the library systems team.</p>';
    echo '</body></html>';
    exit;
}

// Use username as fallback smith_id if not available
if (!$smithId) {
    $smithId = $username;
}

// ── Find or create user ───────────────────────────────────────────

$user = User::findOne(['smith_id' => $smithId]);

$isNewUser = false;
if (!$user) {
    $isNewUser = true;
    $user = new User();
    $user->smith_id = $smithId;
    $user->username = $username ?: $smithId;
    $user->first_name = $firstName;
    $user->last_name = $lastName;
    $user->affiliation = $affiliation;
    $user->email = $email;

    // First user ever → auto-approve as admin
    $existingCount = User::find()->count();
    if ($existingCount == 0) {
        $user->role = 'admin';
        $user->is_approved = 1;
    }

    if (!$user->save()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body>';
        echo '<h1>Account Creation Failed</h1>';
        echo '<p>Could not create your account. Please contact the library systems team.</p>';
        echo '<pre>' . htmlspecialchars(json_encode($user->errors)) . '</pre>';
        echo '</body></html>';
        exit;
    }
} else {
    // Update attributes that may have changed
    $changed = false;
    if ($firstName && $user->first_name !== $firstName) {
        $user->first_name = $firstName;
        $changed = true;
    }
    if ($lastName && $user->last_name !== $lastName) {
        $user->last_name = $lastName;
        $changed = true;
    }
    if ($affiliation && $user->affiliation !== $affiliation) {
        $user->affiliation = $affiliation;
        $changed = true;
    }
    if ($email && $user->email !== $email) {
        $user->email = $email;
        $changed = true;
    }
    if ($changed) {
        $user->save(false);
    }
}

// ── Apply invite approval before notifying admins or issuing tokens ─
$signIn = (new \app\services\AcrlInviteSignInOrchestrator())->run(
    $user,
    $isNewUser,
    $email,
    $uid,
    function ($signInUser, $eppn, $signInUid) {
        $inviteDb = \Yii::$app->db;
        $inviteService = new AcrlInviteService(
            $inviteDb,
            new AcrlSectionAssignmentRepository($inviteDb),
            new AcrlQuestionAssignmentRepository($inviteDb),
            new AcrlInviteMailer()
        );
        return $inviteService->acceptOnSignIn($signInUser, $eppn, $signInUid);
    },
    function ($signInUser) {
        return User::findOne((int)$signInUser->id);
    },
    function ($signInUser) {
        $user = $signInUser;
        User::notifyAdminsOfNewUser($user);
    },
    function ($signInUser) {
        $signInUser->touchLogin();
        return [
            'accessToken' => $signInUser->generateAccessToken(),
            'refreshToken' => $signInUser->generateRefreshToken(),
        ];
    },
    function ($code) {
        \Yii::warning($code, 'acrl.invite.accept');
    }
);

// ── Build redirect URL ────────────────────────────────────────────

$basePath = rtrim(getenv('APP_BASE_PATH') ?: '', '/');

if ($signIn['redirect'] === '/auth/pending') {
    $pendingUrl = $basePath . '/auth/pending';
    header('Location: ' . $pendingUrl);
    exit;
}
$accessToken = $signIn['tokens']['accessToken'];
$refreshToken = $signIn['tokens']['refreshToken'];

// ── Redirect to frontend callback ─────────────────────────────────
// Use fragment (#) so tokens are never sent to the server in a URL

$callbackUrl = $basePath . '/auth/callback#access_token=' . $accessToken
    . '&refresh_token=' . $refreshToken;

header('Location: ' . $callbackUrl);
exit;

// ── Helpers ───────────────────────────────────────────────────────

/**
 * Read a Shibboleth attribute from $_SERVER.
 * Checks both the raw name and the HTTP_ prefixed version.
 *
 * @param string $name Attribute name (e.g. 'fcIdNumber')
 * @return string|null
 */
function getShibAttr($name)
{
    // Direct environment variable (mod_shib with ShibUseEnvironment On)
    if (!empty($_SERVER[$name])) {
        return $_SERVER[$name];
    }

    // HTTP header (proxy or ShibUseHeaders On)
    $httpName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$httpName])) {
        return $_SERVER[$httpName];
    }

    return null;
}
