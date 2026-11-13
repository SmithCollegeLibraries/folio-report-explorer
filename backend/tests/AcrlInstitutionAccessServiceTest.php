<?php

$servicePath = __DIR__ . '/../services/AcrlInstitutionAccessService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL: institution-scoped requests must validate the query parameter before data access.\n");
    exit(1);
}
require_once $servicePath;

use app\services\AcrlInstitutionAccessService;

function acrlInstitutionAccessAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acrlInstitutionAccessRejects(callable $call, int $code, string $message): void
{
    try {
        $call();
    } catch (\DomainException $error) {
        acrlInstitutionAccessAssert($error->getCode() === $code, $message . ' has the right status code.');
        return;
    }
    acrlInstitutionAccessAssert(false, $message . ' is rejected.');
}

$service = new AcrlInstitutionAccessService();
acrlInstitutionAccessAssert($service->requireSupported('institution=SC', ['institution' => 'SC']) === 'SC', 'SC remains enabled.');
foreach (['institution=AC', 'institution=HC', 'institution=MH', 'institution=UM'] as $query) {
    acrlInstitutionAccessRejects(function () use ($service, $query) {
        $service->requireSupported($query, ['institution' => substr($query, 12)]);
    }, 403, $query . ' is valid but disabled in A1.');
}
foreach ([
    ['', []],
    ['', ['institution' => 'SC']],
    ['institution=XX', ['institution' => 'XX']],
    ['institution=SC&institution=SC', ['institution' => 'SC']],
    ['institution%5B%5D=SC', ['institution' => ['SC']]],
    ['institution=SC', ['institution' => ['SC']]],
] as $case) {
    acrlInstitutionAccessRejects(function () use ($service, $case) {
        $service->requireSupported($case[0], $case[1]);
    }, 400, 'Missing, unknown, repeated, or array institution values are rejected.');
}
