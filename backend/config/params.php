<?php

use app\services\SettingsService;

/**
 * Application parameters.
 * Values from data/settings.json override environment variables.
 */
return [
    'geminiApiKey' => SettingsService::get('gemini_api_key', 'GEMINI_API_KEY', ''),
    'geminiModel' => SettingsService::get('gemini_model', null, 'gemini-2.5-flash'),
    'schemaPath' => dirname(__DIR__) . '/data/folio_schema.json',
    'derivedPath' => dirname(__DIR__) . '/data/folio_derived_tables.json',
    'maxQueryRows' => 10000,
    'defaultQueryLimit' => 100,
    'queryTimeoutMs' => 300000,
];
