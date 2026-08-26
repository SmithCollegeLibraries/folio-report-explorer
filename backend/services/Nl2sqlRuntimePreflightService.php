<?php

namespace app\services;

use Yii;

class Nl2sqlRuntimePreflightService
{
    /**
     * Build a runtime parity report from explicit inputs.
     *
     * @param array $params
     * @param array $settings
     * @param array<string,string> $artifactPaths
     * @return array
     */
    public static function buildReport(array $params, array $settings, array $artifactPaths): array
    {
        $effective = [
            'nl2sqlIntentMode' => (bool) ($params['nl2sqlIntentMode'] ?? false),
            'nl2sqlPrimaryMode' => strtolower((string) ($params['nl2sqlPrimaryMode'] ?? 'auto')),
            'nl2sqlShadowMode' => (bool) ($params['nl2sqlShadowMode'] ?? false),
            'nl2sqlShadowUsers' => (string) ($params['nl2sqlShadowUsers'] ?? ''),
            'nl2sqlShadowSamplePercent' => (int) ($params['nl2sqlShadowSamplePercent'] ?? 100),
            'nl2sqlForceLegacy' => (bool) ($params['nl2sqlForceLegacy'] ?? false),
            'nl2sqlTwoLaneEnabled' => !array_key_exists('nl2sqlTwoLaneEnabled', $params)
                || (bool) $params['nl2sqlTwoLaneEnabled'],
        ];

        $persistedRuntimeSettings = array_intersect_key($settings, array_flip([
            'nl2sql_intent_mode',
            'nl2sql_primary_mode',
            'nl2sql_shadow_mode',
            'nl2sql_shadow_users',
            'nl2sql_shadow_sample_percent',
            'nl2sql_force_legacy',
            'nl2sql_two_lane_enabled',
        ]));

        $warnings = [];
        $hasSettingsFile = !empty($settings);
        if (!$hasSettingsFile) {
            $warnings[] = 'backend/data/settings.json is missing or empty, so runtime mode parity depends entirely on environment defaults.';
        }

        if (
            !$effective['nl2sqlIntentMode']
            && $effective['nl2sqlPrimaryMode'] === 'auto'
            && !$effective['nl2sqlForceLegacy']
        ) {
            $warnings[] = 'Effective NL2SQL runtime flags still resolve to legacy defaults; production may silently serve legacy freeform SQL unless intent-mode settings are persisted.';
        }

        if ($effective['nl2sqlForceLegacy']) {
            $warnings[] = 'Emergency rollback is active (`nl2sql_force_legacy=true`), so deterministic intent routing is disabled.';
        }

        if (!$effective['nl2sqlTwoLaneEnabled']) {
            $warnings[] = 'The strict blocker rollback path is active (`nl2sql_two_lane_enabled=false`).';
        }

        $artifacts = [];
        foreach ($artifactPaths as $label => $path) {
            $artifacts[$label] = self::buildArtifactSummary((string) $path);
            if (!($artifacts[$label]['exists'] ?? false)) {
                $warnings[] = 'Required NL2SQL artifact is missing: ' . $label;
            }
        }

        return [
            'status' => empty($warnings) ? 'ok' : 'warning',
            'effective' => $effective,
            'settings' => [
                'hasSettingsFile' => $hasSettingsFile,
                'persistedRuntimeSettings' => $persistedRuntimeSettings,
                'settingsPath' => dirname(__DIR__) . '/data/settings.json',
            ],
            'artifacts' => $artifacts,
            'warnings' => $warnings,
        ];
    }

    /**
     * Build a runtime parity report from the live Yii application context.
     *
     * @return array
     */
    public static function buildFromAppContext(): array
    {
        return self::buildReport(
            Yii::$app->params,
            SettingsService::load(),
            [
                'canonicalQueryGraph' => CanonicalQueryGraphService::getArtifactPath(),
                'queryFamilyContracts' => QueryFamilyContractService::getArtifactPath(),
                'folioSchema' => (string) (Yii::$app->params['schemaPath'] ?? ''),
                'folioDerivedTables' => (string) (Yii::$app->params['derivedPath'] ?? ''),
            ]
        );
    }

    /**
     * @param string $path
     * @return array
     */
    private static function buildArtifactSummary(string $path): array
    {
        $summary = [
            'path' => $path,
            'exists' => file_exists($path),
            'hash' => null,
            'bytes' => null,
            'modifiedAt' => null,
            'artifactVersion' => null,
        ];

        if (!$summary['exists']) {
            return $summary;
        }

        $summary['hash'] = substr(hash_file('sha256', $path), 0, 16);
        $summary['bytes'] = filesize($path) ?: null;
        $modifiedAt = filemtime($path);
        $summary['modifiedAt'] = $modifiedAt ? gmdate('c', $modifiedAt) : null;

        $raw = file_get_contents($path);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded) && is_array($decoded['metadata'] ?? null)) {
            $summary['artifactVersion'] = $decoded['metadata']['artifactVersion']
                ?? $decoded['metadata']['scraped_at']
                ?? $decoded['metadata']['generatedAt']
                ?? null;
        }

        return $summary;
    }
}
