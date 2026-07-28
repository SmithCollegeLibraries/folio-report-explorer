<?php

require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratorySqlSemanticValidatorService.php';

use app\services\ExploratorySemanticContractService;
use app\services\ExploratorySqlSemanticValidatorService;

function organizationScopeAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
            . "\n"
        );
        exit(1);
    }
}

function organizationScopeAssertValidated(string $sql, array $contract, string $message): void
{
    $result = ExploratorySqlSemanticValidatorService::validate($sql, $contract);
    organizationScopeAssertSame('validated', $result['status'] ?? null, $message);
}

function organizationScopeAssertRejected(string $sql, array $contract, string $message): void
{
    $result = ExploratorySqlSemanticValidatorService::validate($sql, $contract);
    organizationScopeAssertSame('rejected', $result['status'] ?? null, $message);
}

$contract = ExploratorySemanticContractService::build(
    'List all statistics notes in organization interfaces limited to the AC acquisition unit',
    null,
    [],
    'unsupported_query_family'
);

$validSql = <<<'SQL'
SELECT intf.statistics_notes
FROM organizations.organizations__t AS org
JOIN organizations.organizations__t__interfaces AS oi
  ON oi.id = org.id
JOIN organizations.interfaces__t AS intf
  ON intf.id = oi.interfaces
JOIN organizations.organizations__t__acq_unit_ids AS ou
  ON ou.id = org.id
JOIN orders.acquisitions_unit__t AS au
  ON au.id = ou.acq_unit_ids
WHERE au.name = 'AC'
  AND intf.statistics_notes IS NOT NULL
LIMIT 100
SQL;

organizationScopeAssertValidated(
    $validSql,
    $contract,
    'The authoritative organization interface and acquisition-unit bridges must validate.'
);

$bridgeOnlySql = <<<'SQL'
SELECT intf.statistics_notes
FROM organizations.organizations__t__interfaces AS oi
JOIN organizations.interfaces__t AS intf
  ON intf.id = oi.interfaces
JOIN organizations.organizations__t__acq_unit_ids AS ou
  ON ou.id = oi.id
JOIN orders.acquisitions_unit__t AS au
  ON au.id = ou.acq_unit_ids
WHERE au.name = 'AC'
LIMIT 100
SQL;
organizationScopeAssertValidated(
    $bridgeOnlySql,
    $contract,
    'Organization-owned bridges may share their parent ID without joining organizations__t.'
);

organizationScopeAssertValidated(
    str_replace("au.name = 'AC'", "TRIM(au.name) = 'AC'", $validSql),
    $contract,
    'A single defensive TRIM wrapper around the canonical exact code must validate.'
);

$organizationOnlyContract = ExploratorySemanticContractService::build(
    'List organizations with the AC acquisition unit',
    null,
    [],
    'unsupported_query_family'
);
$organizationOnlySql = <<<'SQL'
SELECT org.name
FROM organizations.organizations__t AS org
JOIN organizations.organizations__t__acq_unit_ids AS ou
  ON ou.id = org.id
JOIN orders.acquisitions_unit__t AS au
  ON au.id = ou.acq_unit_ids
WHERE au.name = 'AC'
LIMIT 100
SQL;
organizationScopeAssertValidated(
    $organizationOnlySql,
    $organizationOnlyContract,
    'Organization listings must validate through the organization acquisition-unit bridge without requiring interfaces.'
);

$directInterfaceJoinSql = <<<'SQL'
SELECT intf.statistics_notes
FROM organizations.organizations__t AS org
JOIN organizations.interfaces__t AS intf ON intf.id = org.id
JOIN organizations.organizations__t__acq_unit_ids AS ou ON ou.id = org.id
JOIN orders.acquisitions_unit__t AS au ON au.id = ou.acq_unit_ids
WHERE au.name = 'AC'
LIMIT 100
SQL;
organizationScopeAssertRejected(
    $directInterfaceJoinSql,
    $contract,
    'A direct interface-to-organization identity join must be rejected.'
);
organizationScopeAssertRejected(
    str_replace(
        'intf.id = oi.interfaces',
        'intf.id = oi.interfaces AND intf.id = org.id',
        $validSql
    ),
    $contract,
    'A direct interface identity join must remain invalid even when the authoritative bridge is also present.'
);

$missingInterfaceBridgeSql = <<<'SQL'
SELECT intf.statistics_notes
FROM organizations.organizations__t AS org
CROSS JOIN organizations.interfaces__t AS intf
JOIN organizations.organizations__t__acq_unit_ids AS ou ON ou.id = org.id
JOIN orders.acquisitions_unit__t AS au ON au.id = ou.acq_unit_ids
WHERE au.name = 'AC'
LIMIT 100
SQL;
organizationScopeAssertRejected(
    $missingInterfaceBridgeSql,
    $contract,
    'An interface output without the organization interfaces bridge must be rejected.'
);

$missingOrganizationUnitBridgeSql = <<<'SQL'
SELECT intf.statistics_notes
FROM organizations.organizations__t AS org
JOIN organizations.organizations__t__interfaces AS oi ON oi.id = org.id
JOIN organizations.interfaces__t AS intf ON intf.id = oi.interfaces
JOIN orders.acquisitions_unit__t AS au ON au.id = org.id
WHERE au.name = 'AC'
LIMIT 100
SQL;
organizationScopeAssertRejected(
    $missingOrganizationUnitBridgeSql,
    $contract,
    'Organization acquisition scope without its authoritative bridge must be rejected.'
);

$wrongUnitEndpointSql = str_replace(
    'au.id = ou.acq_unit_ids',
    'au.id = ou.id',
    $validSql
);
organizationScopeAssertRejected(
    $wrongUnitEndpointSql,
    $contract,
    'The organization acquisition bridge must use its acq_unit_ids endpoint.'
);

$purchaseOrderBridgeSql = str_replace(
    'organizations.organizations__t__acq_unit_ids',
    'orders.purchase_order__t__acq_unit_ids',
    $validSql
);
organizationScopeAssertRejected(
    $purchaseOrderBridgeSql,
    $contract,
    'A purchase-order acquisition bridge must not substitute for organization scope.'
);

$accountBridgeSql = str_replace(
    'organizations.organizations__t__acq_unit_ids',
    'organizations.organizations__t__accounts__acq_unit_ids',
    $validSql
);
organizationScopeAssertRejected(
    $accountBridgeSql,
    $contract,
    'An account acquisition bridge must not substitute for organization scope.'
);

organizationScopeAssertRejected(
    str_replace("WHERE au.name = 'AC'\n  AND ", 'WHERE ', $validSql),
    $contract,
    'A missing acquisition-unit code predicate must be rejected.'
);
organizationScopeAssertRejected(
    str_replace("au.name = 'AC'", "au.name = 'SC'", $validSql),
    $contract,
    'An exact predicate for the wrong acquisition-unit code must be rejected.'
);
organizationScopeAssertRejected(
    str_replace("au.name = 'AC'", "au.name = 'ac'", $validSql),
    $contract,
    'A lowercase literal must be rejected because PostgreSQL equality is case-sensitive.'
);
organizationScopeAssertRejected(
    str_replace("au.name = 'AC'", "au.name ILIKE 'AC'", $validSql),
    $contract,
    'ILIKE is outside the conservative analyzer subset and must fail closed.'
);
organizationScopeAssertRejected(
    str_replace("au.name = 'AC'", "UPPER(TRIM(au.name)) = 'AC'", $validSql),
    $contract,
    'Nested name normalization is outside the conservative analyzer subset and must fail closed.'
);

$nullableCodeSql = str_replace(
    "JOIN orders.acquisitions_unit__t AS au\n  ON au.id = ou.acq_unit_ids\nWHERE au.name = 'AC'",
    "LEFT JOIN orders.acquisitions_unit__t AS au\n  ON au.id = ou.acq_unit_ids AND au.name = 'AC'\nWHERE intf.statistics_notes IS NOT NULL",
    $validSql
);
$nullableCodeSql = str_replace(
    "\n  AND intf.statistics_notes IS NOT NULL",
    '',
    $nullableCodeSql
);
organizationScopeAssertRejected(
    $nullableCodeSql,
    $contract,
    'An exact code predicate only on a nullable LEFT JOIN must not satisfy organization scope.'
);

$disconnectedScopesSql = <<<'SQL'
WITH interface_rows AS (
    SELECT org.id AS organization_id,
           intf.name AS interface_name,
           intf.statistics_notes
    FROM organizations.organizations__t AS org
    JOIN organizations.organizations__t__interfaces AS oi ON oi.id = org.id
    JOIN organizations.interfaces__t AS intf ON intf.id = oi.interfaces
),
scoped_orgs AS (
    SELECT other_org.id AS organization_id,
           au.name AS acquisition_unit_name
    FROM organizations.organizations__t AS other_org
    JOIN organizations.organizations__t__acq_unit_ids AS ou ON ou.id = other_org.id
    JOIN orders.acquisitions_unit__t AS au ON au.id = ou.acq_unit_ids
    WHERE au.name = 'AC'
)
SELECT interface_rows.statistics_notes
FROM interface_rows
JOIN scoped_orgs
  ON scoped_orgs.acquisition_unit_name = interface_rows.interface_name
LIMIT 100
SQL;
organizationScopeAssertRejected(
    $disconnectedScopesSql,
    $contract,
    'Interface and acquisition-unit evidence in disconnected organization lineages must not satisfy one contract.'
);

$scopedDecoySql = <<<'SQL'
WITH unscoped AS (
    SELECT intf.name AS interface_name,
           intf.statistics_notes
    FROM organizations.organizations__t AS org
    JOIN organizations.organizations__t__interfaces AS oi ON oi.id = org.id
    JOIN organizations.interfaces__t AS intf ON intf.id = oi.interfaces
),
scoped_decoy AS (
    SELECT intf.name AS interface_name,
           au.name AS au_name
    FROM organizations.organizations__t AS org
    JOIN organizations.organizations__t__interfaces AS oi ON oi.id = org.id
    JOIN organizations.interfaces__t AS intf ON intf.id = oi.interfaces
    JOIN organizations.organizations__t__acq_unit_ids AS ou ON ou.id = org.id
    JOIN orders.acquisitions_unit__t AS au ON au.id = ou.acq_unit_ids
    WHERE au.name = 'AC'
)
SELECT unscoped.statistics_notes
FROM unscoped
JOIN scoped_decoy
  ON scoped_decoy.au_name = unscoped.interface_name
LIMIT 100
SQL;
organizationScopeAssertRejected(
    $scopedDecoySql,
    $contract,
    'A fully scoped decoy CTE must not validate unscoped interface output.'
);

fwrite(STDOUT, "Organization acquisition-unit scope test passed\n");
