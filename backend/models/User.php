<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use Firebase\JWT\JWT;

/**
 * User model — authenticated users via Shibboleth + JWT.
 *
 * @property int    $id
 * @property string $smith_id        fcIdNumber from Shibboleth
 * @property string $username        uid from Shibboleth
 * @property string $first_name
 * @property string $last_name
 * @property string $affiliation     fcPersonAffiliation
 * @property string $email           eppn from Shibboleth
 * @property string $role            'admin' or 'user'
 * @property int    $is_approved     1 if approved by admin
 * @property int    $receive_notifications  1 to receive new-user emails (admins)
 * @property string $last_login
 * @property string $refresh_token   Current refresh token (for revocation)
 * @property string $created_at
 * @property string $updated_at
 */
class User extends ActiveRecord implements IdentityInterface
{
    /**
     * JWT access token lifetime in seconds (1 hour).
     */
    const ACCESS_TOKEN_TTL = 3600;

    /**
     * JWT refresh token lifetime in seconds (30 days).
     */
    const REFRESH_TOKEN_TTL = 2592000;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'users';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['smith_id', 'username'], 'required'],
            [['smith_id'], 'string', 'max' => 50],
            [['smith_id'], 'unique'],
            [['username'], 'string', 'max' => 100],
            [['first_name', 'last_name'], 'string', 'max' => 100],
            [['affiliation'], 'string', 'max' => 100],
            [['email'], 'string', 'max' => 255],
            [['email'], 'email'],
            [['receive_notifications'], 'integer'],
            [['receive_notifications'], 'default', 'value' => 1],
            [['role'], 'in', 'range' => ['admin', 'user']],
            [['role'], 'default', 'value' => 'user'],
            [['is_approved'], 'integer'],
            [['is_approved'], 'default', 'value' => 0],
            [['refresh_token'], 'string', 'max' => 512],
        ];
    }

    // ─── IdentityInterface ────────────────────────────────────────

    /**
     * @inheritdoc
     */
    public static function findIdentity($id)
    {
        return static::findOne($id);
    }

    /**
     * Find user by JWT access token.
     * Validates the token, checks expiry and approval status.
     *
     * @param string $token JWT access token
     * @param string $type  Token type (unused)
     * @return static|null
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        $secret = getenv('JWT_SECRET');
        if (!$secret) {
            Yii::warning('JWT_SECRET not configured');
            return null;
        }

        try {
            $decoded = JWT::decode($token, $secret, ['HS256']);

            if (!isset($decoded->sub)) {
                return null;
            }

            $user = static::findOne((int) $decoded->sub);
            if (!$user) {
                return null;
            }

            // Must be approved
            if (!$user->is_approved) {
                return null;
            }

            return $user;
        } catch (\Exception $e) {
            Yii::warning('JWT validation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @inheritdoc — not used (JWT-based auth, no sessions)
     */
    public function getAuthKey()
    {
        return null;
    }

    /**
     * @inheritdoc — not used (JWT-based auth, no sessions)
     */
    public function validateAuthKey($authKey)
    {
        return false;
    }

    // ─── JWT helpers ──────────────────────────────────────────────

    /**
     * Generate a JWT access token for this user.
     *
     * @return string JWT token
     */
    public function generateAccessToken()
    {
        $secret = getenv('JWT_SECRET');

        $payload = [
            'iss' => 'folio-report-explorer',
            'sub' => $this->id,
            'iat' => time(),
            'exp' => time() + self::ACCESS_TOKEN_TTL,
            'type' => 'access',
            'user' => [
                'id' => (int) $this->id,
                'username' => $this->username,
                'firstName' => $this->first_name,
                'lastName' => $this->last_name,
                'role' => $this->role,
            ],
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Generate a JWT refresh token and store its hash.
     *
     * @return string JWT refresh token
     */
    public function generateRefreshToken()
    {
        $secret = getenv('JWT_SECRET');

        $payload = [
            'iss' => 'folio-report-explorer',
            'sub' => $this->id,
            'iat' => time(),
            'exp' => time() + self::REFRESH_TOKEN_TTL,
            'type' => 'refresh',
        ];

        $token = JWT::encode($payload, $secret, 'HS256');

        // Store hash so we can revoke
        $this->refresh_token = hash('sha256', $token);
        $this->save(false);

        return $token;
    }

    /**
     * Validate a refresh token against stored hash.
     *
     * @param string $token Raw refresh token
     * @return bool
     */
    public function validateRefreshToken($token)
    {
        if (!$this->refresh_token) {
            return false;
        }

        return hash_equals($this->refresh_token, hash('sha256', $token));
    }

    /**
     * Revoke the current refresh token.
     */
    public function revokeRefreshToken()
    {
        $this->refresh_token = null;
        $this->save(false);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Check if this user is an admin.
     *
     * @return bool
     */
    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    /**
     * Get display name.
     *
     * @return string
     */
    public function getDisplayName()
    {
        if ($this->first_name && $this->last_name) {
            return $this->first_name . ' ' . $this->last_name;
        }
        return $this->username;
    }

    /**
     * Update login timestamp.
     */
    public function touchLogin()
    {
        $this->last_login = date('Y-m-d H:i:s');
        $this->save(false);
    }

    /**
     * Return safe public representation.
     *
     * @return array
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true)
    {
        return [
            'id' => (int) $this->id,
            'smithId' => $this->smith_id,
            'username' => $this->username,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'displayName' => $this->getDisplayName(),
            'affiliation' => $this->affiliation,
            'email' => $this->email,
            'role' => $this->role,
            'isApproved' => (bool) $this->is_approved,
            'receiveNotifications' => (bool) $this->receive_notifications,
            'lastLogin' => $this->last_login,
            'createdAt' => $this->created_at,
        ];
    }

    // ─── Admin notifications ──────────────────────────────────────

    /**
     * Notify subscribed admins that a new user has signed up and needs approval.
     *
     * @param User $newUser The newly created (unapproved) user
     */
    public static function notifyAdminsOfNewUser($newUser)
    {
        $admins = static::find()
            ->where([
                'role' => 'admin',
                'is_approved' => 1,
                'receive_notifications' => 1,
            ])
            ->andWhere(['not', ['email' => null]])
            ->andWhere(['not', ['email' => '']])
            ->all();

        if (empty($admins)) {
            Yii::info('No admins subscribed to new-user notifications');
            return;
        }

        $displayName = $newUser->first_name && $newUser->last_name
            ? $newUser->first_name . ' ' . $newUser->last_name
            : $newUser->username;

        $basePath = rtrim(getenv('APP_BASE_PATH') ?: '', '/');
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $usersUrl = 'https://' . $host . $basePath . '/users';

        $subject = '[FOLIO Report Explorer] New user pending approval: ' . $displayName;
        $body = "A new user has signed up and needs your approval:\n\n"
            . "  Name:        " . $displayName . "\n"
            . "  Username:    " . $newUser->username . "\n"
            . "  Email:       " . ($newUser->email ?: 'N/A') . "\n"
            . "  Affiliation: " . ($newUser->affiliation ?: 'N/A') . "\n"
            . "  Signed up:   " . date('Y-m-d H:i:s') . "\n\n"
            . "Approve or deny this user at:\n" . $usersUrl . "\n";

        $headers = 'From: noreply@' . $host . "\r\n"
            . 'Reply-To: noreply@' . $host . "\r\n"
            . 'X-Mailer: FOLIO-Report-Explorer';

        foreach ($admins as $admin) {
            $sent = @mail($admin->email, $subject, $body, $headers);
            if ($sent) {
                Yii::info('New-user notification sent to ' . $admin->email);
            } else {
                Yii::warning('Failed to send new-user notification to ' . $admin->email);
            }
        }
    }
}
