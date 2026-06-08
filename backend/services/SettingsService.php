<?php

namespace app\services;

/**
 * SettingsService — read/write dev settings from a JSON file.
 *
 * Settings are stored in data/settings.json and override
 * environment variables for PG and AI provider credentials/models.
 */
class SettingsService
{
    private const OPENAI_API_BASE = 'https://api.openai.com/v1';

    private static $cache = null;

    /**
     * @return string Path to the settings JSON file.
     */
    private static function filePath()
    {
        return dirname(__DIR__) . '/data/settings.json';
    }

    /**
     * Load all settings (returns empty array if file missing).
     */
    public static function load()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = self::filePath();
        if (!file_exists($path)) {
            self::$cache = [];
            return [];
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }

        self::$cache = $data;
        return $data;
    }

    /**
     * Save settings to disk.
     */
    public static function save(array $settings)
    {
        $path = self::filePath();
        $existing = self::load();

        // Merge new values over existing, keeping anything not overwritten
        $merged = array_merge($existing, $settings);

        // Remove empty strings — treat as "unset"
        foreach ($merged as $k => $v) {
            if ($v === '' || $v === null) {
                unset($merged[$k]);
            }
        }

        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::$cache = $merged;

        return $merged;
    }

    /**
     * Get a single setting, falling back to an env var.
     */
    public static function get($key, $envVar = null, $default = '')
    {
        $settings = self::load();
        if (isset($settings[$key]) && $settings[$key] !== '') {
            return $settings[$key];
        }
        if ($envVar) {
            $val = getenv($envVar);
            if ($val !== false && $val !== '') {
                return $val;
            }
        }
        return $default;
    }

    /**
     * Return the masked version for display (hide passwords).
     */
    public static function forDisplay()
    {
        $display = [
            'pg_host' => self::get('pg_host', 'FOLIO_PG_HOST'),
            'pg_port' => self::get('pg_port', 'FOLIO_PG_PORT', '5432'),
            'pg_db'   => self::get('pg_db', 'FOLIO_PG_DB', 'ldplite'),
            'pg_user' => self::get('pg_user', 'FOLIO_PG_USER'),
            'pg_pass' => self::get('pg_pass', 'FOLIO_PG_PASS') ? '••••••••' : '',
            'pg_sslmode' => self::get('pg_sslmode', 'FOLIO_PG_SSLMODE', 'require'),
            'ai_provider' => strtolower((string) self::get('ai_provider', 'AI_PROVIDER', 'openai')),
            'gemini_api_key' => self::get('gemini_api_key', 'GEMINI_API_KEY') ? '••••••••' : '',
            'gemini_model' => self::get('gemini_model', null, 'gemini-2.5-flash'),
            'openai_api_key' => self::get('openai_api_key', 'OPENAI_API_KEY') ? '••••••••' : '',
            'openai_model' => self::get('openai_model', 'OPENAI_MODEL', 'gpt-5.4'),
            'nl2sql_intent_mode' => filter_var(
                self::get('nl2sql_intent_mode', 'NL2SQL_INTENT_MODE', 'true'),
                FILTER_VALIDATE_BOOLEAN
            ),
            'nl2sql_primary_mode' => strtolower((string) self::get(
                'nl2sql_primary_mode',
                'NL2SQL_PRIMARY_MODE',
                'intent'
            )),
            'nl2sql_shadow_mode' => filter_var(
                self::get('nl2sql_shadow_mode', 'NL2SQL_SHADOW_MODE', 'false'),
                FILTER_VALIDATE_BOOLEAN
            ),
            'nl2sql_shadow_users' => (string) self::get(
                'nl2sql_shadow_users',
                'NL2SQL_SHADOW_USERS',
                ''
            ),
            'nl2sql_shadow_sample_percent' => (int) self::get(
                'nl2sql_shadow_sample_percent',
                'NL2SQL_SHADOW_SAMPLE_PERCENT',
                '100'
            ),
            'nl2sql_force_legacy' => filter_var(
                self::get('nl2sql_force_legacy', 'NL2SQL_FORCE_LEGACY', 'false'),
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
        return $display;
    }

    /**
     * Test a Postgres connection with the given (or saved) settings.
     */
    public static function testPostgres($host = null, $port = null, $db = null, $user = null, $pass = null, $sslmode = null)
    {
        $host = $host ?: self::get('pg_host', 'FOLIO_PG_HOST');
        $port = $port ?: self::get('pg_port', 'FOLIO_PG_PORT', '5432');
        $db   = $db   ?: self::get('pg_db', 'FOLIO_PG_DB', 'ldplite');
        $user = $user ?: self::get('pg_user', 'FOLIO_PG_USER');
        $pass = $pass ?: self::get('pg_pass', 'FOLIO_PG_PASS');
        $sslmode = $sslmode ?: self::get('pg_sslmode', 'FOLIO_PG_SSLMODE', 'require');

        if (!$host || !$user) {
            return ['connected' => false, 'error' => 'Host and user are required'];
        }

        try {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $db, $sslmode);
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $row = $pdo->query("SELECT version()")->fetch(\PDO::FETCH_NUM);
            $pdo = null;
            return ['connected' => true, 'version' => $row[0]];
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test a Gemini API key.
     */
    public static function testGemini($apiKey = null, $model = null)
    {
        $apiKey = $apiKey ?: self::get('gemini_api_key', 'GEMINI_API_KEY');
        $model  = $model  ?: self::get('gemini_model', null, 'gemini-2.5-flash');

        if (!$apiKey) {
            return ['connected' => false, 'error' => 'API key is required'];
        }

        try {
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s?key=%s',
                $model,
                $apiKey
            );
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'connected' => true,
                    'model' => $data['name'] ?? $model,
                    'displayName' => $data['displayName'] ?? $model,
                ];
            } else {
                $data = json_decode($response, true);
                return [
                    'connected' => false,
                    'error' => $data['error']['message'] ?? "HTTP $httpCode",
                ];
            }
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test an OpenAI API key.
     */
    public static function testOpenAi($apiKey = null, $model = null)
    {
        $apiKey = $apiKey ?: self::get('openai_api_key', 'OPENAI_API_KEY');
        $model  = $model  ?: self::get('openai_model', 'OPENAI_MODEL', 'gpt-5.4');

        if (!$apiKey) {
            return ['connected' => false, 'error' => 'API key is required'];
        }

        try {
            $url = sprintf('%s/models/%s', self::OPENAI_API_BASE, rawurlencode((string) $model));
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                return ['connected' => false, 'error' => $curlErr ?: 'Failed to call OpenAI API'];
            }

            $data = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'connected' => true,
                    'model' => $data['id'] ?? $model,
                ];
            }

            return [
                'connected' => false,
                'error' => $data['error']['message'] ?? "HTTP $httpCode",
            ];
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }
}
