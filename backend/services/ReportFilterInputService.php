<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';

/** Validates date and fixed-choice report filters and compiles safe IN predicates. */
final class ReportFilterInputService
{
    public static function date($value, string $field, ?string $default = null, bool $optional = false): ?string
    {
        if ($value === null || $value === '') {
            if ($optional) {
                return null;
            }
            $value = $default;
        }
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
            throw new ReportParameterValidationException($field, 'Enter a date in YYYY-MM-DD format.');
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        $errors = \DateTime::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ReportParameterValidationException($field, 'Enter a valid calendar date.');
        }
        return $value;
    }

    public static function selections($value, string $field, array $allowed, array $defaults): array
    {
        if ($value === null) {
            $value = implode(',', $defaults);
        }
        if (!is_string($value)) {
            throw new ReportParameterValidationException($field, 'Choose values from the listed options.');
        }

        $values = array_map('trim', explode(',', $value));
        if ($value === '' || in_array('', $values, true)) {
            throw new ReportParameterValidationException($field, 'Select at least one listed value.');
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new ReportParameterValidationException($field, 'Selected values must be unique.');
        }

        foreach ($values as $selected) {
            if (!in_array($selected, $allowed, true)) {
                throw new ReportParameterValidationException($field, 'Choose only listed values.');
            }
        }

        $ordered = [];
        foreach ($allowed as $option) {
            if (in_array($option, $values, true)) {
                $ordered[] = $option;
            }
        }
        return $ordered;
    }

    public static function selectionPredicate(array $selected, array $all, string $column, string $prefix, array &$params): string
    {
        if ($selected === $all) {
            return 'TRUE';
        }
        if (empty($selected)) {
            return 'FALSE';
        }

        $markers = [];
        foreach ($selected as $index => $value) {
            $marker = ':' . $prefix . $index;
            $markers[] = $marker;
            $params[$marker] = $value;
        }
        return $column . ' IN (' . implode(', ', $markers) . ')';
    }
}
