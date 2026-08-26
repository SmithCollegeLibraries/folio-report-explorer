<?php

namespace app\services;

use Yii;
use yii\httpclient\Client;
use app\exceptions\CanonicalLaneFallbackException;
use app\exceptions\DatabaseQueryCancelledException;
use app\exceptions\ExploratorySqlValidationException;

require_once __DIR__ . '/ClarificationService.php';
require_once __DIR__ . '/AskResponseContractService.php';
require_once __DIR__ . '/ReferenceJsonBundleService.php';
require_once __DIR__ . '/ResolvedReferenceSqlValidatorService.php';
require_once __DIR__ . '/ExploratoryQueryDefaultsService.php';
require_once __DIR__ . '/ExploratoryRoiSqlCompilerService.php';
require_once __DIR__ . '/HardenedPhysicalRoiSqlCompilerService.php';
require_once __DIR__ . '/ExploratorySemanticContractService.php';
require_once __DIR__ . '/ExploratorySqlRepairService.php';
require_once __DIR__ . '/ExploratorySqlSemanticValidatorService.php';
require_once __DIR__ . '/ExplicitReportRequestService.php';
require_once __DIR__ . '/../exceptions/ExploratorySqlValidationException.php';
require_once __DIR__ . '/../exceptions/CanonicalLaneFallbackException.php';
require_once __DIR__ . '/../exceptions/DatabaseQueryCancelledException.php';
require_once __DIR__ . '/../exceptions/PolicyViolationException.php';

/**
 * GeminiService — sends natural-language prompts to Google Gemini 2.5 Flash
 * with schema context, receives generated SQL, validates it, and returns.
 */
class GeminiService
{
    const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
    const OPENAI_API_BASE = 'https://api.openai.com/v1';
    const REQUEST_TIMEOUT_SECONDS = 300;
    const DEFAULT_MAX_RETRIES = 3;
    const DEFAULT_RETRY_BASE_DELAY_MS = 400;
    const MAX_RETRY_BACKOFF_MS = 5000;
    const LEGACY_PROMPT_VERSION = 'legacy_sql_prompt.v1';
    const INTENT_PROMPT_VERSION = 'intent_json_prompt.v1';
    const FAMILY_SLOT_PROMPT_VERSION = 'family_slot_prompt.v1';
    const INDEX_RECOMMENDER_PROMPT_VERSION = 'index_recommender.v1';
    const FOLLOW_UP_SUGGESTION_PROMPT_VERSION = 'followup_suggestions.v1';
    const RESOLVER_CLARIFICATION_PROMPT_VERSION = 'resolver_clarification.v1';
    const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry';

    /**
     * Resolve preferred AI provider from settings (gemini|openai).
     *
     * @return string
     */
    private static function getPreferredAiProvider()
    {
        $provider = strtolower(trim((string)(Yii::$app->params['aiProvider'] ?? 'openai')));
        return $provider === 'openai' ? 'openai' : 'gemini';
    }

    /**
     * Resolve the effective AI configuration.
     * Falls back to the alternate provider when the preferred provider has no key.
     *
     * @return array{provider:string,apiKey:string,model:string}
     */
    private static function getAiConfig()
    {
        $preferredProvider = self::getPreferredAiProvider();
        $geminiApiKey = trim((string)(Yii::$app->params['geminiApiKey'] ?? ''));
        $openaiApiKey = trim((string)(Yii::$app->params['openaiApiKey'] ?? ''));

        $provider = 'none';
        $apiKey = '';

        if ($preferredProvider === 'openai' && $openaiApiKey !== '') {
            $provider = 'openai';
            $apiKey = $openaiApiKey;
        }

        if ($preferredProvider === 'gemini' && $geminiApiKey !== '') {
            $provider = 'gemini';
            $apiKey = $geminiApiKey;
        }

        $model = $preferredProvider === 'openai'
            ? (string)(Yii::$app->params['openaiModel'] ?? 'gpt-5.4')
            : (string)(Yii::$app->params['geminiModel'] ?? 'gemini-2.5-flash');

        return [
            'provider' => $provider,
            'apiKey' => $apiKey,
            'model' => $model,
        ];
    }

    /**
     * Resolve active AI provider (gemini|openai|none).
     *
     * @return string
     */
    private static function getAiProvider()
    {
        return self::getAiConfig()['provider'];
    }

    /**
     * Resolve API key for the active provider.
     *
     * @return string
     */
    private static function getAiApiKey()
    {
        return self::getAiConfig()['apiKey'];
    }

    /**
     * Resolve default model for the active provider.
     *
     * @return string
     */
    private static function getAiModel()
    {
        return self::getAiConfig()['model'];
    }

    /**
     * Standardized message when no AI provider key is configured.
     *
     * @return string
     */
    private static function getMissingAiApiKeyMessage()
    {
        return 'AI API key not configured. Set GEMINI_API_KEY or OPENAI_API_KEY in .env.';
    }

    /**
     * Step 8 entrypoint: run the configured primary mode and optionally execute
     * the alternate mode in shadow for comparison telemetry.
     *
     * @param string $rawQuestion
     * @param string|null $campus
     * @param int|null $userId
     * @param bool $allowExploratory
     * @param string|null $generationPrompt
     * @param array|null $generationTransport Internal non-response context for controller preflight repair.
     * @return array {sql: string, explanation: string, dataSource: string}
     */
    public static function generateSqlWithShadow(
        $rawQuestion,
        $campus = null,
        $userId = null,
        $allowExploratory = false,
        $generationPrompt = null,
        ?array &$generationTransport = null
    ) {
        $rawQuestion = (string)$rawQuestion;
        $generationPrompt = $generationPrompt === null
            ? $rawQuestion
            : (string)$generationPrompt;
        $generationTransport = [
            'rawQuestion' => $rawQuestion,
            'generationPrompt' => $generationPrompt,
        ];
        $referenceBundleMetadata = self::buildReferenceBundleMetadata();
        $explicitReportRequest = ExplicitReportRequestService::extract($rawQuestion);
        $explicitEvidence = self::explicitReportRequestEvidence($explicitReportRequest);
        $referenceResolution = ReferenceResolverService::resolvePrompt($rawQuestion, $userId);
        $resolvedFilters = is_array($referenceResolution['resolvedFilters'] ?? null)
            ? $referenceResolution['resolvedFilters']
            : [];
        $referenceEvidence = [
            'resolvedReferenceFilters' => $resolvedFilters,
        ];
        $askEvidence = array_merge(
            ['referenceBundleMetadata' => $referenceBundleMetadata],
            $explicitEvidence,
            $referenceEvidence
        );
        self::logReferenceResolverTelemetry($referenceResolution, self::fingerprintPrompt($rawQuestion));
        $twoLaneEnabled = self::isTwoLaneEnabled();
        $ambiguity = ClarificationService::detectPromptAmbiguity(
            $rawQuestion,
            self::loadAcceptedClarificationKeys($userId)
        );
        if (!$twoLaneEnabled && !empty($referenceResolution['needsClarification'])) {
            self::logRouteSelection('clarification', (string)($referenceResolution['routeReason'] ?? 'reference_resolver_batch_clarification'), [
                'clarificationBatchId' => $referenceResolution['clarificationBatchId'] ?? null,
                'clarificationSource' => $referenceResolution['clarificationSource'] ?? null,
                'modelClarificationFallbackReason' => $referenceResolution['modelClarificationFallbackReason'] ?? null,
            ]);
            self::logNlTelemetry('nl2sql.generated', [
                'route' => 'clarification',
                'routeReason' => $referenceResolution['routeReason'] ?? null,
                'clarificationType' => $referenceResolution['clarificationType'] ?? null,
                'clarificationBatchId' => $referenceResolution['clarificationBatchId'] ?? null,
                'clarificationSource' => $referenceResolution['clarificationSource'] ?? null,
                'modelClarificationFallbackReason' => $referenceResolution['modelClarificationFallbackReason'] ?? null,
                'dataSource' => null,
            ]);
            return AskResponseContractService::normalizeMode(self::withAskEvidence(
                $referenceResolution,
                $askEvidence
            ));
        }

        if (!empty($explicitReportRequest['needsClarification'])) {
            $clarification = [
                'needsClarification' => true,
                'clarificationType' => 'too_many_explicit_identifiers',
                'clarificationKey' => 'explicit_report_values.too_many_identifiers',
                'question' => 'This report names more than 500 identifiers. Please split it into smaller reports so every value can be preserved exactly.',
                'inputType' => 'free_text',
                'freeTextAllowed' => true,
                'options' => [],
                'warnings' => [],
                'suggestions' => [],
                'route' => 'clarification',
                'routeReason' => 'too_many_explicit_identifiers',
            ];
            return AskResponseContractService::normalizeMode(self::withAskEvidence(
                $clarification,
                $askEvidence
            ));
        }

        $effectivePrompt = $twoLaneEnabled
            ? ReferenceResolverService::appendGenerationContextToPrompt(
                $generationPrompt,
                $referenceResolution,
                $ambiguity
            )
            : ReferenceResolverService::appendGuidanceToPrompt($generationPrompt, $referenceResolution);
        $effectivePrompt = ExplicitReportRequestService::appendGuidance($effectivePrompt, $explicitReportRequest);
        $generationTransport = [
            'rawQuestion' => $rawQuestion,
            'generationPrompt' => $effectivePrompt,
        ];

        if (!$twoLaneEnabled && $ambiguity !== null) {
            self::logRouteSelection('clarification', (string)$ambiguity['routeReason'], [
                'clarificationKey' => $ambiguity['clarificationKey'] ?? null,
            ]);
            self::logNlTelemetry('nl2sql.generated', [
                'route' => 'clarification',
                'routeReason' => $ambiguity['routeReason'] ?? null,
                'clarificationType' => $ambiguity['clarificationType'] ?? null,
                'clarificationKey' => $ambiguity['clarificationKey'] ?? null,
                'dataSource' => null,
            ]);
            return AskResponseContractService::normalizeMode(self::withAskEvidence(
                $ambiguity,
                $askEvidence
            ));
        }

        if ($twoLaneEnabled && $ambiguity !== null) {
            self::logRouteSelection('reference_context_advisory', (string)($ambiguity['routeReason'] ?? 'prompt_ambiguity'), []);
            self::logNlTelemetry('nl2sql.reference_context_advisory', [
                'promptFingerprint' => self::fingerprintPrompt($rawQuestion),
                'routeReason' => $ambiguity['routeReason'] ?? null,
                'clarificationType' => $ambiguity['clarificationType'] ?? null,
                'clarificationKey' => $ambiguity['clarificationKey'] ?? null,
            ]);
        }

        if ($allowExploratory) {
            $primary = self::generateAiBuiltLane(
                $rawQuestion,
                (string)$effectivePrompt,
                $campus,
                'user_requested_exploratory_generation',
                $resolvedFilters
            );

            $primary = self::withInternalReferenceResolverGuidance($primary, $referenceResolution);
            $primary = self::withoutEnabledTwoLaneClarificationState($primary, $twoLaneEnabled);

            return AskResponseContractService::normalizeMode(self::withAskEvidence(
                $primary,
                $askEvidence
            ));
        }

        // Route on the raw user prompt, not the resolver-augmented prompt: the
        // resolver appends guidance boilerplate ("...library or campus name
        // columns") whose scope words would otherwise hijack family selection.
        $queryFamily = self::resolvePromptQueryFamily($rawQuestion, $campus);
        if ($queryFamily === null) {
            $exploratoryReason = self::promptRequiresLegacyFreeform($rawQuestion)
                ? 'canonical_path_unavailable_for_marc_source_records'
                : 'unsupported_query_family';
            $primary = self::generateAiBuiltLane(
                $rawQuestion,
                (string)$effectivePrompt,
                $campus,
                $exploratoryReason,
                $resolvedFilters
            );

            $primary = self::withInternalReferenceResolverGuidance($primary, $referenceResolution);
            $primary = self::withoutEnabledTwoLaneClarificationState($primary, $twoLaneEnabled);

            return AskResponseContractService::normalizeMode(self::withAskEvidence(
                $primary,
                $askEvidence
            ));
        }

        $primaryMode = self::resolvePrimaryModeForPrompt($rawQuestion, $campus);
        $usedAiBuiltLane = false;
        try {
            $primary = $primaryMode === 'intent'
                ? self::generateSql($effectivePrompt, $campus, false, true, $rawQuestion, $resolvedFilters)
                : self::generateSql($effectivePrompt, $campus, true, false, $rawQuestion, $resolvedFilters);
        } catch (CanonicalLaneFallbackException $exception) {
            if (!$twoLaneEnabled) {
                throw $exception;
            }
            $usedAiBuiltLane = true;
            $primary = self::generateAiBuiltLane(
                $rawQuestion,
                (string)$effectivePrompt,
                $campus,
                $exception->getSafeReason(),
                $resolvedFilters,
                $exception->getCandidateResult(),
                'Canonical validation requires AI review.',
                $exception->getFamilyKey()
            );
        } catch (ExploratorySqlValidationException $exception) {
            if (self::isHardCanonicalFailure($exception)) {
                throw $exception;
            }
            $candidate = trim($exception->getCandidateSql()) === ''
                ? []
                : [
                    'sql' => $exception->getCandidateSql(),
                    'route' => $primaryMode === 'intent' ? 'builder_intent' : 'legacy_freeform',
                    'routeReason' => $exception->getSafeCategory(),
                    'repairAttempts' => 0,
                ];
            if (!$twoLaneEnabled) {
                if ($exception->getSafeCategory() !== 'resolved_reference_filter_mismatch') {
                    throw $exception;
                }
                $primary = self::repairExploratorySqlAfterPreflight(
                    $rawQuestion,
                    $campus,
                    $candidate,
                    'Resolved reference filters were not preserved.',
                    (string)$effectivePrompt,
                    $resolvedFilters
                );
            } else {
                $usedAiBuiltLane = true;
                $primary = self::generateAiBuiltLane(
                    $rawQuestion,
                    (string)$effectivePrompt,
                    $campus,
                    'canonical_semantic_validation_failed',
                    $resolvedFilters,
                    $candidate,
                    'Canonical semantic validation requires AI review.',
                    (string)($queryFamily['familyKey'] ?? '')
                );
            }
        } catch (\Exception $exception) {
            if (!$twoLaneEnabled || self::isHardCanonicalFailure($exception)) {
                throw $exception;
            }
            $usedAiBuiltLane = true;
            $primary = self::generateAiBuiltLane(
                $rawQuestion,
                (string)$effectivePrompt,
                $campus,
                'canonical_generation_failed',
                $resolvedFilters,
                [],
                '',
                (string)($queryFamily['familyKey'] ?? '')
            );
        }

        if ($twoLaneEnabled && !$usedAiBuiltLane && !isset($primary['sql'])) {
            $usedAiBuiltLane = true;
            $primary = self::generateAiBuiltLane(
                $rawQuestion,
                (string)$effectivePrompt,
                $campus,
                'canonical_incomplete_result',
                $resolvedFilters,
                [],
                '',
                (string)($queryFamily['familyKey'] ?? '')
            );
        } elseif (!$twoLaneEnabled) {
            $primary = self::repairRoutedCandidateMissingExplicitValues(
                $primary,
                (string)$effectivePrompt,
                $campus,
                $rawQuestion,
                $resolvedFilters
            );
        }

        if ($twoLaneEnabled && isset($primary['sql']) && !isset($primary['generationProvenance'])) {
            $primary = AskResponseContractService::withGenerationProvenance(
                $primary,
                AskResponseContractService::PROVENANCE_VERIFIED_PATTERN
            );
        }

        if (($primary['route'] ?? null) === 'legacy_freeform' && ($primary['routeReason'] ?? '') === 'forced_legacy_mode') {
            $primary['routeReason'] = !empty(Yii::$app->params['nl2sqlForceLegacy'])
                ? 'forced_legacy_mode'
                : 'primary_legacy_mode';
        }

        $primary = self::withInternalReferenceResolverGuidance($primary, $referenceResolution);
        $primary = self::withoutEnabledTwoLaneClarificationState($primary, $twoLaneEnabled);

        if (!self::shouldRunShadowForUser($userId, $rawQuestion)) {
            return AskResponseContractService::normalizeMode(self::withAskEvidence(
                $primary,
                $askEvidence
            ));
        }

        $shadowMode = $primaryMode === 'intent' ? 'legacy' : 'intent';

        try {
            $shadow = $shadowMode === 'intent'
                ? self::generateSql($effectivePrompt, $campus, false, true, $rawQuestion, $resolvedFilters)
                : self::generateSql($effectivePrompt, $campus, true, false, $rawQuestion, $resolvedFilters);

            self::logShadowComparison($primary, $shadow, [
                'primaryMode' => $primaryMode,
                'shadowMode' => $shadowMode,
                'userId' => $userId,
                'promptFingerprint' => self::fingerprintPrompt($rawQuestion),
            ]);
        } catch (\Throwable $e) {
            self::logNlTelemetry('nl2sql.shadow_error', [
                'primaryMode' => $primaryMode,
                'shadowMode' => $shadowMode,
                'userId' => $userId,
                'promptFingerprint' => self::fingerprintPrompt($rawQuestion),
                'error' => $e->getMessage(),
            ], true);
        }

        return AskResponseContractService::normalizeMode(self::withAskEvidence(
            $primary,
            $askEvidence
        ));
    }

    private static function generateAiBuiltLane(
        string $rawQuestion,
        string $generationPrompt,
        $campus,
        string $reason,
        array $resolvedFilters,
        array $seededCandidate = [],
        string $diagnostic = '',
        string $familyKey = ''
    ): array {
        self::logNlTelemetry('nl2sql.lane_transition', [
            'from' => 'verified_pattern',
            'to' => 'ai_built',
            'reason' => self::sanitizeTelemetryLabel($reason, 'canonical_generation_failed'),
            'familyKey' => $familyKey === ''
                ? null
                : self::sanitizeTelemetryLabel($familyKey, 'canonical_family'),
            'seededCandidate' => isset($seededCandidate['sql']),
            'promptFingerprint' => self::fingerprintPrompt($rawQuestion),
        ]);

        if (isset($seededCandidate['sql'])) {
            $result = self::repairExploratorySqlAfterPreflight(
                $rawQuestion,
                $campus,
                $seededCandidate,
                $diagnostic === '' ? 'Canonical semantic validation requires AI review.' : $diagnostic,
                $generationPrompt,
                $resolvedFilters
            );
        } else {
            $result = self::generateExploratorySqlResponse(
                $generationPrompt,
                $campus,
                $reason,
                $rawQuestion,
                $resolvedFilters
            );
        }

        if (isset($result['sql'])) {
            $result['mode'] = 'exploratory';
            $result = AskResponseContractService::withGenerationProvenance(
                $result,
                AskResponseContractService::PROVENANCE_AI_BUILT
            );
        }
        return $result;
    }

    private static function isHardCanonicalFailure(\Throwable $exception): bool
    {
        if ($exception instanceof \app\exceptions\PolicyViolationException
            || $exception instanceof DatabaseQueryCancelledException
        ) {
            return true;
        }
        if ($exception instanceof ExploratorySqlValidationException && !$exception->isRepairable()) {
            return true;
        }

        $message = $exception->getMessage();
        if (self::isAiTimeoutMessage($message)) {
            return true;
        }

        return preg_match(
            '/API key not configured|provider failure|AI (?:API error|request failed)|API request failed|fallback request failed|fallback failed|'
                . 'connection (?:refused|reset|failed)|failed to connect|network is unreachable|could not resolve host|SSL|'
                . 'unauthori[sz]ed|authentication|permission denied|insufficient privilege|access denied|'
                . 'RESOURCE_EXHAUSTED|SQLSTATE\[(?:28P01|42501|53[0-9A-Z]{3})\]|quota|billing|rate limit|'
                . 'HTTP\s*(?:401|403|429)|MAX_TOKENS|truncated|only (?:a single )?SELECT|multiple statements|'
                . 'destructive (?:query|statement|SQL)|restricted (?:data|table)|read-only query/i',
            $message
        ) === 1;
    }

    private static function generateExploratorySqlResponse(
        string $generationPrompt,
        $campus = null,
        string $reason = 'unsupported_query_family',
        ?string $rawQuestion = null,
        array $resolvedFilters = []
    ): array
    {
        $rawQuestion = $rawQuestion === null
            ? $generationPrompt
            : $rawQuestion;
        $assumptions = ExploratoryQueryDefaultsService::resolve($generationPrompt);
        $attemptedPlan = ExploratoryQueryDefaultsService::buildPromptGuidance($assumptions);
        $useHardenedPhysicalRoi = self::useHardenedPhysicalRoi();
        $semanticContract = ExploratorySemanticContractService::build(
            self::semanticContractQuestion($rawQuestion, $generationPrompt),
            is_string($campus) ? $campus : null,
            $assumptions,
            $reason,
            ['physicalRoiPolicyVersion' => $useHardenedPhysicalRoi ? 'v2' : 'legacy']
        );
        $context = [
            'originalQuestion' => $rawQuestion,
            'generationPrompt' => $generationPrompt,
            'campus' => is_string($campus) ? $campus : null,
            'assumptions' => $assumptions,
            'attemptedPlan' => $attemptedPlan,
            'attemptedPlanProvenance' => 'server_defaults',
            'semanticContract' => $semanticContract,
            'resolvedFilters' => $resolvedFilters,
            'route' => 'exploratory_legacy_freeform',
            'routeReason' => $reason,
        ];

        try {
            $outcome = ExploratorySqlRepairService::run(
                function (array $attemptContext) use ($generationPrompt, $campus, $attemptedPlan, $reason, $rawQuestion, $resolvedFilters): array {
                    return self::runExploratorySqlAttempt(
                        $attemptContext + [
                            'route' => 'exploratory_legacy_freeform',
                            'routeReason' => $reason,
                            'resolvedFilters' => $resolvedFilters,
                        ],
                        function () use ($attemptContext, $generationPrompt, $campus, $attemptedPlan, $rawQuestion, $resolvedFilters): array {
                            if ((int)($attemptContext['repairNumber'] ?? 0) === 0) {
                                $guidedPrompt = $generationPrompt;
                                if ($attemptedPlan !== '') {
                                    $guidedPrompt .= "\n\n" . $attemptedPlan;
                                }
                                return self::generateSql(
                                    $guidedPrompt,
                                    $campus,
                                    true,
                                    false,
                                    $rawQuestion,
                                    $resolvedFilters
                                );
                            }

                            return self::generateExploratoryRepairCandidate($attemptContext);
                        }
                    );
                },
                $context
            );
        } catch (\app\exceptions\PolicyViolationException $exception) {
            self::logExploratoryTerminalOutcome($context, 'policy_blocked', 'policy_blocked');
            throw $exception;
        } catch (DatabaseQueryCancelledException $exception) {
            self::logExploratoryTerminalOutcome($context, 'cancelled', 'database_cancelled');
            throw $exception;
        } catch (\Throwable $exception) {
            self::logExploratoryTerminalOutcome($context, 'provider_failure', 'provider_failure');
            throw $exception;
        }

        if (($outcome['status'] ?? null) !== 'validated') {
            $compiledFallback = null;
            if (($semanticContract['concept'] ?? null) === 'cross_domain_call_number_roi') {
                $compiledFallback = $useHardenedPhysicalRoi
                    ? HardenedPhysicalRoiSqlCompilerService::compile($semanticContract)
                    : ExploratoryRoiSqlCompilerService::compile($semanticContract);
            }
            if ($compiledFallback !== null) {
                try {
                    $compiledFallback = self::validateCompiledExploratoryFallback(
                        $compiledFallback,
                        $semanticContract,
                        $resolvedFilters
                    );
                    self::validateExplicitReportValues(
                        (string)($compiledFallback['sql'] ?? ''),
                        (string)($context['originalQuestion'] ?? '')
                    );
                    $compiledFallback = self::withAskEvidence(
                        $compiledFallback,
                        array_merge(
                            is_array($outcome['_askEvidence'] ?? null) ? $outcome['_askEvidence'] : [],
                            [
                                'finalSql' => $compiledFallback['sql'] ?? null,
                                'repairAttempts' => (int)($outcome['repairAttempts'] ?? 0),
                            ]
                        )
                    );
                    $outcome = [
                        'status' => 'validated',
                        'result' => $compiledFallback,
                        'repairAttempts' => (int)($outcome['repairAttempts'] ?? 0),
                    ];
                } catch (\app\exceptions\PolicyViolationException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    self::logNlTelemetry('nl2sql.exploratory_compiled_fallback_rejected', [
                        'promptFingerprint' => self::fingerprintPrompt($rawQuestion),
                        'category' => $exception instanceof ExploratorySqlValidationException
                            ? $exception->getSafeCategory()
                            : 'validation_failure',
                    ], true);
                }
            }
        }

        if (($outcome['status'] ?? null) !== 'validated') {
            self::logExploratoryTerminalOutcome(
                $context,
                'exhausted',
                (string)($outcome['failureCategory'] ?? 'validation_failure'),
                (int)($outcome['repairAttempts'] ?? 0)
            );
            $schemaContext = FolioSchemaService::buildSchemaContext($generationPrompt);
            return self::withAskEvidence(
                self::buildExploratoryRecoveryResponse($context, $outcome, $reason),
                [
                    'modelName' => self::getAiModel(),
                    'promptVersion' => self::LEGACY_PROMPT_VERSION,
                    'schemaMetadata' => self::schemaMetadata(self::buildSchemaTelemetry($schemaContext)),
                ]
            );
        }

        $primary = self::decorateExploratoryResponse($outcome['result'], $reason);
        $primary = self::decorateValidatedExploratoryResult(
            $primary,
            $assumptions,
            (int)$outcome['repairAttempts']
        );
        $schemaContext = FolioSchemaService::buildSchemaContext($generationPrompt);
        $primary = self::withAskEvidence($primary, [
            'modelName' => self::getAiModel(),
            'promptVersion' => self::LEGACY_PROMPT_VERSION,
            'schemaMetadata' => self::schemaMetadata(self::buildSchemaTelemetry($schemaContext)),
            'compilerVersion' => $primary['compilerVersion'] ?? null,
        ]);

        self::logRouteSelection('exploratory_legacy_freeform', $reason, [
            'query' => [],
        ]);
        self::logNlTelemetry('nl2sql.exploratory_notice_attached', [
            'route' => 'exploratory_legacy_freeform',
            'routeReason' => $reason,
            'promptFingerprint' => self::fingerprintPrompt($rawQuestion),
            'dataSource' => $primary['dataSource'] ?? 'folio',
            'mode' => 'exploratory',
        ]);

        return $primary;
    }

    private static function validateCompiledExploratoryFallback(
        array $result,
        array $contract,
        array $resolvedFilters = []
    ): array
    {
        $sql = (string)($result['sql'] ?? '');
        SqlBuilderService::validateSafety($sql);
        SqlBuilderService::validateTablePolicy($sql);
        self::validateTableReferences($sql);

        $semanticValidation = ExploratorySqlSemanticValidatorService::validate($sql, $contract);
        if (($semanticValidation['status'] ?? null) !== 'validated') {
            throw new ExploratorySqlValidationException(
                'semantic_conformance',
                'semantic_coverage_gap',
                $sql,
                true,
                'The deterministic exploratory fallback did not satisfy its semantic contract.',
                null,
                is_array($semanticValidation['violations'] ?? null) ? $semanticValidation['violations'] : []
            );
        }
        self::validateResolvedReferenceSql($sql, $resolvedFilters);

        $result['semanticContractApplicable'] = true;
        $result['semanticValidation'] = $semanticValidation;
        return $result;
    }

    private static function decorateValidatedExploratoryResult(
        array $result,
        array $assumptions,
        int $repairAttempts
    ): array {
        $result['assumptions'] = $assumptions;
        $result['repairAttempts'] = $repairAttempts;
        $result['validationSummary'] = [
            'status' => 'validated',
            'repairAttempts' => $repairAttempts,
            'message' => $repairAttempts > 0
                ? 'SQL passed validation after ' . $repairAttempts . ' automatic repair attempt(s).'
                : 'The initial SQL candidate passed validation.',
        ];

        return $result;
    }

    private static function buildExploratoryRecoveryResponse(
        array $context,
        array $outcome,
        string $reason
    ): array {
        $response = [
            'mode' => 'exploratory',
            'exploratory' => true,
            'route' => 'exploratory_recovery',
            'routeReason' => $reason,
            'needsClarification' => false,
            'repairAttempts' => (int)($outcome['repairAttempts'] ?? 0),
            'assumptions' => $context['assumptions'] ?? [],
            'suggestions' => $outcome['suggestions'] ?? [],
            'unmetRequirements' => is_array($outcome['unmetRequirements'] ?? null)
                ? $outcome['unmetRequirements']
                : [],
            'validationSummary' => [
                'status' => 'exhausted',
                'repairAttempts' => (int)($outcome['repairAttempts'] ?? 0),
                'validatorStage' => $outcome['validatorStage'] ?? 'response_validation',
                'failureCategory' => $outcome['failureCategory'] ?? 'validation_failure',
                'message' => 'I could not build a report I could safely run. Your request is preserved, and you can retry it or adjust one part of the question.',
            ],
            'recoveryContext' => [
                'originalQuestion' => (string)($context['originalQuestion'] ?? ''),
            ],
            'repeatabilityWarning' => self::getExploratoryRepeatabilityWarning(),
            'exploratoryNotice' => self::buildExploratoryNotice($reason),
            '_askEvidence' => is_array($outcome['_askEvidence'] ?? null)
                ? $outcome['_askEvidence']
                : [],
        ];

        $attemptedPlan = trim((string)($context['attemptedPlan'] ?? ''));
        if ($attemptedPlan !== ''
            && ($context['attemptedPlanProvenance'] ?? null) === 'server_defaults'
        ) {
            $response['attemptedPlan'] = $attemptedPlan;
            $response['_attemptedPlanProvenance'] = 'server_defaults';
        }

        return $response;
    }

    private static function decorateExploratoryResponse(array $primary, string $reason): array
    {
        $primary['mode'] = 'exploratory';
        $primary['exploratory'] = true;
        $primary['repeatabilityWarning'] = self::getExploratoryRepeatabilityWarning();
        $primary['route'] = 'exploratory_legacy_freeform';
        $primary['routeReason'] = $reason;
        $primary['needsClarification'] = false;
        $primary['exploratoryNotice'] = self::buildExploratoryNotice($reason);

        return $primary;
    }

    private static function buildExploratoryNotice(string $reason): array
    {
        return [
            'title' => 'AI-assisted query',
            'message' => 'I could not match this request to a verified report pattern, so I built a best-effort query with the assumptions shown here.',
            'detail' => 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.',
            'reason' => $reason,
        ];
    }

    private static function getExploratoryRepeatabilityWarning(): string
    {
        return 'This AI-assisted query may vary between runs until this request type is reviewed and promoted to a verified report pattern.';
    }

    /**
     * @return array<int, string>
     */
    private static function loadAcceptedClarificationKeys($userId): array
    {
        if ($userId === null || $userId === '') {
            return [];
        }

        try {
            $rows = Yii::$app->db->createCommand(
                'SELECT DISTINCT clarification_key
                 FROM ai_clarification_events
                 WHERE user_id = :user_id
                   AND resolved_filter_json IS NOT NULL
                 ORDER BY clarification_key',
                [':user_id' => (int)$userId]
            )->queryColumn();
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $rows)));
    }

    /**
     * Generate index recommendations from query-history workload snapshots.
     *
     * @param array $snapshot
     * @return array
     * @throws \RuntimeException
     */
    public static function recommendIndexesFromHistory(array $snapshot)
    {
        $apiKey = self::getAiApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $model = self::getAiModel();
        $workloadPayload = [
            'generatedAt' => $snapshot['generatedAt'] ?? null,
            'windowDays' => $snapshot['windowDays'] ?? null,
            'workload' => [
                'logsAnalyzed' => $snapshot['workload']['logsAnalyzed'] ?? 0,
                'eligibleLogs' => $snapshot['workload']['eligibleLogs'] ?? 0,
                'uniqueQueryPatterns' => $snapshot['workload']['uniqueQueryPatterns'] ?? 0,
                'tables' => $snapshot['workload']['tables'] ?? [],
                'queryPatterns' => $snapshot['workload']['queryPatterns'] ?? [],
            ],
            'existingIndexesByTable' => $snapshot['existingIndexesByTable'] ?? [],
        ];

        $promptFingerprint = substr(hash('sha256', json_encode($workloadPayload)), 0, 16);
        $systemPrompt = self::buildIndexRecommendationSystemPrompt();

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";
        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [[
                            'text' => "WORKLOAD_SNAPSHOT_JSON:\n" . json_encode(
                                $workloadPayload,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                            ),
                        ]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 4096,
                    'responseMimeType' => 'application/json',
                ],
            ],
            'index_recommend.generate'
        );

        $response = $requestResult['response'];
        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $parsed = json_decode(trim((string)$text), true);
        if (!is_array($parsed)) {
            $fragment = self::extractJsonObject((string)$text);
            if ($fragment !== null) {
                $parsed = json_decode($fragment, true);
            }
        }
        if (!is_array($parsed)) {
            throw new \RuntimeException('Model returned malformed index recommendation JSON.');
        }

        $recommendations = $parsed['recommendations'] ?? [];
        if (!is_array($recommendations)) {
            $recommendations = [];
        }

        $notes = $parsed['notes'] ?? [];
        if (!is_array($notes)) {
            $notes = [];
        }

        self::logNlTelemetry('nl2sql.index_recommendation', [
            'model' => $model,
            'promptVersion' => self::INDEX_RECOMMENDER_PROMPT_VERSION,
            'promptFingerprint' => $promptFingerprint,
            'finishReason' => $finishReason,
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            'recommendationCount' => count($recommendations),
            'tableCount' => count($workloadPayload['workload']['tables'] ?? []),
            'queryPatternCount' => count($workloadPayload['workload']['queryPatterns'] ?? []),
        ]);

        return [
            'summary' => trim((string)($parsed['summary'] ?? '')),
            'recommendations' => $recommendations,
            'notes' => $notes,
            'model' => $model,
            'promptVersion' => self::INDEX_RECOMMENDER_PROMPT_VERSION,
            'route' => 'index_recommender',
        ];
    }

    /**
     * Generate follow-up NL prompts that expand on the original request.
     *
     * @param string $prompt
     * @param string $sql
     * @param string $explanation
     * @param string|null $campus
     * @return array
     */
    public static function suggestFollowUpQueries($prompt, $sql, $explanation = '', $campus = null)
    {
        $apiKey = self::getAiApiKey();
        if (empty($apiKey)) {
            return [];
        }

        $model = self::getAiModel();

        $payload = [
            'prompt' => trim((string)$prompt),
            'sql' => trim((string)$sql),
            'explanation' => trim((string)$explanation),
            'campus' => $campus,
        ];

        $systemPrompt = self::buildFollowUpSuggestionPrompt();
        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [[
                            'text' => "FOLLOW_UP_INPUT_JSON:\n" . json_encode(
                                $payload,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                            ),
                        ]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 512,
                    'responseMimeType' => 'application/json',
                ],
            ],
            'nl2sql.followup_suggestions'
        );

        $response = $requestResult['response'];
        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $parsed = json_decode(trim((string)$text), true);
        if (!is_array($parsed)) {
            $fragment = self::extractJsonObject((string)$text);
            if ($fragment !== null) {
                $parsed = json_decode($fragment, true);
            }
        }

        if (!is_array($parsed)) {
            self::logNlTelemetry('nl2sql.followup_suggestions_parse_error', [
                'model' => $model,
                'promptVersion' => self::FOLLOW_UP_SUGGESTION_PROMPT_VERSION,
                'promptFingerprint' => self::fingerprintPrompt((string)$prompt),
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ], true);

            return self::sanitizeFollowUpSuggestions(
                self::buildFallbackFollowUpSuggestions((string)$prompt, $campus),
                (string)$prompt
            );
        }

        $suggestions = $parsed['suggestions'] ?? [];
        if (!is_array($suggestions)) {
            $suggestions = [];
        }

        $suggestions = self::sanitizeFollowUpSuggestions($suggestions, (string)$prompt);
        if (count($suggestions) < 3) {
            $fallback = self::buildFallbackFollowUpSuggestions((string)$prompt, $campus);
            $suggestions = self::sanitizeFollowUpSuggestions(
                array_merge($suggestions, $fallback),
                (string)$prompt
            );
        }

        self::logNlTelemetry('nl2sql.followup_suggestions_generated', [
            'model' => $model,
            'promptVersion' => self::FOLLOW_UP_SUGGESTION_PROMPT_VERSION,
            'promptFingerprint' => self::fingerprintPrompt((string)$prompt),
            'finishReason' => $finishReason,
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            'suggestionCount' => count($suggestions),
        ]);

        return $suggestions;
    }

    /**
     * Ask the configured model to phrase a clarification from resolver evidence.
     * This method must never generate SQL; callers validate the returned JSON
     * against resolver-provided options before showing it to users.
     *
     * @param string $prompt
     * @param array<string, mixed> $resolverResponse
     * @return array<string, mixed>
     */
    public static function generateResolverClarificationJson(string $prompt, array $resolverResponse): array
    {
        $apiKey = self::getAiApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $model = self::getAiModel();
        $payload = [
            'originalPrompt' => trim($prompt),
            'resolverTrace' => array_values(array_filter($resolverResponse['resolverTrace'] ?? [], 'is_array')),
            'clarificationItems' => self::sanitizeResolverClarificationItemsForModel(
                $resolverResponse['clarificationItems'] ?? []
            ),
        ];

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";
        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => self::buildResolverClarificationPrompt()]],
                ],
                'contents' => [
                    [
                        'parts' => [[
                            'text' => "RESOLVER_EVIDENCE_JSON:\n" . json_encode(
                                $payload,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                            ),
                        ]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 1024,
                    'responseMimeType' => 'application/json',
                ],
            ],
            'nl2sql.resolver_clarification'
        );

        $response = $requestResult['response'];
        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed = json_decode(trim((string)$text), true);
        if (!is_array($parsed)) {
            $fragment = self::extractJsonObject((string)$text);
            if ($fragment !== null) {
                $parsed = json_decode($fragment, true);
            }
        }

        if (!is_array($parsed)) {
            self::logNlTelemetry('nl2sql.resolver_clarification_parse_error', [
                'model' => $model,
                'promptVersion' => self::RESOLVER_CLARIFICATION_PROMPT_VERSION,
                'promptFingerprint' => self::fingerprintPrompt($prompt),
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ], true);
            throw new \RuntimeException('Resolver clarification model returned invalid JSON.');
        }

        self::logNlTelemetry('nl2sql.resolver_clarification_generated', [
            'model' => $model,
            'promptVersion' => self::RESOLVER_CLARIFICATION_PROMPT_VERSION,
            'promptFingerprint' => self::fingerprintPrompt($prompt),
            'finishReason' => $finishReason,
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
        ]);

        return $parsed;
    }

    /**
     * Build the system prompt used for workload-driven index recommendations.
     *
     * @return string
     */
    private static function buildIndexRecommendationSystemPrompt()
    {
        return <<<PROMPT
You are a PostgreSQL performance advisor for a FOLIO reporting workload.

You are given:
1) Query history workload patterns (frequency + execution time + sample SQL).
2) Existing indexes by table.

Goal:
Recommend practical NEW indexes that are most likely to reduce execution time
for the observed workload.

Rules:
1. Recommend only indexes that do not already exist (same table + leading column sequence).
2. Prioritize high-impact patterns first (frequent and/or slow queries).
3. Use realistic PostgreSQL index types: btree by default, gin/gist only when justified.
4. Avoid recommending indexes on tiny lookup/value tables unless clearly justified.
5. Prefer composite indexes when multiple columns repeatedly appear together in JOIN/WHERE predicates.
6. Keep recommendations conservative: max 10 recommendations.
7. If workload is insufficient, return an empty recommendations array and explain why in notes.

Return ONLY JSON with this exact shape:
{
  "summary": "short plain-English summary",
  "recommendations": [
    {
      "table": "schema.table",
      "columns": ["column_a", "column_b"],
      "indexType": "btree",
      "confidence": "high|medium|low",
      "reason": "why this helps",
      "evidence": {
        "patternIds": ["Q001", "Q004"],
        "estimatedImpact": "high|medium|low"
      },
      "createIndexSql": "CREATE INDEX CONCURRENTLY ..."
    }
  ],
  "notes": ["optional caveats or follow-up checks"]
}
PROMPT;
    }

        /**
         * Build the system prompt used to generate follow-up NL suggestions.
         *
         * @return string
         */
        private static function buildFollowUpSuggestionPrompt()
        {
                return <<<PROMPT
You generate short follow-up natural-language report prompts for a library analytics user.

You are given:
1) The user's original question.
2) The SQL that was generated.
3) A brief explanation.
4) Optional campus context.

Return ONLY JSON with this shape:
{
    "suggestions": [
        "prompt 1",
        "prompt 2",
        "prompt 3",
        "prompt 4"
    ]
}

Rules:
1. Provide 3 to 5 suggestions.
2. Suggestions must be user-facing prompts in plain English (not SQL).
3. Keep each suggestion concise (around 6 to 18 words).
4. Make each suggestion distinct: trend, breakdown, anomaly, comparison, or drill-down.
5. Keep scope consistent with the original domain and campus context.
6. Do not repeat the original prompt verbatim.
7. Do not include markdown or extra keys.
PROMPT;
        }

    private static function buildResolverClarificationPrompt(): string
    {
        return <<<PROMPT
You write clarification questions for a library reporting user.

You are given resolver evidence that was produced before SQL generation:
1. The user's original prompt.
2. Resolver trace entries showing what local reference data and searchable report fields were checked.
3. Clarification items and allowed option ids.

Return ONLY JSON with this shape:
{
  "question": "one clear follow-up question",
  "message": "optional short explanation",
  "clarificationItems": [
    {
      "clarificationKey": "exact key from input",
      "question": "short question for this specific term",
      "options": [
        {"id": "exact option id from input"}
      ]
    }
  ]
}

Rules:
1. Do not generate SQL.
2. Do not invent tables, columns, filters, meanings, or option ids.
3. Use only the clarificationKey and option id values present in the resolver evidence.
4. If there are no options for an item, still ask what the term should mean and return an empty options array for that item.
5. Mention that the resolver checked local references/probe fields when useful, but keep the wording concise.
6. Do not include markdown or extra top-level keys.
PROMPT;
    }

    /**
     * @param mixed $items
     * @return array<int, array<string, mixed>>
     */
    private static function sanitizeResolverClarificationItemsForModel($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $options = [];
            foreach (($item['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $options[] = [
                    'id' => (string)($option['id'] ?? ''),
                    'label' => (string)($option['label'] ?? ''),
                    'description' => (string)($option['description'] ?? ''),
                    'resolvedFilter' => is_array($option['resolvedFilter'] ?? null) ? $option['resolvedFilter'] : null,
                ];
            }

            $sanitized[] = [
                'term' => (string)($item['term'] ?? ''),
                'clarificationKey' => (string)($item['clarificationKey'] ?? ''),
                'question' => (string)($item['question'] ?? ''),
                'reason' => (string)($item['reason'] ?? ''),
                'options' => $options,
            ];
        }

        return $sanitized;
    }

    /**
     * Generate SQL from a natural-language prompt.
     *
     * @param string $prompt User's natural language query description
     * @param bool $forceLegacy Internal control for deterministic fallback routing.
     * @param bool $forceIntent Internal control for shadow-mode intent execution.
     * @return array {sql: string, explanation: string, dataSource: string}
     * @throws \RuntimeException
     */
    public static function generateSql(
        $prompt,
        $campus = null,
        $forceLegacy = false,
        $forceIntent = false,
        $originalQuestion = null,
        array $resolvedFilters = []
    )
    {
        $originalQuestion = $originalQuestion === null
            ? (string)$prompt
            : (string)$originalQuestion;

        if ($forceLegacy && $forceIntent) {
            throw new \InvalidArgumentException('Cannot force both legacy and intent generation modes.');
        }

        $apiKey = self::getAiApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $model = self::getAiModel();

        if ($forceIntent && self::promptRequiresLegacyFreeform($originalQuestion)) {
            $fallback = self::generateSql(
                $prompt,
                $campus,
                true,
                false,
                $originalQuestion,
                $resolvedFilters
            );
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = 'structured_intent_unsupported_for_marc_source_records';
            return $fallback;
        }

        if ($forceIntent) {
            return self::generateSqlFromIntent(
                $prompt,
                $campus,
                $apiKey,
                $model,
                $originalQuestion,
                $resolvedFilters
            );
        }

        if (!$forceLegacy && self::isIntentModeEnabled() && !self::promptRequiresLegacyFreeform($originalQuestion)) {
            return self::generateSqlFromIntent(
                $prompt,
                $campus,
                $apiKey,
                $model,
                $originalQuestion,
                $resolvedFilters
            );
        }

        $schemaContext = FolioSchemaService::buildSchemaContext($prompt);
        $schemaTelemetry = self::buildSchemaTelemetry($schemaContext);
        $promptFingerprint = self::fingerprintPrompt($originalQuestion);

        // Load acqUnit codes from settings.json (campus full name → 2-letter abbreviation)
        // Maintained in backend/data/settings.json under "acqUnitCodes" — configurable
        $settingsPath = Yii::getAlias('@app/data/settings.json');
        $acqUnitCodes = [];
        if (file_exists($settingsPath)) {
            $settings = json_decode(file_get_contents($settingsPath), true);
            $acqUnitCodes = $settings['acqUnitCodes'] ?? [];
        }
        // Fallback defaults if settings file is missing or key not present
        if (empty($acqUnitCodes)) {
            $acqUnitCodes = [
                'Smith College'               => 'SC',
                'Amherst College'             => 'AC',
                'Mount Holyoke College'       => 'MH',
                'University Of Massachusetts' => 'UM',
                'Hampshire College'           => 'HC',
                'Five Colleges Collections'   => 'RP',
                'National Yiddish Book Center'=> 'YB',
            ];
        }

        // Build optional campus-scope rule (injected as Rule 17 in the system prompt)
        $campusRule = '';
        if ($campus && $campus !== 'All Colleges') {
            $safe = addslashes($campus);
            $acqCode = $acqUnitCodes[$campus] ?? strtoupper(substr($campus, 0, 2));
            $campusRule = "17. CAMPUS SCOPE — MANDATORY: The user's home institution is {$campus} (acquisitions unit code: {$acqCode}). EVERY query MUST be scoped to this campus unless the user explicitly asks about all colleges or a different campus. Choose the correct join path based on the query domain:

  a) INVENTORY / CIRCULATION (items, holdings, locations, loans): Join through the location hierarchy — inventory.location__t → inventory.loclibrary__t → inventory.loccampus__t (alias: camp) — then add WHERE LOWER(camp.name) = LOWER('{$safe}').

  b) FINANCE / ACQUISITIONS (invoices, purchase orders, vouchers, expense classes, fund distributions, vendor spending): Campus scope is via the ACQUISITIONS UNIT, NOT location. The join chain is: orders.po_line__t (alias: plt) → orders.purchase_order__t__acq_unit_ids (alias: potaui) ON potaui.id = plt.purchase_order_id → orders.acquisitions_unit__t (alias: au) ON au.id = potaui.acq_unit_ids AND au.name = '{$acqCode}'. For queries starting from invoice tables, the full path is: invoice.invoice_lines__t__fund_distributions → orders.po_line__t ON invoice fund distribution po_line_id = po_line id → orders.purchase_order__t__acq_unit_ids → orders.acquisitions_unit__t. Do not join invoice fund distribution id to po_line id. Aggregate line-level amounts (SUM of iltfd.total * iltfd.fund_distributions__value * 0.01), NOT invoice-header totals (inv.total).
  Standing orders are purchase orders where orders.purchase_order__t.order_type = 'Ongoing'. Do not filter orders.po_line__t.order_format or orders.po_line__t.payment_status for standing orders; order_format is the material/resource format, e.g. Physical Resource.
  IMPORTANT: acquisitions_unit__t.name stores 2-letter abbreviation codes (SC, AC, MH, UM, HC, RP, YB) — NOT full campus names. Use au.name = '{$acqCode}' (exact string match). Never use LOWER(au.name) = LOWER('Smith College') or any full-name comparison.

  NEVER skip campus filtering for finance/acquisitions queries. Do not omit the acquisitions unit join.
  System-wide reference data (material types, instance types, fund types, fiscal years, etc.) does NOT need campus filtering.
  Organization and interface reference-data listings do not require an artificial purchase-order campus path. When the user explicitly requests organization acquisition-unit scope, use the organization acquisition-unit bridge.";
        }

      $organizationAcquisitionUnitGuidance = self::buildOrganizationAcquisitionUnitGuidance();
      $referenceNameMatchingGuidance = self::buildReferenceNameMatchingGuidance($resolvedFilters);
      $legacyPromptFamilyGuidance = self::buildLegacyPromptFamilyGuidance($originalQuestion, $campus);
    $legacyPromptUserInput = self::buildLegacyPromptUserInput($prompt, $campus, $originalQuestion);

        $systemPrompt = <<<PROMPT
You are a PostgreSQL query generator for a FOLIO library management system.
The database uses LDLite (a lightweight version of MetaDB) with schema-qualified table names.

RULES:
1. Generate ONLY SELECT queries — never INSERT, UPDATE, DELETE, DROP, or ALTER.
2. Use EXACT table and column names from the schema below — do NOT invent columns.
3. Table names are schema-qualified (e.g. inventory.item__t, circulation.loan__t).
   Always use the full schema.table form. Schema names do NOT have a "folio_" prefix.
4. Qualify column references with table aliases, not the full schema.table name.
5. Use appropriate JOINs based on the foreign key relationships shown.
6. Add a LIMIT clause (default 100) unless the user asks for a specific number.
7. Use PostgreSQL-compatible syntax.
8. LDLite tables have flattened columns (no JSON "data" blobs). Use the column names directly.
   Nested JSON fields appear as double-underscore columns (e.g. metadata__created_date, status__name).
9. Create short aliases for tables (e.g. inventory.item__t AS ii, circulation.loan__t AS cl).
10. Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema below to resolve ambiguous terms.
    For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT an organization/vendor.
    This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
    (SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
    {$referenceNameMatchingGuidance}
    Always check the vocabulary section before choosing a table for user-mentioned entities.
11. For text/name comparisons, ALWAYS use case-insensitive matching with LOWER() on both sides
    or ILIKE operator. Never compare name columns with exact case (e.g. use LOWER(imt.name) = 'book'
    instead of imt.name = 'book'). Database values are often Title Case (e.g. 'Book', 'DVD').
12. For item location joins, ALWAYS use inventory.item__t.effective_location_id (NOT
    holdings_record__t.permanent_location_id). The effective location reflects the item's
    current/temporary location and is the correct column for circulation and item-level queries.
13. If the query references ONLY local supplementary tables (acrl_statistics, report_expense_allocations),
    generate MySQL-compatible SELECT and set DATA SOURCE to "local".
14. Otherwise set DATA SOURCE to "folio" and use PostgreSQL syntax.
15. NEVER use the PostgreSQL ? operator (JSONB key-exists). Our query layer treats ? as a bind-parameter
    placeholder and it causes a fatal syntax error. Instead use jsonb_exists(jsonb_val, 'key'),
    jsonb_typeof(), or jsonb_each(). The same applies to ?| and ?& — use jsonb_exists_any / jsonb_exists_all.
16. CRITICAL — COLUMN TYPE WARNINGS: Before writing any column expression, check the
    COLUMN TYPE WARNINGS & SAMPLE VALUES section below. Many columns that look like they should
    be JSONB are actually TEXT (stored JSON strings). Do NOT use ->, ->>, or @> on TEXT columns.
    If a column is marked as TEXT containing JSON, prefer an alternative table listed under PREFER,
    or cast explicitly with ::jsonb only as a last resort. Sample values listed for enum-like columns
    show the exact casing stored in the database — always match that casing (ILIKE or LOWER()).
18. PERFORMANCE — ORDER BY: Do NOT add ORDER BY unless the user explicitly requests sorted or
    ranked results (keywords: "top N", "highest", "lowest", "sorted by", "alphabetical").
    ORDER BY forces PostgreSQL to materialize and sort the ENTIRE result set before returning
    the first row — even with LIMIT 100 the planner must find and sort all matching rows first.
    On large datasets (10K+ rows) this adds massive overhead with no benefit to the user.
    OMIT ORDER BY for: listing queries, existence checks, missing-field reports, any general
    "show me records" query where the user did not ask for a specific order.
    KEEP ORDER BY only for: ranking queries (ORDER BY count DESC LIMIT 20), explicit top-N
    requests, or when the user specifically asks for a sorted result.
19. UUID TYPE CASTS — NEVER write ::uuid or ::text anywhere. MetaDB join columns are
    already compatible types. Explicit casts bypass PostgreSQL indexes and cause
    catastrophically slow full-table scans. Always write plain equality with no casts:
      ii.material_type_id      = imt.id
      ii.holdings_record_id    = hr.id
      hr.instance_id           = inst.id
      ii.effective_location_id = loc.id
      loc.library_id           = lib.id
      lib.campus_id            = camp.id
      cont.id                  = inst.id
      subj.id                  = inst.id
      iden.id                  = inst.id
      iden.identifiers__identifier_type_id = idt.id
    ::uuid and ::text are NEVER correct anywhere in JOIN ON conditions or WHERE clauses.
20. MONETARY / FINANCIAL COLUMNS — MANDATORY: Format ALL money amounts as USD currency.
    Finance tables store amounts as NUMERIC with many decimal places (e.g. 1548302.2100000000000000).
    ALWAYS use TO_CHAR to format as a USD dollar amount with comma separators:
      TO_CHAR(inv.total, 'FM$999,999,999,990.00')          -- column directly
      TO_CHAR(SUM(inv.amount), 'FM$999,999,999,990.00')    -- aggregate
      TO_CHAR(ROUND(SUM(inv.total), 2), 'FM$999,999,999,990.00')  -- if subquery
    Use TO_CHAR only in the outermost SELECT (not in WHERE, JOIN, or subquery filters).
    This applies to any column from finance.*, invoice.*, acq_unit.*, or any column whose name
    contains: total, amount, price, cost, spent, encumbered, expenditure, budget, balance.
    NEVER return raw unformatted monetary values to the user.
21. SINGLE STATEMENT — Return exactly one SELECT statement for the user's request.
    Never output multiple semicolon-delimited statements, even if the user asks for "also"
    or multiple follow-ups in one prompt. If needed, combine logic into one query.
{$campusRule}
{$organizationAcquisitionUnitGuidance}
{$legacyPromptFamilyGuidance}

SCHEMA:
{$schemaContext}

RESPONSE FORMAT:
Return exactly one SQL statement in a ```sql code block, followed by a brief plain-English explanation
of what the query does and which tables/joins are used.
Then add a final line exactly like: DATA SOURCE: folio OR DATA SOURCE: local
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => $legacyPromptUserInput]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 16384,
                ],
            ],
            'nl2sql.generate'
        );
        $response = $requestResult['response'];

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            self::logNlTelemetry('nl2sql.max_tokens', [
                'route' => 'legacy_freeform',
                'model' => $model,
                'promptVersion' => self::LEGACY_PROMPT_VERSION,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry, true);
            throw new \RuntimeException(
                'The AI response was truncated because the query is too complex. '
                . 'Try simplifying your request or asking for fewer fields.'
            );
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        try {
            $parsed = self::parseResponse($text);
        } catch (\Throwable $e) {
            self::logValidationFailure('legacy_sql_parse', [
                'route' => 'legacy_freeform',
                'model' => $model,
                'promptVersion' => self::LEGACY_PROMPT_VERSION,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'error' => $e->getMessage(),
            ] + $schemaTelemetry);
            throw $e;
        }

        if (!isset($parsed['route'])) {
            $parsed['route'] = 'legacy_freeform';
        }
        if (!isset($parsed['routeReason'])) {
            $parsed['routeReason'] = $forceLegacy ? 'forced_legacy_mode' : 'intent_mode_disabled';
        }
        self::validateResolvedReferenceSql((string)($parsed['sql'] ?? ''), $resolvedFilters);

        self::logNlTelemetry('nl2sql.generated', [
            'route' => $parsed['route'],
            'routeReason' => $parsed['routeReason'],
            'model' => $model,
            'promptVersion' => self::LEGACY_PROMPT_VERSION,
            'promptFingerprint' => $promptFingerprint,
            'finishReason' => $finishReason,
            'dataSource' => $parsed['dataSource'] ?? 'folio',
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
        ] + $schemaTelemetry);

        $parsed = self::withAskEvidence($parsed, [
            'modelName' => $model,
            'promptVersion' => self::LEGACY_PROMPT_VERSION,
            'schemaMetadata' => self::schemaMetadata($schemaTelemetry),
        ]);
        return $parsed;
    }

    /**
     * Feature flag gate for structured intent mode.
     */
    private static function isIntentModeEnabled()
    {
        return !empty(Yii::$app->params['nl2sqlIntentMode']);
    }

    /**
     * Generate SQL through structured QueryIntent output.
     *
     * This path is guarded by a feature flag and intentionally keeps the
     * legacy freeform SQL path unchanged when disabled.
     *
     * @param string $prompt
     * @param string|null $campus
     * @param string $apiKey
     * @param string $model
     * @return array {sql: string, explanation: string, dataSource: string}
     * @throws \RuntimeException
     */
    private static function generateSqlFromIntent(
        $prompt,
        $campus,
        $apiKey,
        $model,
        string $originalQuestion,
        array $resolvedFilters = []
    )
    {
        $schemaContext = FolioSchemaService::buildSchemaContext($prompt);
        $schemaTelemetry = self::buildSchemaTelemetry($schemaContext);
        $promptFingerprint = self::fingerprintPrompt($originalQuestion);
        $requestContext = self::buildIntentRequestContext(
            $prompt,
            $campus,
            $schemaContext,
            $originalQuestion
        );
        $queryFamily = $requestContext['queryFamily'];
        $systemPrompt = $requestContext['systemPrompt'];
        $promptVersion = $requestContext['promptVersion'];

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 8192,
                    'responseMimeType' => 'application/json',
                ],
            ],
            'nl2sql.intent'
        );
        $response = $requestResult['response'];

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            self::logNlTelemetry('nl2sql.max_tokens', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry, true);
            throw new \RuntimeException(
                'The AI intent response was truncated because the query is too complex. '
                . 'Try simplifying your request or asking for fewer fields.'
            );
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        try {
            $intent = self::parseIntentResponse($text);
        } catch (\Throwable $e) {
            self::logValidationFailure('intent_json_parse', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'error' => $e->getMessage(),
            ] + $schemaTelemetry);
            throw $e;
        }

        $familyResponse = self::maybeRouteQueryFamilyIntentResponse(
            $intent,
            $queryFamily,
            $prompt,
            $campus,
            [
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry,
            null,
            null,
            $originalQuestion,
            $resolvedFilters
        );
        if ($familyResponse !== null) {
            self::validateResolvedReferenceResult($familyResponse, $resolvedFilters);
            return $familyResponse;
        }

        $validation = QueryIntentService::validateIntent($intent);
        if (empty($validation['valid'])) {
            $first = $validation['errors'][0] ?? [];
            $path = $first['path'] ?? 'intent';
            $message = $first['message'] ?? 'Unknown validation error.';
            $reason = 'intent_contract_failed';
            self::logValidationFailure('intent_contract', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'errorCount' => count($validation['errors'] ?? []),
                'firstErrorPath' => $path,
                'firstErrorMessage' => $message,
            ] + $schemaTelemetry);
            self::logRouteSelection('legacy_fallback', $reason . ": {$path}: {$message}", $intent);
            if (empty($resolvedFilters)) {
                $fallback = self::generateSql($prompt, $campus, true, false, $originalQuestion);
            } else {
                $fallback = self::generateSql(
                    $prompt,
                    $campus,
                    true,
                    false,
                    $originalQuestion,
                    $resolvedFilters
                );
            }
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $reason;
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry);
            return $fallback;
        }

        $normalizedIntent = $validation['normalizedIntent'];

        $capability = self::classifyIntentCapability($normalizedIntent);
        if (!$capability['supported']) {
            self::logRouteSelection('legacy_fallback', $capability['reason'], $normalizedIntent);
            $fallback = self::generateSql(
                $prompt,
                $campus,
                true,
                false,
                $originalQuestion,
                $resolvedFilters
            );
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $capability['reason'];
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry);
            return $fallback;
        }

        try {
            $queryDef = QueryIntentService::toQueryDefinition($normalizedIntent);
            $built = SqlBuilderService::build($queryDef);
        } catch (QueryIntentValidationException $e) {
            self::logValidationFailure('intent_to_query_definition', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'error' => $e->getMessage(),
            ] + $schemaTelemetry);
            throw new \RuntimeException('Intent validation failed: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $reason = 'builder_conversion_failed';
            self::logRouteSelection('legacy_fallback', $reason . ': ' . $e->getMessage(), $normalizedIntent);
            $fallback = self::generateSql(
                $prompt,
                $campus,
                true,
                false,
                $originalQuestion,
                $resolvedFilters
            );
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $reason;
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry);
            return $fallback;
        }

        $sql = self::inlineParams($built['sql'] ?? '', $built['params'] ?? []);
        $sql = self::normalizeGeneratedSql($sql);
        $sql = self::repairOnlyHoldingLocationAliasLeaks($sql);
        $sql = self::repairResolvedLocationPredicateMisuse($sql);
        self::validateNoOnlyHoldingLocationAliasLeaks($sql);
        self::validateNoResolvedLocationPredicateMisuse($sql);

        SqlBuilderService::validateSafety($sql);
        SqlBuilderService::validateTablePolicy($sql);
        self::validateTableReferences($sql);
        self::validateResolvedReferenceSql($sql, $resolvedFilters);

        $dataSource = 'folio';
        if (preg_match('/\b(acrl_statistics|report_expense_allocations)\b/i', $sql)) {
            $dataSource = 'local';
        }

        $tables = $normalizedIntent['query']['tables'] ?? [];
        $explanation = 'Generated from structured intent mode.';
        if (!empty($tables)) {
            $explanation .= ' Tables: ' . implode(', ', $tables) . '.';
        }

        self::logRouteSelection('builder_intent', 'intent_supported', $normalizedIntent);

        self::logNlTelemetry('nl2sql.generated', [
            'route' => 'builder_intent',
            'routeReason' => 'intent_supported',
            'model' => $model,
            'promptVersion' => $promptVersion,
            'promptFingerprint' => $promptFingerprint,
            'finishReason' => $finishReason,
            'dataSource' => $dataSource,
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
        ] + $schemaTelemetry);

        return [
            'sql' => $sql,
            'explanation' => $explanation,
            'dataSource' => $dataSource,
            'route' => 'builder_intent',
            'routeReason' => 'intent_supported',
            '_askEvidence' => [
                'queryFamily' => null,
                'modelName' => $model,
                'promptVersion' => $promptVersion,
                'schemaMetadata' => self::schemaMetadata($schemaTelemetry),
            ],
        ];
    }

    private static function buildIntentRequestContext(
        $prompt,
        $campus,
        string $schemaContext,
        ?string $originalQuestion = null
    ): array
    {
        $routingQuestion = $originalQuestion === null ? (string)$prompt : $originalQuestion;
        $queryFamily = self::resolvePromptQueryFamily($routingQuestion, $campus);
        $systemPrompt = $queryFamily !== null
            ? self::buildQueryFamilySlotSystemPrompt($queryFamily['familyKey'], $campus)
            : self::buildIntentSystemPrompt($schemaContext, $campus);
        $promptVersion = $queryFamily !== null
            ? self::FAMILY_SLOT_PROMPT_VERSION
            : self::INTENT_PROMPT_VERSION;

        return [
            'queryFamily' => $queryFamily,
            'systemPrompt' => $systemPrompt,
            'promptVersion' => $promptVersion,
        ];
    }

    /**
     * Name-matching guidance for library, location, and material references.
     *
     * Wildcard matching is the right default when the model has to guess at a
     * stored name, but once the reference resolver has supplied authoritative
     * values the generated SQL must use them verbatim: a wildcard predicate
     * cannot be checked against the resolved value set and is rejected by
     * ResolvedReferenceSqlValidatorService.
     *
     * @param array<int, array<string, mixed>> $resolvedFilters
     */
    private static function buildReferenceNameMatchingGuidance(array $resolvedFilters): string
    {
        if ($resolvedFilters === []) {
            return "When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%') since names are stored\n"
                . "    with campus prefixes (e.g. 'SC Neilson Library'). See the Location Naming Schema section for details.";
        }

        return "Resolved reference filters are supplied with this request. Use each resolved value exactly as supplied\n"
            . "    in a plain equality or IN predicate (e.g. lib.name = 'SC Neilson Library'), never a wildcard ILIKE\n"
            . "    pattern and never a shortened form. The names are already complete, including the campus prefix.\n"
            . "    Write the report as one single SELECT statement and put every resolved filter in its top-level WHERE\n"
            . "    clause, comparing the reference table's own name column. Join the reference table to reach it — for\n"
            . "    material types write JOIN inventory.material_type__t AS mt ON mt.id = ii.material_type_id and then\n"
            . "    mt.name IN (...) in WHERE. Never filter through a subquery such as\n"
            . "    ii.material_type_id IN (SELECT id FROM inventory.material_type__t WHERE ...).\n"
            . "    Do not use a WITH (CTE) clause, UNION, or any subquery inside WHERE. Get per-item counts and\n"
            . "    circulation measures with LEFT JOIN plus GROUP BY in that same statement instead.";
    }

    private static function buildLegacyPromptFamilyGuidance($prompt, $campus = null): string
    {
        $queryFamily = self::resolvePromptQueryFamily($prompt, $campus);
        $familyKey = trim((string)($queryFamily['familyKey'] ?? ''));
        if ($familyKey !== 'inventory_collection_age') {
            return '';
        }

        return <<<GUIDANCE

PROMPT-SPECIFIC GUIDANCE:
- For collection-age prompts, compute age from bibliographic publication year, not from record-created, status, or cataloging timestamps.
- Use the holdings-to-instance publication path: inventory.item__t -> inventory.holdings_record__t -> inventory.instance__t -> inventory.instance__t__publication and read publication__date_of_publication when it starts with a 4-digit year.
- Never use metadata__created_date, status__date, or cataloged_date as the age source for collection-age reports.
- For collection-age prompts with library or collection scope, join inventory.item__t.effective_location_id -> inventory.location__t -> inventory.loclibrary__t -> inventory.loccampus__t and apply separate library-name and location-name filters instead of collapsing both concepts into one inventory.location__t keyword match.
GUIDANCE;
    }

    private static function buildOrganizationAcquisitionUnitGuidance(): string
    {
        return <<<'GUIDANCE'
ORGANIZATION RELATIONSHIPS — MANDATORY WHEN APPLICABLE:
- Reach organizations.interfaces__t through organizations.organizations__t__interfaces: the bridge id is the organization ID and its interfaces column is the interface ID.
- Scope an organization by acquisition unit through organizations.organizations__t__acq_unit_ids, then join its acq_unit_ids column to orders.acquisitions_unit__t.id.
- Do not join an organization ID directly to organizations.interfaces__t.id.
- Do not substitute organizations.organizations__t__accounts__acq_unit_ids; that bridge is account-level.
- orders.purchase_order__t__acq_unit_ids.id is the purchase order ID, never an organization ID. It is valid for order-domain reports when joined to orders.purchase_order__t.id and the purchase order vendor joins to the organization.
- Stored acquisition-unit codes use exact equality with canonical casing, for example au.name = 'AC'. Do not use wildcard matching.
- Organization and interface reference-data listings do not require an artificial purchase-order campus path.
GUIDANCE;
    }

    private static function semanticContractQuestion(
        string $rawQuestion,
        string $generationPrompt
    ): string {
        $trustedFollowUpPrefix = 'This is a follow-up request to a previously generated library report.';
        return strpos(ltrim($generationPrompt), $trustedFollowUpPrefix) === 0
            ? $generationPrompt
            : $rawQuestion;
    }

    private static function buildLegacyPromptUserInput(
        $prompt,
        $campus = null,
        ?string $originalQuestion = null
    ): string
    {
        $prompt = trim((string)$prompt);
        if ($prompt === '') {
            return $prompt;
        }

        $routingQuestion = $originalQuestion === null ? $prompt : $originalQuestion;
        $queryFamily = self::resolvePromptQueryFamily($routingQuestion, $campus);
        $familyKey = trim((string)($queryFamily['familyKey'] ?? ''));
        if ($familyKey !== 'inventory_collection_age') {
            return $prompt;
        }

        $library = self::extractCollectionAgeLibraryScope($routingQuestion);
        $location = self::extractCollectionAgeLocationScope($routingQuestion, $library);

        $lines = [
            $prompt,
            '',
            'Interpretation constraints for this request:',
            'Compute average age in years from bibliographic publication year using inventory.instance__t__publication.publication__date_of_publication.',
            'Do not use metadata__created_date, status__date, or cataloged_date as the age source.',
            'Use the age join path inventory.item__t -> inventory.holdings_record__t -> inventory.instance__t -> inventory.instance__t__publication.',
            'Use the scope join path inventory.item__t.effective_location_id -> inventory.location__t -> inventory.loclibrary__t -> inventory.loccampus__t.',
            'Apply separate library and location filters instead of merging both concepts into a single inventory.location__t keyword match.',
        ];

        if ($library !== '') {
            $lines[] = 'Library scope: ' . $library;
            $lines[] = "Use a library predicate that preserves the full phrase '%{$library}%' instead of shortening it to a generic keyword match.";
        }

        if ($location !== '') {
            $lines[] = 'Location scope: ' . $location;
            $lines[] = "Use a location predicate that preserves the full phrase '%{$location}%' instead of shortening it to a generic keyword match.";
        }

        return implode("\n", $lines);
    }

    private static function maybeRouteQueryFamilyIntentResponse(
        array $intent,
        ?array $queryFamily,
        $prompt,
        $campus,
        array $telemetryContext,
        $familyResponseBuilder = null,
        $legacyFallbackFactory = null,
        ?string $originalQuestion = null,
        array $resolvedFilters = []
    ): ?array {
        if ($queryFamily === null) {
            return null;
        }

        $originalQuestion = $originalQuestion === null ? (string)$prompt : $originalQuestion;
        $expectedFamilyKey = trim((string)($queryFamily['familyKey'] ?? ''));
        $returnedFamilyKey = trim((string)($intent['familyKey'] ?? ''));
        if ($expectedFamilyKey !== '' && $returnedFamilyKey !== $expectedFamilyKey) {
            $mismatchTelemetryContext = self::withSlotProvenanceTelemetry(
                $telemetryContext,
                self::buildInitialFamilySlotProvenance(
                    $returnedFamilyKey,
                    is_array($intent['slots'] ?? null) ? $intent['slots'] : []
                )
            );

            if ($legacyFallbackFactory === null) {
                $legacyFallbackFactory = function () use ($prompt, $campus, $originalQuestion, $resolvedFilters): array {
                    return self::generateSql(
                        $prompt,
                        $campus,
                        true,
                        false,
                        $originalQuestion,
                        $resolvedFilters
                    );
                };
            }

            $reason = 'family_contract_mismatch';
            self::logValidationFailure('family_contract_mismatch', [
                'route' => 'intent_json',
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                'expectedFamilyKey' => $expectedFamilyKey,
                'returnedFamilyKey' => $returnedFamilyKey === '' ? null : $returnedFamilyKey,
            ] + $mismatchTelemetryContext);

            $promptRecoveredResponse = self::buildPromptRecoveredQueryFamilyIntentResponse(
                $queryFamily,
                $prompt,
                $campus,
                $mismatchTelemetryContext,
                $intent,
                $originalQuestion,
                $resolvedFilters
            );
            if ($promptRecoveredResponse !== null) {
                return $promptRecoveredResponse;
            }

            self::guardCoveredFamilyFallback(
                $expectedFamilyKey,
                $reason,
                $mismatchTelemetryContext
            );

            self::logRouteSelection('legacy_fallback', $reason . ':' . $expectedFamilyKey, [
                'query' => [],
            ]);

            $fallback = $legacyFallbackFactory();
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $reason;
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                'expectedFamilyKey' => $expectedFamilyKey,
                'returnedFamilyKey' => $returnedFamilyKey === '' ? null : $returnedFamilyKey,
            ] + $mismatchTelemetryContext);
            return $fallback;
        }

        if ($familyResponseBuilder === null) {
            $familyResponseBuilder = function (
                array $intent,
                array $queryFamily,
                $prompt,
                $campus,
                array $telemetryContext
            ) use ($originalQuestion, $resolvedFilters): array {
                return self::buildQueryFamilyIntentResponse(
                    $intent,
                    $queryFamily,
                    $prompt,
                    $campus,
                    $telemetryContext,
                    null,
                    null,
                    null,
                    $originalQuestion,
                    $resolvedFilters
                );
            };
        }

        return $familyResponseBuilder(
            $intent,
            $queryFamily,
            $prompt,
            $campus,
            $telemetryContext
        );
    }

    private static function buildPromptRecoveredQueryFamilyIntentResponse(
        array $queryFamily,
        $prompt,
        $campus,
        array $telemetryContext,
        array $sourceIntent = [],
        ?string $originalQuestion = null,
        array $resolvedFilters = []
    ): ?array {
        $originalQuestion = $originalQuestion === null ? (string)$prompt : $originalQuestion;
        $expectedFamilyKey = trim((string)($queryFamily['familyKey'] ?? ''));
        if ($expectedFamilyKey !== 'inventory_collection_age') {
            if ($expectedFamilyKey === 'inventory_library_location_listing' && self::promptExplicitlyRequestsOnlyHoldingLocation($originalQuestion)) {
                try {
                    return self::buildQueryFamilyIntentResponse(
                        self::rebuildInventoryListingIntentForPromptOnlyMode(
                            $queryFamily,
                            $sourceIntent,
                            $originalQuestion
                        ),
                        $queryFamily,
                        $prompt,
                        $campus,
                        $telemetryContext,
                        null,
                        null,
                        null,
                        $originalQuestion,
                        $resolvedFilters
                    );
                } catch (
                    CanonicalLaneFallbackException
                    | \app\exceptions\PolicyViolationException
                    | DatabaseQueryCancelledException
                    | ExploratorySqlValidationException $e
                ) {
                    throw $e;
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    if (self::isHardCanonicalFailure($e)) {
                        throw $e;
                    }
                    self::logValidationFailure('family_contract_prompt_recovery', [
                        'route' => 'intent_json',
                        'model' => $telemetryContext['model'] ?? null,
                        'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                        'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                        'finishReason' => $telemetryContext['finishReason'] ?? null,
                        'attempts' => $telemetryContext['attempts'] ?? null,
                        'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                        'expectedFamilyKey' => $expectedFamilyKey,
                        'error' => $e->getMessage(),
                    ] + $telemetryContext);
                }
            }

            return null;
        }

        try {
            return self::buildQueryFamilyIntentResponse(
                [
                    'familyKey' => $expectedFamilyKey,
                    'slots' => [
                        'requested_outputs' => ['average_age_years'],
                    ],
                ],
                $queryFamily,
                $prompt,
                $campus,
                $telemetryContext,
                null,
                null,
                null,
                $originalQuestion,
                $resolvedFilters
            );
        } catch (
            CanonicalLaneFallbackException
            | \app\exceptions\PolicyViolationException
            | DatabaseQueryCancelledException
            | ExploratorySqlValidationException $e
        ) {
            throw $e;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            if (self::isHardCanonicalFailure($e)) {
                throw $e;
            }
            self::logValidationFailure('family_contract_prompt_recovery', [
                'route' => 'intent_json',
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                'expectedFamilyKey' => $expectedFamilyKey,
                'error' => $e->getMessage(),
            ] + $telemetryContext);
        }

        return null;
    }

    private static function rebuildInventoryListingIntentForPromptOnlyMode(
        array $queryFamily,
        array $sourceIntent,
        string $prompt
    ): array {
        $familyKey = trim((string)($queryFamily['familyKey'] ?? 'inventory_library_location_listing'));
        $sourceSlots = is_array($sourceIntent['slots'] ?? null) ? $sourceIntent['slots'] : [];
        $slots = [
            'match_policy' => QueryFamilySlotService::DEFAULT_MATCH_POLICY,
            'requested_outputs' => ['title'],
        ];

        foreach (['campus', 'library', 'location', 'location_code', 'only_holding_location'] as $slotName) {
            if (!array_key_exists($slotName, $sourceSlots)) {
                continue;
            }

            $slots[$slotName] = $sourceSlots[$slotName];
        }

        if (array_key_exists('match_policy', $sourceSlots) && is_string($sourceSlots['match_policy'])) {
            $slots['match_policy'] = trim((string)$sourceSlots['match_policy']);
        }

        $requestedOutputs = is_array($sourceSlots['requested_outputs'] ?? null)
            ? $sourceSlots['requested_outputs']
            : [];
        $filteredOutputs = self::filterInventoryListingOutputsFromSource($requestedOutputs);
        if ($filteredOutputs !== []) {
            $slots['requested_outputs'] = $filteredOutputs;
        }

        return [
            'familyKey' => $familyKey,
            'slots' => $slots,
        ];
    }

    private static function filterInventoryListingOutputsFromSource(array $requestedOutputs): array
    {
        $allowedOutputs = [
            'author',
            'barcode',
            'call_number',
            'contributor_name',
            'instance_hrid',
            'instance_number',
            'pub_date',
            'publication_date',
            'title',
        ];

        $outputs = [];
        foreach ($requestedOutputs as $outputField) {
            $normalized = trim((string)$outputField);
            if ($normalized === '') {
                continue;
            }
            if (!in_array($normalized, $allowedOutputs, true)) {
                continue;
            }
            if (!in_array($normalized, $outputs, true)) {
                $outputs[] = $normalized;
            }
        }

        return $outputs;
    }

    private static function guardCoveredFamilyFallback(
        string $familyKey,
        string $failureReason,
        array $telemetryContext,
        ?\Throwable $error = null,
        array $candidateResult = []
    ): void {
        if (!empty(Yii::$app->params['nl2sqlForceLegacy'])) {
            return;
        }

        self::logValidationFailure('family_fallback_guard', [
            'route' => 'guarded_failure',
            'routeReason' => $failureReason,
            'familyKey' => $familyKey === '' ? null : $familyKey,
            'forceLegacyEnabled' => false,
            'error' => $error ? $error->getMessage() : null,
        ] + $telemetryContext);

        if (self::isTwoLaneEnabled()) {
            throw new CanonicalLaneFallbackException(
                $familyKey,
                self::canonicalFallbackReason($failureReason),
                $candidateResult,
                $error
            );
        }

        throw new \RuntimeException(
            self::buildCoveredFamilyFallbackGuardMessage($familyKey, $failureReason)
        );
    }

    private static function isTwoLaneEnabled(): bool
    {
        return !array_key_exists('nl2sqlTwoLaneEnabled', Yii::$app->params)
            || (bool)Yii::$app->params['nl2sqlTwoLaneEnabled'];
    }

    private static function canonicalFallbackReason(string $failureReason): string
    {
        $reasons = [
            'family_compiler_failed' => 'canonical_compiler_failed',
            'family_contract_mismatch' => 'canonical_family_contract_mismatch',
            'missing_required_slot' => 'canonical_missing_required_slot',
            'reference_not_representable' => 'canonical_reference_not_representable',
            'semantic_validation_failed' => 'canonical_semantic_validation_failed',
        ];
        return $reasons[$failureReason] ?? 'canonical_generation_failed';
    }

    private static function buildCoveredFamilyFallbackGuardMessage(string $familyKey, string $failureReason): string
    {
        $familyLabel = trim(str_replace('_', ' ', $familyKey));
        if ($familyLabel === '') {
            $familyLabel = 'deterministic report';
        }

        if ($failureReason === 'family_contract_mismatch') {
            return 'I could not safely interpret this ' . $familyLabel
                . ' request, and legacy fallback is disabled for this route to avoid incorrect results. '
                . 'Please restate the report scope and requested fields more explicitly.';
        }

        return 'I could not safely generate this ' . $familyLabel
            . ' request, and legacy fallback is disabled for this route to avoid incorrect results. '
            . 'Please try a more explicit request or contact an administrator if the problem persists.';
    }

    /**
     * Deterministic capability classifier for builder support.
     *
     * @param array $normalizedIntent
     * @return array {supported: bool, reason: string}
     */
    private static function classifyIntentCapability(array $normalizedIntent)
    {
        $query = $normalizedIntent['query'] ?? [];
        $tables = $query['tables'] ?? [];
        $joins = $query['joins'] ?? 'auto';

        // Phase 1 router keeps explicit joins on the fallback path.
        if (is_array($joins) && !empty($joins)) {
            return [
                'supported' => false,
                'reason' => 'explicit_joins_unsupported_in_builder_route',
            ];
        }

        // Cap very large multi-table plans for deterministic builder routing.
        if (is_array($tables) && count($tables) > 6) {
            return [
                'supported' => false,
                'reason' => 'too_many_tables_for_builder_route',
            ];
        }

        return [
            'supported' => true,
            'reason' => 'intent_supported',
        ];
    }

    /**
     * Record selected route for observability.
     *
     * @param string $route
     * @param string $reason
     * @param array $normalizedIntent
     */
    private static function logRouteSelection($route, $reason, array $normalizedIntent)
    {
        $query = $normalizedIntent['query'] ?? [];
        $payload = [
            'route' => $route,
            'reason' => $reason,
            'tables' => $query['tables'] ?? [],
            'selectCount' => count($query['select'] ?? []),
            'whereCount' => count($query['where'] ?? []),
            'hasExplicitJoins' => is_array($query['joins'] ?? null),
            'intentVersion' => $normalizedIntent['intentVersion'] ?? null,
        ];

        Yii::info('NL2SQL route: ' . json_encode($payload), 'nl2sql.routing');
    }

    /**
     * Build deterministic schema telemetry fields from the prompt context payload.
     */
    private static function buildSchemaTelemetry($schemaContext)
    {
        $metadata = FolioSchemaService::getMetadata();

        return [
            'schemaContextHash' => substr(hash('sha256', (string)$schemaContext), 0, 16),
            'schemaContextBytes' => strlen((string)$schemaContext),
            'schemaVersion' => $metadata['scraped_at'] ?? null,
        ];
    }

    private static function schemaMetadata(array $telemetry): array
    {
        return [
            'version' => $telemetry['schemaVersion'] ?? null,
            'contextHash' => $telemetry['schemaContextHash'] ?? null,
            'contextBytes' => isset($telemetry['schemaContextBytes'])
                ? (int)$telemetry['schemaContextBytes']
                : null,
        ];
    }

    private static function buildReferenceBundleMetadata(): ?array
    {
        try {
            $bundle = ReferenceJsonBundleService::loadBundle();
            if ($bundle === []) {
                return null;
            }
            $encoded = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return null;
            }
            return [
                'version' => isset($bundle['generated_at']) ? (string)$bundle['generated_at'] : null,
                'hash' => hash('sha256', $encoded),
            ];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private static function withAskEvidence(array $result, array $evidence): array
    {
        $existing = is_array($result['_askEvidence'] ?? null)
            ? $result['_askEvidence']
            : [];
        unset($evidence['modelConfidence']);
        $result['_askEvidence'] = array_merge($existing, $evidence);
        return $result;
    }

    private static function withInternalReferenceResolverGuidance(
        array $result,
        array $referenceResolution
    ): array {
        if (
            ($result['route'] ?? null) === 'exploratory_recovery'
            || empty($referenceResolution['guidanceLines'])
        ) {
            return $result;
        }

        $result['referenceResolver'] = [
            'resolved' => true,
            'guidanceLines' => $referenceResolution['guidanceLines'],
        ];
        return $result;
    }

    private static function withoutEnabledTwoLaneClarificationState(
        array $result,
        bool $twoLaneEnabled
    ): array {
        if ($twoLaneEnabled) {
            unset($result['needsClarification']);
        }
        return $result;
    }

    private static function explicitReportRequestEvidence(array $request): array
    {
        return empty($request['applicable'])
            ? []
            : [
                'explicitReportRequest' => $request,
                'explicitReportRequestProvenance' => 'server_extracted',
            ];
    }

    private static function withKnownFamilyEvidence(array $result, string $familyKey): array
    {
        $familyKey = trim($familyKey);
        return self::withAskEvidence($result, [
            'queryFamily' => $familyKey === '' ? null : $familyKey,
        ]);
    }

    /**
     * Create a stable prompt fingerprint for telemetry without logging prompt text.
     */
    private static function fingerprintPrompt($prompt)
    {
        return substr(hash('sha256', trim((string)$prompt)), 0, 16);
    }

    /**
     * Structured NL2SQL telemetry logging.
     */
    private static function logNlTelemetry($event, array $payload, $warning = false)
    {
        $record = array_merge([
            'event' => (string)$event,
            'timestamp' => gmdate('c'),
        ], $payload);

        $message = 'NL2SQL telemetry: ' . json_encode($record);
        if ($warning) {
            Yii::warning($message, self::NL2SQL_TELEMETRY_CATEGORY);
            return;
        }

        Yii::info($message, self::NL2SQL_TELEMETRY_CATEGORY);
    }

    /**
     * Emit structured validation-failure telemetry.
     */
    private static function logValidationFailure($stage, array $payload)
    {
        $rawError = (string)($payload['error'] ?? $payload['exception'] ?? '');
        unset($payload['error'], $payload['exception'], $payload['message']);
        $payload['failureCategory'] = self::sanitizeTelemetryFailureCategory((string)$stage, $rawError, $payload);
        self::logNlTelemetry('nl2sql.validation_failure', array_merge([
            'stage' => (string)$stage,
        ], $payload), true);
    }

    private static function sanitizeTelemetryFailureCategory(string $stage, string $error, array $payload = []): string
    {
        $existing = strtolower(trim((string)($payload['issueFamily'] ?? $payload['failureCategory'] ?? '')));
        if ($existing !== '' && preg_match('/^[a-z0-9_]{1,80}$/', $existing) === 1) {
            return $existing;
        }
        if (strpos(strtolower($stage), 'parse') !== false) {
            return 'parser_failure';
        }
        if (self::isPreflightPolicyFailure($error)) {
            return 'policy_blocked';
        }
        return self::sanitizePreflightFailureCategory($error);
    }

    private static function logExploratoryTerminalOutcome(
        array $context,
        string $outcome,
        ?string $category = null,
        int $repairAttempts = 0
    ): void {
        $allowedOutcomes = [
            'validated',
            'exhausted',
            'policy_blocked',
            'connectivity_failure',
            'provider_failure',
            'cancelled',
        ];
        $safeOutcome = in_array($outcome, $allowedOutcomes, true) ? $outcome : 'provider_failure';
        self::logNlTelemetry('nl2sql.exploratory_terminal_outcome', [
            'promptFingerprint' => self::fingerprintPrompt((string)($context['originalQuestion'] ?? '')),
            'route' => self::sanitizeTelemetryLabel($context['route'] ?? null, 'exploratory'),
            'routeReason' => self::sanitizeTelemetryLabel($context['routeReason'] ?? null, 'exploratory_processing'),
            'outcome' => $safeOutcome,
            'category' => $category === null ? null : self::sanitizeExploratoryTelemetryCategory($category),
            'repairAttempts' => max(0, min(ExploratorySqlRepairService::MAX_REPAIR_ATTEMPTS, $repairAttempts)),
            'provider' => self::getAiProvider(),
        ], $safeOutcome !== 'validated');
    }

    private static function sanitizeTelemetryLabel($value, string $fallback): string
    {
        $value = strtolower(trim((string)$value));
        return $value !== '' && preg_match('/^[a-z0-9_.:-]{1,120}$/', $value) === 1
            ? $value
            : $fallback;
    }

    private static function sanitizeExploratoryTelemetryCategory(string $category): string
    {
        $normalized = strtolower(trim($category));
        $allowed = [
            'ambiguous_column',
            'database_cancelled',
            'database_connectivity',
            'database_validation',
            'grouping_error',
            'blocked_keyword',
            'invalid_operator',
            'invalid_select_shape',
            'missing_select',
            'multiple_statements',
            'non_select',
            'policy_blocked',
            'provider_failure',
            'query_too_complex',
            'syntax_error',
            'unknown_column',
            'unknown_table',
            'unsupported_source_shape',
            'validation_failure',
        ];
        return in_array($normalized, $allowed, true)
            ? $normalized
            : self::sanitizePreflightFailureCategory($category);
    }

    private static function logReferenceResolverTelemetry(array $referenceResolution, string $promptFingerprint): void
    {
        if (!empty($referenceResolution['needsClarification'])) {
            self::logNlTelemetry('nl2sql.reference_resolver_clarification', [
                'promptFingerprint' => $promptFingerprint,
                'routeReason' => $referenceResolution['routeReason'] ?? null,
                'clarificationType' => $referenceResolution['clarificationType'] ?? null,
                'clarificationBatchId' => $referenceResolution['clarificationBatchId'] ?? null,
                'clarificationItemCount' => count($referenceResolution['clarificationItems'] ?? []),
            ], true);
            return;
        }

        $resolved = array_values(array_filter($referenceResolution['resolvedReferences'] ?? [], 'is_array'));
        if (empty($resolved)) {
            return;
        }

        $sourceTables = [];
        $matchedBy = [];
        $resolvedDimensions = [];
        $resolvedValueCount = 0;
        foreach ($resolved as $match) {
            $table = trim((string)($match['source_table'] ?? ''));
            if ($table !== '') {
                $sourceTables[$table] = true;
            }
            $method = trim((string)($match['matched_by'] ?? ''));
            if ($method !== '') {
                $matchedBy[$method] = true;
            }
        }
        foreach (($referenceResolution['resolvedFilters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $dimension = trim((string)($filter['dimension'] ?? ''));
            if ($dimension !== '') {
                $resolvedDimensions[$dimension] = true;
            }
            $resolvedValueCount += count(
                is_array($filter['values'] ?? null) ? $filter['values'] : []
            );
        }
        $resolvedDimensions = array_keys($resolvedDimensions);
        sort($resolvedDimensions);

        self::logNlTelemetry('nl2sql.reference_resolver_match', [
            'promptFingerprint' => $promptFingerprint,
            'routeReason' => $referenceResolution['routeReason'] ?? null,
            'resolvedCount' => count($resolved),
            'sourceTables' => array_keys($sourceTables),
            'matchedBy' => array_keys($matchedBy),
            'resolvedDimensions' => $resolvedDimensions,
            'resolvedValueCount' => $resolvedValueCount,
        ]);
    }

    /**
     * Resolve the primary NL2SQL mode for user-facing responses.
     */
    private static function resolvePrimaryMode()
    {
        if (!empty(Yii::$app->params['nl2sqlForceLegacy'])) {
            return 'legacy';
        }

        $configured = strtolower((string)(Yii::$app->params['nl2sqlPrimaryMode'] ?? ''));
        if ($configured === 'intent' || $configured === 'legacy') {
            return $configured;
        }

        return self::isIntentModeEnabled() ? 'intent' : 'legacy';
    }

    /**
     * Covered families should prefer deterministic intent mode unless legacy is explicitly forced.
     */
    private static function resolvePrimaryModeForPrompt($prompt, $campus = null)
    {
        if (self::promptRequiresLegacyFreeform($prompt)) {
            return 'legacy';
        }

        $primaryMode = self::resolvePrimaryMode();
        if ($primaryMode !== 'legacy') {
            return $primaryMode;
        }

        if (!empty(Yii::$app->params['nl2sqlForceLegacy'])) {
            return 'legacy';
        }

        return self::resolvePromptQueryFamily($prompt, $campus) !== null
            ? 'intent'
            : 'legacy';
    }

    /**
     * Some prompt families require SQL expressions/lateral JSON extraction that
     * the structured QueryIntent contract intentionally cannot represent.
     */
    private static function promptRequiresLegacyFreeform($prompt)
    {
        return self::promptMentionsMarcConstraint($prompt);
    }

    /**
     * Determine if the current user/prompt should run shadow comparison.
     */
    private static function shouldRunShadowForUser($userId, $prompt)
    {
        if (empty(Yii::$app->params['nl2sqlShadowMode'])) {
            return false;
        }

        if (!self::isShadowUserAllowed($userId)) {
            return false;
        }

        $percent = (int)(Yii::$app->params['nl2sqlShadowSamplePercent'] ?? 100);
        $percent = max(0, min(100, $percent));
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        $seed = (string)$userId . '|' . self::fingerprintPrompt((string)$prompt);
        $hash = hash('sha256', $seed);
        $bucket = hexdec(substr($hash, 0, 8)) % 100;
        return $bucket < $percent;
    }

    /**
     * Check user cohort allowlist for shadow-mode execution.
     */
    private static function isShadowUserAllowed($userId)
    {
        $raw = trim((string)(Yii::$app->params['nl2sqlShadowUsers'] ?? ''));
        if ($raw === '') {
            return false;
        }

        $normalized = strtolower($raw);
        if ($normalized === '*' || $normalized === 'all') {
            return true;
        }

        if ($userId === null) {
            return false;
        }

        $allowed = array_filter(array_map('trim', explode(',', $raw)), function ($value) {
            return $value !== '';
        });

        return in_array((string)$userId, $allowed, true);
    }

    /**
     * Normalize SQL text for stable hash comparisons.
     */
    private static function normalizeSqlForHash($sql)
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string)$sql)));
        return trim((string)$normalized);
    }

    /**
     * Build a family-specific semantic comparison signature for SQL pairs that
     * are known to be structurally different but semantically equivalent.
     */
    private static function buildSemanticSqlComparisonSignature($sql)
    {
        $sql = trim((string)$sql);
        if ($sql === '') {
            return null;
        }

        $normalized = self::normalizeSqlForHash($sql);
        if (!self::looksLikeCollectionAgeSemanticSql($normalized)) {
            return null;
        }

        $publicationAlias = self::extractSqlTableAlias($sql, 'inventory.instance__t__publication');
        $libraryAlias = self::extractSqlTableAlias($sql, 'inventory.loclibrary__t');
        $locationAlias = self::extractSqlTableAlias($sql, 'inventory.location__t');
        $campusAlias = self::extractSqlTableAlias($sql, 'inventory.loccampus__t');

        if ($publicationAlias === '' || $libraryAlias === '' || $locationAlias === '' || $campusAlias === '') {
            return null;
        }

        $libraryFilter = self::extractSqlAliasIlikeValue($sql, $libraryAlias);
        $locationFilter = self::extractSqlAliasIlikeValue($sql, $locationAlias);
        $campusFilter = self::extractSqlAliasCampusValue($sql, $campusAlias);
        if ($libraryFilter === '' || $campusFilter === '') {
            return null;
        }

        $signature = [
            'family' => 'inventory_collection_age',
            'age_basis' => 'publication_year',
            'campus' => self::canonicalizeSqlComparisonLiteral($campusFilter),
            'library' => self::canonicalizeSqlComparisonLiteral($libraryFilter),
            'location' => $locationFilter === ''
                ? null
                : self::canonicalizeSqlComparisonLiteral($locationFilter),
        ];

        return json_encode($signature, JSON_UNESCAPED_SLASHES);
    }

    private static function looksLikeCollectionAgeSemanticSql(string $normalizedSql): bool
    {
        $requiredFragments = [
            'publication__date_of_publication',
            'inventory.item__t',
            'inventory.holdings_record__t',
            'inventory.instance__t',
            'inventory.instance__t__publication',
            'inventory.location__t',
            'inventory.loclibrary__t',
            'inventory.loccampus__t',
            "publication__date_of_publication ~ '^\\d{4}'",
        ];

        foreach ($requiredFragments as $fragment) {
            if (strpos($normalizedSql, $fragment) === false) {
                return false;
            }
        }

        if (
            strpos($normalizedSql, 'avg(') === false
            && strpos($normalizedSql, 'sum(scoped_instances.item_count * (extract(year from current_date) - cast(substring(') === false
        ) {
            return false;
        }

        if (strpos($normalizedSql, 'extract(year from current_date) - cast(substring(') === false) {
            return false;
        }

        return preg_match('/\b(metadata__created_date|status__date|cataloged_date)\b/i', $normalizedSql) !== 1;
    }

    private static function extractSqlTableAlias(string $sql, string $tableName): string
    {
        $pattern = '/\b(?:from|join|left\s+join)\s+' . preg_quote($tableName, '/') . '\s+(?:as\s+)?([a-z_][a-z0-9_]*)\b/i';
        if (preg_match($pattern, $sql, $matches) === 1) {
            return strtolower((string)($matches[1] ?? ''));
        }

        return '';
    }

    private static function extractSqlAliasIlikeValue(string $sql, string $alias): string
    {
        $pattern = '/\b' . preg_quote($alias, '/') . '\.name\s+ilike\s+\'((?:[^\']|\'\')*)\'/i';
        if (preg_match($pattern, $sql, $matches) === 1) {
            return str_replace("''", "'", (string)($matches[1] ?? ''));
        }

        $lowerPattern = '/lower\(\s*' . preg_quote($alias, '/') . '\.name\s*\)\s*ilike\s*lower\(\s*\'((?:[^\']|\'\')*)\'\s*\)/i';
        if (preg_match($lowerPattern, $sql, $matches) === 1) {
            return str_replace("''", "'", (string)($matches[1] ?? ''));
        }

        return '';
    }

    private static function extractSqlAliasCampusValue(string $sql, string $alias): string
    {
        $ilikePattern = '/\b' . preg_quote($alias, '/') . '\.name\s+ilike\s+\'((?:[^\']|\'\')*)\'/i';
        if (preg_match($ilikePattern, $sql, $matches) === 1) {
            return str_replace("''", "'", (string)($matches[1] ?? ''));
        }

        $lowerPattern = '/lower\(\s*' . preg_quote($alias, '/') . '\.name\s*\)\s*=\s*lower\(\s*\'((?:[^\']|\'\')*)\'\s*\)/i';
        if (preg_match($lowerPattern, $sql, $matches) === 1) {
            return str_replace("''", "'", (string)($matches[1] ?? ''));
        }

        return '';
    }

    private static function extractSqlLimitValue(string $sql): ?string
    {
        if (preg_match('/\blimit\s+(\d+)\b/i', $sql, $matches) === 1) {
            return (string)($matches[1] ?? '');
        }

        return null;
    }

    private static function canonicalizeSqlComparisonLiteral(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim((string)$normalized);
    }

    /**
     * Log shadow comparison metrics without affecting the primary response.
     */
    private static function logShadowComparison(array $primary, array $shadow, array $context)
    {
        $primarySql = $primary['sql'] ?? '';
        $shadowSql = $shadow['sql'] ?? '';

        $primaryHash = $primarySql !== ''
            ? substr(hash('sha256', self::normalizeSqlForHash($primarySql)), 0, 16)
            : null;
        $shadowHash = $shadowSql !== ''
            ? substr(hash('sha256', self::normalizeSqlForHash($shadowSql)), 0, 16)
            : null;

        $primarySemanticSignature = self::buildSemanticSqlComparisonSignature($primarySql);
        $shadowSemanticSignature = self::buildSemanticSqlComparisonSignature($shadowSql);
        $primarySemanticHash = $primarySemanticSignature !== null
            ? substr(hash('sha256', $primarySemanticSignature), 0, 16)
            : null;
        $shadowSemanticHash = $shadowSemanticSignature !== null
            ? substr(hash('sha256', $shadowSemanticSignature), 0, 16)
            : null;

        $sqlComparisonMethod = 'raw_sql_hash';
        $sqlComparisonMatch = $primaryHash !== null && $shadowHash !== null
            ? $primaryHash === $shadowHash
            : null;

        if ($primarySemanticHash !== null && $shadowSemanticHash !== null) {
            $sqlComparisonMethod = 'semantic_sql_signature';
            $sqlComparisonMatch = $primarySemanticHash === $shadowSemanticHash;
        }

        self::logNlTelemetry('nl2sql.shadow_compare', array_merge($context, [
            'primaryRoute' => $primary['route'] ?? null,
            'primaryRouteReason' => $primary['routeReason'] ?? null,
            'shadowRoute' => $shadow['route'] ?? null,
            'shadowRouteReason' => $shadow['routeReason'] ?? null,
            'primaryDataSource' => $primary['dataSource'] ?? null,
            'shadowDataSource' => $shadow['dataSource'] ?? null,
            'primarySqlHash' => $primaryHash,
            'shadowSqlHash' => $shadowHash,
            'sqlHashMatch' => $primaryHash !== null && $shadowHash !== null
                ? $primaryHash === $shadowHash
                : null,
            'primarySemanticSqlHash' => $primarySemanticHash,
            'shadowSemanticSqlHash' => $shadowSemanticHash,
            'sqlComparisonMethod' => $sqlComparisonMethod,
            'sqlComparisonMatch' => $sqlComparisonMatch,
            'primarySqlLength' => strlen((string)$primarySql),
            'shadowSqlLength' => strlen((string)$shadowSql),
        ]));
    }

    /**
     * Send Gemini API requests with deterministic retry policy for transient failures.
     *
     * @param string $url
     * @param array $payload
     * @param string $metricContext
     * @return array {response: mixed, attempts: int, elapsedMs: int}
     * @throws \RuntimeException
     */
    private static function sendGeminiRequestWithRetries($url, array $payload, $metricContext)
    {
        $provider = self::getAiProvider();
        $apiKey = self::getAiApiKey();

        if (empty($apiKey)) {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $maxRetries = (int)(Yii::$app->params['geminiMaxRetries'] ?? self::DEFAULT_MAX_RETRIES);
        if ($maxRetries < 1) {
            $maxRetries = 1;
        }

        $baseDelayMs = (int)(Yii::$app->params['geminiRetryBaseDelayMs'] ?? self::DEFAULT_RETRY_BASE_DELAY_MS);
        if ($baseDelayMs < 1) {
            $baseDelayMs = self::DEFAULT_RETRY_BASE_DELAY_MS;
        }

        $attempt = 0;
        $startedAt = microtime(true);
        $openAiPayload = null;
        $didRetryOpenAiMaxTokenFallback = false;

        while (true) {
            $attempt++;

            try {
                $client = new Client();
                $client->transport = 'yii\\httpclient\\CurlTransport';

                $requestUrl = $url;
                $requestPayload = $payload;
                $headers = ['Content-Type' => 'application/json'];

                if ($provider === 'openai') {
                    $requestUrl = self::OPENAI_API_BASE . '/chat/completions';
                    if ($openAiPayload === null) {
                        $openAiPayload = self::buildOpenAiPayloadFromGeminiShape($payload);
                    }

                    $requestPayload = $openAiPayload;
                    $headers['Authorization'] = 'Bearer ' . $apiKey;
                }

                $response = $client->createRequest()
                    ->setMethod('POST')
                    ->setUrl($requestUrl)
                    ->setHeaders($headers)
                    ->addOptions([CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS])
                    ->setContent(json_encode($requestPayload))
                    ->send();

                if ($response->isOk) {
                    $normalizedResponse = $provider === 'openai'
                        ? self::normalizeOpenAiResponseToGeminiShape($response)
                        : $response;

                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    self::logRequestOutcome($metricContext, $attempt, $elapsedMs);
                    return [
                        'response' => $normalizedResponse,
                        'attempts' => $attempt,
                        'elapsedMs' => $elapsedMs,
                    ];
                }

                $statusCode = (int)$response->statusCode;
                $errorMessage = self::extractGeminiErrorMessage($response);

                if (
                    $provider === 'openai'
                    && !$didRetryOpenAiMaxTokenFallback
                    && self::isOpenAiMaxTokenUnsupportedError($errorMessage)
                    && self::openAiPayloadUsesMaxTokens($requestPayload)
                ) {
                    $openAiPayload = self::convertOpenAiPayloadToMaxCompletionTokens($requestPayload);
                    $didRetryOpenAiMaxTokenFallback = true;

                    self::logRetryAttempt($metricContext, $attempt, $maxRetries, $statusCode, $errorMessage, false);
                    continue;
                }

                $retryable = self::isRetryableGeminiResponse($statusCode, $errorMessage);

                if (!$retryable || $attempt >= $maxRetries) {
                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    self::logRequestOutcome($metricContext, $attempt, $elapsedMs, false, $statusCode, $errorMessage);
                    throw new \RuntimeException('AI API error: ' . $errorMessage);
                }

                self::logRetryAttempt($metricContext, $attempt, $maxRetries, $statusCode, $errorMessage, false);
                self::sleepWithBackoff($attempt, $baseDelayMs);
            } catch (\Throwable $e) {
                if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'AI API error:') === 0) {
                    throw $e;
                }

                $timedOut = self::isTimeoutThrowable($e);
                $retryable = self::isRetryableThrowable($e);

                if (!$retryable || $attempt >= $maxRetries) {
                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    self::logRequestOutcome($metricContext, $attempt, $elapsedMs, $timedOut, null, $e->getMessage());
                    throw new \RuntimeException('AI request failed: ' . $e->getMessage(), 0, $e);
                }

                self::logRetryAttempt($metricContext, $attempt, $maxRetries, null, $e->getMessage(), $timedOut);
                self::sleepWithBackoff($attempt, $baseDelayMs);
            }
        }
    }

    /**
     * Convert existing Gemini-style payload shape into OpenAI chat payload.
     *
     * @param array $payload
     * @return array
     */
    private static function buildOpenAiPayloadFromGeminiShape(array $payload)
    {
        $messages = [];

        $systemParts = $payload['system_instruction']['parts'] ?? [];
        $systemText = [];
        foreach ($systemParts as $part) {
            if (is_array($part) && isset($part['text'])) {
                $systemText[] = (string)$part['text'];
            }
        }
        $systemMessage = trim(implode("\n\n", $systemText));
        if ($systemMessage !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $systemMessage,
            ];
        }

        foreach (($payload['contents'] ?? []) as $content) {
            $parts = $content['parts'] ?? [];
            $texts = [];
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $texts[] = (string)$part['text'];
                }
            }

            $message = trim(implode("\n\n", $texts));
            if ($message !== '') {
                $messages[] = [
                    'role' => 'user',
                    'content' => $message,
                ];
            }
        }

        if (empty($messages)) {
            $messages[] = [
                'role' => 'user',
                'content' => '',
            ];
        }

        $generationConfig = $payload['generationConfig'] ?? [];
        $model = (string)(Yii::$app->params['openaiModel'] ?? 'gpt-5.4');
        $maxOutputTokens = isset($generationConfig['maxOutputTokens'])
            ? (int)$generationConfig['maxOutputTokens']
            : 4096;
        $openAiPayload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => isset($generationConfig['temperature'])
                ? (float)$generationConfig['temperature']
                : 0.1,
        ];

        if (self::openAiModelUsesMaxCompletionTokens($model)) {
            $openAiPayload['max_completion_tokens'] = $maxOutputTokens;
        } else {
            $openAiPayload['max_tokens'] = $maxOutputTokens;
        }

        if (($generationConfig['responseMimeType'] ?? '') === 'application/json') {
            $openAiPayload['response_format'] = ['type' => 'json_object'];
        }

        return $openAiPayload;
    }

    private static function openAiPayloadUsesMaxTokens(array $payload): bool
    {
        return array_key_exists('max_tokens', $payload);
    }

    private static function convertOpenAiPayloadToMaxCompletionTokens(array $payload): array
    {
        if (array_key_exists('max_completion_tokens', $payload)) {
            return $payload;
        }

        if (!array_key_exists('max_tokens', $payload)) {
            return $payload;
        }

        $payload['max_completion_tokens'] = $payload['max_tokens'];
        unset($payload['max_tokens']);
        return $payload;
    }

    private static function isOpenAiMaxTokenUnsupportedError(string $errorMessage): bool
    {
        return preg_match('/unsupported parameter.*max_tokens/i', $errorMessage) === 1
            || preg_match('/max_tokens\b.*is not supported/i', $errorMessage) === 1
            || preg_match('/use .*max_completion_tokens/i', $errorMessage) === 1;
    }

    private static function openAiModelUsesMaxCompletionTokens(string $model): bool
    {
        $normalized = strtolower(trim($model));
        return preg_match('/^(?:gpt-4|gpt-5|o[0-9])/', $normalized) === 1;
    }

    /**
     * Normalize OpenAI response shape into the Gemini-like structure expected by parsers.
     *
     * @param mixed $response
     * @return object
     */
    private static function normalizeOpenAiResponseToGeminiShape($response)
    {
        $decoded = [];
        if (!empty($response->content)) {
            $decoded = json_decode((string)$response->content, true);
        }

        $choice = $decoded['choices'][0] ?? [];
        $finishReason = strtoupper((string)($choice['finish_reason'] ?? ''));
        if ($finishReason === 'LENGTH') {
            $finishReason = 'MAX_TOKENS';
        }

        $messageContent = $choice['message']['content'] ?? '';
        if (is_array($messageContent)) {
            $parts = [];
            foreach ($messageContent as $segment) {
                if (is_array($segment) && ($segment['type'] ?? '') === 'text') {
                    $parts[] = (string)($segment['text'] ?? '');
                }
            }
            $messageContent = implode('', $parts);
        }

        $geminiLike = [
            'candidates' => [[
                'finishReason' => $finishReason,
                'content' => [
                    'parts' => [[
                        'text' => (string)$messageContent,
                    ]],
                ],
            ]],
        ];

        $normalized = new \stdClass();
        $normalized->content = json_encode($geminiLike);
        return $normalized;
    }

    /**
     * Extract a normalized Gemini API error message from an HTTP response.
     */
    private static function extractGeminiErrorMessage($response)
    {
        $error = null;

        if (!empty($response->content)) {
            $decoded = json_decode($response->content, true);
            if (is_array($decoded)) {
                $error = $decoded['error']['message'] ?? null;
            }
        }

        if (empty($error) && is_array($response->data ?? null)) {
            $error = $response->data['error']['message'] ?? null;
        }

        if (!empty($error)) {
            return (string)$error;
        }

        $statusCode = (int)($response->statusCode ?? 0);
        return $statusCode > 0
            ? "Unknown Gemini API error (HTTP {$statusCode})"
            : 'Unknown Gemini API error';
    }

    /**
     * Retry only transient HTTP failures.
     */
    private static function isRetryableGeminiResponse($statusCode, $errorMessage)
    {
        if (in_array((int)$statusCode, [408, 500, 502, 503, 504], true)) {
            return true;
        }

        if ((int)$statusCode === 429) {
            // Retry rate-limit spikes, but do not retry hard quota/billing failures.
            return !preg_match('/quota|billing|exceeded/i', (string)$errorMessage);
        }

        return preg_match(
            '/temporar(?:y|ily)|unavailable|timeout|timed out|deadline exceeded|resource exhausted|backend error|try again/i',
            (string)$errorMessage
        ) === 1;
    }

    /**
     * Determine if a thrown exception indicates a timeout condition.
     */
    private static function isTimeoutThrowable(\Throwable $e)
    {
        return self::isAiTimeoutMessage($e->getMessage());
    }

    public static function isAiTimeoutMessage(string $message): bool
    {
        return preg_match('/timeout|timed out|deadline exceeded|operation timed out/i', $message) === 1;
    }

    /**
     * Retry only transient transport/availability exceptions.
     */
    private static function isRetryableThrowable(\Throwable $e)
    {
        $message = $e->getMessage();

        if (self::isTimeoutThrowable($e)) {
            return true;
        }

        return preg_match(
            '/temporar(?:y|ily)|unavailable|connection reset|connection refused|failed to connect|network is unreachable|could not resolve host|ssl|try again/i',
            $message
        ) === 1;
    }

    /**
     * Returns true when a Gemini response signals hard quota/billing exhaustion
     * — errors that will not resolve on retry and warrant a provider fallback.
     */
    private static function isQuotaExhaustedResponse($statusCode, $errorMessage)
    {
        $msg = (string)$errorMessage;

        // Hard 429 with quota/billing language (Gemini REST API)
        if ((int)$statusCode === 429 && preg_match('/quota|billing|exceeded/i', $msg)) {
            return true;
        }

        // RESOURCE_EXHAUSTED gRPC status that may surface via any HTTP code
        if (preg_match('/RESOURCE_EXHAUSTED|quota exceeded|free tier.*limit|daily.*limit|monthly.*limit/i', $msg)) {
            return true;
        }

        return false;
    }

    /**
     * Perform a single OpenAI request as a transparent fallback when Gemini
     * quota is exhausted. Re-uses the same Gemini-shape payload and metric
     * context so callers require no changes.
     *
     * @param array    $payload       Gemini-shape payload (will be translated internally)
     * @param string   $metricContext Logging context string
     * @param float    $startedAt     microtime(true) from the original request
     * @param int|null $statusCode    Source-provider HTTP status when available
     * @param string   $errorMessage  Source-provider error message used for reason classification
     * @return array {response, attempts, elapsedMs, providerFallback}
     * @throws \RuntimeException
     */
    private static function sendOpenAiFallbackRequest(array $payload, $metricContext, $startedAt, $statusCode = null, $errorMessage = '')
    {
        $apiKey = (string)(Yii::$app->params['openaiApiKey'] ?? '');

        self::logProviderFallback('gemini', 'openai', $metricContext, $statusCode, $errorMessage);

        try {
            $client = new Client();
            $client->transport = 'yii\\httpclient\\CurlTransport';
            $openAiPayload = self::buildOpenAiPayloadFromGeminiShape($payload);

            $response = $client->createRequest()
                ->setMethod('POST')
                ->setUrl(self::OPENAI_API_BASE . '/chat/completions')
                ->setHeaders([
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->addOptions([CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS])
                ->setContent(json_encode($openAiPayload))
                ->send();

            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

            if ($response->isOk) {
                self::logRequestOutcome($metricContext . '.openai_fallback', 1, $elapsedMs);
                return [
                    'response'       => self::normalizeOpenAiResponseToGeminiShape($response),
                    'attempts'       => 1,
                    'elapsedMs'      => $elapsedMs,
                    'providerFallback' => 'openai',
                ];
            }

            $statusCode   = (int)$response->statusCode;
            $errorMessage = self::extractGeminiErrorMessage($response);

            if (
                self::isOpenAiMaxTokenUnsupportedError($errorMessage)
                && self::openAiPayloadUsesMaxTokens($openAiPayload)
            ) {
                $openAiPayload = self::convertOpenAiPayloadToMaxCompletionTokens($openAiPayload);

                $response = $client->createRequest()
                    ->setMethod('POST')
                    ->setUrl(self::OPENAI_API_BASE . '/chat/completions')
                    ->setHeaders([
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $apiKey,
                    ])
                    ->addOptions([CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS])
                    ->setContent(json_encode($openAiPayload))
                    ->send();

                $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                if ($response->isOk) {
                    self::logRequestOutcome($metricContext . '.openai_fallback', 2, $elapsedMs);
                    return [
                        'response'       => self::normalizeOpenAiResponseToGeminiShape($response),
                        'attempts'       => 2,
                        'elapsedMs'      => $elapsedMs,
                        'providerFallback' => 'openai',
                    ];
                }

                $statusCode   = (int)$response->statusCode;
                $errorMessage = self::extractGeminiErrorMessage($response);
            }

            self::logRequestOutcome($metricContext . '.openai_fallback', 1, $elapsedMs, false, $statusCode, $errorMessage);
            throw new \RuntimeException('OpenAI fallback failed: ' . $errorMessage);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenAI fallback request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Exponential backoff with jitter for retry pacing.
     */
    private static function sleepWithBackoff($attempt, $baseDelayMs)
    {
        $expDelayMs = (int)($baseDelayMs * pow(2, max(0, (int)$attempt - 1)));
        $jitterMs = random_int(0, 200);
        $delayMs = min(self::MAX_RETRY_BACKOFF_MS, $expDelayMs + $jitterMs);
        usleep($delayMs * 1000);
    }

    /**
     * Emit per-attempt retry telemetry.
     */
    private static function logRetryAttempt($context, $attempt, $maxRetries, $statusCode, $errorMessage, $timedOut)
    {
        $payload = [
            'context' => $context,
            'attempt' => (int)$attempt,
            'maxRetries' => (int)$maxRetries,
            'statusCode' => $statusCode,
            'timedOut' => (bool)$timedOut,
            'error' => (string)$errorMessage,
        ];

        Yii::warning('Gemini retry attempt: ' . json_encode($payload), 'nl2sql.retry');
    }

    /**
     * Emit terminal request metrics for success or final failure.
     */
    private static function logRequestOutcome($context, $attempts, $elapsedMs, $timedOut = false, $statusCode = null, $errorMessage = null)
    {
        $payload = [
            'context' => $context,
            'attempts' => (int)$attempts,
            'elapsedMs' => (int)$elapsedMs,
            'timedOut' => (bool)$timedOut,
            'statusCode' => $statusCode,
            'error' => $errorMessage,
        ];

        if (!empty($errorMessage)) {
            Yii::warning('Gemini request failed: ' . json_encode($payload), 'nl2sql.retry');
            return;
        }

        Yii::info('Gemini request success: ' . json_encode($payload), 'nl2sql.retry');
    }

    /**
     * Normalize provider fallback causes into stable report-friendly reason codes.
     */
    private static function classifyProviderFallbackReason($statusCode, $errorMessage)
    {
        if (self::isQuotaExhaustedResponse($statusCode, $errorMessage)) {
            return 'quota_exhausted';
        }

        return 'provider_failure';
    }

    /**
     * Emit structured provider-fallback telemetry for daily shadow reporting.
     */
    private static function logProviderFallback($sourceProvider, $targetProvider, $context, $statusCode = null, $errorMessage = '')
    {
        self::logNlTelemetry('nl2sql.provider_fallback', [
            'context' => (string)$context,
            'sourceProvider' => (string)$sourceProvider,
            'targetProvider' => (string)$targetProvider,
            'reasonCode' => self::classifyProviderFallbackReason($statusCode, $errorMessage),
            'statusCode' => $statusCode === null ? null : (int)$statusCode,
        ], true);
    }

    /**
     * Build the system prompt for structured QueryIntent generation.
     */
    private static function buildIntentSystemPrompt($schemaContext, $campus)
    {
        $campusRule = '';
        if ($campus && $campus !== 'All Colleges') {
            $safeCampus = str_replace("'", "''", (string)$campus);
            $campusRule = <<<RULE

CAMPUS SCOPE REQUIREMENT:
- The user's home institution is '{$safeCampus}'.
- Unless the prompt explicitly asks for all colleges or a different campus, include a campus filter in query.where.
- For inventory/circulation entities, campus is represented through location campus names.
- For finance/acquisitions entities, campus is represented through acquisitions unit codes.
RULE;
        }

        return <<<PROMPT
You are a deterministic QueryIntent planner for a FOLIO PostgreSQL dataset.

Return ONLY a JSON object matching this contract:
{
  "intentVersion": 1,
  "query": {
        "tables": ["inventory_items"],
    "select": [
            {"table": "inventory_items", "column": "id", "alias": "optional_alias", "aggregate": "COUNT|SUM|AVG|MIN|MAX"}
    ],
    "where": [
            {"table": "inventory_items", "column": "barcode", "op": "=|!=|<>|>|<|>=|<=|LIKE|ILIKE|NOT LIKE|IN|NOT IN|IS NULL|IS NOT NULL|BETWEEN", "value": "literal or array"}
    ],
    "joins": "auto",
        "groupBy": [{"table": "inventory_items", "column": "material_type_id"}],
        "having": [{"aggregate": "COUNT|SUM|AVG|MIN|MAX", "table": "inventory_items", "column": "id", "op": "=|!=|>|<|>=|<=", "value": "literal"}],
        "sort": [{"table": "inventory_items", "column": "id", "direction": "ASC|DESC"}],
    "distinct": false,
    "limit": 100
  }
}

Rules:
1. Use ONLY table and column names present in SCHEMA below.
2. Use table identifiers from SCHEMA keys (for example: inventory_items, circulation_loans).
3. Do NOT use schema-qualified SQL names like inventory.item__t in the JSON contract.
4. Generate SELECT-only intent; no DDL/DML behavior.
5. Keep joins as "auto" unless an explicit join structure is required.
6. Use limit <= 1000. Default to 100 if unsure.
7. Prefer case-insensitive matching for name/text filters via ILIKE or LOWER semantics.
8. Do not include markdown, code fences, or commentary.
9. Return exactly one query object (one SQL statement intent), never multiple alternatives.
{$campusRule}

SCHEMA:
{$schemaContext}
PROMPT;
    }

    /**
     * Parse and validate raw model output into an intent array.
     */
    private static function parseIntentResponse($text)
    {
        $clean = trim((string)$text);
        if ($clean === '') {
            throw new \RuntimeException('Model returned an empty structured intent response.');
        }

        // Be tolerant if the model still wraps JSON in markdown.
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        $intent = json_decode($clean, true);
        if (is_array($intent)) {
            return $intent;
        }

        $fragment = self::extractJsonObject($clean);
        if ($fragment !== null) {
            $intent = json_decode($fragment, true);
            if (is_array($intent)) {
                return $intent;
            }
        }

        throw new \RuntimeException(
            'Model returned malformed intent JSON. Unable to parse structured response.'
        );
    }

    /**
     * Extract the first balanced JSON object from arbitrary text.
     *
     * @param string $text
     * @return string|null
     */
    private static function extractJsonObject($text)
    {
        $len = strlen($text);
        $start = -1;
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($ch === '}') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start >= 0) {
                        return substr($text, $start, $i - $start + 1);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Normalize and filter follow-up prompt suggestions.
     *
     * @param array $suggestions
     * @param string $originalPrompt
     * @return array
     */
    private static function sanitizeFollowUpSuggestions(array $suggestions, $originalPrompt)
    {
        $original = strtolower(trim((string)$originalPrompt));
        $seen = [];
        $final = [];

        foreach ($suggestions as $candidate) {
            $text = trim((string)$candidate);
            if ($text === '') {
                continue;
            }

            $text = preg_replace('/\s+/', ' ', $text);
            if ($text === '') {
                continue;
            }

            $normalized = strtolower($text);
            if ($normalized === $original) {
                continue;
            }
            if (isset($seen[$normalized])) {
                continue;
            }
            if (strlen($text) < 10 || strlen($text) > 180) {
                continue;
            }

            $seen[$normalized] = true;
            $final[] = $text;

            if (count($final) >= 5) {
                break;
            }
        }

        return $final;
    }

    /**
     * Deterministic fallback suggestions when model output is weak/unavailable.
     *
     * @param string $prompt
     * @param string|null $campus
     * @return array
     */
    private static function buildFallbackFollowUpSuggestions($prompt, $campus = null)
    {
        $promptLower = strtolower(trim((string)$prompt));
        $scopeSuffix = '';
        if (!empty($campus) && $campus !== 'All Colleges') {
            $scopeSuffix = ' for ' . trim((string)$campus);
        }

        $generic = [
            'Break this result down by month over the last 12 months' . $scopeSuffix,
            'Show the top 10 categories contributing the most to this result' . $scopeSuffix,
            'Compare this metric across campuses and highlight differences',
            'List records that are missing key fields related to this query',
            'Show year-over-year trend changes for this metric' . $scopeSuffix,
        ];

        $finance = [
            'Show this spending trend by fiscal year' . $scopeSuffix,
            'Which vendors account for the highest share of this spending' . $scopeSuffix,
            'Break this amount down by fund and expense class' . $scopeSuffix,
            'Compare encumbered versus expended amounts for the same scope',
        ];

        $circulation = [
            'Show this circulation metric by material type' . $scopeSuffix,
            'Which locations have the highest and lowest circulation for this scope',
            'Break this down by patron group and loan type' . $scopeSuffix,
            'Show monthly circulation trend and identify peak periods' . $scopeSuffix,
        ];

        $inventory = [
            'Break this inventory count down by library and location' . $scopeSuffix,
            'Show item age distribution for this result set',
            'Which call number ranges are most represented in this scope',
            'Show records added in the last 90 days for this same criteria',
        ];

        if (preg_match('/spent|spend|budget|invoice|encumber|expend|vendor|fund|fiscal/', $promptLower)) {
            return array_merge($finance, $generic);
        }

        if (preg_match('/loan|checkout|circulation|renew|return/', $promptLower)) {
            return array_merge($circulation, $generic);
        }

        if (preg_match('/item|holdings|instance|location|call number|inventory|material type/', $promptLower)) {
            return array_merge($inventory, $generic);
        }

        return $generic;
    }

    /**
     * Inline SqlBuilder-style bind parameters into the SQL string so the
     * existing NL execution flow can continue to submit raw SQL.
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    private static function inlineParams($sql, array $params)
    {
        if (empty($params)) {
            return $sql;
        }

        uksort($params, function ($a, $b) {
            return strlen((string)$b) <=> strlen((string)$a);
        });

        foreach ($params as $name => $value) {
            $sql = str_replace((string)$name, self::toSqlLiteral($value), $sql);
        }

        return $sql;
    }

    private static function buildQueryFamilyIntentResponse(
        array $intent,
        array $queryFamily,
        $prompt,
        $campus,
        array $telemetryContext,
        $familyResultBuilder = null,
        $exploratoryFallbackFactory = null,
        $explicitRepairFactory = null,
        ?string $originalQuestion = null,
        array $resolvedFilters = []
    ): array {
        $originalQuestion = $originalQuestion === null ? (string)$prompt : $originalQuestion;
        $recoveredIntent = self::recoverPromptScopedFamilySlotsWithProvenance(
            $intent,
            $originalQuestion,
            $campus
        );
        $intent = $recoveredIntent['intent'];
        $slotProvenance = $recoveredIntent['slotProvenance'] ?? [];
        $intent = self::applyResolvedReferenceFiltersToFamilyIntent(
            $intent,
            $resolvedFilters,
            $originalQuestion,
            $slotProvenance
        );
        $telemetryContext = self::withSlotProvenanceTelemetry(
            $telemetryContext,
            $slotProvenance
        );

        $slotValidation = QueryFamilySlotService::validateFamilyPayload($intent, [
            'campus' => $campus,
        ]);
        if (empty($slotValidation['valid'])) {
            if (self::shouldRecoverInventoryListingMissingLibraryAsExploratory(
                $intent,
                $slotValidation['errors'] ?? [],
                $originalQuestion
            )) {
                if (self::isTwoLaneEnabled()) {
                    self::guardCoveredFamilyFallback(
                        (string)($intent['familyKey'] ?? $queryFamily['familyKey'] ?? ''),
                        'missing_required_slot',
                        $telemetryContext
                    );
                }
                $fallback = $exploratoryFallbackFactory === null
                    ? self::generateExploratorySqlResponse(
                        (string)$prompt,
                        $campus,
                        'inventory_listing_unscoped_missing_library',
                        $originalQuestion,
                        $resolvedFilters
                    )
                    : $exploratoryFallbackFactory();
                return self::withKnownFamilyEvidence(
                    $fallback,
                    (string)($intent['familyKey'] ?? $queryFamily['familyKey'] ?? '')
                );
            }

            $clarification = self::buildFamilySlotClarificationResponse(
                $slotValidation['errors'] ?? [],
                $intent,
                $telemetryContext
            );
            if ($clarification !== null) {
                return $clarification;
            }

            if (self::isTwoLaneEnabled()) {
                self::guardCoveredFamilyFallback(
                    (string)($intent['familyKey'] ?? $queryFamily['familyKey'] ?? ''),
                    'family_contract_mismatch',
                    $telemetryContext
                );
            }

            $first = $slotValidation['errors'][0] ?? [];
            $path = $first['path'] ?? 'slots';
            $message = $first['message'] ?? 'Unknown validation error.';
            self::logValidationFailure('family_slot_contract', [
                'route' => 'intent_json',
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                'errorCount' => count($slotValidation['errors'] ?? []),
                'firstErrorPath' => $path,
                'firstErrorMessage' => $message,
            ] + $telemetryContext);
            throw new \RuntimeException(
                "Model returned invalid family slot JSON ({$path}): {$message}"
            );
        }

        $normalizedPayload = QueryFamilySlotService::applyPromptMatchPolicy(
            $slotValidation['normalizedPayload'],
            $originalQuestion,
            $campus
        );
        $normalizedPayload = self::normalizeQueryFamilyPayload(
            $normalizedPayload,
            $originalQuestion,
            $campus
        );
        $slotProvenance = self::finalizeRecoveredSlotProvenance(
            $normalizedPayload,
            $slotProvenance,
            $campus
        );
        $telemetryContext = self::withSlotProvenanceTelemetry(
            $telemetryContext,
            $slotProvenance
        );

        if (
            trim((string)($normalizedPayload['familyKey'] ?? '')) === 'inventory_library_location_listing'
            && self::promptExplicitlyRequestsOnlyHoldingLocation($originalQuestion)
            && !self::inventoryListingPayloadHasOnlyHoldingLocationScope($normalizedPayload['slots'] ?? [])
        ) {
            $clarification = self::buildFamilySlotClarificationResponse(
                [
                    [
                        'path' => 'slots.location',
                        'code' => 'required',
                        'message' => 'An explicit holding-location scope is required for only-holding location prompts.',
                    ],
                ],
                $normalizedPayload,
                $telemetryContext
            );
            if ($clarification !== null) {
                return $clarification;
            }
        }

        $routeReason = 'family_contract_supported:'
            . ($normalizedPayload['familyKey'] ?? $queryFamily['familyKey'] ?? '');

        if ($familyResultBuilder === null) {
            $familyResultBuilder = function (
                array $normalizedPayload,
                string $familyRouteReason,
                $requestPrompt,
                $requestCampus,
                array $requestTelemetry
            ) use ($originalQuestion, $resolvedFilters): array {
                return self::buildCompiledQueryFamilyOrLegacyFallback(
                    $normalizedPayload,
                    $familyRouteReason,
                    $requestPrompt,
                    $requestCampus,
                    $requestTelemetry,
                    null,
                    null,
                    null,
                    $originalQuestion,
                    $resolvedFilters
                );
            };
        }

        $compiledFamily = $familyResultBuilder(
            $normalizedPayload,
            $routeReason,
            $prompt,
            $campus,
            $telemetryContext
        );

        if (($compiledFamily['route'] ?? null) === 'legacy_fallback') {
            return $compiledFamily;
        }

        $explicitValidation = self::explicitReportValueValidation(
            (string)($compiledFamily['sql'] ?? ''),
            $originalQuestion
        );
        if ($explicitValidation !== null) {
            if (self::isTwoLaneEnabled()) {
                self::guardCoveredFamilyFallback(
                    (string)($normalizedPayload['familyKey'] ?? $queryFamily['familyKey'] ?? ''),
                    'semantic_validation_failed',
                    $telemetryContext,
                    null,
                    $compiledFamily
                );
            }
            $repair = $explicitRepairFactory === null
                ? function (string $repairPrompt, $repairCampus, array $candidate) use ($originalQuestion, $resolvedFilters): array {
                    return self::repairRoutedCandidateAfterExplicitFailure(
                        $repairPrompt,
                        $repairCampus,
                        $candidate,
                        $originalQuestion,
                        $resolvedFilters
                    );
                }
                : $explicitRepairFactory;
            return self::withKnownFamilyEvidence(
                $repair((string)$prompt, $campus, $compiledFamily),
                (string)($normalizedPayload['familyKey'] ?? $queryFamily['familyKey'] ?? '')
            );
        }

        self::logRouteSelection('builder_intent', $routeReason, [
            'intentVersion' => QueryIntentService::CONTRACT_VERSION,
            'query' => [
                'tables' => $compiledFamily['queryDefinition']['tables'] ?? [],
                'select' => $compiledFamily['queryDefinition']['columns'] ?? [],
                'where' => $compiledFamily['queryDefinition']['filters'] ?? [],
                'joins' => $compiledFamily['queryDefinition']['joins'] ?? [],
            ],
        ]);
        self::logNlTelemetry('nl2sql.generated', [
            'route' => 'builder_intent',
            'routeReason' => $routeReason,
            'model' => $telemetryContext['model'] ?? null,
            'promptVersion' => $telemetryContext['promptVersion'] ?? null,
            'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
            'finishReason' => $telemetryContext['finishReason'] ?? null,
            'dataSource' => $compiledFamily['dataSource'] ?? 'folio',
            'attempts' => $telemetryContext['attempts'] ?? null,
            'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
        ] + $telemetryContext);

        $compiledFamily = self::withAskEvidence($compiledFamily, [
            'queryFamily' => (string)($normalizedPayload['familyKey'] ?? $queryFamily['familyKey'] ?? ''),
            'modelName' => $telemetryContext['model'] ?? null,
            'promptVersion' => $telemetryContext['promptVersion'] ?? null,
            'schemaMetadata' => self::schemaMetadata($telemetryContext),
        ]);
        unset($compiledFamily['queryDefinition']);
        return $compiledFamily;
    }

    private static function shouldRecoverInventoryListingMissingLibraryAsExploratory(
        array $intent,
        array $errors,
        string $prompt
    ): bool {
        if (trim((string)($intent['familyKey'] ?? '')) !== 'inventory_library_location_listing') {
            return false;
        }

        if (self::promptMentionsLibraryLocationListingScope($prompt)) {
            return false;
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            if ((string)($error['code'] ?? '') === 'required' && (string)($error['path'] ?? '') === 'slots.library') {
                return true;
            }
        }

        return false;
    }

    private static function recoverPromptScopedFamilySlotsWithProvenance(array $intent, string $prompt, $campus = null): array
    {
        $familyKey = trim((string)($intent['familyKey'] ?? ''));
        if (!is_array($intent['slots'] ?? null)) {
            return [
                'intent' => $intent,
                'slotProvenance' => [],
            ];
        }

        $slotProvenance = self::buildInitialFamilySlotProvenance(
            $familyKey,
            $intent['slots']
        );

        if ($familyKey === 'inventory_collection_age') {
            $collectionAgeRecovery = self::recoverCollectionAgeFamilySlotsFromPrompt(
                $intent['slots'],
                $prompt,
                $slotProvenance
            );
            $intent['slots'] = $collectionAgeRecovery['slots'];
            $slotProvenance = $collectionAgeRecovery['slotProvenance'];
        }

        if ($familyKey === 'inventory_library_location_listing') {
            $inventoryListingRecovery = self::recoverInventoryListingFamilySlotsFromPrompt(
                $intent['slots'],
                $prompt,
                $slotProvenance
            );
            $intent['slots'] = $inventoryListingRecovery['slots'];
            $slotProvenance = $inventoryListingRecovery['slotProvenance'];
        }

        return [
            'intent' => $intent,
            'slotProvenance' => $slotProvenance,
        ];
    }

    private static function buildInitialFamilySlotProvenance(string $familyKey, array $slots): array
    {
        $provenance = [];
        foreach (self::getSupportedQueryFamilySlots($familyKey) as $slotName) {
            if (!array_key_exists($slotName, $slots)) {
                continue;
            }

            if (!self::slotValueHasTelemetryContent($slots[$slotName])) {
                continue;
            }

            $provenance[$slotName] = 'model_output';
        }

        ksort($provenance, SORT_STRING);
        return $provenance;
    }

    private static function getSupportedQueryFamilySlots(string $familyKey): array
    {
        try {
            $contracts = QueryFamilyContractService::loadContracts();
        } catch (\RuntimeException $e) {
            return [];
        }

        $contract = $contracts[$familyKey] ?? null;
        if (!is_array($contract)) {
            return [];
        }

        return array_values($contract['slots']['supported'] ?? []);
    }

    private static function slotValueHasTelemetryContent($value): bool
    {
        if (is_array($value)) {
            return !empty($value);
        }

        if (!is_scalar($value)) {
            return false;
        }

        return trim((string)$value) !== '';
    }

    private static function finalizeRecoveredSlotProvenance(array $normalizedPayload, array $slotProvenance, $campus = null): array
    {
        $familyKey = trim((string)($normalizedPayload['familyKey'] ?? ''));
        $normalizedSlots = $normalizedPayload['slots'] ?? null;
        if (!is_array($normalizedSlots)) {
            return $slotProvenance;
        }

        foreach (self::getSupportedQueryFamilySlots($familyKey) as $slotName) {
            if (isset($slotProvenance[$slotName])) {
                continue;
            }

            $value = $normalizedSlots[$slotName] ?? null;
            if (!self::slotValueHasTelemetryContent($value)) {
                continue;
            }

            if (
                $slotName === 'campus'
                && is_scalar($campus)
                && trim((string)$campus) !== ''
                && strcasecmp(trim((string)$value), trim((string)$campus)) === 0
            ) {
                $slotProvenance[$slotName] = 'default_context';
                continue;
            }

            $slotProvenance[$slotName] = 'normalized_payload';
        }

        ksort($slotProvenance, SORT_STRING);
        return $slotProvenance;
    }

    private static function withSlotProvenanceTelemetry(array $telemetryContext, array $slotProvenance): array
    {
        if ($slotProvenance === []) {
            return $telemetryContext;
        }

        ksort($slotProvenance, SORT_STRING);
        $telemetryContext['slotProvenance'] = $slotProvenance;
        return $telemetryContext;
    }

    private static function recoverInventoryListingFamilySlotsFromPrompt(array $slots, string $prompt, array $slotProvenance = []): array
    {
        if (!is_array($slots['requested_outputs'] ?? null) || $slots['requested_outputs'] === []) {
            $slots['requested_outputs'] = ['title'];
            $slotProvenance['requested_outputs'] = 'documented_default';
        }

        if (self::promptRequestsInventoryListingCallNumber($prompt)) {
            $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];
            $requestedOutputs[] = 'call_number';
            $slots['requested_outputs'] = array_values(array_unique($requestedOutputs));
            sort($slots['requested_outputs'], SORT_STRING);
            $slotProvenance['requested_outputs'] = 'prompt_repaired';
        }

        $materialType = self::extractInventoryListingMaterialTypeFromPrompt($prompt);
        if ($materialType !== '') {
            $existingMaterialType = trim((string)($slots['material_type'] ?? ''));
            if ($existingMaterialType === '' || strcasecmp($existingMaterialType, $materialType) !== 0) {
                $slots['material_type'] = $materialType;
                $slotProvenance['material_type'] = $existingMaterialType === ''
                    ? 'prompt_explicit'
                    : 'prompt_repaired';
            }
        }

        $itemStatus = self::extractInventoryListingItemStatusFromPrompt($prompt);
        if ($itemStatus !== '') {
            $existingItemStatus = trim((string)($slots['item_status'] ?? ''));
            if ($existingItemStatus === '' || strcasecmp($existingItemStatus, $itemStatus) !== 0) {
                $slots['item_status'] = $itemStatus;
                $slotProvenance['item_status'] = $existingItemStatus === ''
                    ? 'prompt_explicit'
                    : 'prompt_repaired';
            }
        }

        $clarificationLocation = self::extractClarificationInventoryListingLocation($prompt);
        if ($clarificationLocation !== '') {
            $existingLocation = trim((string)($slots['location'] ?? ''));
            $slots['location'] = $clarificationLocation;

            if (array_key_exists('location', $slots) && (strtolower($existingLocation) !== strtolower($clarificationLocation))) {
                $slotProvenance['location'] = array_key_exists('location', $slotProvenance)
                    ? 'prompt_repaired'
                    : 'prompt_repaired';
            } else {
                $slotProvenance['location'] = array_key_exists('location', $slotProvenance)
                    ? 'prompt_repaired'
                : 'prompt_explicit';
            }
        }

        if ($clarificationLocation === '') {
            $recoveredLocation = self::extractInventoryListingLocationFromPrompt($prompt);
            if ($recoveredLocation !== '') {
                $existingLocation = trim((string)($slots['location'] ?? ''));
                if ($existingLocation === '') {
                    $slots['location'] = $recoveredLocation;
                    $slotProvenance['location'] = 'prompt_explicit';
                } elseif (strcasecmp($existingLocation, $recoveredLocation) !== 0) {
                    $slots['location'] = $recoveredLocation;
                    $slotProvenance['location'] = 'prompt_repaired';
                }
            }
        }

        $locationCodes = self::extractInventoryListingLocationCodes($prompt);
        if ($locationCodes === []) {
            $slots = self::removeInventoryListingLocationMasqueradingAsLibrary($slots, $prompt, $slotProvenance);
            $slots = self::removeInventoryListingStatusMasqueradingAsLibrary($slots, $prompt, $slotProvenance);
            $slots = self::removeInventoryListingCampusMasqueradingAsLibrary($slots, $prompt, $slotProvenance);
            $slots = self::removeInventoryListingDefaultCampusForFiveCollegesOnlyHolding($slots, $prompt, $slotProvenance);
            $slots = self::normalizeInventoryListingOnlyHoldingSlotFromPrompt($slots, $prompt, $slotProvenance);
            ksort($slotProvenance, SORT_STRING);
            return [
                'slots' => $slots,
                'slotProvenance' => $slotProvenance,
            ];
        }

        $slots['location_code'] = implode(',', $locationCodes);

        $library = trim((string)($slots['library'] ?? ''));
        if ($library !== '' && self::valueLooksLikeLocationCodeList($library) && !self::promptMentionsExplicitLibraryScope($prompt)) {
            unset($slots['library']);
        }

        $location = trim((string)($slots['location'] ?? ''));
        if ($location !== '' && self::valueLooksLikeLocationCodeList($location)) {
            unset($slots['location']);
        }

        $slots = self::normalizeInventoryListingOnlyHoldingSlotFromPrompt($slots, $prompt, $slotProvenance);
        ksort($slotProvenance, SORT_STRING);
        return [
            'slots' => $slots,
            'slotProvenance' => $slotProvenance,
        ];
    }

    private static function applyResolvedReferenceFiltersToFamilyIntent(
        array $intent,
        array $resolvedFilters,
        string $originalQuestion,
        array &$slotProvenance
    ): array {
        if (
            trim((string)($intent['familyKey'] ?? '')) !== 'inventory_library_location_listing'
            || !is_array($intent['slots'] ?? null)
        ) {
            return $intent;
        }

        $slotByDimension = [
            'campus' => 'campus',
            'library' => 'library',
            'location' => 'location',
            'material_type' => 'material_type',
        ];
        $expectedTables = [
            'campus' => 'inventory.loccampus__t',
            'library' => 'inventory.loclibrary__t',
            'location' => 'inventory.location__t',
            'material_type' => 'inventory.material_type__t',
        ];
        $applied = false;

        foreach ($resolvedFilters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $dimension = strtolower(trim((string)($filter['dimension'] ?? '')));
            $slotName = $slotByDimension[$dimension] ?? null;
            if (
                $slotName === null
                || strtolower(trim((string)($filter['source_table'] ?? ''))) !== $expectedTables[$dimension]
                || strtolower(trim((string)($filter['column'] ?? ''))) !== 'name'
            ) {
                continue;
            }

            $values = [];
            foreach ((array)($filter['values'] ?? []) as $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $value = trim((string)$value);
                if ($value !== '' && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
            if ($values === []) {
                continue;
            }

            $intent['slots'][$slotName] = $dimension === 'material_type' && count($values) > 1
                ? $values
                : $values[0];
            $slotProvenance[$slotName] = 'reference_resolver';
            $applied = true;
        }

        if ($applied) {
            $intent['slots']['match_policy'] = 'exact_phrase';
            $slotProvenance['match_policy'] = 'reference_resolver';
        }

        if (
            isset($intent['slots']['material_type'])
            && !self::promptMentionsCoveredInventoryOutputs($originalQuestion)
        ) {
            $outputs = is_array($intent['slots']['requested_outputs'] ?? null)
                ? $intent['slots']['requested_outputs']
                : [];
            if (!in_array('material_type', $outputs, true)) {
                $outputs[] = 'material_type';
            }
            if (!in_array('title', $outputs, true)) {
                $outputs[] = 'title';
            }
            $intent['slots']['requested_outputs'] = $outputs;
            $slotProvenance['requested_outputs'] = 'documented_default';
        }

        return $intent;
    }

    private static function removeInventoryListingLocationMasqueradingAsLibrary(array $slots, string $prompt, array &$slotProvenance): array
    {
        if (self::promptMentionsExplicitLibraryScope($prompt)) {
            return $slots;
        }

        $location = trim((string)($slots['location'] ?? ''));
        $library = trim((string)($slots['library'] ?? ''));
        if ($location === '' || $library === '') {
            return $slots;
        }

        if (
            strcasecmp($library, $location) !== 0
            && preg_match('/\b(?:location|collection|reference|rare book|mrbc)\b/i', $library) !== 1
        ) {
            return $slots;
        }

        unset($slots['library']);
        $slotProvenance['library'] = 'policy_omitted_location_not_library';
        return $slots;
    }

    private static function removeInventoryListingStatusMasqueradingAsLibrary(array $slots, string $prompt, array &$slotProvenance): array
    {
        if (self::promptMentionsExplicitLibraryScope($prompt)) {
            return $slots;
        }

        $library = trim((string)($slots['library'] ?? ''));
        if ($library === '') {
            return $slots;
        }

        if (!self::promptMentionsItemStatusScope($prompt) && !self::valueLooksLikeItemStatus($library)) {
            return $slots;
        }

        if (self::valueLooksLikeItemStatus($library)) {
            unset($slots['library']);
            $slotProvenance['library'] = 'policy_omitted_item_status_not_library';
        }

        return $slots;
    }

    private static function removeInventoryListingCampusMasqueradingAsLibrary(array $slots, string $prompt, array &$slotProvenance): array
    {
        if (!self::promptMentionsCampusScopedInventoryItemFilterListing($prompt)) {
            return $slots;
        }

        $library = trim((string)($slots['library'] ?? ''));
        if ($library === '' || !self::valueLooksLikeCampusScope($library)) {
            return $slots;
        }

        unset($slots['library']);
        $slotProvenance['library'] = 'policy_omitted_campus_not_library';
        return $slots;
    }

    private static function removeInventoryListingDefaultCampusForFiveCollegesOnlyHolding(array $slots, string $prompt, array &$slotProvenance): array
    {
        $campus = trim((string)($slots['campus'] ?? ''));
        $location = trim((string)($slots['location'] ?? ''));
        if ($campus === '' || $location === '') {
            return $slots;
        }

        if (!self::promptExplicitlyRequestsOnlyHoldingLocation($prompt)) {
            return $slots;
        }

        if (preg_match('/\b(?:five|5)\s+colleges\b/i', $prompt) !== 1) {
            return $slots;
        }

        if (self::promptMentionsSpecificCampusScope($prompt)) {
            return $slots;
        }

        unset($slots['campus']);
        $slotProvenance['campus'] = 'policy_omitted_five_colleges_only_holding';
        return $slots;
    }

    private static function promptMentionsSpecificCampusScope(string $prompt): bool
    {
        return preg_match(
            '/\b(?:Smith College|Amherst College|Mount Holyoke College|Mt\.?\s+Holyoke College|Hampshire College|UMass|University of Massachusetts|Yiddish Book Center)\b/i',
            $prompt
        ) === 1;
    }

    private static function promptMentionsItemStatusScope(string $prompt): bool
    {
        return preg_match('/\bitem\s+status\b|\bstatus\s+of\b/i', $prompt) === 1;
    }

    private static function valueLooksLikeItemStatus(string $value): bool
    {
        $normalized = self::normalizeItemStatusValue($value);

        return in_array($normalized, [
            'available',
            'checked out',
            'in process',
            'in transit',
            'missing',
            'lost',
            'withdrawn',
            'on order',
            'paged',
            'awaiting pickup',
            'declared lost',
            'long missing',
            'restricted',
        ], true);
    }

    private static function valueLooksLikeCampusScope(string $value): bool
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', (string)$normalized);
        $normalized = trim((string)preg_replace('/\s+/', ' ', (string)$normalized));

        return in_array($normalized, [
            'smith college',
            'amherst college',
            'hampshire college',
            'mount holyoke college',
            'mt holyoke college',
            'umass',
            'university of massachusetts',
            'yiddish book center',
        ], true);
    }

    private static function promptRequestsInventoryListingCallNumber(string $prompt): bool
    {
        return preg_match('/\bcall\s+numbers?\b/i', $prompt) === 1;
    }

    private static function extractInventoryListingLocationFromPrompt(string $prompt): string
    {
        if (trim($prompt) === '') {
            return '';
        }

        $prompt = ' ' . strtolower(trim((string)$prompt)) . ' ';

        $stopwords = [
            'a',
            'an',
            'the',
            'this',
            'that',
            'these',
            'those',
            'all',
            'and',
            'or',
            'with',
            'where',
            'which',
            'containing',
            'called',
            'from',
            'for',
            'at',
            'in',
            'on',
            'of',
            'only',
            'records',
            'record',
            'holding',
            'holdings',
            'location',
            'locations',
            'library',
            'libraries',
        ];

        if (preg_match('/\bmrbc\s+reference\s+collection\b/i', $prompt) === 1) {
            return 'SC Rare Book Collection Reference';
        }

        if (preg_match('/\bmrbc\s+reference\b/i', $prompt) === 1) {
            return 'MRBC Reference';
        }

        $patterns = [
            '/\b(?:with|from|for|in|at)\s+(?:the\s+)?location\s+([a-z0-9][a-z0-9 .\'"-]*?)(?=\s+(?:containing|where|and|or|with|for|that|which|only|,|\.|;|:|\?|!|$))/i',
            '/\blocation\s+([a-z0-9][a-z0-9 .\'"-]*?)(?=\s+(?:containing|where|and|or|with|for|that|which|only|,|\.|;|:|\?|!|$))/i',
            '/\bin\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'"-]*?)\s+location\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            $location = trim((string)($matches[1] ?? ''));
            if ($location === '') {
                continue;
            }

            $location = self::normalizeRecoveredPromptScope((string)$location);
            if ($location === '') {
                continue;
            }

            $lower = strtolower($location);
            if (in_array($lower, $stopwords, true)) {
                continue;
            }

            return $location;
        }

        return '';
    }

    private static function extractClarificationInventoryListingLocation(string $prompt): string
    {
        if (trim($prompt) === '') {
            return '';
        }

        $patterns = [
            '/\\binventory\\.location__t\\.name\\s*=\\s*([\'"])([^\'"]+)\\1/i',
            '/\\binventory\\.location__t\\.name\\s*=\\s*([^\\.\\n;]+?)(?=\\s+for\\s+mrbc|\\.|$)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            $location = trim((string)($matches[2] ?? $matches[1] ?? ''));
            if ($location === '') {
                continue;
            }

            $location = trim($location);
            $location = trim($location, " \t\n\r\0\x0B'\"");
            $location = preg_replace('/\\s+for\\s+mrbc(?:\\b|\\s+reference|\\s+collection)?\\b.*$/i', '', (string)$location);
            $location = trim((string)$location);
            if ($location === '') {
                continue;
            }

            return self::normalizeRecoveredPromptScope($location);
        }

        return '';
    }

    private static function normalizeInventoryListingOnlyHoldingSlotFromPrompt(array $slots, string $prompt, array &$slotProvenance): array
    {
        if (!QueryFamilySlotService::slotRequiresExplicitPromptEvidence('inventory_library_location_listing', 'only_holding_location')) {
            return $slots;
        }

        $promptRequestsOnlyHoldingLocation = self::promptExplicitlyRequestsOnlyHoldingLocation($prompt);
        $rawOnlyHoldingLocation = $slots['only_holding_location'] ?? null;
        $onlyHoldingLocation = self::normalizeBooleanPromptValue($rawOnlyHoldingLocation);

        if ($promptRequestsOnlyHoldingLocation) {
            if ($onlyHoldingLocation !== true) {
                $slots['only_holding_location'] = true;
                $slotProvenance['only_holding_location'] = array_key_exists('only_holding_location', $slotProvenance)
                    ? 'prompt_repaired'
                    : (array_key_exists('only_holding_location', $slots) ? 'prompt_repaired' : 'prompt_explicit');
            } else {
                $slotProvenance['only_holding_location'] = 'prompt_explicit';
            }

            return $slots;
        }

        if ($onlyHoldingLocation === true) {
            unset($slots['only_holding_location']);
            $slotProvenance['only_holding_location'] = 'policy_omitted_explicit_prompt_only';
        }

        return $slots;
    }

    private static function normalizeBooleanPromptValue($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        return null;
    }

    private static function recoverCollectionAgeFamilySlotsFromPrompt(array $slots, string $prompt, array $slotProvenance = []): array
    {
        if (trim($prompt) === '') {
            return [
                'slots' => $slots,
                'slotProvenance' => $slotProvenance,
            ];
        }

        if (self::promptRequestsCollectionAgeItemCount($prompt)) {
            $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];
            $requestedOutputs[] = 'item_count';
            if (!in_array('average_age_years', $requestedOutputs, true)) {
                $requestedOutputs[] = 'average_age_years';
            }
            $slots['requested_outputs'] = array_values(array_unique($requestedOutputs));
            sort($slots['requested_outputs'], SORT_STRING);
            $slotProvenance['requested_outputs'] = 'prompt_repaired';
        }

        $locationRequiresExplicitPrompt = QueryFamilySlotService::slotRequiresExplicitPromptEvidence(
            'inventory_collection_age',
            'location'
        );

        $existingLibrary = trim((string)($slots['library'] ?? ''));
        $recoveredLibrary = self::extractCollectionAgeLibraryScope($prompt);
        if ($recoveredLibrary !== '') {
            $slots['library'] = $recoveredLibrary;
            if ($existingLibrary === '') {
                $slotProvenance['library'] = 'prompt_explicit';
            } elseif (strcasecmp($existingLibrary, $recoveredLibrary) !== 0) {
                $slotProvenance['library'] = 'prompt_repaired';
            }
        }

        $existingLocation = trim((string)($slots['location'] ?? ''));
        $effectiveLibrary = $recoveredLibrary !== '' ? $recoveredLibrary : $existingLibrary;
        $recoveredLocation = self::extractCollectionAgeLocationScope($prompt, $effectiveLibrary);
        if ($recoveredLocation !== '') {
            $slots['location'] = $recoveredLocation;
            if ($existingLocation === '') {
                $slotProvenance['location'] = 'prompt_explicit';
            } elseif (strcasecmp($existingLocation, $recoveredLocation) !== 0) {
                $slotProvenance['location'] = 'prompt_repaired';
            }
        } elseif ($locationRequiresExplicitPrompt && !self::promptMentionsExplicitCollectionAgeLocationScope($prompt, $effectiveLibrary)) {
            unset($slots['location']);
            $slotProvenance['location'] = 'policy_omitted_explicit_prompt_only';
        }

        ksort($slotProvenance, SORT_STRING);
        return [
            'slots' => $slots,
            'slotProvenance' => $slotProvenance,
        ];
    }

    private static function extractCollectionAgeLibraryScope(string $prompt): string
    {
        $namedCollectionScope = self::extractNamedCollectionAgeCollectionScope($prompt);
        if (($namedCollectionScope['library'] ?? '') !== '') {
            return (string)$namedCollectionScope['library'];
        }

        $patterns = [
            '/\b(?:in|at|from|for)\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?library)\b/i',
            '/\bof\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?library)\s+collections?\b/i',
            '/\b(?:of|in|at|for)\s+(?:items\s+in\s+)?(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?)\s+reference collection\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            $library = self::normalizeRecoveredPromptScope((string)($matches[1] ?? ''));
            if ($library === '') {
                continue;
            }

            if (preg_match('/\blibrary\b/i', $library) !== 1) {
                $library .= ' Library';
            }

            return $library;
        }

        return '';
    }

    private static function extractCollectionAgeLocationScope(string $prompt, string $libraryScope = ''): string
    {
        $namedReferenceLocation = self::extractNamedReferenceCollectionLocationScope($prompt);
        if ($namedReferenceLocation !== '') {
            return $namedReferenceLocation;
        }

        $namedCollectionScope = self::extractNamedCollectionAgeCollectionScope($prompt);
        if (($namedCollectionScope['location'] ?? '') !== '') {
            return (string)$namedCollectionScope['location'];
        }

        $explicitLocationScope = self::extractExplicitCollectionAgeLocationScope($prompt, $libraryScope);
        if ($explicitLocationScope !== '') {
            return $explicitLocationScope;
        }

        if (
            QueryFamilySlotService::slotRequiresExplicitPromptEvidence('inventory_collection_age', 'location')
            && !self::promptMentionsExplicitCollectionAgeLocationScope($prompt, $libraryScope)
        ) {
            return '';
        }

        $library = self::extractCollectionAgeLibraryScope($prompt);
        if (preg_match('/^(.+?)\s+library$/i', $library, $matches) === 1) {
            $baseName = self::normalizeRecoveredPromptScope((string)($matches[1] ?? ''));
            if ($baseName !== '') {
                return $baseName . ' Reference';
            }
        }

        if (preg_match('/\breference collection\b/i', $prompt) === 1) {
            return 'Reference collection';
        }

        return '';
    }

    private static function promptMentionsExplicitCollectionAgeLocationScope(string $prompt, string $libraryScope = ''): bool
    {
        return preg_match('/\breference collection\b/i', $prompt) === 1
            || self::extractNamedCollectionAgeCollectionScope($prompt) !== []
            || self::extractExplicitCollectionAgeLocationScope($prompt, $libraryScope) !== '';
    }

    private static function extractNamedReferenceCollectionLocationScope(string $prompt): string
    {
        $patterns = [
            '/\b(?:of|in|at|for)\s+(?:items\s+in\s+)?(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?)\s+reference collection\b/i',
            '/\breference collection\s+(?:in|at|from|for)\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?library)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            $baseName = self::normalizeRecoveredPromptScope((string)($matches[1] ?? ''));
            $baseName = trim((string) preg_replace('/\s+library\s*$/i', '', $baseName));
            if ($baseName === '' || in_array(strtolower($baseName), ['a', 'an', 'the', 'this', 'that', 'item', 'items'], true)) {
                continue;
            }

            return $baseName . ' Reference';
        }

        return '';
    }

    private static function extractNamedCollectionAgeCollectionScope(string $prompt): array
    {
        $patterns = [
            [
                'pattern' => '/\b(?:of|in|at|from|for)\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?library)\s+([a-z0-9][a-z0-9 .\'\-]*?)\s+collection\b/i',
                'libraryIndex' => 1,
                'locationIndex' => 2,
            ],
            [
                'pattern' => '/\b(?:of|in|at|from|for)\s+(?:items\s+in\s+)?(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?)\s+collection\s+in\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?library)\b/i',
                'libraryIndex' => 2,
                'locationIndex' => 1,
                'locationSuffix' => '',
            ],
            [
                'pattern' => '/\b(?:of|in|at|from|for)\s+(?:items\s+in\s+)?(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?)\s+collection\s+(?:at|from|for)\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?library)\b/i',
                'libraryIndex' => 2,
                'locationIndex' => 1,
                'locationSuffix' => ' Collection',
            ],
        ];

        foreach ($patterns as $patternSpec) {
            if (preg_match($patternSpec['pattern'], $prompt, $matches) !== 1) {
                continue;
            }

            $library = self::normalizeRecoveredPromptScope((string)($matches[$patternSpec['libraryIndex']] ?? ''));
            $location = self::normalizeRecoveredPromptScope((string)($matches[$patternSpec['locationIndex']] ?? ''));
            $location = trim((string) preg_replace('/\s+collection\s*$/i', '', $location));
            $locationSuffix = (string)($patternSpec['locationSuffix'] ?? '');

            if ($library === '' || $location === '') {
                continue;
            }

            if (preg_match('/\blibrary\b/i', $library) !== 1) {
                $library .= ' Library';
            }
            $library = trim((string) preg_replace('/\blibrary\b/i', 'Library', $library));
            if ($locationSuffix !== '') {
                $library = trim((string) preg_replace('/\s+Library\s*$/i', '', $library));
            }

            if (in_array(strtolower($location), ['a', 'an', 'the', 'this', 'that', 'item', 'items', 'reference'], true)) {
                continue;
            }

            $locationWasLowercase = $location === strtolower($location);
            if ($locationSuffix !== '' && stripos($location, trim($locationSuffix)) === false) {
                $location .= $locationSuffix;
            }

            if ($locationWasLowercase) {
                $location = ucwords($location);
            }

            return [
                'library' => $library,
                'location' => $location,
            ];
        }

        return [];
    }

    private static function extractExplicitCollectionAgeLocationScope(string $prompt, string $libraryScope = ''): string
    {
        $patterns = [
            '/\b(?:of|in|at|from|for)\s+(?:items\s+in\s+)?(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?)\s+collections?\b/i',
            '/\b(?:of|in|at|from|for)\s+(?:items\s+in\s+)?(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?(?:stacks?|room|case|reserve|reserves))\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            $location = self::normalizeCollectionAgeLocationScope(
                (string)($matches[1] ?? ''),
                $libraryScope
            );

            if ($location !== '') {
                return $location;
            }
        }

        return '';
    }

    private static function promptRequestsCollectionAgeItemCount(string $prompt): bool
    {
        return preg_match('/\b(how many|number of|count of|total items?|item count)\b/i', $prompt) === 1;
    }

    private static function normalizeCollectionAgeLocationScope(string $value, string $libraryScope = ''): string
    {
        $normalized = self::normalizeRecoveredPromptScope($value);
        $normalized = trim((string) preg_replace('/\s+collections?\s*$/i', '', $normalized));
        $normalized = self::stripCollectionAgeLibraryQualifier($normalized, $libraryScope);
        if (preg_match('/\s+library\s*$/i', $normalized) === 1) {
            return '';
        }
        $hadUppercase = preg_match('/[A-Z]/', $normalized) === 1;
        $normalized = trim((string) preg_replace('/\bstacks\b/i', 'Stack', $normalized));

        if ($normalized === '') {
            return '';
        }

        $lower = strtolower($normalized);
        if (in_array($lower, ['a', 'an', 'the', 'this', 'that', 'item', 'items', 'collection', 'collections', 'library'], true)) {
            return '';
        }

        if (!$hadUppercase || $normalized === $lower) {
            $normalized = ucwords($normalized);
        }

        return $normalized;
    }

    private static function stripCollectionAgeLibraryQualifier(string $location, string $libraryScope): string
    {
        $location = self::normalizeRecoveredPromptScope($location);
        $libraryScope = self::normalizeRecoveredPromptScope($libraryScope);
        if ($location === '' || $libraryScope === '') {
            return $location;
        }

        $libraryWithoutSuffix = trim((string) preg_replace('/\s+library\s*$/i', '', $libraryScope));
        $qualifiers = array_filter(array_unique([
            $libraryScope,
            $libraryWithoutSuffix,
            strtok($libraryWithoutSuffix, ' ') ?: '',
        ]));

        usort($qualifiers, static function (string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });

        foreach ($qualifiers as $qualifier) {
            $pattern = '/^' . preg_quote($qualifier, '/') . '\b\s*/i';
            $stripped = trim((string) preg_replace($pattern, '', $location, 1));
            if ($stripped !== $location) {
                return $stripped;
            }
        }

        return $location;
    }

    private static function normalizeRecoveredPromptScope(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function extractInventoryListingLocationCodes(string $prompt): array
    {
        if (trim($prompt) === '') {
            return [];
        }

        $patterns = [
            '/\blocation codes?\s+((?:[A-Z0-9]{3,10}(?:\s*(?:,|and|or)\s*)?)+)\b/i',
            '/\b(?:in|at|from|for)\s+(?:the\s+)?((?:[A-Z0-9]{3,10}(?:\s*(?:,|and|or)\s*)?)+)\s+locations?\b/i',
            '/\b(?:in|at|from|for)\s+(?:the\s+)?((?:[A-Z0-9]{3,10}(?:\s*(?:,|and|or)\s*)?)+)\s+location codes?\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            preg_match_all('/\b[A-Z0-9]{3,10}\b/', strtoupper((string)($matches[1] ?? '')), $codeMatches);
            $codes = [];
            foreach (($codeMatches[0] ?? []) as $code) {
                if (in_array($code, ['AND', 'OR'], true)) {
                    continue;
                }

                if (!in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }

            if ($codes !== []) {
                return $codes;
            }
        }

        return [];
    }

    private static function promptExplicitlyRequestsOnlyHoldingLocation(string $prompt): bool
    {
        if (trim($prompt) === '') {
            return false;
        }

        $normalizedPrompt = strtolower((string)$prompt);

        if (preg_match('/\bonly\s+(?:the\s+)?(?:holding|holdings?)\s+location\b/i', $normalizedPrompt) === 1) {
            return true;
        }

        if (preg_match('/\b(?:exclusive|exclusively|solely)\s+(?:holding|holdings?)\s+location\b/i', $normalizedPrompt) === 1) {
            return true;
        }

        if (preg_match('/\bno\s+other\s+(?:holding\s+)?locations?\b/i', $normalizedPrompt) === 1) {
            return true;
        }

        return false;
    }

    private static function inventoryListingPayloadHasOnlyHoldingLocationScope(array $slots): bool
    {
        $location = trim((string)($slots['location'] ?? ''));
        if ($location !== '') {
            return true;
        }

        return trim((string)($slots['location_code'] ?? '')) !== '';
    }

    private static function valueLooksLikeLocationCodeList(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        $tokens = preg_split('/\s*(?:,|and|or)\s*/i', strtoupper($value)) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static function (string $token): bool {
            return $token !== '';
        }));

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (preg_match('/^[A-Z0-9]{3,10}$/', $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function promptMentionsExplicitLibraryScope(string $prompt): bool
    {
        return preg_match('/\blibrary\b/i', $prompt) === 1;
    }

    private static function normalizeQueryFamilyPayload(array $normalizedPayload, string $prompt, $campus = null): array
    {
        $familyKey = trim((string)($normalizedPayload['familyKey'] ?? ''));
        if (!is_array($normalizedPayload['slots'] ?? null)) {
            return $normalizedPayload;
        }

        if ($familyKey === 'circulation_trends_matrix') {
            $normalizedPayload['slots'] = self::normalizeTrendFamilySlots(
                $normalizedPayload['slots'],
                $prompt,
                $campus
            );

            return $normalizedPayload;
        }

        if ($familyKey === 'circulation_top_items') {
            $normalizedPayload['slots'] = self::normalizeTopItemsFamilySlots(
                $normalizedPayload['slots'],
                $prompt,
                $campus
            );
        }

        if ($familyKey === 'inventory_library_location_listing') {
            $slotProvenance = [];
            $normalizedPayload['slots'] = self::removeInventoryListingDefaultCampusForFiveCollegesOnlyHolding(
                $normalizedPayload['slots'],
                $prompt,
                $slotProvenance
            );
        }

        return $normalizedPayload;
    }

    private static function normalizeTrendFamilySlots(array $slots, string $prompt, $campus = null): array
    {
        $normalizedPrompt = strtolower(trim($prompt));
        if ($normalizedPrompt === '') {
            return $slots;
        }

        $groupingDimension = trim((string)($slots['grouping_dimension'] ?? ''));
        if ($groupingDimension !== '') {
            $normalizedGroupingDimension = strtolower(str_replace(['-', ' '], '_', $groupingDimension));
            if (in_array($normalizedGroupingDimension, ['primary_call_number_class', 'call_number_class'], true)) {
                $slots['grouping_dimension'] = 'primary_call_number_class';
            }
        }

        $circulationSourcePolicy = trim((string)($slots['circulation_source_policy'] ?? ''));
        if (self::promptMentionsFormerAlephComparisonPolicy($normalizedPrompt)) {
            $slots['circulation_source_policy'] = 'former_aleph_comparison';

            return $slots;
        }

        if (self::promptMentionsPriorYearComparisonPolicy($normalizedPrompt)) {
            $slots['circulation_source_policy'] = 'prior_year_comparison';

            return $slots;
        }

        if (self::promptMentionsCumulativeBeforeComparisonPolicy($normalizedPrompt)) {
            $slots['circulation_source_policy'] = 'cumulative_before_selected_years_comparison';

            return $slots;
        }

        if (
            ($circulationSourcePolicy === '' || $circulationSourcePolicy !== 'current_loans_only')
            && !self::promptMentionsExplicitHistoricalCirculationPolicy($normalizedPrompt)
        ) {
            $slots['circulation_source_policy'] = 'current_loans_only';
        }

        return $slots;
    }

    private static function normalizeTopItemsFamilySlots(array $slots, string $prompt, $campus = null): array
    {
        $materialType = strtolower(trim((string)($slots['material_type'] ?? '')));
        if ($materialType !== '' && preg_match('/\bbooks?\b/', $materialType) === 1) {
            $slots['material_type'] = 'book';
        }

        $limit = trim((string)($slots['limit'] ?? ''));
        if ($limit === '' && preg_match('/\btop\s+(\d+)\b/i', $prompt, $matches) === 1) {
            $slots['limit'] = $matches[1];
        }

        return $slots;
    }

    private static function extractInventoryListingMaterialTypeFromPrompt(string $prompt): string
    {
        $patterns = [
            '/\b(?:material|document|item)\s+type\s+(?:of|is|=|:)?\s*"([^"]+)"/i',
            "/\b(?:material|document|item)\s+type\s+(?:of|is|=|:)?\s*'([^']+)'/i",
            '/\b(?:material|document|item)\s+type\s+(?:of|is|=|:)?\s+([a-z0-9][a-z0-9 ._-]*?)(?:\.|,|\band\b|\bat\b|\bin\b|\bfor\b|\bfrom\b|\bwith\b|\binclude\b|$)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) === 1) {
                return trim((string)($matches[1] ?? ''));
            }
        }

        return '';
    }

    /**
     * Canonicalize a free-text item-status value to the lowercased, single-spaced
     * form used by FOLIO status names so hyphen/case variants ("Checked-Out")
     * compare equal to the stored value ("Checked out").
     */
    private static function normalizeItemStatusValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string)preg_replace('/[^a-z0-9]+/', ' ', $normalized);

        return trim((string)preg_replace('/\s+/', ' ', $normalized));
    }

    private static function extractInventoryListingItemStatusFromPrompt(string $prompt): string
    {
        $patterns = [
            '/\bitem\s+status\s+(?:of|is|=|:)?\s*"([^"]+)"/i',
            "/\bitem\s+status\s+(?:of|is|=|:)?\s*'([^']+)'/i",
            '/\bstatus\s+of\s+"([^"]+)"/i',
            "/\bstatus\s+of\s+'([^']+)'/i",
            '/\bitem\s+status\s+(?:of|is|=|:)?\s+([a-z0-9][a-z0-9 ._-]*?)(?:\.|,|\band\b|\bwith\b|\binclude\b|$)/i',
            '/\bstatus\s+of\s+([a-z0-9][a-z0-9 ._-]*?)(?:\.|,|\band\b|\bwith\b|\binclude\b|$)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) === 1) {
                $value = trim((string)($matches[1] ?? ''));
                if (self::valueLooksLikeItemStatus($value)) {
                    return self::normalizeItemStatusValue($value);
                }
            }
        }

        return '';
    }

    private static function promptMentionsExplicitHistoricalCirculationPolicy(string $normalizedPrompt): bool
    {
        if (self::promptMentionsFormerAlephComparisonPolicy($normalizedPrompt)
            || self::promptMentionsPriorYearComparisonPolicy($normalizedPrompt)
            || self::promptMentionsCumulativeBeforeComparisonPolicy($normalizedPrompt)
        ) {
            return true;
        }

        return preg_match('/\baudit[_ -]?loan\b/', $normalizedPrompt) === 1;
    }

    private static function buildFamilySlotClarificationResponse(
        array $errors,
        array $intent,
        array $telemetryContext
    ): ?array {
        $missingSlots = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            if ((string)($error['code'] ?? '') !== 'required') {
                continue;
            }

            $path = (string)($error['path'] ?? '');
            if (strpos($path, 'slots.') !== 0) {
                continue;
            }

            $slotName = substr($path, strlen('slots.'));
            if ($slotName === false || $slotName === '') {
                continue;
            }

            $missingSlots[] = $slotName;
        }

        $missingSlots = array_values(array_unique($missingSlots));
        sort($missingSlots);
        if (empty($missingSlots)) {
            return null;
        }

        if (self::isTwoLaneEnabled()) {
            self::guardCoveredFamilyFallback(
                (string)($intent['familyKey'] ?? ''),
                'missing_required_slot',
                $telemetryContext
            );
        }

        $question = self::buildFamilySlotClarificationQuestion($missingSlots);
        $routeReason = 'family_slot_missing_required_slot';

        self::logRouteSelection('clarification', $routeReason, [
            'familyKey' => $intent['familyKey'] ?? null,
            'missingSlots' => $missingSlots,
        ]);
        self::logNlTelemetry('nl2sql.generated', [
            'route' => 'clarification',
            'routeReason' => $routeReason,
            'model' => $telemetryContext['model'] ?? null,
            'promptVersion' => $telemetryContext['promptVersion'] ?? null,
            'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
            'finishReason' => $telemetryContext['finishReason'] ?? null,
            'dataSource' => null,
            'attempts' => $telemetryContext['attempts'] ?? null,
            'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
            'missingSlots' => $missingSlots,
        ] + $telemetryContext);

        return [
            'needsClarification' => true,
            'clarificationType' => 'missing_required_slot',
            'clarificationKey' => 'family_slot:' . implode(',', $missingSlots),
            'freeTextAllowed' => true,
            'question' => $question,
            'message' => self::buildFamilySlotClarificationMessage($missingSlots),
            'options' => [],
            'missingSlots' => $missingSlots,
            'warnings' => [],
            'suggestions' => [],
            'route' => 'clarification',
            'routeReason' => $routeReason,
        ];
    }

    private static function buildFamilySlotClarificationQuestion(array $missingSlots): string
    {
        sort($missingSlots);

        if ($missingSlots === ['library']) {
            return 'Which library should I use for this report?';
        }

        if ($missingSlots === ['location']) {
            return 'Which location or collection should I use for this report?';
        }

        if ($missingSlots === ['location_code']) {
            return 'Which location code should I use for this report?';
        }

        if (count($missingSlots) === 1) {
            switch ($missingSlots[0]) {
                case 'contributor_name':
                    return 'Which contributor should I use for this report?';
                case 'campus':
                    return 'Which campus should I scope this report to?';
                case 'material_type':
                    return 'Which material type should I use for this report?';
                case 'grouping_dimension':
                    return 'Which grouping dimension should I use for this report?';
                case 'year_buckets':
                    return 'Which years should I use for this report?';
                case 'requested_outputs':
                    return 'What fields should I include in the results?';
            }
        }

        return 'I need one more detail before I can generate SQL for this request.';
    }

    private static function buildFamilySlotClarificationMessage(array $missingSlots): string
    {
        sort($missingSlots);

        if ($missingSlots === ['library']) {
            return 'Type the library name, for example Neilson Library.';
        }

        if ($missingSlots === ['location']) {
            return 'Type the location or collection name, for example SC Rare Book Collection Reference.';
        }

        if ($missingSlots === ['location_code']) {
            return 'Type the location code, for example SJTR.';
        }

        if ($missingSlots === ['contributor_name']) {
            return 'Type the contributor or author name to use in the report.';
        }

        return 'Type the missing detail and continue.';
    }

    private static function buildCompiledQueryFamilyOrLegacyFallback(
        array $normalizedPayload,
        string $routeReason,
        $prompt,
        $campus,
        array $telemetryContext,
        $compiler = null,
        $legacyFallbackFactory = null,
        $exploratoryFallbackFactory = null,
        ?string $originalQuestion = null,
        array $resolvedFilters = []
    ): array {
        $originalQuestion = $originalQuestion === null ? (string)$prompt : $originalQuestion;
        if ($compiler === null) {
            $compiler = function (array $payload, string $reason): array {
                return self::buildCompiledQueryFamilyResult($payload, $reason);
            };
        }

        if ($legacyFallbackFactory === null) {
            $legacyFallbackFactory = function () use ($prompt, $campus, $originalQuestion, $resolvedFilters): array {
                return self::generateSql(
                    $prompt,
                    $campus,
                    true,
                    false,
                    $originalQuestion,
                    $resolvedFilters
                );
            };
        }

        try {
            $compiled = $compiler($normalizedPayload, $routeReason);
        } catch (
            CanonicalLaneFallbackException
            | \app\exceptions\PolicyViolationException
            | DatabaseQueryCancelledException
            | ExploratorySqlValidationException $e
        ) {
            throw $e;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            if (self::isHardCanonicalFailure($e)) {
                throw $e;
            }
            $reason = 'family_compiler_failed';
            $familyKey = trim((string)($normalizedPayload['familyKey'] ?? ''));

            if ($familyKey === 'inventory_library_location_listing') {
                return self::buildInventoryListingCompilerClarificationResponse(
                    $normalizedPayload,
                    $telemetryContext,
                    $e,
                    (string)$prompt,
                    $campus,
                    $exploratoryFallbackFactory,
                    $originalQuestion,
                    $resolvedFilters
                );
            }

            self::guardCoveredFamilyFallback(
                $familyKey,
                $reason,
                $telemetryContext,
                $e
            );

            self::logRouteSelection('legacy_fallback', $reason . ': ' . $e->getMessage(), [
                'query' => [],
            ]);
            $fallback = $legacyFallbackFactory();
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $reason;
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
            ] + $telemetryContext);
            return self::withKnownFamilyEvidence($fallback, $familyKey);
        }

        self::validateResolvedReferenceResult($compiled, $resolvedFilters);
        return $compiled;
    }

    private static function buildInventoryListingCompilerClarificationResponse(
        array $normalizedPayload,
        array $telemetryContext,
        \Throwable $error,
        string $prompt = '',
        $campus = null,
        $exploratoryFallbackFactory = null,
        ?string $originalQuestion = null,
        array $resolvedFilters = []
    ): array {
        if (self::isTwoLaneEnabled()) {
            self::guardCoveredFamilyFallback(
                (string)($normalizedPayload['familyKey'] ?? ''),
                'family_compiler_failed',
                $telemetryContext,
                $error
            );
        }

        $originalQuestion = $originalQuestion === null ? $prompt : $originalQuestion;
        $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
        $library = trim((string)($slots['library'] ?? ''));
        if (
            (
                !self::promptMentionsLibraryLocationListingScope($originalQuestion)
                && !self::promptMentionsCampusScopedInventoryItemFilterListing($originalQuestion)
            )
            || self::valueLooksLikeItemStatus($library)
        ) {
            $response = $exploratoryFallbackFactory === null
                ? self::generateExploratorySqlResponse(
                    $prompt,
                    $campus,
                    'inventory_listing_unscoped_compiler_failed',
                    $originalQuestion,
                    $resolvedFilters
                )
                : $exploratoryFallbackFactory();
            $response['message'] = 'This looks like a campus-scoped inventory request, not a library or location request. I can still try to build and run the query, but the results may be incomplete or inaccurate. Check the reported scope and assumptions before using the results.';
            return self::withKnownFamilyEvidence(
                $response,
                (string)($normalizedPayload['familyKey'] ?? '')
            );
        }

        $routeReason = 'inventory_listing_compiler_failed';
        $knownScopeParts = [];
        foreach (['campus', 'library', 'location', 'location_code'] as $slotName) {
            $value = trim((string)($slots[$slotName] ?? ''));
            if ($value !== '') {
                $knownScopeParts[] = str_replace('_', ' ', $slotName) . ': ' . $value;
            }
        }

        self::logValidationFailure('family_compiler_failed_clarification', [
            'route' => 'clarification',
            'routeReason' => $routeReason,
            'familyKey' => $normalizedPayload['familyKey'] ?? null,
            'error' => $error->getMessage(),
        ] + $telemetryContext);
        self::logRouteSelection('clarification', $routeReason, [
            'familyKey' => $normalizedPayload['familyKey'] ?? null,
            'knownScope' => $knownScopeParts,
        ]);
        self::logNlTelemetry('nl2sql.generated', [
            'route' => 'clarification',
            'routeReason' => $routeReason,
            'model' => $telemetryContext['model'] ?? null,
            'promptVersion' => $telemetryContext['promptVersion'] ?? null,
            'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
            'finishReason' => $telemetryContext['finishReason'] ?? null,
            'dataSource' => null,
            'attempts' => $telemetryContext['attempts'] ?? null,
            'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
        ] + $telemetryContext);

        $scopeSummary = $knownScopeParts === []
            ? 'I could not confirm the inventory location scope.'
            : 'I found this scope: ' . implode('; ', $knownScopeParts) . '.';
        $question = 'Can you confirm the exact library, location, or location code I should use for this inventory listing?';
        $message = $scopeSummary . ' Type the library name, location name, or location code to use, then continue.';

        if (self::promptMentionsCampusScopedInventoryItemFilterListing($originalQuestion)) {
            $question = 'I could not produce fully validated SQL for this campus-scoped item listing.';
            $message = 'This request matches a reviewed item-listing pattern, but the validated SQL compiler failed before a query could be produced. Review the request or try again.';
        }

        return [
            'needsClarification' => true,
            'clarificationType' => 'inventory_listing_scope',
            'clarificationKey' => 'inventory_listing_scope',
            'freeTextAllowed' => true,
            'question' => $question,
            'message' => $message,
            'options' => [],
            'missingSlots' => [],
            'warnings' => [],
            'suggestions' => [],
            'route' => 'clarification',
            'routeReason' => $routeReason,
        ];
    }

    private static function buildCompiledQueryFamilyResult(array $normalizedPayload, string $routeReason): array
    {
        $queryDef = QueryFamilyCompilerService::compileToQueryDefinition($normalizedPayload);
        $built = QueryFamilyCompilerService::compileToSql($normalizedPayload);

        $sql = self::inlineParams($built['sql'] ?? '', $built['params'] ?? []);
        $sql = self::normalizeGeneratedSql($sql);
        $sql = self::repairOnlyHoldingLocationAliasLeaks($sql);
        $sql = self::repairResolvedLocationPredicateMisuse($sql);
        self::validateNoOnlyHoldingLocationAliasLeaks($sql);
        self::validateNoResolvedLocationPredicateMisuse($sql);

        SqlBuilderService::validateSafety($sql);
        SqlBuilderService::validateTablePolicy($sql);
        self::validateTableReferences($sql);

        try {
            self::validateCompiledQueryFamilyShape($normalizedPayload, $queryDef, $sql);
        } catch (\InvalidArgumentException $e) {
            $issueFamily = 'family_sql_shape';
            if (preg_match('/^([a-z0-9_]+)/i', $e->getMessage(), $matches) === 1) {
                $issueFamily = strtolower((string)$matches[1]);
            }

            self::logValidationFailure('family_sql_shape', [
                'route' => 'builder_intent',
                'routeReason' => $routeReason,
                'familyKey' => $normalizedPayload['familyKey'] ?? null,
                'issueFamily' => $issueFamily,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $dataSource = 'folio';
        if (preg_match('/\b(acrl_statistics|report_expense_allocations)\b/i', $sql)) {
            $dataSource = 'local';
        }

        $tables = $queryDef['tables'] ?? [];
        $explanation = 'Generated from structured family compiler mode.';
        if (!empty($tables)) {
            $explanation .= ' Tables: ' . implode(', ', $tables) . '.';
        }

        return [
            'sql' => $sql,
            'explanation' => $explanation,
            'dataSource' => $dataSource,
            'route' => 'builder_intent',
            'routeReason' => $routeReason,
            'queryDefinition' => $queryDef,
        ];
    }

    private static function validateCompiledQueryFamilyShape(array $normalizedPayload, array $queryDef, string $sql): void
    {
        $familyKey = trim((string)($normalizedPayload['familyKey'] ?? ''));
        if ($familyKey === 'inventory_collection_age') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];

            $hasPublicationYearAnchor = in_array('inventory_instance__t__publication', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_instance__t__publication')
                && stripos($sql, 'LEFT JOIN inventory.instance__t__publication') !== false
                && stripos($sql, 'publication__date_of_publication') !== false
                && stripos($sql, "publication__date_of_publication ~ '^\\d{4}'") !== false;

            $usesInvalidAgeSource = preg_match('/\b(status__date|metadata__created_date|cataloged_date)\b/i', $sql) === 1;
            if (!$hasPublicationYearAnchor || $usesInvalidAgeSource) {
                throw new \InvalidArgumentException(
                    'missing_publication_year_anchor: Collection-age family prompts require validated instance publication-year logic.'
                );
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = in_array('inventory_libraries', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                    && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                    && self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Collection-age family prompts require a library lookup join and filter.'
                    );
                }
            }

            $location = trim((string)($slots['location'] ?? ''));
            if ($location !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationFilter = QueryFamilySlotService::resolveSlotMatch('location', $location, $matchPolicy);
                $hasLocationScope = in_array('inventory_locations', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_items', 'inventory_locations')
                    && stripos($sql, 'JOIN inventory.location__t') !== false
                    && self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'name', (string)($expectedLocationFilter['value'] ?? ''));

                if (!$hasLocationScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_scope_anchor: Collection-age family location prompts require a location lookup join and filter.'
                    );
                }
            }

            return;
        }

        if ($familyKey === 'inventory_library_location_listing') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];
            $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];
            $onlyHoldingLocation = !empty($slots['only_holding_location']);

            $requiresItemOutput = false;
            foreach ($requestedOutputs as $outputField) {
                if (in_array($outputField, ['barcode', 'item_id'], true)) {
                    $requiresItemOutput = true;
                    break;
                }
            }

            $hasInventoryListingScopeAnchor = in_array('inventory_instances', $queryTables, true)
                && in_array('inventory_holdings', $queryTables, true)
                && in_array('inventory_locations', $queryTables, true)
                && in_array('inventory_libraries', $queryTables, true)
                && in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_holdings')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_holdings', 'inventory_items')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && (
                    stripos($sql, 'JOIN inventory.holdings_record__t') !== false
                    || stripos($sql, 'FROM inventory.holdings_record__t') !== false
                )
                && stripos($sql, 'JOIN inventory.location__t') !== false
                && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false;

            if ($onlyHoldingLocation) {
                $hasOnlyHoldingAnchor = $hasInventoryListingScopeAnchor
                    && preg_match('/\\bNOT\\s+EXISTS\\b[\\s\\S]*other_hr[\\s\\S]*NOT\\s+IN\\s*\\(\\s*SELECT\\s+id\\s+FROM\\s+target_locations\\s*\\)/i', $sql) === 1;

                if (!$hasOnlyHoldingAnchor) {
                    throw new \InvalidArgumentException(
                        'missing_only_holding_anchor: Listing prompts with only-holding-location intent require anti-join logic that excludes instances with additional non-target holdings locations.'
                    );
                }
            }

            if ($requiresItemOutput) {
                $hasInventoryListingScopeAnchor = $hasInventoryListingScopeAnchor && stripos($sql, 'JOIN inventory.item__t') !== false;
            }

            if (!$hasInventoryListingScopeAnchor) {
                if (!$onlyHoldingLocation) {
                    throw new \InvalidArgumentException(
                        'missing_inventory_listing_scope_anchor: Library/location listing prompts require the canonical inventory scope path from instances through holdings, items, and library lookups.'
                    );
                }

                throw new \InvalidArgumentException(
                    'missing_only_holding_inventory_listing_scope_anchor: Listing prompts with only-holding-location intent require the holdings-to-location inventory scope path and anti-join exclusion logic.'
                );
            }

            $requiresContributorJoin = false;
            foreach ($requestedOutputs as $outputField) {
                if (in_array($outputField, ['author', 'contributor_name'], true)) {
                    $requiresContributorJoin = true;
                    break;
                }
            }

            if ($requiresContributorJoin) {
                $hasContributorOutputAnchor = in_array('inventory_instance__t__contributors', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_instance__t__contributors')
                    && stripos($sql, 'JOIN inventory.instance__t__contributors') !== false;

                if (!$hasContributorOutputAnchor) {
                    throw new \InvalidArgumentException(
                        'missing_listing_contributor_anchor: Library/location listing prompts that request author outputs require the contributor join.'
                    );
                }
            }

            $campus = trim((string)($slots['campus'] ?? ''));
            if ($campus !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedCampusFilter = QueryFamilySlotService::resolveSlotMatch('campus', $campus, $matchPolicy);
                $hasCampusScope = self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', (string)($expectedCampusFilter['value'] ?? ''));

                if (!$hasCampusScope) {
                    throw new \InvalidArgumentException(
                        'missing_campus_scope_anchor: Library/location listing prompts require a campus lookup filter when campus scope is present.'
                    );
                }
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Library/location listing prompts require a library lookup filter.'
                    );
                }
            }

            $location = trim((string)($slots['location'] ?? ''));
            if ($location !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationFilter = QueryFamilySlotService::resolveSlotMatch('location', $location, $matchPolicy);
                $hasLocationScope = self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'name', (string)($expectedLocationFilter['value'] ?? ''));

                if (!$hasLocationScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_scope_anchor: Library/location listing prompts require a location lookup filter when explicit location scope is present.'
                    );
                }
            }

            $locationCode = trim((string)($slots['location_code'] ?? ''));
            if ($locationCode !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationCodeFilter = QueryFamilySlotService::resolveSlotMatch('location_code', $locationCode, $matchPolicy);
                $hasLocationCodeScope = self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'code', (string)($expectedLocationCodeFilter['value'] ?? ''));

                if (!$hasLocationCodeScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_code_scope_anchor: Library/location listing prompts with location codes require an inventory location code filter.'
                    );
                }
            }

            return;
        }

        if ($familyKey === 'circulation_top_items') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];

            $hasInventoryScopeAnchor = in_array('inventory_instances', $queryTables, true)
                && in_array('inventory_holdings', $queryTables, true)
                && in_array('inventory_items', $queryTables, true)
                && in_array('inventory_locations', $queryTables, true)
                && in_array('inventory_libraries', $queryTables, true)
                && in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_holdings')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_holdings', 'inventory_items')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_items', 'inventory_locations')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && stripos($sql, 'JOIN inventory.holdings_record__t') !== false
                && stripos($sql, 'JOIN inventory.instance__t') !== false
                && stripos($sql, 'JOIN inventory.location__t') !== false
                && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false;

            if (!$hasInventoryScopeAnchor) {
                throw new \InvalidArgumentException(
                    'missing_top_items_scope_anchor: Top-items family prompts require the canonical inventory scope path from instances through holdings, items, and library lookups.'
                );
            }

            $campus = trim((string)($slots['campus'] ?? ''));
            if ($campus !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedCampusFilter = QueryFamilySlotService::resolveSlotMatch('campus', $campus, $matchPolicy);
                $hasCampusScope = self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', (string)($expectedCampusFilter['value'] ?? ''));

                if (!$hasCampusScope) {
                    throw new \InvalidArgumentException(
                        'missing_campus_scope_anchor: Top-items family prompts require a campus lookup filter when campus scope is present.'
                    );
                }
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Top-items family prompts require a library lookup filter.'
                    );
                }
            }

            $location = trim((string)($slots['location'] ?? ''));
            if ($location !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationFilter = QueryFamilySlotService::resolveSlotMatch('location', $location, $matchPolicy);
                $hasLocationScope = self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'name', (string)($expectedLocationFilter['value'] ?? ''));

                if (!$hasLocationScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_scope_anchor: Top-items family prompts with explicit location scope require a location lookup filter.'
                    );
                }
            }

            $materialType = trim((string)($slots['material_type'] ?? ''));
            if ($materialType !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedMaterialTypeFilter = QueryFamilySlotService::resolveSlotMatch('material_type', $materialType, $matchPolicy);
                $hasMaterialTypeAnchor = in_array('inventory_material_types', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_items', 'inventory_material_types')
                    && stripos($sql, 'JOIN inventory.material_type__t') !== false
                    && self::queryDefinitionHasFilter($queryDef, 'inventory_material_types', 'name', (string)($expectedMaterialTypeFilter['value'] ?? ''));

                if (!$hasMaterialTypeAnchor) {
                    throw new \InvalidArgumentException(
                        'missing_material_type_anchor: Top-items family prompts require a material-type join and filter for ranked item scope.'
                    );
                }
            }

            $limit = trim((string)($slots['limit'] ?? ''));
            $expectedLimit = $limit === '' ? QueryFamilySlotService::DEFAULT_LIMIT : max(1, min((int)$limit, QueryFamilySlotService::DEFAULT_LIMIT));
            $hasCirculationAnchor = stripos($sql, 'FROM circulation.audit_loan__t al') !== false
                && stripos($sql, "al.loan__action IN ('checkedout', 'checkedOutThroughOverride')") !== false
                && stripos($sql, 'FROM inventory.item__t__notes itn') !== false
                && stripos($sql, "itn.notes__item_note_type_id = '" . QueryFamilyCompilerService::FORMER_CIRCULATION_NOTE_TYPE_ID . "'") !== false
                && stripos($sql, 'AS total_circulation') !== false
                && stripos($sql, 'ORDER BY total_circulation DESC') !== false
                && stripos($sql, 'LIMIT ' . $expectedLimit) !== false;

            if (!$hasCirculationAnchor) {
                throw new \InvalidArgumentException(
                    'missing_top_items_circulation_anchor: Top-items family prompts require audit-loan counts, former-circulation notes, total_circulation ranking, and the requested top-N limit.'
                );
            }

            return;
        }

        if ($familyKey === 'circulation_trends_matrix') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];

            $hasCallNumberClassAnchor = in_array('circulation_loans', $queryTables, true)
                && in_array('inventory_items', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'circulation_loans', 'inventory_items')
                && stripos($sql, 'FROM circulation.loan__t') !== false
                && stripos($sql, 'JOIN inventory.item__t') !== false
                && stripos($sql, 'AS call_number_class') !== false
                && stripos($sql, "effective_call_number_components__call_number ~ '^[A-Z]{1,3}[0-9]'") !== false
                && stripos($sql, 'LPAD(') !== false
                && preg_match('/LEFT\s*\([^\)]*call_number/i', $sql) !== 1;

            if (!$hasCallNumberClassAnchor) {
                throw new \InvalidArgumentException(
                    'missing_call_number_class_anchor: Trend-matrix family prompts require the canonical primary call-number-class extraction logic.'
                );
            }

            $hasLocationBranch = in_array('inventory_locations', $queryTables, true)
                && in_array('inventory_libraries', $queryTables, true)
                && in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'circulation_loans', 'inventory_locations')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && stripos($sql, 'JOIN inventory.location__t') !== false
                && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false;

            if (!$hasLocationBranch) {
                throw new \InvalidArgumentException(
                    'missing_circulation_scope_anchor: Trend-matrix family prompts require the circulation location-to-library scope branch.'
                );
            }

            $campus = trim((string)($slots['campus'] ?? ''));
            if ($campus !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedCampusFilter = QueryFamilySlotService::resolveSlotMatch('campus', $campus, $matchPolicy);
                $hasCampusScope = self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', (string)($expectedCampusFilter['value'] ?? ''));

                if (!$hasCampusScope) {
                    throw new \InvalidArgumentException(
                        'missing_campus_scope_anchor: Trend-matrix family prompts require a campus lookup filter.'
                    );
                }
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Trend-matrix family prompts require a library lookup filter.'
                    );
                }
            }

            $yearBuckets = is_array($slots['year_buckets'] ?? null) ? $slots['year_buckets'] : [];
            foreach ($yearBuckets as $year) {
                $year = (string)$year;
                if (
                    stripos($sql, 'EXTRACT(YEAR FROM cl.loan_date) = ' . $year) === false
                    || stripos($sql, 'AS circulation_' . $year) === false
                ) {
                    throw new \InvalidArgumentException(
                        'missing_year_bucket_anchor: Trend-matrix family prompts require one aggregate column per requested year bucket.'
                    );
                }
            }

            $circulationSourcePolicy = trim((string)($slots['circulation_source_policy'] ?? ''));
            if ($circulationSourcePolicy === 'current_loans_only'
                && (
                    stripos($sql, "cl.action IN ('checkedout', 'checkedOutThroughOverride')") === false
                    || stripos($sql, 'GROUP BY call_number_class') === false
                )
            ) {
                throw new \InvalidArgumentException(
                    'missing_current_circulation_anchor: Trend-matrix family prompts require current checkout action filtering and grouped matrix output.'
                );
            }

            return;
        }

        if ($familyKey !== 'inventory_contributor_campus_item_barcode') {
            return;
        }

        $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
        $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];
        $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];

        $requiresItemBranch = false;
        foreach ($requestedOutputs as $outputField) {
            if (in_array($outputField, ['barcode', 'item_id'], true)) {
                $requiresItemBranch = true;
                break;
            }
        }

        if ($requiresItemBranch) {
            $hasHoldingsBranch = in_array('inventory_holdings', $queryTables, true)
                && in_array('inventory_items', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_holdings')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_holdings', 'inventory_items')
                && stripos($sql, 'JOIN inventory.holdings_record__t') !== false
                && stripos($sql, 'JOIN inventory.item__t') !== false;

            if (!$hasHoldingsBranch) {
                throw new \InvalidArgumentException(
                    'missing_holdings_item_branch: Covered-family item outputs require holdings-to-items joins.'
                );
            }
        }

        $campus = trim((string)($slots['campus'] ?? ''));
        if ($campus !== '') {
            $hasCampusScope = in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false
                && self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', $campus);

            if (!$hasCampusScope) {
                throw new \InvalidArgumentException(
                    'missing_campus_scope_anchor: Covered-family campus prompts require a campus lookup join and filter.'
                );
            }
        }

        $contributorNameType = trim((string)($slots['contributor_name_type'] ?? ''));
        if ($contributorNameType !== '') {
            $hasContributorNameTypeScope = in_array('inventory_contributor_name_types', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instance__t__contributors', 'inventory_contributor_name_types')
                && stripos($sql, 'JOIN inventory.contributor_name_type__t') !== false
                && self::queryDefinitionHasFilter(
                    $queryDef,
                    'inventory_contributor_name_types',
                    'name',
                    $contributorNameType
                );

            if (!$hasContributorNameTypeScope) {
                throw new \InvalidArgumentException(
                    'missing_contributor_name_type_anchor: Covered-family contributor name type prompts require the contributor-name-type join and filter.'
                );
            }
        }
    }

    private static function queryDefinitionHasJoin(array $queryDef, string $fromTable, string $toTable): bool
    {
        foreach (($queryDef['joins'] ?? []) as $join) {
            if (!is_array($join)) {
                continue;
            }

            if (($join['from_table'] ?? null) === $fromTable && ($join['to_table'] ?? null) === $toTable) {
                return true;
            }
        }

        return false;
    }

    private static function queryDefinitionHasFilter(array $queryDef, string $table, string $column, string $value): bool
    {
        foreach (($queryDef['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            if (
                ($filter['table'] ?? null) === $table
                && ($filter['column'] ?? null) === $column
                && (string)($filter['value'] ?? '') === $value
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a scalar value to a SQL literal representation.
     *
     * @param mixed $value
     * @return string
     */
    private static function toSqlLiteral($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_numeric($value)) {
            $numeric = trim((string)$value);
            if (!preg_match('/^0[0-9]+$/', $numeric)) {
                return $numeric;
            }
        }

        $escaped = str_replace("'", "''", (string)$value);
        return "'{$escaped}'";
    }

    /**
     * Validate a QueryIntent payload against the server-side contract.
     *
     * This is intentionally additive for NL2SQL-003 and does not change the
     * existing freeform SQL generation pipeline.
     *
     * @param mixed $intent
     * @return array {valid: bool, errors: array, normalizedIntent: array|null}
     */
    public static function validateQueryIntent($intent)
    {
        return QueryIntentService::validateIntent($intent);
    }

    /**
     * Translate a QueryIntent payload to a SqlBuilder query definition.
     *
     * @param mixed $intent
     * @return array QueryDefinition shape accepted by SqlBuilderService::build
     * @throws QueryIntentValidationException
     */
    public static function intentToQueryDefinition($intent)
    {
        return QueryIntentService::toQueryDefinition($intent);
    }

    /**
     * Repair exploratory SQL rejected by PostgreSQL preflight while preserving
     * the same two-repair budget used during static validation.
     */
    public static function repairExploratorySqlAfterPreflight(
        string $originalQuestion,
        $campus,
        array $currentResult,
        string $preflightError,
        ?string $generationPrompt = null,
        array $resolvedFilters = []
    ): array {
        $generationPrompt = $generationPrompt === null ? $originalQuestion : $generationPrompt;
        if (empty($resolvedFilters)) {
            $evidenceFilters = $currentResult['_askEvidence']['resolvedReferenceFilters'] ?? [];
            $resolvedFilters = is_array($evidenceFilters) ? $evidenceFilters : [];
        }
        $terminalContext = [
            'originalQuestion' => $originalQuestion,
            'generationPrompt' => $generationPrompt,
            'route' => $currentResult['route'] ?? 'exploratory',
            'routeReason' => $currentResult['routeReason'] ?? 'preflight_validation_failed',
        ];
        if (self::isPreflightConnectivityFailure($preflightError)) {
            self::logExploratoryTerminalOutcome($terminalContext, 'connectivity_failure', 'database_connectivity');
            throw new \RuntimeException($preflightError);
        }
        if (self::isPreflightCancellationFailure($preflightError)) {
            self::logExploratoryTerminalOutcome($terminalContext, 'cancelled', 'database_cancelled');
            throw new DatabaseQueryCancelledException();
        }
        if (self::isPreflightPolicyFailure($preflightError)) {
            self::logExploratoryTerminalOutcome($terminalContext, 'policy_blocked', 'policy_blocked');
            throw new \app\exceptions\PolicyViolationException('Database access policy blocked query validation.');
        }

        $candidateSql = (string)($currentResult['sql'] ?? '');
        $repairAttemptsUsed = (int)($currentResult['repairAttempts'] ?? 0);
        $assumptions = ExploratoryQueryDefaultsService::resolve($generationPrompt);
        $attemptedPlan = ExploratoryQueryDefaultsService::buildPromptGuidance($assumptions);
        $modelCandidateExplanation = trim((string)($currentResult['explanation'] ?? ''));

        $useHardenedPhysicalRoi = self::useHardenedPhysicalRoi();
        $context = [
            'originalQuestion' => $originalQuestion,
            'generationPrompt' => $generationPrompt,
            'campus' => is_string($campus) ? $campus : null,
            'assumptions' => $assumptions,
            'attemptedPlan' => $attemptedPlan,
            'attemptedPlanProvenance' => 'server_defaults',
            'modelCandidateExplanation' => $modelCandidateExplanation,
            'semanticContract' => ExploratorySemanticContractService::build(
                self::semanticContractQuestion($originalQuestion, $generationPrompt),
                is_string($campus) ? $campus : null,
                $assumptions,
                (string)($currentResult['routeReason'] ?? 'preflight_validation_failed'),
                ['physicalRoiPolicyVersion' => $useHardenedPhysicalRoi ? 'v2' : 'legacy']
            ),
            'resolvedFilters' => $resolvedFilters,
            'route' => $currentResult['route'] ?? 'exploratory',
            'routeReason' => $currentResult['routeReason'] ?? 'preflight_validation_failed',
            '_askEvidence' => is_array($currentResult['_askEvidence'] ?? null)
                ? $currentResult['_askEvidence']
                : [],
        ];
        try {
            self::validateResolvedReferenceSql($candidateSql, $resolvedFilters);
            $failure = new ExploratorySqlValidationException(
                'database_preflight',
                self::sanitizePreflightFailureCategory($preflightError),
                $candidateSql,
                true,
                'PostgreSQL preflight rejected the exploratory SQL candidate.'
            );
        } catch (ExploratorySqlValidationException $exception) {
            $failure = $exception;
        }

        try {
            $outcome = ExploratorySqlRepairService::run(
                function (array $attemptContext) use ($context, $resolvedFilters): array {
                    return self::runExploratorySqlAttempt(
                        $attemptContext + [
                            'route' => $context['route'],
                            'routeReason' => $context['routeReason'],
                            'resolvedFilters' => $resolvedFilters,
                        ],
                        function () use ($attemptContext): array {
                            return self::generateExploratoryRepairCandidate($attemptContext);
                        }
                    );
                },
                $context,
                $repairAttemptsUsed,
                $failure
            );
        } catch (\app\exceptions\PolicyViolationException $exception) {
            self::logExploratoryTerminalOutcome($context, 'policy_blocked', 'policy_blocked', $repairAttemptsUsed);
            throw $exception;
        } catch (DatabaseQueryCancelledException $exception) {
            self::logExploratoryTerminalOutcome($context, 'cancelled', 'database_cancelled', $repairAttemptsUsed);
            throw $exception;
        } catch (\Throwable $exception) {
            self::logExploratoryTerminalOutcome($context, 'provider_failure', 'provider_failure', $repairAttemptsUsed);
            throw $exception;
        }

        $reason = (string)($currentResult['routeReason'] ?? 'preflight_validation_failed');
        if (($outcome['status'] ?? null) !== 'validated') {
            self::logExploratoryTerminalOutcome(
                $context,
                'exhausted',
                (string)($outcome['failureCategory'] ?? 'validation_failure'),
                (int)($outcome['repairAttempts'] ?? 0)
            );
            return self::buildExploratoryRecoveryResponse($context, $outcome, $reason);
        }

        $result = self::decorateExploratoryResponse($outcome['result'], $reason);
        return self::decorateValidatedExploratoryResult(
            $result,
            $assumptions,
            (int)$outcome['repairAttempts']
        );
    }

    private static function useHardenedPhysicalRoi(): bool
    {
        return !isset(Yii::$app->params['nl2sqlHardenedPhysicalRoi'])
            || (bool)Yii::$app->params['nl2sqlHardenedPhysicalRoi'];
    }

    private static function generateExploratoryRepairCandidate(array $context): array
    {
        $apiKey = self::getAiApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $model = self::getAiModel();
        $generationPrompt = trim((string)($context['generationPrompt'] ?? '')) !== ''
            ? (string)$context['generationPrompt']
            : (string)($context['originalQuestion'] ?? '');
        $schemaContext = FolioSchemaService::buildSchemaContext($generationPrompt);
        $assumptionGuidance = ExploratoryQueryDefaultsService::buildPromptGuidance(
            is_array($context['assumptions'] ?? null) ? $context['assumptions'] : []
        );
        $campusScope = trim((string)($context['campus'] ?? ''));
        if ($campusScope === '') {
            $campusScope = 'Not separately supplied.';
        }
        $systemPrompt = <<<'PROMPT'
You are repairing one PostgreSQL SELECT query for a FOLIO reporting request.
Preserve requested outputs, campus scope, and documented interpretations.
Correct the reported validation failure without weakening filters or omitting requested domains.
Use only supplied schema tables and columns. Never access blocked data or produce non-SELECT SQL.
Return the corrected query in one ```sql code block, followed by a concise explanation and a final line exactly like DATA SOURCE: folio.
Never include a second SQL statement, an alternate query, or a semicolon inside the SQL code block.
PROMPT;
        $systemPrompt .= "\n\n" . self::buildOrganizationAcquisitionUnitGuidance();

        $semanticGuidance = [];
        foreach (($context['safeViolations'] ?? []) as $violation) {
            if (!is_array($violation)) {
                continue;
            }
            $semanticGuidance[] = sprintf(
                '- %s: %s',
                (string)($violation['key'] ?? ''),
                (string)($violation['guidance'] ?? '')
            );
        }

        $userContent = implode("\n\n", [
            "MODEL GENERATION CONTEXT\n" . $generationPrompt,
            "CAMPUS SCOPE\n" . $campusScope,
            "PREVIOUS CANDIDATE\n" . (string)($context['previousCandidate'] ?? ''),
            "VALIDATOR STAGE\n" . (string)($context['validatorStage'] ?? 'response_validation'),
            "SAFE CATEGORY\n" . (string)($context['safeCategory'] ?? 'validation_failure'),
            "SEMANTIC REQUIREMENTS TO CORRECT\n" . ($semanticGuidance !== [] ? implode("\n", $semanticGuidance) : 'None supplied.'),
            "ASSUMPTIONS\n" . ($assumptionGuidance !== '' ? $assumptionGuidance : 'None documented.'),
            "ATTEMPTED PLAN\n" . (string)($context['attemptedPlan'] ?? ''),
            "PREVIOUS MODEL EXPLANATION\n" . (string)($context['modelCandidateExplanation'] ?? ''),
            "SCOPED SCHEMA CONTEXT\n" . $schemaContext,
        ]);

        $requestResult = self::sendGeminiRequestWithRetries(
            self::API_BASE . "/{$model}:generateContent?key={$apiKey}",
            [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [['parts' => [['text' => $userContent]]]],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 16384,
                ],
            ],
            'nl2sql.exploratory_repair'
        );
        $data = json_decode($requestResult['response']->content, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        try {
            return self::withAskEvidence(self::parseResponse($text), [
                'modelName' => $model,
                'promptVersion' => self::LEGACY_PROMPT_VERSION,
                'schemaMetadata' => self::schemaMetadata(self::buildSchemaTelemetry($schemaContext)),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $candidateSql = '';
            try {
                $candidateSql = (string)(self::extractSqlResponseParts($text)['sql'] ?? '');
            } catch (\Throwable $ignored) {
                // A safe category is sufficient when no candidate SELECT can
                // be isolated from the model response.
            }

            throw new ExploratorySqlValidationException(
                'response_format',
                self::classifyInvalidRepairResponse($exception),
                $candidateSql,
                true,
                'The AI repair response was not a single valid SELECT statement.',
                $exception
            );
        }
    }

    private static function classifyInvalidRepairResponse(\InvalidArgumentException $exception): string
    {
        $message = strtolower(trim($exception->getMessage()));
        if (strpos($message, 'single select statement') !== false) {
            return 'multiple_statements';
        }
        if (strpos($message, 'blocked keyword') !== false) {
            return 'blocked_keyword';
        }
        if (strpos($message, 'marctab') !== false) {
            return 'unsupported_source_shape';
        }
        if (strpos($message, 'only select queries') !== false || strpos($message, 'sql cannot be empty') !== false) {
            return 'non_select';
        }

        return 'invalid_select_shape';
    }

    private static function validateExplicitReportValues(string $sql, string $prompt): void
    {
        $validation = self::explicitReportValueValidation($sql, $prompt);
        if ($validation === null) {
            return;
        }

        $violations = [];
        $position = 0;
        foreach (($validation['missingIdentifiers'] ?? []) as $unusedValue) {
            $position++;
            $violations[] = [
                'key' => 'explicit_identifier_' . $position,
                'category' => 'explicit_values',
                'label' => 'Explicit report identifier',
                'guidance' => 'Keep every requested identifier exactly as supplied.',
            ];
        }
        foreach (($validation['unexpectedIdentifiers'] ?? []) as $unusedValue) {
            $position++;
            $violations[] = [
                'key' => 'explicit_identifier_' . $position,
                'category' => 'explicit_values',
                'label' => 'Explicit report identifier',
                'guidance' => 'Use only the identifiers that were explicitly requested.',
            ];
        }
        foreach (($validation['missingFields'] ?? []) as $unusedField) {
            $position++;
            $violations[] = [
                'key' => 'explicit_output_' . $position,
                'category' => 'explicit_values',
                'label' => 'Requested report output',
                'guidance' => 'Keep every requested output field in the report.',
            ];
        }
        if (empty($validation['limitValid'])) {
            $violations[] = [
                'key' => 'explicit_limit',
                'category' => 'explicit_values',
                'label' => 'Explicit report limit',
                'guidance' => 'Keep the requested result limit exactly.',
            ];
        }

        throw new ExploratorySqlValidationException(
            'explicit_values',
            'explicit_values_missing',
            $sql,
            true,
            'The SQL candidate did not preserve all explicit report values.',
            null,
            $violations
        );
    }

    private static function validateResolvedReferenceResult(
        array $result,
        array $resolvedFilters
    ): void {
        if (!isset($result['sql'])) {
            return;
        }

        self::validateResolvedReferenceSql((string)$result['sql'], $resolvedFilters);
    }

    private static function validateResolvedReferenceSql(
        string $sql,
        array $resolvedFilters
    ): void {
        try {
            ResolvedReferenceSqlValidatorService::validate($sql, $resolvedFilters);
        } catch (\InvalidArgumentException $exception) {
            throw new ExploratorySqlValidationException(
                'semantic_validation',
                'resolved_reference_filter_mismatch',
                $sql,
                true,
                'The SQL candidate did not preserve the resolved library or material filters.',
                $exception,
                // Without a violation the repair prompt reports "None supplied"
                // and the model has to guess what to change.
                [[
                    'key' => 'resolved_reference_filters',
                    'category' => 'resolved_reference_filters',
                    'label' => 'Library and material filters',
                    'guidance' => 'Apply every resolved library, location, and material value exactly as supplied,'
                        . ' in the top-level WHERE clause of a single SELECT statement.'
                        . ' Do not use a WITH (CTE) clause, UNION, a subquery inside WHERE, OR, CASE, or a wildcard'
                        . ' ILIKE pattern for those values; use LEFT JOIN with GROUP BY for counts instead.',
                ]]
            );
        }
    }

    private static function explicitReportValueValidation(string $sql, string $prompt): ?array
    {
        $request = ExplicitReportRequestService::extract($prompt);
        if (empty($request['applicable']) || !empty($request['needsClarification'])) {
            return null;
        }

        $validation = ExplicitReportRequestService::validateCandidate($sql, $request);
        return !empty($validation['valid']) ? null : $validation;
    }

    private static function repairRoutedCandidateMissingExplicitValues(
        array $candidate,
        string $generationPrompt,
        $campus,
        ?string $originalQuestion = null,
        array $resolvedFilters = []
    ): array
    {
        $originalQuestion = $originalQuestion === null ? $generationPrompt : $originalQuestion;
        if (!isset($candidate['sql'])) {
            return $candidate;
        }

        $hasExplicitFailure = self::explicitReportValueValidation(
            (string)$candidate['sql'],
            $originalQuestion
        ) !== null;
        $hasResolvedFilterFailure = false;
        try {
            self::validateResolvedReferenceSql((string)$candidate['sql'], $resolvedFilters);
        } catch (ExploratorySqlValidationException $exception) {
            $hasResolvedFilterFailure = true;
        }
        if (!$hasExplicitFailure && !$hasResolvedFilterFailure) {
            return $candidate;
        }

        return self::repairRoutedCandidateAfterExplicitFailure(
            $generationPrompt,
            $campus,
            $candidate,
            $originalQuestion,
            $resolvedFilters
        );
    }

    private static function repairRoutedCandidateAfterExplicitFailure(
        string $generationPrompt,
        $campus,
        array $candidate,
        ?string $originalQuestion = null,
        array $resolvedFilters = []
    ): array {
        $originalQuestion = $originalQuestion === null ? $generationPrompt : $originalQuestion;
        $candidate['repairAttempts'] = (int)($candidate['repairAttempts'] ?? 0);
        $repaired = self::repairExploratorySqlAfterPreflight(
            $originalQuestion,
            $campus,
            $candidate,
            'Explicit report values were not preserved.',
            $generationPrompt,
            $resolvedFilters
        );
        return self::withAskEvidence(
            $repaired,
            is_array($candidate['_askEvidence'] ?? null) ? $candidate['_askEvidence'] : []
        );
    }

    private static function runExploratorySqlAttempt(array $context, callable $attempt): array
    {
        $startedAt = microtime(true);
        $repairNumber = (int)($context['repairNumber'] ?? 0);
        $result = null;
        $semanticValidation = [];
        $assumptionKeys = [];
        foreach (($context['assumptions'] ?? []) as $assumption) {
            if (is_array($assumption) && isset($assumption['key'])) {
                $assumptionKeys[] = (string)$assumption['key'];
            }
        }
        sort($assumptionKeys);
        $telemetry = [
            'promptFingerprint' => self::fingerprintPrompt((string)($context['originalQuestion'] ?? '')),
            'route' => self::sanitizeTelemetryLabel($context['route'] ?? null, 'exploratory'),
            'routeReason' => self::sanitizeTelemetryLabel($context['routeReason'] ?? null, 'exploratory_processing'),
            'phase' => $repairNumber === 0 ? 'initial_generation' : 'automatic_repair',
            'repairNumber' => $repairNumber,
            'maximumRepairs' => ExploratorySqlRepairService::MAX_REPAIR_ATTEMPTS,
            'stage' => $context['validatorStage'] ?? null,
            'category' => $context['safeCategory'] ?? null,
            'candidateLength' => strlen((string)($context['previousCandidate'] ?? '')),
            'provider' => self::getAiProvider(),
            'elapsedMs' => 0,
            'assumptionKeys' => $assumptionKeys,
        ];
        self::logNlTelemetry('nl2sql.exploratory_repair_attempt', $telemetry + ['outcome' => 'started']);

        try {
            $result = $attempt();
            $contract = is_array($context['semanticContract'] ?? null)
                ? $context['semanticContract']
                : [];
            $result['semanticContractApplicable'] = !empty($contract['applicable']);
            $semanticValidation = ExploratorySqlSemanticValidatorService::validate(
                (string)($result['sql'] ?? ''),
                $contract
            );
            if (($semanticValidation['status'] ?? null) === 'rejected') {
                $violations = is_array($semanticValidation['violations'] ?? null)
                    ? $semanticValidation['violations']
                    : [];
                throw new ExploratorySqlValidationException(
                    'semantic_conformance',
                    (string)($violations[0]['category'] ?? 'semantic_coverage_gap'),
                    (string)($result['sql'] ?? ''),
                    true,
                    'The exploratory SQL candidate did not satisfy its semantic contract.',
                    null,
                    $violations
                );
            }
            if (($semanticValidation['status'] ?? null) === 'validated') {
                $result['semanticValidation'] = $semanticValidation;
            }
            self::validateExplicitReportValues(
                (string)($result['sql'] ?? ''),
                (string)($context['originalQuestion'] ?? '')
            );
            self::validateResolvedReferenceSql(
                (string)($result['sql'] ?? ''),
                is_array($context['resolvedFilters'] ?? null)
                    ? $context['resolvedFilters']
                    : []
            );
            $checkedRequirements = is_array($semanticValidation['checkedRequirements'] ?? null)
                ? $semanticValidation['checkedRequirements']
                : [];
            $ruleKeys = array_map(
                static function (array $requirement): string {
                    return (string)($requirement['key'] ?? '');
                },
                $checkedRequirements
            );
            sort($ruleKeys);
            self::logNlTelemetry('nl2sql.exploratory_repair_outcome', array_merge($telemetry, [
                'candidateLength' => strlen((string)($result['sql'] ?? '')),
                'contractVersion' => (int)($semanticValidation['contractVersion'] ?? 0),
                'failureCount' => 0,
                'ruleKeys' => $ruleKeys,
                'elapsedMs' => (int)round((microtime(true) - $startedAt) * 1000),
                'outcome' => 'validated',
            ]));
            return $result;
        } catch (\Throwable $exception) {
            if (is_array($result)
                && $exception instanceof ExploratorySqlValidationException
                && self::mayAcceptAdvisoryFailure($context, $exception)
            ) {
                $result['semanticValidation'] = [
                    'status' => 'advisory',
                    'contractVersion' => (int)($semanticValidation['contractVersion'] ?? 0),
                    'checkedRequirements' => is_array($semanticValidation['checkedRequirements'] ?? null)
                        ? $semanticValidation['checkedRequirements']
                        : [],
                ];
                $result['reviewRequired'] = true;
                $disclosures = is_array($result['reportDisclosures'] ?? null)
                    ? $result['reportDisclosures']
                    : [];
                $disclosures[] = 'AI reviewed this interpretation, but the semantic checker could not verify every requested detail.';
                $result['reportDisclosures'] = array_values(array_unique($disclosures));
                $result['assumptions'] = self::mergeAdvisoryAssumptions(
                    is_array($result['assumptions'] ?? null) ? $result['assumptions'] : [],
                    $exception->getSafeViolations()
                );

                self::logNlTelemetry('nl2sql.exploratory_repair_outcome', array_merge($telemetry, [
                    'stage' => $exception->getStage(),
                    'category' => $exception->getSafeCategory(),
                    'candidateLength' => strlen((string)($result['sql'] ?? '')),
                    'contractVersion' => (int)($semanticValidation['contractVersion'] ?? 0),
                    'failureCount' => count($exception->getSafeViolations()),
                    'elapsedMs' => (int)round((microtime(true) - $startedAt) * 1000),
                    'outcome' => 'advisory',
                ]), true);
                return $result;
            }

            $failureTelemetry = $telemetry;
            if ($exception instanceof ExploratorySqlValidationException) {
                $failureTelemetry['stage'] = $exception->getStage();
                $failureTelemetry['category'] = $exception->getSafeCategory();
                $failureTelemetry['candidateLength'] = strlen($exception->getCandidateSql());
                $safeViolations = $exception->getSafeViolations();
                $ruleKeys = array_values(array_unique(array_map(
                    static function (array $violation): string {
                        return (string)($violation['key'] ?? '');
                    },
                    $safeViolations
                )));
                sort($ruleKeys);
                $failureTelemetry['ruleKeys'] = $ruleKeys;
                $failureTelemetry['failureCount'] = count($safeViolations);
                $failureTelemetry['contractVersion'] = (int)($context['semanticContract']['contractVersion'] ?? 0);
            }
            $failureTelemetry['elapsedMs'] = (int)round((microtime(true) - $startedAt) * 1000);
            $failureTelemetry['outcome'] = 'rejected';
            self::logNlTelemetry('nl2sql.exploratory_repair_outcome', $failureTelemetry, true);
            throw $exception;
        }
    }

    private static function mayAcceptAdvisoryFailure(
        array $context,
        ExploratorySqlValidationException $exception
    ): bool {
        if ((int)($context['repairNumber'] ?? 0) < ExploratorySqlRepairService::MAX_REPAIR_ATTEMPTS) {
            return false;
        }
        return in_array($exception->getStage(), [
            'semantic_conformance',
            'semantic_validation',
            'explicit_values',
        ], true);
    }

    private static function mergeAdvisoryAssumptions(array $assumptions, array $safeViolations): array
    {
        $existingKeys = [];
        foreach ($assumptions as $assumption) {
            if (is_array($assumption) && isset($assumption['key'])) {
                $existingKeys[(string)$assumption['key']] = true;
            }
        }

        foreach ($safeViolations as $violation) {
            if (!is_array($violation)) {
                continue;
            }
            $violationKey = (string)($violation['key'] ?? 'unverified_requirement');
            $category = (string)($violation['category'] ?? 'semantic_validation');
            $label = self::safeAdvisoryLabel((string)($violation['label'] ?? 'Requested report detail'));
            $key = 'advisory_' . substr(hash('sha256', $category . '|' . $violationKey . '|' . $label), 0, 12);
            if (isset($existingKeys[$key])) {
                continue;
            }
            $assumptions[] = [
                'key' => $key,
                'label' => $label,
                'value' => 'not_fully_verified',
                'explanation' => 'AI review could not independently verify this requested detail.',
                'correctionExample' => 'Review this detail before relying on the report.',
                'source' => 'default',
            ];
            $existingKeys[$key] = true;
        }

        return $assumptions;
    }

    private static function safeAdvisoryLabel(string $label): string
    {
        $label = trim((string)preg_replace('/\s+/', ' ', strip_tags($label)));
        if ($label === '' || preg_match('/\b(?:SELECT|FROM|WHERE|JOIN|HAVING|UNION)\b|[a-z0-9_]+\.[a-z0-9_]+/i', $label) === 1) {
            return 'Requested report detail';
        }
        return substr($label, 0, 120);
    }

    private static function isPreflightConnectivityFailure(string $error): bool
    {
        return preg_match(
            '/SQLSTATE\[08[0-9A-Z]{3}\]|server closed the connection|connection (?:refused|reset|failed)|could not connect|no connection to the server/i',
            $error
        ) === 1;
    }

    private static function isPreflightCancellationFailure(string $error): bool
    {
        return preg_match(
            '/SQLSTATE\[57014\]|cancel(?:ing|ling)? statement due to|query (?:canceled|cancelled)/i',
            $error
        ) === 1;
    }

    private static function isPreflightPolicyFailure(string $error): bool
    {
        return preg_match(
            '/SQLSTATE\[42501\]|permission denied|insufficient privilege|row-level security|access denied|not authorized|must be owner of/i',
            $error
        ) === 1;
    }

    private static function sanitizePreflightFailureCategory(string $error): string
    {
        $patterns = [
            'unknown_column' => '/\bcolumn\b.+\bdoes not exist\b|undefined column/i',
            'unknown_table' => '/\b(?:relation|table)\b.+\bdoes not exist\b|undefined table/i',
            'ambiguous_column' => '/\bcolumn reference\b.+\bambiguous\b|ambiguous column/i',
            'invalid_operator' => '/operator does not exist|invalid operator/i',
            'grouping_error' => '/must appear in the GROUP BY|grouping error/i',
            'syntax_error' => '/syntax error/i',
            'query_too_complex' => '/query.+too complex/i',
        ];

        foreach ($patterns as $category => $pattern) {
            if (preg_match($pattern, $error) === 1) {
                return $category;
            }
        }

        return 'database_validation';
    }

    /**
     * Parse the Gemini response into SQL and explanation.
     * @param string $text Raw Gemini response text
      * @return array {sql: string, explanation: string, dataSource: string}
     */
    private static function parseResponse($text)
    {
        $parsed = self::extractSqlResponseParts((string)$text);
        $sql = $parsed['sql'];
        $explanation = $parsed['explanation'];
        $dataSource = $parsed['dataSource'];

        try {
            $sql = self::repairInvalidInventoryTitleReferences($sql);
            $sql = self::repairOnlyHoldingLocationAliasLeaks($sql);
            $sql = self::repairResolvedLocationPredicateMisuse($sql);
            self::validateNoOnlyHoldingLocationAliasLeaks($sql);
            self::validateNoResolvedLocationPredicateMisuse($sql);
        } catch (ExploratorySqlValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ExploratorySqlValidationException(
                'semantic_validation',
                'semantic_guard',
                $sql,
                true,
                'The SQL candidate failed a semantic validation guard.',
                $exception
            );
        }

        // Policy and destructive/non-SELECT failures are deliberate hard stops.
        // Keep their original exception types so the repair coordinator cannot
        // convert them into retryable validation feedback.
        SqlBuilderService::validateSafety($sql);
        SqlBuilderService::validateTablePolicy($sql);
        self::validateTableReferences($sql);

        if (preg_match('/\b(acrl_statistics|report_expense_allocations)\b/i', $sql)) {
            $dataSource = 'local';
        }

        $sql = self::normalizeGeneratedSql($sql);

        return [
            'sql' => $sql,
            'explanation' => $explanation,
            'dataSource' => $dataSource,
        ];
    }

    private static function extractSqlResponseParts(string $text): array
    {
        $sql = '';
        $explanation = '';
        $dataSource = 'folio';

        $destructiveCommand = self::findDestructiveSqlCommand($text);
        if ($destructiveCommand !== null) {
            throw new ExploratorySqlValidationException(
                'safety',
                'non_select',
                trim($text),
                false,
                'The AI response contains a non-SELECT SQL command: ' . $destructiveCommand . '.'
            );
        }

        // Extract SQL from ```sql ... ``` code block
        if (preg_match('/```sql\s*\n(.*?)```/s', $text, $matches)) {
            $sql = trim($matches[1]);
        } elseif (preg_match('/```\s*\n(.*?)```/s', $text, $matches)) {
            $sql = trim($matches[1]);
        } else {
            // Try to find SELECT statement directly
            if (preg_match('/(SELECT\s+.+)/si', $text, $matches)) {
                $sql = trim($matches[1]);
            }
        }

        // Everything outside the code block is the explanation
        $explanation = preg_replace('/```(?:sql)?\s*\n.*?```/s', '', $text);
        $explanation = trim($explanation);

        if (preg_match('/DATA\s+SOURCE\s*:\s*(local|folio)/i', $text, $matches)) {
            $dataSource = strtolower($matches[1]);
        }

        // Strip DATA SOURCE directive if Gemini included it inside the SQL block
        $sql = preg_replace('/^\s*DATA\s+SOURCE\s*:\s*(local|folio)\s*\n?/im', '', $sql);
        $sql = trim($sql);

        if (empty($sql)) {
            throw new ExploratorySqlValidationException(
                'response_format',
                'missing_select',
                '',
                true,
                'Could not extract a SELECT statement from the AI response.'
            );
        }

        return [
            'sql' => $sql,
            'explanation' => $explanation,
            'dataSource' => $dataSource,
        ];
    }

    private static function findDestructiveSqlCommand(string $text): ?string
    {
        $commands = 'INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|MERGE|CALL|DO|COPY|VACUUM|ANALYZE';
        if (preg_match('/(?:^|\R)\s*(' . $commands . ')\b/im', $text, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/\bSELECT\b/i', $text) !== 1
            && preg_match('/\b(' . $commands . ')\b/i', $text, $matches) === 1
        ) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Strip any AI-generated type casts from SQL.
     *
     * MetaDB join columns are already compatible types — explicit casts bypass
     * PostgreSQL indexes and cause catastrophically slow seq scan / nested loop plans.
     * The original "operator does not exist: uuid = text" errors were caused by the
     * AI writing one-sided ::uuid casts, not by genuinely mismatched column types.
     * Solution: remove all ::uuid and ::text casts; write no new ones.
     */
    public static function normalizeGeneratedSql(string $sql): string
    {
        $sql = self::normalizeIdCasts($sql);
        $sql = self::normalizeStandingOrderFilters($sql);
        return $sql;
    }

    private static function normalizeIdCasts(string $sql): string
    {
        $sql = preg_replace('/::uuid\b/i', '', $sql);
        $sql = preg_replace('/::text\b/i', '', $sql);
        return $sql;
    }

    private static function normalizeStandingOrderFilters(string $sql): string
    {
        $poAlias = self::findTableAlias($sql, 'orders.purchase_order__t');
        $poLineAlias = self::findTableAlias($sql, 'orders.po_line__t');
        if ($poAlias === null || $poLineAlias === null) {
            return $sql;
        }

        $poAliasPattern = preg_quote($poAlias, '/');
        $poLineAliasPattern = preg_quote($poLineAlias, '/');
        $replacement = "LOWER({$poAlias}.order_type) = LOWER('Ongoing')";

        foreach (['order_format', 'payment_status'] as $column) {
            $columnPattern = preg_quote($column, '/');
            $patterns = [
                "/LOWER\\(\\s*{$poLineAliasPattern}\\.{$columnPattern}\\s*\\)\\s*=\\s*LOWER\\(\\s*'Ongoing'\\s*\\)/i",
                "/{$poLineAliasPattern}\\.{$columnPattern}\\s*=\\s*'Ongoing'/i",
                "/{$poLineAliasPattern}\\.{$columnPattern}\\s+ILIKE\\s+'Ongoing'/i",
                "/LOWER\\(\\s*{$poLineAliasPattern}\\.{$columnPattern}\\s*\\)\\s+ILIKE\\s+'%?ongoing%?'/i",
            ];

            foreach ($patterns as $pattern) {
                $sql = preg_replace($pattern, $replacement, $sql);
            }
        }

        return $sql;
    }

    private static function findTableAlias(string $sql, string $tableName): ?string
    {
        $tablePattern = preg_quote($tableName, '/');
        if (preg_match('/\\b(?:FROM|JOIN)\\s+' . $tablePattern . '\\s+(?:AS\\s+)?([a-z_][a-z0-9_]*)\\b/i', $sql, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Repair a common model hallucination: item__t has no title column. When the
     * query already joins instance__t, use that alias for bibliographic titles.
     */
    private static function repairInvalidInventoryTitleReferences(string $sql): string
    {
        preg_match_all(
            '/\b(?:FROM|JOIN)\s+(inventory\.(?:item|instance)__t)\s+(?:AS\s+)?([a-z_][a-z0-9_]*)\b/i',
            $sql,
            $matches,
            PREG_SET_ORDER
        );

        $itemAliases = [];
        $instanceAlias = null;
        foreach ($matches as $match) {
            $table = strtolower($match[1]);
            $alias = $match[2];
            if ($table === 'inventory.item__t') {
                $itemAliases[] = $alias;
            } elseif ($table === 'inventory.instance__t' && $instanceAlias === null) {
                $instanceAlias = $alias;
            }
        }

        if ($instanceAlias === null || empty($itemAliases)) {
            return $sql;
        }

        $repaired = $sql;
        $changed = false;
        foreach ($itemAliases as $itemAlias) {
            $pattern = '/\b' . preg_quote($itemAlias, '/') . '\.title\b/i';
            if (preg_match($pattern, $repaired) === 1) {
                $repaired = preg_replace($pattern, $instanceAlias . '.title', $repaired);
                $changed = true;
            }
        }

        if (!$changed) {
            return $sql;
        }

        return self::ensureGroupedExpression($repaired, $instanceAlias . '.title');
    }

    /**
     * Repair a common model anti-join bug for "only holding location" prompts:
     * references an outer alias (such as tl.name) from inside a NOT EXISTS filter.
     */
    private static function repairOnlyHoldingLocationAliasLeaks(string $sql): string
    {
        if (
            !self::sqlMentionsTargetLocationCte($sql)
            || stripos($sql, 'NOT EXISTS') === false
            || stripos($sql, 'other_hr.effective_location_id') === false
        ) {
            return $sql;
        }

        $targetLocationCte = stripos($sql, 'target_locations') !== false ? 'target_locations' : 'target_location';
        $pattern = '/\\b(?:other_loc\\.name\\s*(?:<>|!=|NOT\\s+ILIKE|NOT\\s+LIKE)\\s*[a-z_][a-z0-9_]*\\.name|[a-z_][a-z0-9_]*\\.name\\s*(?:<>|!=|NOT\\s+ILIKE|NOT\\s+LIKE)\\s*other_loc\\.name)\\b/i';
        if (preg_match($pattern, $sql) !== 1) {
            return $sql;
        }

        $repaired = preg_replace(
            $pattern,
            'other_hr.effective_location_id NOT IN (SELECT id FROM ' . $targetLocationCte . ')',
            $sql
        );
        if (!is_string($repaired)) {
            return $sql;
        }

        return $repaired;
    }

    /**
     * Fail before database validation when generated only-holding SQL still
     * references the target-location CTE alias from an outer anti-join scope.
     */
    private static function validateNoOnlyHoldingLocationAliasLeaks(string $sql): void
    {
        if (
            !self::sqlMentionsTargetLocationCte($sql)
            || stripos($sql, 'NOT EXISTS') === false
            || stripos($sql, 'other_hr') === false
        ) {
            return;
        }

        if (preg_match('/\\bNOT\\s+EXISTS\\s*\\([\\s\\S]*\\btl\\.name\\b[\\s\\S]*\\)/i', $sql) !== 1) {
            return;
        }

        throw new \RuntimeException(
            'Generated only-holding-location SQL leaked target location alias tl outside its query scope.'
        );
    }

    /**
     * Repair resolver drift where a location value resolved for inventory.location__t.name
     * is also applied to inventory.loclibrary__t.name, which can silently zero results.
     */
    private static function repairResolvedLocationPredicateMisuse(string $sql): string
    {
        $aliases = self::extractSqlTableAliases($sql);
        $locationAliases = $aliases['inventory.location__t'] ?? [];
        $libraryAliases = $aliases['inventory.loclibrary__t'] ?? [];
        if (empty($locationAliases) || empty($libraryAliases)) {
            return $sql;
        }

        $repaired = self::repairKnownLocationAliasLibraryLeak($sql, $locationAliases, $libraryAliases);

        $locationValues = self::extractAliasNamePredicateValues($sql, $locationAliases);
        if (empty($locationValues)) {
            return self::normalizeSqlBooleanWhitespace($repaired);
        }

        foreach ($libraryAliases as $libraryAlias) {
            $libraryValues = self::extractAliasNamePredicateValues($repaired, [$libraryAlias]);
            foreach ($libraryValues as $libraryValue) {
                foreach ($locationValues as $locationValue) {
                    if (!self::sqlTextValuesShareLocationMeaning($libraryValue, $locationValue)) {
                        continue;
                    }
                    $repaired = self::removeAliasTextPredicate($repaired, $libraryAlias, 'name', $libraryValue);
                }
            }
        }

        foreach ($locationAliases as $locationAlias) {
            if (!self::aliasHasNamePredicateValue($sql, $locationAlias, $locationValues)) {
                continue;
            }
            $repaired = self::removeAliasColumnPredicate($repaired, $locationAlias, 'code');
        }

        return self::normalizeSqlBooleanWhitespace($repaired);
    }

    /**
     * Repair known local aliases that are not library names. MRBC is a location
     * alias; if it appears on loclibrary__t.name, use the resolved location.
     *
     * @param array<int, string> $locationAliases
     * @param array<int, string> $libraryAliases
     */
    private static function repairKnownLocationAliasLibraryLeak(string $sql, array $locationAliases, array $libraryAliases): string
    {
        $knownLocationAliases = [
            'mrbc' => 'SC Rare Book Collection Reference',
        ];

        $repaired = $sql;
        foreach ($libraryAliases as $libraryAlias) {
            $libraryValues = self::extractAliasNamePredicateValues($repaired, [$libraryAlias]);
            foreach ($libraryValues as $libraryValue) {
                $normalizedLibraryValue = self::normalizeSqlTextValue($libraryValue);
                $resolvedLocation = $knownLocationAliases[$normalizedLibraryValue] ?? null;
                if ($resolvedLocation === null) {
                    continue;
                }

                $repaired = self::removeAliasTextPredicate($repaired, $libraryAlias, 'name', $libraryValue);
                foreach ($locationAliases as $locationAlias) {
                    $repaired = self::replaceAliasNamePredicateValue($repaired, $locationAlias, $resolvedLocation);
                    $repaired = self::removeAliasColumnPredicate($repaired, $locationAlias, 'code');
                }
            }
        }

        return $repaired;
    }

    /**
     * Fail closed if a resolved location name is still filtered on the library table.
     */
    private static function validateNoResolvedLocationPredicateMisuse(string $sql): void
    {
        $aliases = self::extractSqlTableAliases($sql);
        $locationAliases = $aliases['inventory.location__t'] ?? [];
        $libraryAliases = $aliases['inventory.loclibrary__t'] ?? [];
        if (empty($locationAliases) || empty($libraryAliases)) {
            return;
        }

        $locationValues = self::extractAliasNamePredicateValues($sql, $locationAliases);
        if (empty($locationValues)) {
            return;
        }

        foreach ($libraryAliases as $libraryAlias) {
            $libraryValues = self::extractAliasNamePredicateValues($sql, [$libraryAlias]);
            foreach ($libraryValues as $libraryValue) {
                foreach ($locationValues as $locationValue) {
                    if (self::sqlTextValuesShareLocationMeaning($libraryValue, $locationValue)) {
                        self::logValidationFailure('resolved_location_wrong_hierarchy', [
                            'route' => 'sql_validation',
                            'issueFamily' => 'resolved_location_wrong_hierarchy',
                            'libraryValue' => $libraryValue,
                            'locationValue' => $locationValue,
                        ]);
                        throw new \RuntimeException(
                            'Generated SQL is invalid: resolved location value was applied to inventory.loclibrary__t.name instead of inventory.location__t.name.'
                        );
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function extractSqlTableAliases(string $sql): array
    {
        preg_match_all(
            '/\b(?:FROM|JOIN)\s+(inventory\.(?:location|loclibrary)__t)\s+(?:AS\s+)?([a-z_][a-z0-9_]*)\b/i',
            $sql,
            $matches,
            PREG_SET_ORDER
        );

        $aliases = [];
        foreach ($matches as $match) {
            $table = strtolower((string)$match[1]);
            $alias = (string)$match[2];
            $aliases[$table][] = $alias;
        }

        return $aliases;
    }

    /**
     * @param array<int, string> $aliases
     * @return array<int, string>
     */
    private static function extractAliasNamePredicateValues(string $sql, array $aliases): array
    {
        $values = [];
        foreach ($aliases as $alias) {
            $pattern = '/\b' . preg_quote($alias, '/') . '\.name\s*(?:ILIKE|LIKE|=)\s*\'((?:\'\'|[^\'])*)\'/i';
            if (preg_match_all($pattern, $sql, $matches) < 1) {
                continue;
            }
            foreach ($matches[1] as $value) {
                $values[] = str_replace("''", "'", (string)$value);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $values
     */
    private static function aliasHasNamePredicateValue(string $sql, string $alias, array $values): bool
    {
        $aliasValues = self::extractAliasNamePredicateValues($sql, [$alias]);
        foreach ($aliasValues as $aliasValue) {
            foreach ($values as $value) {
                if (self::normalizeSqlTextValue($aliasValue) === self::normalizeSqlTextValue($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normalizeSqlTextValue(string $value): string
    {
        $value = trim($value);
        $value = trim($value, '%');
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string)preg_replace('/\s+/', ' ', (string)$value));
    }

    private static function sqlTextValuesShareLocationMeaning(string $left, string $right): bool
    {
        $left = self::normalizeSqlLocationComparisonValue($left);
        $right = self::normalizeSqlLocationComparisonValue($right);
        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right
            || strpos($left, $right) !== false
            || strpos($right, $left) !== false;
    }

    private static function normalizeSqlLocationComparisonValue(string $value): string
    {
        $normalized = self::normalizeSqlTextValue($value);
        $normalized = preg_replace('/^(?:sc|ac|hc|mh|um|rp|yb)\s+/', '', $normalized, 1);
        return trim((string)$normalized);
    }

    private static function removeAliasTextPredicate(string $sql, string $alias, string $column, string $value): string
    {
        $quotedValue = preg_quote(str_replace("'", "''", $value), '/');
        $patterns = [
            '/\s+\bAND\s+' . preg_quote($alias, '/') . '\.' . preg_quote($column, '/') . '\s*(?:ILIKE|LIKE|=)\s*\'%?' . $quotedValue . '%?\'/i',
            '/\s+\bOR\s+' . preg_quote($alias, '/') . '\.' . preg_quote($column, '/') . '\s*(?:ILIKE|LIKE|=)\s*\'%?' . $quotedValue . '%?\'/i',
            '/\bWHERE\s+' . preg_quote($alias, '/') . '\.' . preg_quote($column, '/') . '\s*(?:ILIKE|LIKE|=)\s*\'%?' . $quotedValue . '%?\'\s+\bAND\s+/i',
        ];

        $repaired = $sql;
        foreach ($patterns as $pattern) {
            $replacement = stripos($pattern, '\\bWHERE') !== false ? 'WHERE ' : '';
            $next = preg_replace($pattern, $replacement, $repaired);
            if (is_string($next)) {
                $repaired = $next;
            }
        }

        return $repaired;
    }

    private static function replaceAliasNamePredicateValue(string $sql, string $alias, string $value): string
    {
        $replacementValue = str_replace("'", "''", $value);
        $pattern = '/\b' . preg_quote($alias, '/') . '\.name\s*(ILIKE|LIKE|=)\s*\'((?:\'\'|[^\'])*)\'/i';
        $repaired = preg_replace(
            $pattern,
            $alias . ".name $1 '%" . $replacementValue . "%'",
            $sql
        );

        return is_string($repaired) ? $repaired : $sql;
    }

    private static function removeAliasColumnPredicate(string $sql, string $alias, string $column): string
    {
        $patterns = [
            '/\s+\bAND\s+' . preg_quote($alias, '/') . '\.' . preg_quote($column, '/') . '\s*(?:ILIKE|LIKE|=)\s*\'(?:\'\'|[^\'])*\'/i',
            '/\s+\bOR\s+' . preg_quote($alias, '/') . '\.' . preg_quote($column, '/') . '\s*(?:ILIKE|LIKE|=)\s*\'(?:\'\'|[^\'])*\'/i',
            '/\bWHERE\s+' . preg_quote($alias, '/') . '\.' . preg_quote($column, '/') . '\s*(?:ILIKE|LIKE|=)\s*\'(?:\'\'|[^\'])*\'\s+\bAND\s+/i',
        ];

        $repaired = $sql;
        foreach ($patterns as $pattern) {
            $replacement = stripos($pattern, '\\bWHERE') !== false ? 'WHERE ' : '';
            $next = preg_replace($pattern, $replacement, $repaired);
            if (is_string($next)) {
                $repaired = $next;
            }
        }

        return $repaired;
    }

    private static function normalizeSqlBooleanWhitespace(string $sql): string
    {
        $sql = preg_replace('/\bWHERE\s+(?:AND|OR)\s+/i', 'WHERE ', $sql);
        $sql = preg_replace('/\s+(?:AND|OR)\s+(?=\)|LIMIT\b|ORDER\b|GROUP\b|HAVING\b|$)/i', ' ', (string)$sql);
        $sql = preg_replace('/[ \t]+\n/', "\n", (string)$sql);
        return trim((string)$sql);
    }

    private static function sqlMentionsTargetLocationCte(string $sql): bool
    {
        return stripos($sql, 'target_locations') !== false
            || stripos($sql, 'target_location') !== false;
    }

    private static function ensureGroupedExpression(string $sql, string $expression): string
    {
        if (preg_match('/\bGROUP\s+BY\b/i', $sql) !== 1) {
            return $sql;
        }
        if (preg_match('/\bSELECT\b(?<select>.*?)\bFROM\b/sis', $sql, $selectMatch) !== 1) {
            return $sql;
        }
        if (stripos($selectMatch['select'], $expression) === false) {
            return $sql;
        }

        $pattern = '/\bGROUP\s+BY\s+(?<group>.*?)(?<tail>\s*)(?=\bHAVING\b|\bORDER\s+BY\b|\bLIMIT\b|\bOFFSET\b|$)/is';
        if (preg_match($pattern, $sql, $groupMatch) !== 1) {
            return $sql;
        }

        $groupClause = trim($groupMatch['group']);
        if (preg_match('/(^|,)\s*' . preg_quote($expression, '/') . '\s*(,|$)/i', $groupClause) === 1) {
            return $sql;
        }

        $tail = $groupMatch['tail'] !== '' ? $groupMatch['tail'] : ' ';
        $replacement = 'GROUP BY ' . rtrim($groupClause) . ', ' . $expression . $tail;
        return preg_replace($pattern, $replacement, $sql, 1);
    }

    /**
     * Answer a schema-related question using AI.
     * Provides expert knowledge about the FOLIO schema, table relationships,
     * and can suggest relevant tables/SQL snippets.
     *
     * @param string $question The user's question about the schema
     * @param string|null $selectedTable Optional table for context
     * @return array {answer: string, recommendedTables?: string[], sql?: string}
     * @throws \RuntimeException
     */
    public static function answerSchemaQuestion($question, $selectedTable = null)
    {
        $apiKey = Yii::$app->params['geminiApiKey'];
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env'
            );
        }

        $model = Yii::$app->params['geminiModel'] ?: 'gemini-2.5-flash';
        $schemaContext = FolioSchemaService::buildSchemaContext();

        // If a specific table is selected, add its full detail
        $tableContext = '';
        if ($selectedTable) {
            $detail = FolioSchemaService::getTable($selectedTable);
            if ($detail) {
                $cols = array_map(function($c) {
                    return "  - {$c['name']} ({$c['type']}" . ($c['nullable'] ? ', nullable' : '') . ')';
                }, $detail['table']['columns'] ?? []);
                $tableContext = "\n\nCURRENTLY SELECTED TABLE: {$selectedTable}\nColumns:\n" . implode("\n", $cols);
                if (!empty($detail['relationships']['parents'])) {
                    $tableContext .= "\nParent FKs:";
                    foreach ($detail['relationships']['parents'] as $p) {
                        $tableContext .= "\n  - {$p['local_column']} → {$p['parent_table']}.{$p['parent_column']}";
                    }
                }
                if (!empty($detail['relationships']['children'])) {
                    $tableContext .= "\nChild FKs:";
                    foreach ($detail['relationships']['children'] as $c) {
                        $tableContext .= "\n  - {$c['child_table']}.{$c['child_column']} → {$c['local_column']}";
                    }
                }
            }
        }

        $systemPrompt = <<<PROMPT
You are a FOLIO library management system schema expert. You help users understand the database schema,
find the right tables for their needs, and write queries.

The database uses LDLite (a lightweight version of MetaDB) with schema-qualified table names.
Tables ending in __t are MetaDB flattened tables. Tables with __t__ in the name are subtables
(flattened array/object columns from the parent table).

IMPORTANT: Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema to resolve ambiguous terms.
For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT a vendor organization.
This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
(SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%'). See Location Naming Schema.
Always check the vocabulary section before choosing a table for user-mentioned entities.
For text/name comparisons, use case-insensitive matching (LOWER() or ILIKE). Database values are often Title Case.
For item location joins, ALWAYS use item__t.effective_location_id (NOT holdings_record__t.permanent_location_id).

SCHEMA:
{$schemaContext}
{$tableContext}

RESPONSE FORMAT:
Return a JSON object with these fields:
- "answer": A clear, helpful explanation answering the user's question. Use markdown formatting.
- "recommendedTables": (optional) An array of full table names that are relevant to the question.
  Only include this if the question involves finding or recommending tables.
- "sql": (optional) A sample SQL query if helpful. Only include if the user is asking how to
  query something or wants an example. Use PostgreSQL syntax with schema-qualified table names.

Return ONLY the JSON object, no code fences or other text.
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $client = new Client();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setContent(json_encode([
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $question]],
                    ],
                ],
                'systemInstruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 4096,
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                ],
            ]))
            ->send();

        if (!$response->isOk) {
            $err = $response->data['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("Gemini API error: {$err}");
        }

        $data = $response->data;
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Clean up response - remove any code fences
        $text = preg_replace('/^```(?:json)?\s*\n?/m', '', $text);
        $text = preg_replace('/\n?```\s*$/m', '', $text);
        $text = trim($text);

        $result = json_decode($text, true);
        if (!$result || !is_array($result) || empty($result['answer'])) {
            // If JSON parsing failed, return the raw text as the answer
            return ['answer' => $text];
        }

        return $result;
    }

    /**
     * Check that table names in the generated SQL actually exist in our schema.
     * Handles both LDP1 names and MetaDB schema-qualified names.
     * @param string $sql
     */
    private static function validateTableReferences($sql)
    {
        $ldpTableNames = [];
        foreach (FolioSchemaService::getTableNames() as $tableName) {
            $ldpTableNames[strtolower((string)$tableName)] = true;
        }
        $metadbMap = FolioSchemaService::discoverTableMapping();
        $metadbValues = [];
        foreach (array_values($metadbMap) as $metadbName) {
            $metadbValues[strtolower((string)$metadbName)] = true;
        }
        $mappingCachePath = Yii::getAlias('@app/data/table_mapping_cache.json');
        $mappingCache = is_string($mappingCachePath) && is_file($mappingCachePath)
            ? json_decode((string)file_get_contents($mappingCachePath), true)
            : null;
        foreach (array_values(is_array($mappingCache['mapping'] ?? null) ? $mappingCache['mapping'] : []) as $metadbName) {
            $metadbValues[strtolower((string)$metadbName)] = true;
        }
        $subtableCachePath = Yii::getAlias('@app/data/subtable_cache.json');
        $subtableCache = is_string($subtableCachePath) && is_file($subtableCachePath)
            ? json_decode((string)file_get_contents($subtableCachePath), true)
            : null;
        foreach (array_keys(is_array($subtableCache['subtables'] ?? null) ? $subtableCache['subtables'] : []) as $metadbName) {
            $metadbValues[strtolower((string)$metadbName)] = true;
        }
        $localTables = ['acrl_statistics' => true, 'report_expense_allocations' => true];
        $cteAliases = [];
        $identifierPattern = '"(?:[^"]|"")*"|[\w$-]+';

        if (preg_match('/^\s*WITH\s+(?:RECURSIVE\s+)?/i', (string)$sql) === 1) {
            preg_match_all(
                '/(?:\bWITH\s+(?:RECURSIVE\s+)?|,\s*)(' . $identifierPattern . ')\s*(?:\([^)]*\))?\s+AS\s+(?:(?:NOT\s+)?MATERIALIZED\s+)?\(/i',
                (string)$sql,
                $cteMatches
            );
            foreach ($cteMatches[1] ?? [] as $cteAlias) {
                $cteAliases[self::normalizeSqlIdentifierReference((string)$cteAlias)] = true;
            }
        }

        $unknownTables = [];

        foreach (self::extractSqlTableReferenceMatches((string)$sql, $identifierPattern) as $referenceMatch) {
            $rawReference = (string)$referenceMatch[0];
            $referenceEnd = (int)$referenceMatch[1] + strlen($rawReference);
            if (preg_match('/^\s*\(/', substr((string)$sql, $referenceEnd)) === 1) {
                continue;
            }

            $ref = self::normalizeSqlIdentifierReference($rawReference);
            if ($ref === 'select' || $ref === 'lateral' || $ref === 'unnest') {
                continue;
            }

            if (isset($cteAliases[$ref])) {
                continue;
            }

            // Re-run the canonical table policy against the normalized
            // physical reference so quoted identifiers cannot bypass it.
            SqlBuilderService::validateTablePolicy('SELECT 1 FROM ' . $ref);

            // Check if it's a known MetaDB name (schema.table format)
            if (isset($metadbValues[$ref])) {
                continue; // Valid MetaDB table
            }

            // LDP1 aliases must also match an accepted schema name exactly.
            // Fuzzy suffix/contains matching is useful for discovery, but it is
            // unsafe for validating executable SQL generated by a model.
            if (isset($ldpTableNames[$ref])) {
                continue; // Valid LDP1 table
            }

            if (isset($localTables[$ref])) {
                continue;
            }

            $unknownTables[] = $ref;
        }

        if (!empty($unknownTables)) {
            $unknownTables = array_values(array_unique($unknownTables));
            throw new ExploratorySqlValidationException(
                'schema_reference',
                'unknown_table',
                (string)$sql,
                true,
                'The SQL candidate references unknown physical table(s): ' . implode(', ', $unknownTables) . '.'
            );
        }
    }

    private static function extractSqlTableReferenceMatches(string $sql, string $identifierPattern): array
    {
        $referencePattern = '(?:' . $identifierPattern . ')(?:\s*\.\s*(?:' . $identifierPattern . '))?';
        $references = [];
        foreach (self::findSqlRelationReferenceOffsets($sql) as $referenceOffset) {
            $candidate = substr($sql, $referenceOffset);
            if (preg_match('/^\s*(' . $referencePattern . ')/i', $candidate, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $references[] = [
                (string)$match[1][0],
                $referenceOffset + (int)$match[1][1],
            ];
        }

        return $references;
    }

    private static function findSqlRelationReferenceOffsets(string $sql): array
    {
        $offsets = [];
        $depth = 0;
        $selectDepths = [];
        $fromDepths = [];
        $quote = null;
        $inLineComment = false;
        $inBlockComment = false;
        $length = strlen($sql);
        $clauseBoundaryWords = [
            'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET',
            'UNION', 'EXCEPT', 'INTERSECT', 'WINDOW', 'RETURNING',
        ];

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    if ($next === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $index++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $index++;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                foreach (array_keys($fromDepths) as $fromDepth) {
                    if ($fromDepth > $depth) {
                        unset($fromDepths[$fromDepth]);
                    }
                }
                foreach (array_keys($selectDepths) as $selectDepth) {
                    if ($selectDepth > $depth) {
                        unset($selectDepths[$selectDepth]);
                    }
                }
                continue;
            }
            if ($char === ',' && isset($fromDepths[$depth])) {
                $offsets[] = $index + 1;
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $wordEnd = $index + 1;
                while ($wordEnd < $length && preg_match('/[A-Za-z0-9_]/', $sql[$wordEnd]) === 1) {
                    $wordEnd++;
                }
                $word = strtoupper(substr($sql, $index, $wordEnd - $index));
                if ($word === 'SELECT') {
                    $selectDepths[$depth] = true;
                    unset($fromDepths[$depth]);
                } elseif ($word === 'FROM' && isset($selectDepths[$depth])) {
                    $fromDepths[$depth] = true;
                    $offsets[] = $wordEnd;
                } elseif ($word === 'JOIN' && isset($fromDepths[$depth])) {
                    $offsets[] = $wordEnd;
                } elseif (in_array($word, $clauseBoundaryWords, true)) {
                    unset($fromDepths[$depth]);
                }
                $index = $wordEnd - 1;
            }
        }

        return $offsets;
    }

    private static function normalizeSqlIdentifierReference(string $reference): string
    {
        $parts = preg_split('/\s*\.\s*/', trim($reference));
        $normalized = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if (strlen($part) >= 2 && $part[0] === '"' && substr($part, -1) === '"') {
                $normalized[] = str_replace('""', '"', substr($part, 1, -1));
                continue;
            }
            $normalized[] = strtolower($part);
        }

        return implode('.', $normalized);
    }

    /**
     * Generate a report template from a natural-language description.
     * Returns a structured template definition (not yet saved) for user preview.
     *
     * @param string $description What report the user wants
     * @return array Template definition: {slug, name, description, category, sqlTemplate, parameters, defaultLimit}
     * @throws \RuntimeException
     */
    public static function generateReportTemplate($description)
    {
        $apiKey = Yii::$app->params['geminiApiKey'];
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env'
            );
        }

        $model = Yii::$app->params['geminiModel'] ?: 'gemini-2.5-flash';
        $schemaContext = FolioSchemaService::buildSchemaContext();

        $systemPrompt = <<<PROMPT
You are a report template generator for a FOLIO library management system (LDLite/MetaDB database).

Your job is to create a parameterized SQL report template with parameter definitions based on a user's description.

RULES:
1. Generate ONLY SELECT queries — never INSERT, UPDATE, DELETE, DROP, or ALTER.
2. Use EXACT table and column names from the schema below.
3. Table names are schema-qualified (e.g. inventory.item__t, circulation.loan__t).
   Schema names do NOT have a "folio_" prefix.
4. Use PostgreSQL-compatible syntax.
5. LDLite tables have flattened columns (no JSON "data" blobs).
   Nested JSON fields appear as double-underscore columns (e.g. metadata__created_date, status__name).
6. Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema to resolve ambiguous terms.
   For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT a vendor organization.
   This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
   (SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
   When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%'). See Location Naming Schema.
   Always check the vocabulary section before choosing a table for user-mentioned entities.
   For text/name comparisons, use case-insensitive matching (LOWER() or ILIKE). Database values are often Title Case.
   For item location joins, ALWAYS use item__t.effective_location_id (NOT holdings_record__t.permanent_location_id).
7. For parameters that users should fill in, use :paramName placeholders (PDO bind syntax).
7. Choose appropriate parameter types: date, text, select, number, boolean.
8. For text filters that should do partial matching, add "wrap": "like" to the parameter definition.
9. For select parameters, include "options_sql" — a small SQL query to populate the dropdown.
10. NEVER use the PostgreSQL ? operator for JSONB queries — PDO treats ? as a positional parameter placeholder.
    Instead of: data->'key' ? :param  use: po.acq_unit_ids = :param (LDLite tables have denormalized columns).
    If you must query JSONB, use jsonb_exists(data->'key', :param) instead of the ? operator.
11. ALWAYS prefer SUBTABLES over JSONB/data column queries. Subtables (pattern: schema.parent__t__child) are
    flattened versions of nested JSON arrays. They join to their parent on id.
    Examples:
    - Use invoice.invoice_lines__t__fund_distributions instead of data->'fundDistributions' or jsonb_array_elements()
    - Use orders.purchase_order__t__acq_unit_ids instead of data->'acqUnitIds'
    - Use finance.fund__t__acq_unit_ids instead of data->'acqUnitIds'
    NEVER use data-> column references or jsonb_array_elements() — these do not exist in __t tables.
12. Use smart default macros where appropriate:
    - \$fiscal_year_start — July 1 of current fiscal year
    - \$fiscal_year_end — June 30 of current fiscal year  
    - \$today — current date
    - \$30_days_ago — 30 days before today
    - \$90_days_ago — 90 days before today
13. PERFORMANCE — ORDER BY: Do NOT add ORDER BY unless the user explicitly requests sorted or
    ranked results (e.g. "top N", "highest", "sorted by"). ORDER BY forces PostgreSQL to
    materialize the ENTIRE result set before returning any rows — even with LIMIT 100.
    OMIT ORDER BY for listing/existence/missing-field queries. KEEP it only for ranking
    (ORDER BY count DESC) or when the user explicitly asks for sorted output.
14. UUID TYPE CASTS — NEVER write ::uuid or ::text anywhere. MetaDB join columns are
    already compatible types. Explicit casts bypass PostgreSQL indexes and cause
    catastrophically slow full-table scans. Always write plain equality with no casts:
    hr.instance_id = inst.id, ii.holdings_record_id = hr.id, etc.
    ::uuid and ::text are NEVER correct in JOIN ON conditions or WHERE clauses.
15. MONETARY / FINANCIAL COLUMNS — MANDATORY: Format ALL money amounts as USD currency.
    Finance tables store amounts as NUMERIC with many decimal places.
    ALWAYS use TO_CHAR to format as a USD dollar amount with comma separators:
      TO_CHAR(inv.total, 'FM$999,999,999,990.00')
      TO_CHAR(SUM(inv.amount), 'FM$999,999,999,990.00')
    Use TO_CHAR only in the outermost SELECT (not in WHERE, JOIN, or subquery filters).
    Applies to any column from finance.*, invoice.*, or any column whose name contains:
    total, amount, price, cost, spent, encumbered, expenditure, budget, balance.
    NEVER return raw unformatted monetary values.

SCHEMA:
{$schemaContext}

RESPONSE FORMAT:
Return ONLY a JSON object (no markdown, no code blocks) with this structure:
{
  "slug": "kebab-case-name",
  "name": "Human Readable Report Name",
  "description": "What this report shows and why it's useful.",
  "category": "acquisitions|circulation|inventory|finance|users|cataloging|other",
  "sqlTemplate": "SELECT ... FROM ... WHERE col LIKE :param ...",
  "parameters": [
    {
      "name": "paramName",
      "type": "date|text|select|number|boolean",
      "label": "User-Facing Label",
      "required": true,
      "default": "\$fiscal_year_start",
      "placeholder": "Hint text",
      "description": "What this parameter filters"
    }
  ],
  "defaultLimit": 100
}
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $client = new \yii\httpclient\Client();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setContent(json_encode([
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => "Create a report template for: {$description}"]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 16384,
                ],
            ]))
            ->send();

        if (!$response->isOk) {
            $error = json_decode($response->content, true);
            $msg = $error['error']['message'] ?? 'Unknown Gemini API error';
            throw new \RuntimeException("Gemini API error: {$msg}");
        }

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            throw new \RuntimeException(
                'AI response was truncated (report too complex). Try a shorter description.'
            );
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return self::parseReportTemplate($text);
    }

    /**
     * Convert a Yii2 PHP report model into a report template using Gemini AI.
     *
     * @param string $phpCode The PHP source code of a Yii2 report model
     * @return array Template definition: {slug, name, description, category, sqlTemplate, parameters, defaultLimit}
     * @throws \RuntimeException
     */
    public static function convertReportFromPhp($phpCode)
    {
        $apiKey = Yii::$app->params['geminiApiKey'];
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env'
            );
        }

        $model = Yii::$app->params['geminiModel'] ?: 'gemini-2.5-flash';
        $schemaContext = FolioSchemaService::buildSchemaContext();

        $systemPrompt = <<<PROMPT
You are a report template converter for a FOLIO library management system (LDLite/MetaDB database).

Your job is to analyze a Yii2 PHP report model and convert it into a parameterized SQL report template with parameter definitions.

UNDERSTANDING THE PHP CODE:
- The PHP class extends a base model and builds SQL queries using Yii2's SqlDataProvider.
- SQL is typically in a \$sql string variable or returned from a method.
- Parameters are bound via \$params array with ':paramName' => \$value patterns.
- `setDefaultDates()` sets \$fiscal_year_start and \$fiscal_year_end — map these to \$fiscal_year_start and \$fiscal_year_end macros.
- Attributes like \$this->start_date, \$this->end_date etc. are user-supplied parameters.
- Pattern `'%' . \$this->someParam . '%'` means the parameter should have "wrap": "like".
- Dynamic IN clauses built from arrays (e.g. exploding textarea input into multiple bind params) should use a single "list" type parameter — the system will expand `:paramName` into `:paramName_0, :paramName_1, ...` at runtime.
- Remove any `ldlite.` or `ldplite.` schema prefix from table references — use only the standard schema.table__t format.
- Remove `ldp.table_name` references — use the documented schema-qualified names.

CONVERSION RULES:
1. Extract the core SQL SELECT query from the PHP code.
2. Replace PHP variable bindings with :paramName placeholders.
3. Keep the SQL PostgreSQL-compatible.
4. For dynamic fiscal-year column pivots (e.g. SUM(CASE WHEN year = X)), simplify to a single date range filter with a total column.
5. For parameters with select/dropdown values populated from DB queries, include "options_sql" with the query.
6. Use appropriate parameter types: date, text, select, number, boolean, list.
7. The "list" type is for parameters that accept multiple values (one per line) for IN clauses.
8. NEVER use the PostgreSQL ? operator for JSONB queries — PDO treats ? as a positional parameter placeholder.
   Instead of: data->'key' ? :param  use the denormalized column (e.g. po.acq_unit_ids = :param).
   If you must query JSONB, use jsonb_exists(data->'key', :param) instead of the ? operator.
9. ALWAYS prefer SUBTABLES over JSONB/data column queries. Subtables (pattern: schema.parent__t__child) are
   flattened versions of nested JSON arrays. They join to their parent on id.
   Examples:
   - Use invoice.invoice_lines__t__fund_distributions instead of data->'fundDistributions' or jsonb_array_elements()
   - Use orders.purchase_order__t__acq_unit_ids instead of data->'acqUnitIds'
   - Use finance.fund__t__acq_unit_ids instead of data->'acqUnitIds'
   NEVER use data-> column references or jsonb_array_elements() — these do not exist in __t tables.
10. Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema to resolve ambiguous terms.
    For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT a vendor organization.
    This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
    (SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
    When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%'). See Location Naming Schema.
    Always check the vocabulary section before choosing a table for user-mentioned entities.
    For text/name comparisons, use case-insensitive matching (LOWER() or ILIKE). Database values are often Title Case.
    For item location joins, ALWAYS use item__t.effective_location_id (NOT holdings_record__t.permanent_location_id).
11. Use smart default macros where appropriate:
   - \$fiscal_year_start — July 1 of current fiscal year
   - \$fiscal_year_end — June 30 of current fiscal year
   - \$today — current date
   - \$30_days_ago — 30 days before today
   - \$90_days_ago — 90 days before today
12. PERFORMANCE — ORDER BY: Do NOT add ORDER BY unless the user explicitly requests sorted or
   ranked results (e.g. "top N", "highest", "sorted by"). ORDER BY forces PostgreSQL to
   materialize the ENTIRE result set before returning any rows — even with LIMIT 100.
   OMIT ORDER BY for listing/existence/missing-field queries. KEEP it only for ranking
   (ORDER BY count DESC) or when the user explicitly asks for sorted output.
13. UUID TYPE CASTS — NEVER write ::uuid or ::text anywhere. MetaDB join columns are
   already compatible types. Explicit casts bypass PostgreSQL indexes and cause
   catastrophically slow full-table scans. Always write plain equality with no casts:
   hr.instance_id = inst.id, ii.holdings_record_id = hr.id, etc.
   ::uuid and ::text are NEVER correct in JOIN ON conditions or WHERE clauses.
14. MONETARY / FINANCIAL COLUMNS — MANDATORY: Format ALL money amounts as USD currency.
   Finance tables store amounts as NUMERIC with many decimal places.
   ALWAYS use TO_CHAR to format as a USD dollar amount with comma separators:
     TO_CHAR(inv.total, 'FM$999,999,999,990.00')
     TO_CHAR(SUM(inv.amount), 'FM$999,999,999,990.00')
   Use TO_CHAR only in the outermost SELECT (not in WHERE, JOIN, or subquery filters).
   Applies to any column from finance.*, invoice.*, or any column whose name contains:
   total, amount, price, cost, spent, encumbered, expenditure, budget, balance.
   NEVER return raw unformatted monetary values.

AVAILABLE SCHEMA (use these exact table/column names):
{$schemaContext}

RESPONSE FORMAT:
Return ONLY a JSON object (no markdown, no code blocks) with this structure:
{
  "slug": "kebab-case-name",
  "name": "Human Readable Report Name",
  "description": "What this report shows and why it's useful.",
  "category": "acquisitions|circulation|inventory|finance|users|cataloging|other",
  "sqlTemplate": "SELECT ... FROM ... WHERE col = :param ...",
  "parameters": [
    {
      "name": "paramName",
      "type": "date|text|select|number|boolean|list",
      "label": "User-Facing Label",
      "required": true,
      "default": "\$fiscal_year_start",
      "placeholder": "Hint text",
      "description": "What this parameter filters",
      "wrap": "like",
      "options_sql": "SELECT DISTINCT col FROM table ORDER BY col"
    }
  ],
  "defaultLimit": 100
}
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $client = new \yii\httpclient\Client();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setContent(json_encode([
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => "Convert this Yii2 PHP report model into a report template:\n\n{$phpCode}"]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 16384,
                ],
            ]))
            ->send();

        if (!$response->isOk) {
            $error = json_decode($response->content, true);
            $msg = $error['error']['message'] ?? 'Unknown Gemini API error';
            throw new \RuntimeException("Gemini API error: {$msg}");
        }

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            throw new \RuntimeException(
                'AI response was truncated (report too complex). Try simplifying the PHP model before converting.'
            );
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return self::parseReportTemplate($text);
    }

    /**
     * Parse Gemini's report template response into a structured array.
     * @param string $text Raw Gemini response
     * @return array Parsed template definition
     */
    private static function parseReportTemplate($text)
    {
        // Strip markdown code blocks if present
        $text = preg_replace('/^```(?:json)?\s*\n?/m', '', $text);
        $text = preg_replace('/\n?```\s*$/m', '', $text);
        $text = trim($text);

        $template = json_decode($text, true);
        if (!$template || !is_array($template)) {
            throw new \RuntimeException(
                'Could not parse report template from AI response. Raw: ' . substr($text, 0, 500)
            );
        }

        // Validate required fields
        $required = ['slug', 'name', 'sqlTemplate', 'parameters'];
        foreach ($required as $field) {
            if (empty($template[$field])) {
                throw new \RuntimeException("AI response missing required field: {$field}");
            }
        }

        // Auto-fix: replace JSONB ? operator with jsonb_exists() to avoid PDO conflicts
        // PDO interprets ? as a positional parameter, breaking named params
        $template['sqlTemplate'] = preg_replace(
            "/(->'[^']+')\s*\?\s*(:[a-zA-Z_]+)/",
            'jsonb_exists($1, $2)',
            $template['sqlTemplate']
        );

        // Validate SQL safety
        SqlBuilderService::validateSafety($template['sqlTemplate']);

        // Ensure proper defaults
        $template['category'] = $template['category'] ?? 'other';
        $template['description'] = $template['description'] ?? '';
        $template['defaultLimit'] = $template['defaultLimit'] ?? 100;
        $template['createdBy'] = 'ai';

        return $template;
    }

    private static function promptNeedsTrendTimeframeClarification(string $normalizedPrompt): bool
    {
        $mentionsCirculation = strpos($normalizedPrompt, 'circulation') !== false;
        $mentionsTrend = preg_match('/\btrend\b|\btrends\b/i', $normalizedPrompt) === 1;
        if (!$mentionsCirculation || !$mentionsTrend) {
            return false;
        }

        if (self::promptHasExplicitTimeframe($normalizedPrompt)) {
            return false;
        }

        return true;
    }

    private static function promptNeedsPreviousCirculationClarification(string $normalizedPrompt): bool
    {
        if (strpos($normalizedPrompt, 'previous circulation') === false) {
            return false;
        }

        if (self::promptMentionsFormerAlephComparisonPolicy($normalizedPrompt)
            || self::promptMentionsPriorYearComparisonPolicy($normalizedPrompt)
            || self::promptMentionsCumulativeBeforeComparisonPolicy($normalizedPrompt)
        ) {
            return false;
        }

        return true;
    }

    private static function promptHasExplicitTimeframe(string $normalizedPrompt): bool
    {
        $timeframePatterns = [
            '/\b(19|20)\d{2}\b/',
            '/\blast\s+\d+\s+(day|days|week|weeks|month|months|year|years)\b/',
            '/\bthis\s+(year|month|quarter|week)\b/',
            '/\bcurrent\s+(year|month|quarter|week|fiscal year|academic year)\b/',
            '/\bfiscal year\b/',
            '/\bacademic year\b/',
            '/\bbetween\b/',
            '/\bfrom\b.*\bto\b/',
            '/\bmonthly\b/',
            '/\bweekly\b/',
            '/\bdaily\b/',
        ];

        foreach ($timeframePatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsFormerAlephComparisonPolicy(string $normalizedPrompt): bool
    {
        $formerAlephPatterns = [
            '/\bformer\b/',
            '/\bhistoric\b/',
            '/\bhistorical\b/',
            '/\baleph\b/',
        ];

        foreach ($formerAlephPatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsPriorYearComparisonPolicy(string $normalizedPrompt): bool
    {
        $priorYearPatterns = [
            '/\bprior year\b/',
            '/\bprevious year\b/',
            '/\byear over year\b/',
            '/\byoy\b/',
        ];

        foreach ($priorYearPatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsCumulativeBeforeComparisonPolicy(string $normalizedPrompt): bool
    {
        $cumulativeBeforePatterns = [
            '/\bcumulative before\b/',
            '/\bcumulative circulation before\b/',
        ];

        foreach ($cumulativeBeforePatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsContributorConstraint($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        return preg_match(
            '/\b(other author|by this contributor|by the contributor|with (?:the )?(?:corporate[- ]body )?(?:author|authors|contributor|contributors)|(?:author|authors|contributor|contributors)\s+(?:named|called|listed as|matching)|corporate[- ]body contributor)\b/i',
            $prompt
        ) === 1;
    }

    private static function resolvePromptQueryFamily($prompt, $campus = null)
    {
        $prompt = strtolower(trim((string)$prompt));
        if ($prompt === '') {
            return null;
        }

        if (self::promptMentionsMarcConstraint($prompt)) {
            return null;
        }

        if (self::promptMentionsCollectionAgeFamily($prompt)) {
            return [
                'familyKey' => 'inventory_collection_age',
            ];
        }

        if (self::promptMentionsCirculationTrendMatrixFamily($prompt)) {
            return [
                'familyKey' => 'circulation_trends_matrix',
            ];
        }

        if (self::promptMentionsTopCirculatingItemsFamily($prompt)) {
            return [
                'familyKey' => 'circulation_top_items',
            ];
        }

        if (self::promptMentionsInventoryLibraryLocationListingFamily($prompt)) {
            return [
                'familyKey' => 'inventory_library_location_listing',
            ];
        }

        if (!self::promptMentionsContributorConstraint($prompt)) {
            return null;
        }

        if (!self::promptMentionsCoveredInventoryOutputs($prompt)) {
            return null;
        }

        $hasCampusContext = !empty($campus) && $campus !== 'All Colleges';
        $mentionsCampusLikeScope = self::promptMentionsCoveredInventoryScope($prompt);
        if (!$hasCampusContext && !$mentionsCampusLikeScope) {
            return null;
        }

        return [
            'familyKey' => 'inventory_contributor_campus_item_barcode',
        ];
    }

    private static function promptMentionsCoveredInventoryScope($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        if (preg_match('/\b(campus|library|location|holdings?)\b/i', $prompt) === 1) {
            return true;
        }

        if (preg_match('/\b(at|from|for|in)\s+[a-z0-9 .\-]*college\b/i', $prompt) === 1) {
            return true;
        }

        return preg_match('/\b[a-z0-9 .\-]*college\s+(campus|library)\b/i', $prompt) === 1;
    }

    private static function promptMentionsCollectionAgeFamily($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        $mentionsAge = preg_match('/\b(average\s+age|avg\s+age|age)\b/i', $prompt) === 1;
        if (!$mentionsAge) {
            return false;
        }

        $mentionsScope = preg_match('/\b(library|location|collection|shelving)\b/i', $prompt) === 1;
        if (!$mentionsScope) {
            return false;
        }

        return preg_match('/\b(circulation|trend|trends|previous circulation|barcode|barcodes|instance number|item id|contributor|author)\b/i', $prompt) !== 1;
    }

    private static function promptMentionsCirculationTrendMatrixFamily($prompt)
    {
        if (!self::promptMentionsCirculationTrendMatrixCandidate($prompt)) {
            return false;
        }

        return preg_match('/\b(primary call number class|call number class)\b/i', $prompt) === 1;
    }

    private static function promptMentionsCirculationTrendMatrixCandidate($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        if (self::promptNeedsTrendTimeframeClarification(strtolower($prompt))) {
            return false;
        }

        if (self::promptNeedsPreviousCirculationClarification(strtolower($prompt))) {
            return false;
        }

        $mentionsCirculation = preg_match('/\b(circulation|loan|loans|checkout|checkouts)\b/i', $prompt) === 1;
        if (!$mentionsCirculation) {
            return false;
        }

        preg_match_all('/\b20\d{2}\b/', $prompt, $yearMatches);
        if (count($yearMatches[0] ?? []) < 2) {
            return false;
        }

        return preg_match('/\b(campus|library|location)\b/i', $prompt) === 1;
    }

    private static function promptMentionsTopCirculatingItemsFamily($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        $hasTopRanking = preg_match('/\b(top|most)\b/i', $prompt) === 1;
        $hasCirculationLanguage = preg_match('/\b(circulating|circulated|circulation)\b/i', $prompt) === 1;
        $hasItemConstraint = preg_match('/\b(item|items|material|materials|book(?:s)?|dvd(?:s)?|cd(?:s)?|video(?:s)?|journal(?:s)?|magazine(?:s)?|map(?:s)?|score(?:s)?|thes(?:is|es)|dissertation(?:s)?)\b/i', $prompt) === 1;

        return $hasTopRanking
            && $hasCirculationLanguage
            && $hasItemConstraint
            && preg_match('/\b(campus|library|location)\b/i', $prompt) === 1;
    }

    private static function promptMentionsInventoryLibraryLocationListingFamily($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        if (self::promptMentionsContributorConstraint($prompt)) {
            return false;
        }

        if (self::promptMentionsUnsupportedInventoryListingOutput($prompt)) {
            return false;
        }

        if (self::promptMentionsUnsupportedInventoryListingConstraint($prompt)) {
            return false;
        }

        if (self::promptMentionsExplicitInstanceHridList($prompt)) {
            return false;
        }

        if (
            !self::promptMentionsCoveredInventoryOutputs($prompt)
            && !self::promptMentionsInventoryListingSubject($prompt)
        ) {
            return false;
        }

        if (
            !self::promptMentionsLibraryLocationListingScope($prompt)
            && !self::promptMentionsCampusScopedInventoryItemFilterListing($prompt)
        ) {
            return false;
        }

        $hasListingLanguage = preg_match('/\b(list|listing|show|find|create)\b/i', $prompt) === 1;
        $hasInventoryNoun = self::promptMentionsCoveredInventoryOutputs($prompt)
            || self::promptMentionsInventoryListingSubject($prompt);

        if (!$hasListingLanguage || !$hasInventoryNoun) {
            return false;
        }

        return preg_match('/\b(top|circulating|circulation|average\s+age|avg\s+age|loan|checkout|trend|trends)\b/i', $prompt) !== 1;
    }

    private static function promptMentionsCampusScopedInventoryItemFilterListing($prompt): bool
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        $mentionsCampusScope = self::promptMentionsSpecificCampusScope($prompt)
            || preg_match('/\b(at|from|for|in)\s+[a-z0-9 .\-]*college\b/i', $prompt) === 1;
        if (!$mentionsCampusScope) {
            return false;
        }

        $mentionsItemFilter = preg_match('/\bmaterial\s+type\b|\bdocument\s+type\b|\bitem\s+type\b/i', $prompt) === 1
            || self::promptMentionsItemStatusScope($prompt);

        return $mentionsItemFilter;
    }

    private static function promptMentionsLibraryLocationListingScope($prompt): bool
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        if (preg_match('/\b(location|locations|location\s+code|holdings?|only\s+holding|only\s+holdings)\b/i', $prompt) === 1) {
            return true;
        }

        if (preg_match('/\blibrary\b/i', $prompt) === 1) {
            return true;
        }

        return preg_match('/\bin\s+[a-z0-9 .\'"-]+\s+(?:collection|reference|stacks|case|room|shelving)\b/i', $prompt) === 1;
    }

    private static function promptMentionsUnsupportedInventoryListingOutput($prompt): bool
    {
        return preg_match('/\b(publisher|publishers|publication\s+place|place\s+of\s+publication)\b/i', (string)$prompt) === 1;
    }

    private static function promptMentionsUnsupportedInventoryListingConstraint($prompt): bool
    {
        $prompt = (string)$prompt;
        if (preg_match('/\b(?:18|19|20|21)\d{2}\b/', $prompt) === 1) {
            return true;
        }

        return preg_match(
            '/\b(subject|language|genre|classification|isbn|issn|titled)\b'
                . '|\b(?:published|released|issued|created|acquired|cataloged)\s+(?:in|before|after|since|between)\b/i',
            $prompt
        ) === 1;
    }

    private static function promptMentionsExplicitInstanceHridList($prompt): bool
    {
        if (preg_match('/\binstance\s+(?:numbers?|ids?)\s+below\b/i', (string)$prompt) === 1) {
            return true;
        }

        preg_match_all('/\bin\d{8,}\b/i', (string)$prompt, $matches);
        return count($matches[0] ?? []) > 1;
    }

    private static function promptMentionsCoveredInventoryOutputs($prompt)
    {
        return preg_match('/\b(barcode|barcodes|item id|item ids|instance number|instance numbers|publication date|pub date|title|titles)\b/', (string)$prompt) === 1;
    }

    private static function promptMentionsInventoryListingSubject($prompt): bool
    {
        return preg_match(
            '/\b(video|videos|vhs|dvd|dvds|blu[- ]?ray|blu[- ]?rays|videocassette|videocassettes|film|films)\b/i',
            (string)$prompt
        ) === 1;
    }

    private static function promptMentionsMarcConstraint($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        return preg_match('/\bmarc\b|\bfield\s*[0-9]{3}\b|\b[0-9]{3}\s*field\b|\b[0-9]xx\s+fields?\b/i', $prompt) === 1;
    }

    private static function buildQueryFamilySlotSystemPrompt($familyKey, $campus)
    {
        $contracts = QueryFamilyContractService::loadContracts();
        $contract = $contracts[$familyKey] ?? null;
        if (!is_array($contract)) {
            throw new \RuntimeException('Missing query family contract for slot extraction: ' . $familyKey);
        }

        $campusRule = '';
        if ($campus && $campus !== 'All Colleges') {
            $safeCampus = str_replace("'", "''", (string)$campus);
            $campusRule = <<<RULE

CAMPUS SLOT DEFAULT:
- The user's home institution is '{$safeCampus}'.
- If the prompt does not name another campus explicitly, set slots.campus to '{$safeCampus}'.
RULE;
        }

        $requiredSlots = json_encode(array_values($contract['slots']['required'] ?? []), JSON_UNESCAPED_SLASHES);
        $supportedSlots = json_encode(array_values($contract['slots']['supported'] ?? []), JSON_UNESCAPED_SLASHES);
        $allowedOutputs = json_encode(array_values($contract['outputs']['allowed'] ?? []), JSON_UNESCAPED_SLASHES);
        $matchPolicies = json_encode(array_values($contract['matchPolicy']['supported'] ?? []), JSON_UNESCAPED_SLASHES);
        $defaultMatchPolicy = trim((string)($contract['matchPolicy']['default'] ?? 'case_insensitive_contains'));
        $slotContract = self::buildQueryFamilySlotPromptContract($contract, $defaultMatchPolicy);
        $slotInferenceRules = self::buildQueryFamilySlotInferenceRules($contract);

        return <<<PROMPT
You are a deterministic query-family slot extractor for a FOLIO inventory workflow.

Return ONLY a JSON object matching this contract:
{$slotContract}

Rules:
1. Use only the family key {$familyKey}.
2. Required slots: {$requiredSlots}
3. Supported slots: {$supportedSlots}
4. Allowed outputs: {$allowedOutputs}
5. Supported match policies: {$matchPolicies}
6. Choose exact_phrase when the prompt uses quotation marks or wording such as named, listed as, or called for a contributor or other named entity; otherwise use {$defaultMatchPolicy}.
7. Do NOT return tables, joins, SQL operators, SQL snippets, raw schema names, or query objects.
8. Do NOT include markdown, code fences, or commentary.
{$slotInferenceRules}
{$campusRule}
PROMPT;
    }

    private static function buildQueryFamilySlotPromptContract(array $contract, string $defaultMatchPolicy): string
    {
        $requiredSlots = array_fill_keys(array_values($contract['slots']['required'] ?? []), true);
        $supportedSlots = array_values($contract['slots']['supported'] ?? []);

        $lines = [];
        $lines[] = '{';
        $lines[] = '    "familyKey": "' . ($contract['familyKey'] ?? '') . '",';
        $lines[] = '    "slots": {';

        $slotLines = [];
        foreach ($supportedSlots as $slotName) {
            if ($slotName === 'year_buckets') {
                $slotLines[] = '        "' . $slotName . '": ["' . (isset($requiredSlots[$slotName]) ? 'required years' : 'optional years') . '"]';
                continue;
            }

            $slotLines[] = '        "' . $slotName . '": "' . (isset($requiredSlots[$slotName]) ? 'required string' : 'optional string') . '"';
        }
        $slotLines[] = '        "requested_outputs": ["one or more allowed outputs"]';
        $slotLines[] = '        "match_policy": "' . $defaultMatchPolicy . '"';

        $lastIndex = count($slotLines) - 1;
        foreach ($slotLines as $index => $slotLine) {
            $lines[] = $slotLine . ($index === $lastIndex ? '' : ',');
        }

        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    private static function buildQueryFamilySlotInferenceRules(array $contract): string
    {
        $policies = $contract['slots']['inferencePolicies'] ?? [];
        if (!is_array($policies) || $policies === []) {
            return '';
        }

        $lines = [
            '',
            'Slot inference rules:',
        ];

        foreach ($policies as $slotName => $policy) {
            if ($policy !== QueryFamilyContractService::SLOT_INFERENCE_POLICY_EXPLICIT_PROMPT_ONLY) {
                continue;
            }

            if (($contract['familyKey'] ?? '') === 'inventory_collection_age' && $slotName === 'location') {
                $lines[] = '- Only set slots.location when the prompt explicitly names a collection or sub-location scope; if the prompt only names a library, omit slots.location.';
                continue;
            }

            $lines[] = '- Only set slots.' . $slotName . ' when the prompt explicitly asks for that scope; otherwise omit slots.' . $slotName . '.';
        }

        return count($lines) > 2 ? implode("\n", $lines) : '';
    }
}
