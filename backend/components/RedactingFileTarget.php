<?php

namespace app\components;

use app\services\ApiKeyRedactor;

class RedactingFileTarget extends \yii\log\FileTarget
{
    public function formatMessage($message)
    {
        return ApiKeyRedactor::redact(parent::formatMessage($message));
    }
}
