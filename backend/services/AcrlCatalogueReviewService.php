<?php

namespace app\services;

use Yii;

/** Reads catalogue years and builds the review response for one annual transition. */
class AcrlCatalogueReviewService
{
    private $db;
    private $matcher;
    private $publicationPolicy;

    public function __construct($db = null, $matcher = null, $publicationPolicy = null)
    {
        $this->db = $db ?: Yii::$app->db;
        $this->matcher = $matcher ?: new AcrlQuestionMatcherService();
        $this->publicationPolicy = $publicationPolicy ?: new AcrlPublicationPolicyService();
    }

    public function listYears(): array
    {
        $rows = $this->db->createCommand(
            'SELECT survey_year, status, source_file_name, survey_prompt_count, trends_prompt_count,'
                . ' survey_item_count, trends_item_count, published_at FROM acrl_survey_years ORDER BY survey_year DESC'
        )->queryAll();
        return array_map(function ($row) {
            return $this->yearResponse($row);
        }, $rows);
    }

    public function yearRow(int $year): ?array
    {
        $row = $this->db->createCommand('SELECT * FROM acrl_survey_years WHERE survey_year = :year', [':year' => $year])->queryOne();
        return $row ?: null;
    }

    public function questionRows(int $year): array
    {
        $rows = $this->db->createCommand(
            'SELECT id, survey_year, sheet, question_id, part, section, prompt, answer_type,'
                . ' required_status, calculated_from, survey_order FROM acrl_questions'
                . ' WHERE survey_year = :year ORDER BY sheet, survey_order, question_id, part',
            [':year' => $year]
        )->queryAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['survey_year'] = (int)$row['survey_year'];
            $row['survey_order'] = (int)$row['survey_order'];
            $row['required'] = $row['required_status'];
            unset($row['required_status']);
        }
        unset($row);
        return $rows;
    }

    public function getReview(int $year): array
    {
        $currentYear = $this->yearRow($year);
        if (!$currentYear) throw new \DomainException('Survey year was not found.');
        $previousYear = $this->yearRow($year - 1);
        $current = $this->questionRows($year);
        $previous = $previousYear ? $this->questionRows($year - 1) : [];
        $items = $previousYear ? $this->matcher->compare($previous, $current) : array_map(function ($question) {
            return [
                'status' => 'new', 'match_kind' => null, 'score' => 0.0,
                'previous' => null, 'current' => $question, 'topical' => $question['sheet'] === 'trends',
            ];
        }, $current);
        $links = $previousYear ? $this->linkRows($year - 1, $year) : [];
        $items = $this->overlayStoredLinks($items, $links, $previous, $current);
        $policy = $this->publicationPolicy->evaluate($items);

        return [
            'surveyYear' => $year,
            'previousYear' => $previousYear ? $year - 1 : null,
            'status' => $currentYear['status'],
            'items' => array_map(function ($item) {
                return $this->reviewItemResponse($item);
            }, $items),
            'previousQuestions' => array_map(function ($question) {
                return $this->questionResponse($question);
            }, $previous),
            'unresolvedCount' => $policy['unresolved_count'],
            'canPublish' => $policy['can_publish'],
        ];
    }

    public function findQuestion(int $year, $sheet, $questionId, $part): ?array
    {
        if (!in_array($sheet, ['survey', 'trends'], true) || !is_string($questionId) || !is_string($part)) return null;
        $row = $this->db->createCommand(
            'SELECT id, survey_year, sheet, question_id, part, section, prompt, answer_type,'
                . ' required_status, calculated_from, survey_order FROM acrl_questions'
                . ' WHERE survey_year = :year AND sheet = :sheet AND question_id = :question_id AND part = :part',
            [':year' => $year, ':sheet' => $sheet, ':question_id' => $questionId, ':part' => $part]
        )->queryOne();
        if (!$row) return null;
        $row['id'] = (int)$row['id'];
        $row['survey_year'] = (int)$row['survey_year'];
        $row['survey_order'] = (int)$row['survey_order'];
        $row['required'] = $row['required_status'];
        unset($row['required_status']);
        return $row;
    }

    public function publishYear(int $year, string $userId): array
    {
        $current = $this->yearRow($year);
        if (!$current) throw new \DomainException('Survey year was not found.');
        if ($current['status'] === 'published') {
            throw new \DomainException('Published years are read-only. Choose replace draft to make changes.');
        }
        $review = $this->getReview($year);
        if (!$review['canPublish']) {
            throw new \DomainException('Every matched question part needs an approved or rejected decision before publishing.');
        }
        $this->db->createCommand()->update('acrl_survey_years', [
            'status' => 'published',
            'published_by' => $userId,
            'published_at' => date('Y-m-d H:i:s'),
        ], ['survey_year' => $year])->execute();
        return $this->getReview($year);
    }

    public function questionKey(array $question): string
    {
        return $question['sheet'] . '|' . $question['question_id'] . '|' . $question['part'];
    }

    public function pairKey(array $previous, array $current): string
    {
        return $this->questionKey($previous) . '>' . $this->questionKey($current);
    }

    public function yearResponse(array $row): array
    {
        $surveyItems = (int)$row['survey_item_count'];
        $trendsItems = (int)$row['trends_item_count'];
        return [
            'surveyYear' => (int)$row['survey_year'], 'status' => $row['status'],
            'sourceFileName' => $row['source_file_name'],
            'surveyPromptCount' => (int)$row['survey_prompt_count'],
            'trendsPromptCount' => (int)$row['trends_prompt_count'],
            'surveyItemCount' => $surveyItems, 'trendsItemCount' => $trendsItems,
            'totalItemCount' => $surveyItems + $trendsItems, 'publishedAt' => $row['published_at'],
        ];
    }

    private function linkRows(int $previousYear, int $currentYear): array
    {
        return $this->db->createCommand(
            'SELECT * FROM acrl_question_links WHERE previous_year = :previous AND current_year = :current',
            [':previous' => $previousYear, ':current' => $currentYear]
        )->queryAll();
    }

    private function overlayStoredLinks(array $items, array $links, array $previousQuestions, array $currentQuestions): array
    {
        foreach ($links as $link) {
            $pairKey = $link['sheet'] . '|' . $link['previous_question_id'] . '|' . $link['previous_part']
                . '>' . $link['sheet'] . '|' . $link['current_question_id'] . '|' . $link['current_part'];
            $found = false;
            foreach ($items as &$item) {
                if ($item['previous'] && $item['current'] && $this->pairKey($item['previous'], $item['current']) === $pairKey) {
                    $item['decision'] = $link['decision'];
                    $item['link_id'] = (int)$link['id'];
                    $found = true;
                    break;
                }
            }
            unset($item);
            if ($found) continue;

            $previous = $this->findByKey($previousQuestions, $link['sheet'], $link['previous_question_id'], $link['previous_part']);
            $current = $this->findByKey($currentQuestions, $link['sheet'], $link['current_question_id'], $link['current_part']);
            if (!$previous || !$current) continue;
            foreach ($items as $index => $item) {
                if (($item['status'] === 'removed' && $item['previous']
                        && $this->questionKey($item['previous']) === $this->questionKey($previous))
                    || ($item['status'] === 'new' && $item['current']
                        && $this->questionKey($item['current']) === $this->questionKey($current))) {
                    unset($items[$index]);
                }
            }
            $items[] = [
                'status' => $link['match_kind'] === 'same' ? 'unchanged' : ($link['match_kind'] === 'renumbered' ? 'renumbered' : 'reworded'),
                'match_kind' => $link['match_kind'], 'score' => (float)$link['score'],
                'previous' => $previous, 'current' => $current, 'topical' => $current['sheet'] === 'trends',
                'decision' => $link['decision'], 'link_id' => (int)$link['id'],
            ];
        }
        return array_values($items);
    }

    private function findByKey(array $questions, string $sheet, string $questionId, string $part): ?array
    {
        foreach ($questions as $question) {
            if ($question['sheet'] === $sheet && $question['question_id'] === $questionId && $question['part'] === $part) {
                return $question;
            }
        }
        return null;
    }

    private function reviewItemResponse(array $item): array
    {
        return [
            'status' => $item['status'], 'matchKind' => $item['match_kind'] ?? null,
            'score' => (float)($item['score'] ?? 0),
            'previous' => $item['previous'] ? $this->questionResponse($item['previous']) : null,
            'current' => $item['current'] ? $this->questionResponse($item['current']) : null,
            'topical' => !empty($item['topical']), 'decision' => $item['decision'] ?? null,
            'linkId' => isset($item['link_id']) ? (int)$item['link_id'] : null,
        ];
    }

    private function questionResponse(array $question): array
    {
        return [
            'id' => (int)$question['id'], 'sheet' => $question['sheet'],
            'question_id' => $question['question_id'], 'part' => $question['part'],
            'section' => $question['section'], 'prompt' => $question['prompt'],
            'answer_type' => $question['answer_type'], 'required' => $question['required'],
            'calculated_from' => $question['calculated_from'], 'survey_order' => (int)$question['survey_order'],
        ];
    }
}
