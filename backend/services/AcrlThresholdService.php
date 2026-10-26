<?php

namespace app\services;

/** Reads and validates the advisory ACRL change threshold. */
class AcrlThresholdService
{
    private $settings;

    public function __construct($settings = null)
    {
        $this->settings = $settings ?: new AcrlThresholdSettingsStore();
    }

    public function get(): float
    {
        $value = $this->settings->getThreshold();
        return is_numeric($value) && (float)$value >= 0 && (float)$value <= 100
            ? (float)$value
            : 10.0;
    }

    public function save($value): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 100) {
            throw new \InvalidArgumentException('The change threshold must be between 0 and 100 percent.');
        }
        $normalized = round((float)$value, 2);
        $this->settings->saveThreshold($normalized);
        return $normalized;
    }
}

class AcrlThresholdSettingsStore
{
    public function getThreshold()
    {
        return SettingsService::getAcrlChangeThresholdPercent();
    }

    public function saveThreshold(float $value): void
    {
        SettingsService::save(['acrl_change_threshold_percent' => $value]);
    }
}
