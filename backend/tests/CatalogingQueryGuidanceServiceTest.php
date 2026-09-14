<?php
require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';
function catalogingGuidanceCheck($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$prompt = 'Find Smith College instances that do not have holdings and do not have (SMHASLKR) or (SMISLKR)';
$assumptions = app\services\ExploratoryQueryDefaultsService::resolve($prompt);
catalogingGuidanceCheck(count($assumptions) >= 2, 'Holdings-less requests need visible holdings and suppression interpretations.');
use app\services\CatalogingQueryGuidanceService as Guidance;
foreach (['instances without holdings','holding-less records','holdingless instances','instances with no holdings','instances that do not have holdings', "instances that don't have holdings", 'instances missing holdings', 'instances without Smith holdings'] as $question) {
    catalogingGuidanceCheck(Guidance::matches($question), "Recognize $question");
}
foreach (['items without barcodes','holdings without items','instances with holdings','MARC bibliographic records missing a tag'] as $question) {
    catalogingGuidanceCheck(!Guidance::matches($question), "Do not hijack $question");
}
foreach ([
    'Find instances without holdings' => 'all',
    'Find unsuppressed instances without holdings' => 'exclude_both',
    'Find instances without holdings that are not suppressed' => 'exclude_both',
    'Find instances without holdings; exclude discovery suppression only' => 'exclude_discovery',
    'Find instances without holdings; exclude staff suppression only' => 'exclude_staff',
    'Find instances without holdings; exclude both discovery and staff suppression' => 'exclude_both',
    'Find instances without holdings including suppressed instances' => 'all',
    'Find instances without holdings; do not exclude suppressed instances' => 'all',
    'Find suppressed instances without holdings' => 'as_requested',
    'Find instances without holdings; exclude discovery suppression and include staff-suppressed records' => 'as_requested',
    'Find instances without holdings; not staff-suppressed' => 'exclude_staff',
] as $question=>$mode) {
    $values = array_column(app\services\ExploratoryQueryDefaultsService::resolve($question), 'value', 'key');
    catalogingGuidanceCheck(($values['instance_suppression'] ?? null) === $mode, "Suppression interpretation: $question");
}
$plan = app\services\ExploratoryQueryDefaultsService::buildPromptGuidance($assumptions);
catalogingGuidanceCheck(strpos($plan, 'ROI') === false, 'Cataloging interpretations must not inject unrelated ROI guidance.');
$guidance = Guidance::buildPromptGuidance($prompt);
foreach (['NOT EXISTS', '998', "'SM'", "'AM'", 'Do not infer ownership from', '035', 'staff_suppress', 'discovery_suppress', 'LKRS', 'All colleges'] as $required) {
    catalogingGuidanceCheck(strpos($guidance, $required) !== false, "Guidance covers $required");
}
foreach (['Find instances without holdings at all', 'Find instances without holdings at any college'] as $question) {
    $scope = array_column(app\services\ExploratoryQueryDefaultsService::resolve($question), 'value', 'key');
    catalogingGuidanceCheck($scope['holdings_absence'] === 'consortium', 'At all / at any college must retain consortium scope.');
}
$values = array_column(app\services\ExploratoryQueryDefaultsService::resolve('instances without Smith holdings'), 'value', 'key');
catalogingGuidanceCheck($values['holdings_absence'] === 'institution', 'Distinguish institution-specific holdings absence.');
catalogingGuidanceCheck(Guidance::buildPromptGuidance('holdings without items') === '', 'Unrelated queries receive no holdings-less rules.');
echo "Cataloging Ask AI guidance tests passed\n";
