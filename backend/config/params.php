<?php

use app\services\SettingsService;

/**
 * Application parameters.
 * Values from data/settings.json override environment variables.
 */
return [
    'geminiApiKey' => SettingsService::get('gemini_api_key', 'GEMINI_API_KEY', ''),
    'geminiModel' => SettingsService::get('gemini_model', null, 'gemini-2.5-flash'),
    'nl2sqlIntentMode' => filter_var(
        SettingsService::get('nl2sql_intent_mode', 'NL2SQL_INTENT_MODE', 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'nl2sqlPrimaryMode' => strtolower((string) SettingsService::get(
        'nl2sql_primary_mode',
        'NL2SQL_PRIMARY_MODE',
        'auto'
    )),
    'nl2sqlShadowMode' => filter_var(
        SettingsService::get('nl2sql_shadow_mode', 'NL2SQL_SHADOW_MODE', 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'nl2sqlShadowUsers' => (string) SettingsService::get(
        'nl2sql_shadow_users',
        'NL2SQL_SHADOW_USERS',
        ''
    ),
    'nl2sqlShadowSamplePercent' => (int) SettingsService::get(
        'nl2sql_shadow_sample_percent',
        'NL2SQL_SHADOW_SAMPLE_PERCENT',
        '100'
    ),
    'nl2sqlForceLegacy' => filter_var(
        SettingsService::get('nl2sql_force_legacy', 'NL2SQL_FORCE_LEGACY', 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'schemaPath' => dirname(__DIR__) . '/data/folio_schema.json',
    'derivedPath' => dirname(__DIR__) . '/data/folio_derived_tables.json',
    'maxQueryRows' => 10000,
    'defaultQueryLimit' => 100,
    'queryTimeoutMs' => 1800000,
    'exportRowThreshold' => 10000,
    'exportCostThreshold' => 500000,
    'exportRowLimit' => 500000,
    'exportPreviewRows' => 200,
    'exportFileRetentionDays' => 7,
];
