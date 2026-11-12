<?php

namespace app\services;

require_once __DIR__ . '/CanonicalSeedJsonService.php';

/** Recognizes both canonical report rows seeded by migration 070. */
final class GiftReportsMigrationRecognitionService
{
    public static function reportsAppearComplete($db): bool
    {
        foreach (self::definitions() as $slug => $expected) {
            $definition = $db->createCommand(
                'SELECT slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, composite_config, default_limit, is_active, created_by'
                    . ' FROM report_templates WHERE slug = :slug LIMIT 1',
                [':slug' => $slug]
            )->queryOne();
            if (!is_array($definition) || !self::isCanonicalDefinition($definition, $expected)) {
                return false;
            }
        }
        return true;
    }

    private static function isCanonicalDefinition(array $definition, array $expected): bool
    {
        foreach (['slug', 'name', 'category', 'data_source', 'created_by'] as $field) {
            if (($definition[$field] ?? null) !== $expected[$field]) {
                return false;
            }
        }
        foreach (['description', 'help_text', 'sql_template'] as $field) {
            if (!is_string($definition[$field] ?? null)
                || !hash_equals($expected[$field . '_sha256'], hash('sha256', $definition[$field]))) {
                return false;
            }
        }
        if ((int) ($definition['default_limit'] ?? 0) !== $expected['default_limit']
            || (int) ($definition['is_active'] ?? 0) !== 1
            || !array_key_exists('execution_config', $definition) || $definition['execution_config'] !== null
            || !array_key_exists('composite_config', $definition) || $definition['composite_config'] !== null) {
            return false;
        }

        return CanonicalSeedJsonService::normalize($definition['parameters'] ?? null)
            === CanonicalSeedJsonService::normalize($expected['parameters']);
    }

    private static function definitions(): array
    {
        return [
            'gift-items-one-time' => [
                'slug' => 'gift-items-one-time',
                'name' => 'Gift items (one-time)',
                'category' => 'acquisitions',
                'data_source' => 'folio',
                'created_by' => 'manual',
                'default_limit' => 100000,
                'description_sha256' => '2a1a81e1b96180479c3e2a25cc5b4c3e25198f42607b6f31f698e20f045f24af',
                'help_text_sha256' => '864e0e3207c2833b60c96c2f2b47fcc528df68750e785f2c28eca67ce8088f21',
                'sql_template_sha256' => '1ee5599974b752bdc94c8879b2dd96e1020705dc4192631bd53f6d5a98d5f23d',
                'parameters' => [
                    ['name' => 'startDate', 'type' => 'date', 'label' => 'Start date', 'required' => true, 'default' => '$previous_fiscal_year_start'],
                    ['name' => 'endDate', 'type' => 'date', 'label' => 'End date', 'required' => true, 'default' => '$previous_fiscal_year_end'],
                ],
            ],
            'gift-ongoing' => [
                'slug' => 'gift-ongoing',
                'name' => 'Ongoing gifts',
                'category' => 'acquisitions',
                'data_source' => 'folio',
                'created_by' => 'manual',
                'default_limit' => 100000,
                'description_sha256' => '13267963a45f32cef0ee4b927a891436663a75c7ecb8b706262c41a9069fbc39',
                'help_text_sha256' => 'c6f2b3fd420c8961d0574371f4516fa5602589b4e78f53ff33259afc99284ba6',
                'sql_template_sha256' => '603b9811b42dbd4634815d765fd69a32466ebf9d4da8036e9fb9ce50c33d6f13',
                'parameters' => [
                    ['name' => 'startDate', 'type' => 'date', 'label' => 'Start date', 'required' => true, 'default' => '$previous_fiscal_year_start'],
                    ['name' => 'endDate', 'type' => 'date', 'label' => 'End date', 'required' => true, 'default' => '$previous_fiscal_year_end'],
                ],
            ],
        ];
    }
}
