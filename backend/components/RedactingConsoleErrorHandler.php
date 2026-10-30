<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace app\components;

use app\services\ApiKeyRedactor;
use yii\base\ErrorException;
use yii\base\UserException;
use yii\console\Exception;
use yii\console\UnknownCommandException;
use yii\helpers\Console;

class RedactingConsoleErrorHandler extends \yii\console\ErrorHandler
{
    use RedactingErrorHandlerTrait;

    protected function renderException($exception)
    {
        $previous = $exception->getPrevious();
        if ($exception instanceof UnknownCommandException) {
            $message = $this->formatMessage($exception->getName() . ': ') . $exception->command;
            $alternatives = $exception->getSuggestedAlternatives();
            if (count($alternatives) === 1) {
                $message .= "\n\nDid you mean \"" . reset($alternatives) . '"?';
            } elseif (count($alternatives) > 1) {
                $message .= "\n\nDid you mean one of these?\n    - " . implode("\n    - ", $alternatives);
            }
        } elseif ($exception instanceof UserException && ($exception instanceof Exception || !YII_DEBUG)) {
            $message = $this->formatMessage($exception->getName() . ': ') . $exception->getMessage();
        } elseif (YII_DEBUG) {
            if ($exception instanceof Exception) {
                $message = $this->formatMessage("Exception ({$exception->getName()})");
            } elseif ($exception instanceof ErrorException) {
                $message = $this->formatMessage($exception->getName());
            } else {
                $message = $this->formatMessage('Exception');
            }
            $message .= $this->formatMessage(" '" . get_class($exception) . "'", [Console::BOLD, Console::FG_BLUE])
                . ' with message ' . $this->formatMessage("'{$exception->getMessage()}'", [Console::BOLD])
                . "\n\nin " . dirname($exception->getFile()) . DIRECTORY_SEPARATOR . $this->formatMessage(basename($exception->getFile()), [Console::BOLD])
                . ':' . $this->formatMessage($exception->getLine(), [Console::BOLD, Console::FG_YELLOW]) . "\n";
            if ($exception instanceof \yii\db\Exception && !empty($exception->errorInfo)) {
                $message .= "\n" . $this->formatMessage("Error Info:\n", [Console::BOLD]) . print_r($exception->errorInfo, true);
            }
            if ($previous === null) {
                $message .= "\n" . $this->formatMessage("Stack trace:\n", [Console::BOLD]) . $exception->getTraceAsString();
            }
        } else {
            $message = $this->formatMessage('Error: ') . $exception->getMessage();
        }

        $message = ApiKeyRedactor::redact($message);
        if (PHP_SAPI === 'cli') {
            Console::stderr($message . "\n");
        } else {
            echo $message . "\n";
        }

        if (YII_DEBUG && $previous !== null) {
            $causedBy = $this->formatMessage('Caused by: ', [Console::BOLD]);
            $causedBy = ApiKeyRedactor::redact($causedBy);
            if (PHP_SAPI === 'cli') {
                Console::stderr($causedBy);
            } else {
                echo $causedBy;
            }
            $this->renderException($previous);
        }
    }
}
