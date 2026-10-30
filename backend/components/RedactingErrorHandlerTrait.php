<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace app\components;

use app\services\ApiKeyRedactor;

trait RedactingErrorHandlerTrait
{
    private $preloggedWarning = null;

    public function handleError($code, $message, $file, $line)
    {
        if ((error_reporting() & $code) && ($code === E_WARNING || $code === E_USER_WARNING)) {
            try {
                return parent::handleError($code, $message, $file, $line);
            } catch (\yii\base\ErrorException $exception) {
                // Keep caught warnings logged while avoiding a duplicate in Yii's exception handler.
                $this->preloggedWarning = $exception;
                $category = get_class($exception) . ':' . $exception->getSeverity();
                \Yii::error($exception, $category);
                throw $exception;
            }
        }

        return parent::handleError($code, $message, $file, $line);
    }

    public function logException($exception)
    {
        $alreadyLogged = $this->preloggedWarning;
        $this->preloggedWarning = null;
        if ($exception === $alreadyLogged) {
            return;
        }

        parent::logException($exception);
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
