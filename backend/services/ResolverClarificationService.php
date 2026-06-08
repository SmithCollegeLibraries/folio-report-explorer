<?php

namespace app\services;

/**
 * Turns resolver evidence into a user-facing clarification, using a model only
 * when its response validates against resolver-provided options.
 */
class ResolverClarificationService
{
    /** @var callable|null */
    private $modelClient;

    public function __construct(?callable $modelClient = null)
    {
        $this->modelClient = $modelClient;
    }

    /**
     * @param array<string, mixed> $resolverResponse
     * @return array<string, mixed>
     */
    public function buildClarification(string $prompt, array $resolverResponse): array
    {
        if (empty($resolverResponse['needsClarification'])) {
            return $resolverResponse;
        }

        $client = $this->modelClient ?: $this->defaultModelClient();
        if ($client === null) {
            return $this->deterministicFallback($resolverResponse, 'model_client_unavailable');
        }

        try {
            $modelResponse = $client($prompt, $resolverResponse);
        } catch (\Throwable $e) {
            return $this->deterministicFallback($resolverResponse, 'model_client_error: ' . $e->getMessage());
        }

        $validationError = $this->validateModelResponse($modelResponse, $resolverResponse);
        if ($validationError !== null) {
            return $this->deterministicFallback($resolverResponse, $validationError);
        }

        return $this->mergeModelClarification($modelResponse, $resolverResponse);
    }

    private function defaultModelClient(): ?callable
    {
        if (class_exists(GeminiService::class) && method_exists(GeminiService::class, 'generateResolverClarificationJson')) {
            return [GeminiService::class, 'generateResolverClarificationJson'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $modelResponse
     * @param array<string, mixed> $resolverResponse
     */
    private function validateModelResponse(array $modelResponse, array $resolverResponse): ?string
    {
        if (isset($modelResponse['sql']) || stripos((string)($modelResponse['question'] ?? ''), 'select ') !== false) {
            return 'model_returned_sql';
        }

        if (trim((string)($modelResponse['question'] ?? '')) === '') {
            return 'model_missing_question';
        }

        $modelItems = $modelResponse['clarificationItems'] ?? null;
        if (!is_array($modelItems) || empty($modelItems)) {
            return 'model_missing_clarification_items';
        }

        $allowedItems = $this->allowedItemsByKey($resolverResponse);
        $seenKeys = [];
        foreach ($modelItems as $modelItem) {
            if (!is_array($modelItem)) {
                return 'model_invalid_item';
            }

            $key = (string)($modelItem['clarificationKey'] ?? '');
            if ($key === '' || !isset($allowedItems[$key])) {
                return 'model_invalid_clarification_key';
            }
            $seenKeys[$key] = true;

            $modelOptions = $modelItem['options'] ?? [];
            if (!is_array($modelOptions)) {
                return 'model_invalid_options';
            }

            foreach ($modelOptions as $modelOption) {
                if (!is_array($modelOption)) {
                    return 'model_invalid_option';
                }

                $optionId = (string)($modelOption['id'] ?? '');
                if ($optionId === '' || !isset($allowedItems[$key][$optionId])) {
                    return 'model_invalid_option';
                }
            }
        }

        foreach (array_keys($allowedItems) as $requiredKey) {
            if (!isset($seenKeys[$requiredKey])) {
                return 'model_missing_clarification_item';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $modelResponse
     * @param array<string, mixed> $resolverResponse
     * @return array<string, mixed>
     */
    private function mergeModelClarification(array $modelResponse, array $resolverResponse): array
    {
        $merged = $resolverResponse;
        $merged['question'] = trim((string)$modelResponse['question']);
        if (trim((string)($modelResponse['message'] ?? '')) !== '') {
            $merged['message'] = trim((string)$modelResponse['message']);
        }
        $merged['clarificationItems'] = $this->mergeModelItems(
            $modelResponse['clarificationItems'] ?? [],
            $resolverResponse['clarificationItems'] ?? []
        );
        $merged['clarificationSource'] = 'model';
        $merged['routeReason'] = 'resolver_model_clarification';
        return $merged;
    }

    /**
     * @param array<int, mixed> $modelItems
     * @param array<int, mixed> $resolverItems
     * @return array<int, array<string, mixed>>
     */
    private function mergeModelItems(array $modelItems, array $resolverItems): array
    {
        $resolverByKey = [];
        foreach ($resolverItems as $resolverItem) {
            if (!is_array($resolverItem)) {
                continue;
            }
            $resolverByKey[(string)($resolverItem['clarificationKey'] ?? '')] = $resolverItem;
        }

        $merged = [];
        foreach ($modelItems as $modelItem) {
            if (!is_array($modelItem)) {
                continue;
            }

            $key = (string)($modelItem['clarificationKey'] ?? '');
            if (!isset($resolverByKey[$key])) {
                continue;
            }

            $item = $resolverByKey[$key];
            if (trim((string)($modelItem['question'] ?? '')) !== '') {
                $item['question'] = trim((string)$modelItem['question']);
            }

            $requestedOptionIds = [];
            foreach (($modelItem['options'] ?? []) as $modelOption) {
                if (is_array($modelOption) && isset($modelOption['id'])) {
                    $requestedOptionIds[(string)$modelOption['id']] = true;
                }
            }

            if (!empty($requestedOptionIds)) {
                $item['options'] = array_values(array_filter($item['options'] ?? [], function ($option) use ($requestedOptionIds) {
                    return is_array($option) && isset($requestedOptionIds[(string)($option['id'] ?? '')]);
                }));
            }

            $merged[] = $item;
        }

        return !empty($merged) ? $merged : array_values(array_filter($resolverItems, 'is_array'));
    }

    /**
     * @param array<string, mixed> $resolverResponse
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function allowedItemsByKey(array $resolverResponse): array
    {
        $allowed = [];
        foreach (($resolverResponse['clarificationItems'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string)($item['clarificationKey'] ?? '');
            if ($key === '') {
                continue;
            }
            $allowed[$key] = [];
            foreach (($item['options'] ?? []) as $option) {
                if (is_array($option) && isset($option['id'])) {
                    $allowed[$key][(string)$option['id']] = $option;
                }
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, mixed> $resolverResponse
     * @return array<string, mixed>
     */
    private function deterministicFallback(array $resolverResponse, string $reason): array
    {
        $fallback = $resolverResponse;
        $fallback['clarificationSource'] = 'deterministic';
        $fallback['routeReason'] = 'resolver_deterministic_fallback';
        $fallback['modelClarificationFallbackReason'] = $reason;
        return $fallback;
    }
}
