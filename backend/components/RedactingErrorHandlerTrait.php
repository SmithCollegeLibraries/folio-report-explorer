<?php

namespace app\components;

use app\services\ApiKeyRedactor;

trait RedactingErrorHandlerTrait
{
    public function handleError($code, $message, $file, $line)
    {
        if ((error_reporting() & $code) && ($code === E_WARNING || $code === E_USER_WARNING)) {
            \Yii::warning($message, 'application');
        }

        return parent::handleError($code, $message, $file, $line);
    }

    protected function handleFallbackExceptionMessage($exception, $previousException)
    {
        $message = "An Error occurred while handling another error:\n";
        $message .= (string)$exception;
        $message .= "\nPrevious exception:\n";
        $message .= (string)$previousException;
        $message = ApiKeyRedactor::redact($message);

        if (YII_DEBUG) {
            if (PHP_SAPI === 'cli') {
                echo $message . "\n";
            } else {
                echo '<pre>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>';
            }
        } else {
            echo 'An internal server error occurred.';
        }

        error_log($message);
        if (defined('HHVM_VERSION')) {
            flush();
        }
        exit(1);
    }
}
