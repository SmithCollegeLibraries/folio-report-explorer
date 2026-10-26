<?php

require_once __DIR__ . '/../services/AcrlThresholdService.php';

use app\services\AcrlThresholdService;

class AcrlThresholdTestSettings
{
    public $value = null;

    public function getThreshold()
    {
        return $this->value;
    }

    public function saveThreshold(float $value): void
    {
        $this->value = $value;
    }
}

function acrlThresholdAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$settings = new AcrlThresholdTestSettings();
$service = new AcrlThresholdService($settings);
acrlThresholdAssert($service->get() === 10.0, 'The default change threshold is 10 percent.');
acrlThresholdAssert($service->save('12.5') === 12.5 && $service->get() === 12.5, 'Administrators can save and read a decimal threshold.');
foreach ([-1, 100.01, 'not-a-number'] as $invalid) {
    try {
        $service->save($invalid);
        acrlThresholdAssert(false, 'Thresholds outside 0 to 100 must be rejected.');
    } catch (InvalidArgumentException $exception) {
    }
}

echo "PASS: ACRL threshold default, read/write, and range validation\n";
