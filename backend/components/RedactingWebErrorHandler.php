<?php

namespace app\components;

class RedactingWebErrorHandler extends \yii\web\ErrorHandler
{
    use RedactingErrorHandlerTrait;
}
