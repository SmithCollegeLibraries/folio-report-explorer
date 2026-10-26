<?php

namespace app\services;

use Yii;

/** Persists the question catalogue and composes adjacent-year reviews. */
class AcrlCatalogueService
{
    private $db;
    private $validator;
    private $reviewService;

    public function __construct($db = null, $matcher = null, $validator = null, $publicationPolicy = null, $reviewService = null)
    {
        $this->db = $db ?: Yii::$app->db;
        $this->validator = $validator ?: new AcrlQuestionImportValidatorService();
        $this->reviewService = $reviewService ?: new AcrlCatalogueReviewService(
            $this->db,
            $matcher ?: new AcrlQuestionMatcherService(),
            $publicationPolicy ?: new AcrlPublicationPolicyService()
        );
    }

    public function listYears(): array
    {
        return $this->reviewService->listYears();
    }

    public function importWorksheet($year, $fileName, $sha256, $items, bool $replacePublished, string $userId): array
    {
        $items = $this->validator->validateUpload($year, $fileName, $sha256, $items);
        $year = (int)$year;
        $existing = $this->reviewService->yearRow($year);
        if ($existing && $existing['status'] === 'published' && !$replacePublished) {
            throw new \DomainException('This year is published. Choose replace draft before uploading a replacement.');
        }

        $counts = ['survey' => ['prompts' => [], 'items' => 0], 'trends' => ['prompts' => [], 'items' => 0]];
        foreach ($items as $item) {
            $counts[$item['sheet']]['prompts'][$item['question_id']] = true;
            $counts[$item['sheet']]['items'] += 1;
        }

        $transaction = $this->db->beginTransaction();
        try {
            if (!$existing) {
                $this->db->createCommand()->insert('acrl_survey_years', [
                    'survey_year' => $year,
                    'status' => 'draft',
                    'source_file_name' => $fileName,
                    'source_sha256' => strtolower($sha256),
                    'survey_prompt_count' => count($counts['survey']['prompts']),
                    'trends_prompt_count' => count($counts['trends']['prompts']),
                    'survey_item_count' => $counts['survey']['items'],
                    'trends_item_count' => $counts['trends']['items'],
                    'created_by' => $userId,
                ])->execute();
            } else {
                $this->db->createCommand()->update('acrl_survey_years', [
                    'status' => 'draft',
                    'source_file_name' => $fileName,
                    'source_sha256' => strtolower($sha256),
                    'survey_prompt_count' => count($counts['survey']['prompts']),
                    'trends_prompt_count' => count($counts['trends']['prompts']),
                    'survey_item_count' => $counts['survey']['items'],
                    'trends_item_count' => $counts['trends']['items'],
                    'published_by' => null,
                    'published_at' => null,
                ], ['survey_year' => $year])->execute();
            }

            $existingQuestions = $this->reviewService->questionRows($year);
            $keep = [];
            foreach ($items as $item) {
                $key = $this->reviewService->questionKey($item);
                $keep[$key] = true;
                $values = $item;
                $values['survey_year'] = $year;
                $values['required_status'] = $values['required'];
                unset($values['required']);
                $this->db->createCommand()->upsert('acrl_questions', $values, [
                    'section' => $item['section'],
                    'prompt' => $item['prompt'],
                    'answer_type' => $item['answer_type'],
                    'required_status' => $item['required'],
                    'calculated_from' => $item['calculated_from'],
                    'survey_order' => $item['survey_order'],
                ])->execute();
            }
            foreach ($existingQuestions as $question) {
                if (!isset($keep[$this->reviewService->questionKey($question)])) {
                    $this->db->createCommand()->delete('acrl_questions', ['id' => $question['id']])->execute();
                }
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }

        return [
            'success' => true,
            'surveyYear' => $this->reviewService->yearResponse($this->reviewService->yearRow($year)),
            'review' => $this->getReview($year),
        ];
    }

    public function getReview(int $year): array
    {
        return $this->reviewService->getReview($year);
    }

    public function saveLinks(int $year, $links, string $userId): array
    {
        if (!is_array($links) || empty($links) || count($links) > 500) {
            throw new \InvalidArgumentException('Provide between 1 and 500 review decisions.');
        }
        $review = $this->reviewService->getReview($year);
        if ($review['previousYear'] === null) {
            throw new \DomainException('This year has no previous-year catalogue to review.');
        }
        if ($review['status'] === 'published') {
            throw new \DomainException('Published years are read-only. Choose replace draft before changing review decisions.');
        }
        $proposals = [];
        foreach ($review['items'] as $item) {
            if ($item['previous'] && $item['current']) {
                $proposals[$this->reviewService->pairKey($item['previous'], $item['current'])] = $item;
            }
        }

        $transaction = $this->db->beginTransaction();
        try {
            foreach ($links as $link) {
                if (!is_array($link) || !in_array($link['decision'] ?? null, ['approved', 'rejected'], true)) {
                    throw new \InvalidArgumentException('Every review decision must be approved or rejected.');
                }
                $sheet = $link['sheet'] ?? null;
                $previous = $this->reviewService->findQuestion($review['previousYear'], $sheet, $link['previous_question_id'] ?? null, $link['previous_part'] ?? '');
                $current = $this->reviewService->findQuestion($year, $sheet, $link['current_question_id'] ?? null, $link['current_part'] ?? '');
                if (!$previous || !$current || $previous['sheet'] !== $current['sheet']) {
                    throw new \InvalidArgumentException('Review links must connect question parts from adjacent years on the same sheet.');
                }
                $proposal = $proposals[$this->reviewService->pairKey($previous, $current)] ?? null;
                if (!$proposal && empty($link['manual'])) {
                    throw new \InvalidArgumentException('A new question link must be explicitly marked as a manual link.');
                }
                $kind = $proposal ? $proposal['matchKind'] : ($link['match_kind'] ?? null);
                if (!in_array($kind, ['same', 'reworded', 'renumbered', 'split', 'merged'], true)) {
                    throw new \InvalidArgumentException('Manual links need a supported match kind.');
                }
                $this->db->createCommand()->upsert('acrl_question_links', [
                    'previous_year' => $review['previousYear'],
                    'current_year' => $year,
                    'sheet' => $current['sheet'],
                    'previous_question_id' => $previous['question_id'],
                    'previous_part' => $previous['part'],
                    'current_question_id' => $current['question_id'],
                    'current_part' => $current['part'],
                    'match_kind' => $kind,
                    'score' => $proposal ? $proposal['score'] : 0,
                    'decision' => $link['decision'],
                    'decided_by' => $userId,
                    'decided_at' => date('Y-m-d H:i:s'),
                ], [
                    'match_kind' => $kind,
                    'score' => $proposal ? $proposal['score'] : 0,
                    'decision' => $link['decision'],
                    'decided_by' => $userId,
                    'decided_at' => date('Y-m-d H:i:s'),
                ])->execute();
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }
        return $this->reviewService->getReview($year);
    }

    public function publishYear(int $year, string $userId): array
    {
        return $this->reviewService->publishYear($year, $userId);
    }
}
