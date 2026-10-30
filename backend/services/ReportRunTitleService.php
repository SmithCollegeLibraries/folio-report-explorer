<?php

namespace app\services;

/** Builds a display title for a newly queued fixed-report run. */
final class ReportRunTitleService
{
    private $optionSource;
    private $nameNormalizer;

    /**
     * @param object $optionSource Provides getOptions() and getExpenseClassOptions().
     * @param callable $nameNormalizer The existing query-job name normalizer.
     */
    public function __construct($optionSource, $nameNormalizer)
    {
        $this->optionSource = $optionSource;
        $this->nameNormalizer = $nameNormalizer;
    }

    /**
     * @param object $report
     * @param array $params Submitted report parameters.
     * @param bool $identifierExport
     * @return string
     */
    public function buildTitle($report, array $params, $identifierExport = false)
    {
        $definitions = $report->getDecodedParameters();
        if (!is_array($definitions)) {
            $definitions = [];
        }

        $values = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition) || empty($definition['name'])) {
                continue;
            }
            $name = (string) $definition['name'];
            $values[$name] = $this->resolveValue($definition, $params);
        }

        $expenseClassOptions = [];
        if ($this->isExpenseClassReport($report)
            && ($this->hasValue($values['acqUnit'] ?? null) || $this->hasValue($values['funds'] ?? null))) {
            try {
                $loadedOptions = $this->optionSource->getExpenseClassOptions($report, $values);
                $expenseClassOptions = is_array($loadedOptions) ? $loadedOptions : [];
            } catch (\Throwable $exception) {
                $expenseClassOptions = [];
            }
        }

        $settings = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition) || empty($definition['name'])) {
                continue;
            }

            $name = (string) $definition['name'];
            $value = $values[$name] ?? null;
            if (!$this->hasValue($value)) {
                continue;
            }

            $options = [];
            if (!$this->isExpenseClassPicker($report, $name)
                && in_array(($definition['type'] ?? ''), ['select', 'multiselect'], true)
                && !empty($definition['options_sql'])) {
                try {
                    $loadedOptions = $this->optionSource->getOptions($report, $definition);
                    $options = is_array($loadedOptions) ? $loadedOptions : [];
                } catch (\Throwable $exception) {
                    $options = [];
                }
            }

            $settings[] = $this->formatSetting($report, $definition, $value, $options, $expenseClassOptions);
        }

        $title = trim((string) ($report->name ?? ''));
        if (!empty($settings)) {
            $settingsTitle = implode(', ', $settings);
            $title = $title === '' ? $settingsTitle : $title . ' — ' . $settingsTitle;
        }
        if ($identifierExport) {
            $title .= ' (identifiers)';
        }

        $normalized = call_user_func($this->nameNormalizer, $title);
        return is_string($normalized) ? $normalized : (string) $normalized;
    }

    /**
     * Add the template id without removing metadata owned by another report path.
     *
     * @param array|null $metadata
     * @param int $reportTemplateId
     * @return array
     */
    public function mergeReportTemplateId($metadata, $reportTemplateId)
    {
        $merged = is_array($metadata) ? $metadata : [];
        $merged['reportTemplateId'] = (int) $reportTemplateId;
        return $merged;
    }

    private function resolveValue(array $definition, array $params)
    {
        $name = (string) $definition['name'];
        $value = array_key_exists($name, $params) ? $params[$name] : null;
        if ($this->hasValue($value) || !array_key_exists('default', $definition)) {
            return $value;
        }

        $default = $definition['default'];
        if (is_string($default)) {
            $default = \app\models\ReportTemplate::resolveDefaultMacro($default);
        }
        return $default;
    }

    private function formatSetting($report, array $definition, $value, array $options, array $expenseClassOptions)
    {
        $name = (string) $definition['name'];
        if (($definition['type'] ?? '') === 'multiselect') {
            $values = $this->multiSelectValues($value);
            if (count($values) > 3) {
                return count($values) . ' selected';
            }

            $labels = [];
            foreach ($values as $selectedValue) {
                $labels[] = $this->labelForValue($report, $name, $selectedValue, $options, $expenseClassOptions);
            }
            return implode(', ', $labels);
        }

        return $this->labelForValue($report, $name, $value, $options, $expenseClassOptions);
    }

    private function labelForValue($report, $name, $value, array $options, array $expenseClassOptions)
    {
        if ($this->isExpenseClassReport($report)) {
            if ($name === 'acqUnit') {
                $prefix = (string) ($expenseClassOptions['prefix'] ?? '');
                if ($prefix !== '' && strtoupper(trim($this->stringValue($value))) === $prefix) {
                    return $prefix;
                }
            } elseif ($name === 'funds') {
                $fundLabel = $this->expenseClassFundLabel($value, $expenseClassOptions);
                if ($fundLabel !== null) {
                    return $fundLabel;
                }
            }
        }

        $label = $this->optionLabel($value, $options);
        return $label === null ? $this->stringValue($value) : $label;
    }

    private function optionLabel($value, array $options)
    {
        $valueString = $this->stringValue($value);
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $optionValues = array_values($option);
            $optionValue = $option['value'] ?? ($optionValues[0] ?? null);
            $optionLabel = $option['label'] ?? ($optionValues[1] ?? $optionValue);
            if ($optionValue !== null && (string) $optionValue === $valueString && $this->hasValue($optionLabel)) {
                return $this->stringValue($optionLabel);
            }
        }
        return null;
    }

    private function expenseClassFundLabel($value, array $options)
    {
        $valueString = strtolower($this->stringValue($value));
        foreach (($options['funds'] ?? []) as $fund) {
            if (!is_array($fund) || !isset($fund['id'])) {
                continue;
            }
            if (strtolower((string) $fund['id']) === $valueString && $this->hasValue($fund['label'] ?? null)) {
                return $this->stringValue($fund['label']);
            }
        }
        return null;
    }

    private function multiSelectValues($value)
    {
        if (is_array($value)) {
            $values = $value;
        } elseif (is_string($value)) {
            $values = preg_split('/[\r\n,]+/', $value);
        } else {
            $values = [$value];
        }

        if (!is_array($values)) {
            return [];
        }

        $selected = [];
        foreach ($values as $selectedValue) {
            if ($this->hasValue($selectedValue)) {
                $selected[] = is_string($selectedValue) ? trim($selectedValue) : $selectedValue;
            }
        }
        return $selected;
    }

    private function isExpenseClassPicker($report, $name)
    {
        return $this->isExpenseClassReport($report) && in_array($name, ['acqUnit', 'funds'], true);
    }

    private function isExpenseClassReport($report)
    {
        return isset($report->slug) && $report->slug === 'budget-year-expense-class';
    }

    private function hasValue($value)
    {
        if ($value === null || $value === false) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasValue($item)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    private function stringValue($value)
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $part) {
                if ($this->hasValue($part)) {
                    $parts[] = $this->stringValue($part);
                }
            }
            return implode(', ', $parts);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
