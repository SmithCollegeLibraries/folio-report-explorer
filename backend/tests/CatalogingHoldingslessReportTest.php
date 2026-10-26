<?php
require_once __DIR__ . '/support/HoldingslessReportFixture.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
use app\services\CatalogingReportCompilerService;
use app\services\ReportExecutionContractService;
require_once __DIR__ . '/../services/ReportExecutionContractService.php';
function checkHoldingsless($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$probe = new app\models\ReportTemplate();
$probe->slug = 'instances-without-holdings';
checkHoldingsless(CatalogingReportCompilerService::supports($probe), 'The cataloging compiler must support the holdings-less report.');
$report = holdingslessReportFixture();
$db = new HoldingslessLookupDb();
$base = ['ownershipCode' => 'all', 'suppressionMode' => 'all', 'excluded035Prefixes' => ''];
$compile = static function ($overrides = []) use ($report, $db, $base) {
    return CatalogingReportCompilerService::build($report, array_replace($base, $overrides), $db);
};
$compiled = $compile(['ownershipCode' => 'sm', 'excluded035Prefixes' => ' (SMHASLKR), (SMISLKR) ']);
checkHoldingsless($compiled['params'][':ownershipCode'] === 'SM', 'Normalize ownership code.');
checkHoldingsless($compiled['params'][':excluded035Prefixes'] === '(SMHASLKR),(SMISLKR)', 'Normalize prefix separators.');
foreach ([['ownershipCode' => 'SC'], ['suppressionMode' => 'unsuppressed'], ['excluded035Prefixes' => 'a,,b'], ['excluded035Prefixes' => ['x']]] as $invalid) {
    try { $compile($invalid); checkHoldingsless(false, 'Invalid input was accepted'); }
    catch (app\exceptions\ReportParameterValidationException $e) {
        checkHoldingsless(array_keys($e->getFieldErrors()) === array_keys($invalid), 'Report the invalid field.');
    }
}
$literal = "(X_%'), OR 1=1 --";
$bound = $compile(['excluded035Prefixes' => $literal]);
checkHoldingsless(strpos($bound['sql'], $literal) === false, 'Prefixes stay bound, never interpolated.');
$contract = ReportExecutionContractService::fromReport($report, [
    'exportKind' => 'identifier', 'marcTag' => $compiled['marcTag'],
    'locationName' => $compiled['location']['name'], 'locationCode' => $compiled['location']['code'],
]);
checkHoldingsless($contract['identifierExport']['sourceColumn'] === 'Instance UUID', 'Use the existing UUID export.');
ReportExecutionContractService::assertStaticExportSql($compiled['sql'], $contract);
$originalParameters = $report->parameters;
$parameters = json_decode($report->parameters, true);
foreach ($parameters as &$parameter) { ksort($parameter); }
unset($parameter);
$report->parameters = json_encode($parameters);
$compile(); // MySQL JSON reorders object keys; that is not metadata drift.
$report->parameters = $originalParameters;
$originalConfig = $report->execution_config;
$report->parameters = json_decode($originalParameters, true);
$report->execution_config = json_decode($originalConfig, true);
try {
    $compile(); // Yii's MySQL ActiveRecord typecasts JSON columns to arrays.
} catch (InvalidArgumentException $e) {
    checkHoldingsless(false, 'Accept canonical JSON metadata decoded by Yii ActiveRecord.');
}
$decodedParameters = $report->parameters;
$decodedParameters[0]['default'] = 'SM';
$report->parameters = $decodedParameters;
try { $compile(); checkHoldingsless(false, 'Reject drift in decoded metadata'); } catch (InvalidArgumentException $e) {}
$report->parameters = $originalParameters;
$report->execution_config = $originalConfig;
$originalSql = $report->sql_template;
$report->sql_template = str_replace('NOT EXISTS', 'EXISTS', $originalSql);
try { $compile(); checkHoldingsless(false, 'Reject edited seed SQL'); } catch (InvalidArgumentException $e) {}
$report->sql_template = $originalSql;
$db->missingTable = true;
try { $compile(); checkHoldingsless(false, 'Missing MARC tables must not masquerade as no results'); } catch (InvalidArgumentException $e) {}
$db->missingTable = false;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
@$pdo->sqliteCreateFunction('BTRIM', 'trim', 1);
@$pdo->sqliteCreateFunction('STRPOS', static function ($text, $needle) { $pos = strpos((string)$text, (string)$needle); return $pos === false ? 0 : $pos + 1; }, 2);
$pdo->exec("ATTACH DATABASE ':memory:' AS inventory");
$pdo->exec("ATTACH DATABASE ':memory:' AS marctab");
$pdo->exec('CREATE TABLE inventory.instance__t (id TEXT, hrid TEXT, title TEXT, source TEXT, discovery_suppress BOOLEAN, staff_suppress BOOLEAN)');
$pdo->exec('CREATE TABLE inventory.holdings_record__t (id TEXT, instance_id TEXT, discovery_suppress BOOLEAN)');
foreach (['998','035'] as $tag) { $pdo->exec("CREATE TABLE marctab.mt$tag (instance_id TEXT, instance_hrid TEXT, sf TEXT, content TEXT)"); }
$insert = $pdo->prepare('INSERT INTO inventory.instance__t VALUES (?,?,?,\'MARC\',?,?)');
foreach ([['smith','1',0,0], ['amherst','2',0,0], ['unknown','3',0,0], ['discovery','4',1,0], ['staff','5',0,1], ['both','6',1,1], ['unset',null,null,null], ['held','8',0,0], ['marked','9',0,0], ['wildcard','10',0,0]] as [$id,$hrid,$disc,$staff]) {
    $insert->execute([$id,$hrid,$id,$disc,$staff]);
    if ($id !== 'unknown') {
        $pdo->prepare('INSERT INTO marctab.mt998 VALUES (?,NULL,\'a\',?)')->execute([$id,$id === 'amherst' ? 'AM' : 'SM']);
    }
}
$pdo->exec("INSERT INTO inventory.holdings_record__t VALUES ('other-college-holdings','held',1)");
$pdo->exec("INSERT INTO marctab.mt035 VALUES ('marked',NULL,'a',' (smhaslkr)123'), ('marked',NULL,'a','unrelated'), ('wildcard',NULL,'a','(Xab)123')");
$run = static function ($overrides = []) use ($compile, $pdo) {
    $compiled = $compile($overrides);
    $sql = str_replace("unnest(string_to_array(:excluded035Patterns, ',')) AS excluded(pattern)", 'json_each(:excluded035Patterns) AS excluded', $compiled['sql']);
    $sql = str_replace('AS MATERIALIZED', 'AS', $sql);
    $sql = str_replace(['inst.hrid NULLS LAST', 'candidates.hrid NULLS LAST'], ['(inst.hrid IS NULL), inst.hrid', '(candidates.hrid IS NULL), candidates.hrid'], $sql);
    $sql = str_replace('excluded.pattern', 'excluded.value', $sql);
    $sql = str_replace('OFFSET 0', 'LIMIT -1 OFFSET 0', $sql);
    $sql = preg_replace("/STRING_AGG\(DISTINCT BTRIM\(m.content\), '; ' ORDER BY BTRIM\(m.content\)\)/", 'GROUP_CONCAT(DISTINCT BTRIM(m.content))', $sql);
    $compiled['params'][':excluded035Patterns'] = json_encode(explode(',', $compiled['params'][':excluded035Patterns']));
    $statement = $pdo->prepare($sql); $statement->execute($compiled['params']);
    return array_column($statement->fetchAll(PDO::FETCH_ASSOC), null, 'Instance UUID');
};
$all = $run();
checkHoldingsless(count($all) === 9 && isset($all['amherst'], $all['unknown'], $all['unset']) && !isset($all['held']), 'All-college scope includes missing ownership and null HRID, but no holdings from any institution.');
$smith = $run(['ownershipCode' => 'SM']);
checkHoldingsless(count($smith) === 7 && !isset($smith['amherst'], $smith['unknown']), 'Ownership uses MARC UUID evidence independently of holdings and HRID.');
foreach (['exclude_discovery'=>7, 'exclude_staff'=>7, 'exclude_both'=>6] as $mode=>$count) {
    checkHoldingsless(count($run(['suppressionMode'=>$mode])) === $count, "Honor suppression mode $mode including null flags.");
}
$excluded = $run(['excluded035Prefixes'=>'(SMHASLKR),(SMISLKR)']);
checkHoldingsless(!isset($excluded['marked']) && isset($excluded['amherst']), 'Exclude entire instance even when another 035 row does not match; do not infer ownership from exclusions.');
checkHoldingsless(isset($run(['excluded035Prefixes'=>'(X_%)'])['wildcard']), 'Percent and underscore are literal prefix characters.');
checkHoldingsless(!isset($run(['excluded035Prefixes'=>'smhaslkr'])['marked']), 'Bare marker codes match parenthesized 035 values case-insensitively.');
checkHoldingsless(!isset($run(['excluded035Prefixes'=>'SM*LKR'])['marked']), 'An asterisk matches any sequence inside a marker, with optional opening parenthesis.');
$pdo->exec("INSERT INTO marctab.mt035 VALUES ('smith',NULL,'a','SMISLKR123'), ('amherst',NULL,'a','prefix(SMHASLKR)123'), ('unknown',NULL,'a','(X_%)123')");
$wildcardRows = $run(['excluded035Prefixes'=>'SM*LKR']);
checkHoldingsless(!isset($wildcardRows['smith']) && !isset($wildcardRows['marked']) && isset($wildcardRows['amherst']), 'Wildcard prefixes match bare and parenthesized markers, but remain anchored at the start.');
checkHoldingsless(!isset($run(['excluded035Prefixes'=>'*SMHASLKR*'])['amherst']), 'A leading wildcard explicitly allows matching elsewhere in a value.');
$literalRows = $run(['excluded035Prefixes'=>'(X_%)']);
checkHoldingsless(!isset($literalRows['unknown']) && isset($literalRows['wildcard']), 'Literal percent and underscore exclusions match only literal punctuation.');
$pdo->exec("INSERT INTO marctab.mt035 VALUES ('unknown',NULL,'a','(X!_%)123'), ('wildcard',NULL,'a','(X!ab)123')");
$escapedRows = $run(['excluded035Prefixes'=>'(X!_%)']);
checkHoldingsless(!isset($escapedRows['unknown']) && isset($escapedRows['wildcard']), 'Escape characters remain literal and cannot turn percent or underscore into wildcards.');
checkHoldingsless(isset($run()['marked']), 'Leaving exclusions empty retains marked records.');
$pdo->exec("INSERT INTO marctab.mt035 VALUES ('both',NULL,'a','(SMHASLKR)1'), ('both',NULL,'a','SMISLKR2'), ('marked',NULL,'a','(SMHASLKR)duplicate')");
$twoPatterns = ['excluded035Prefixes'=>'SMHASLKR, SMISLKR'];
$allMatches = $run($twoPatterns + ['exclusionMatch'=>'all']);
checkHoldingsless(isset($allMatches['marked'], $allMatches['smith'], $allMatches['unset']) && !isset($allMatches['both']), 'All-pattern exclusion removes records matching both patterns across separate 035 fields, retaining single matches and absent markers.');
$anyMatches = $run($twoPatterns + ['exclusionMatch'=>'any']);
checkHoldingsless(!isset($anyMatches['marked']) && !isset($anyMatches['smith']) && !isset($anyMatches['both']) && isset($anyMatches['unset']), 'Any-pattern exclusion removes records matching either pattern.');
checkHoldingsless(array_keys($run($twoPatterns)) === array_keys($anyMatches), 'Omitted match mode preserves the any-pattern default.');
foreach (['SMHASLKR', 'SM*LKR', 'SMHASLKR, SMHASLKR', '(SMHASLKR), smhaslkr'] as $patterns) {
    checkHoldingsless(array_keys($run(['excluded035Prefixes'=>$patterns, 'exclusionMatch'=>'all'])) === array_keys($run(['excluded035Prefixes'=>$patterns, 'exclusionMatch'=>'any'])), 'Equivalent patterns and parenthesis alternatives do not become separate required matches.');
}
checkHoldingsless(array_keys($run(['exclusionMatch'=>'all'])) === array_keys($run()), 'Empty exclusions keep all candidates in either mode.');
try { $compile(['exclusionMatch'=>'AND']); checkHoldingsless(false, 'Reject unknown match modes'); }
catch (app\exceptions\ReportParameterValidationException $e) { checkHoldingsless(isset($e->getFieldErrors()['exclusionMatch']), 'Attach match-mode errors to its field.'); }
checkHoldingsless($allMatches['marked']['035$a Exclusion Rule'] === 'Exclude only if all patterns match', 'The CSV explains the selected exclusion rule.');
checkHoldingsless(array_key_exists('Discovery Suppressed', $all['smith']) && array_key_exists('Staff Suppressed', $all['smith']) && array_key_exists('Ownership Markers (998$a)', $all['smith']), 'Worklist includes review evidence.');
echo "Holdings-less report compiler, SQL semantics, and export contract checks passed\n";
