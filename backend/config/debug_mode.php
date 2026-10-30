<?php

function resolveYiiDebugMode($debugValue, $environmentValue): bool
{
    if ($debugValue === 'true') {
        return true;
    }

    if ($debugValue === 'false') {
        return false;
    }

    return $debugValue === false && $environmentValue === 'dev';
}
