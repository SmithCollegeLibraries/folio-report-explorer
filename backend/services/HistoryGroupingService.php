<?php

namespace app\services;

final class HistoryGroupingService
{
    private const ACTIVE_STATUSES = ['pending', 'pending_export', 'running', 'cancelling'];

    private $db;
    private $historyGroupKeyColumnResolved = false;
    private $historyGroupKeyColumn = 'NULL';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function list(array $options): array
    {
        $historyGroupKey = $this->historyGroupKeyColumn();
        list($singleWhere, $singleParams) = $this->filteredJobsWhere($options, 'qj', ':singletonUserId');
        $params = $singleParams;
        if ($historyGroupKey !== 'NULL') {
            list($keyedWhere, $keyedParams) = $this->filteredJobsWhere($options, 'qj', ':keyedUserId');
            list($candidateWhere, $candidateParams) = $this->filteredJobsWhere($options, 'candidate', ':candidateUserId');
            $params = array_merge($keyedParams, $candidateParams, $singleParams);
            $cte = $this->groupedListCte($keyedWhere, $candidateWhere, $singleWhere);
        } else {
            $cte = $this->singletonListCte($singleWhere);
        }
        $limit = max(1, min(100, (int) ($options['limit'] ?? 50)));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $order = $historyGroupKey === 'NULL'
            ? 'COALESCE(completed_at, created_at) DESC, id DESC'
            : 'CASE WHEN active_run_count > 0 THEN 0 ELSE 1 END ASC, COALESCE(completed_at, created_at) DESC, id DESC';
        $command = $this->db->createCommand(
            $cte . ' SELECT id, user_id, history_group_key, run_count, active_run_count, COUNT(*) OVER() AS total '
            . 'FROM visible_jobs '
            . 'ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset',
            $params
        );
        $command->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $command->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $representatives = $command->queryAll();
        $total = empty($representatives)
            ? (int) $this->db->createCommand($cte . ' SELECT COUNT(*) FROM visible_jobs', $params)->queryScalar()
            : (int) $representatives[0]['total'];
        $jobs = $this->loadItemsByIds(array_column($representatives, 'id'), $options);
        $byId = [];
        foreach ($jobs as $job) {
            $byId[(string) $job['jobId']] = $job;
        }

        $items = [];
        foreach ($representatives as $representative) {
            $jobId = (string) $representative['id'];
            if (!isset($byId[$jobId])) {
                continue;
            }
            $item = $byId[$jobId];
            $item['groupId'] = HistoryGroupKeyService::groupId(
                $representative['user_id'],
                $representative['history_group_key']
            );
            $item['runCount'] = (int) $representative['run_count'];
            $item['activeRunCount'] = (int) $representative['active_run_count'];
            $items[] = $item;
        }

        return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => $items];
    }

    public function expand($jobId, array $options): array
    {
        $context = $this->db->createCommand(
            'SELECT qj.user_id, ' . $this->historyGroupKeyColumn() . ' AS history_group_key FROM query_jobs qj WHERE qj.id = :id',
            [':id' => $jobId]
        )->queryOne();
        $limit = max(1, min(100, (int) ($options['limit'] ?? 20)));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        if (!$context) {
            return ['found' => false, 'total' => 0, 'offset' => $offset, 'limit' => $limit, 'items' => []];
        }

        $where = [];
        $params = [];
        if ($context['user_id'] === null || $context['history_group_key'] === null) {
            $where[] = 'id = :jobId';
            $params[':jobId'] = $jobId;
        } else {
            $where[] = 'user_id = :ownerId';
            $where[] = 'history_group_key = :groupKey';
            $params[':ownerId'] = $context['user_id'];
            $params[':groupKey'] = $context['history_group_key'];
        }
        $this->appendStatusFilter($where, $params, $options['status'] ?? 'all');
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM query_jobs WHERE ' . $whereSql,
            $params
        )->queryScalar();
        $command = $this->db->createCommand(
            'SELECT id FROM query_jobs WHERE ' . $whereSql
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $params
        );
        $command->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $command->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $ids = array_column($command->queryAll(), 'id');
        $loaded = $this->loadItemsByIds($ids, $options);
        $byId = [];
        foreach ($loaded as $item) {
            $byId[(string) $item['jobId']] = $item;
        }
        $items = [];
        foreach ($ids as $id) {
            if (isset($byId[(string) $id])) {
                $items[] = $byId[(string) $id];
            }
        }
        return [
            'found' => true,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'items' => $items,
        ];
    }

    public function item($jobId, array $options = [])
    {
        $items = $this->loadItemsByIds([$jobId], $options);
        return $items[0] ?? null;
    }

    private function filteredJobsWhere(array $options, string $alias = 'qj', string $userIdParameter = ':userId'): array
    {
        $where = ['1 = 1'];
        $params = [];
        $this->appendStatusFilter($where, $params, $options['status'] ?? 'all', $alias);
        if (!empty($options['mineOnly'])) {
            if (($options['userId'] ?? null) === null) {
                $where[] = '1 = 0';
            } else {
                $where[] = $alias . '.user_id = ' . $userIdParameter;
                $params[$userIdParameter] = $options['userId'];
            }
        }
        return [implode(' AND ', $where), $params];
    }

    private function appendStatusFilter(array &$where, array &$params, $status, string $alias = ''): void
    {
        $statusColumn = $alias === '' ? 'status' : $alias . '.status';
        if ($status === 'active') {
            $where[] = $statusColumn . " IN ('pending', 'pending_export', 'running', 'cancelling')";
        } elseif ($status === 'completed') {
            $where[] = $statusColumn . " = 'completed'";
        } elseif ($status === 'failed') {
            $where[] = $statusColumn . " = 'failed'";
        }
    }

    private function groupedListCte(string $keyedWhere, string $candidateWhere, string $singleWhere): string
    {
        return 'WITH keyed_groups AS ('
            . ' SELECT qj.user_id, qj.history_group_key, COUNT(*) AS run_count,'
            . " SUM(CASE WHEN qj.status IN ('pending', 'pending_export', 'running', 'cancelling') THEN 1 ELSE 0 END) AS active_run_count"
            . ' FROM query_jobs qj WHERE ' . $keyedWhere
            . ' AND qj.user_id IS NOT NULL AND qj.history_group_key IS NOT NULL'
            . ' GROUP BY qj.user_id, qj.history_group_key'
            . '), keyed_representatives AS ('
            . ' SELECT qj.id, qj.user_id, qj.history_group_key, group_counts.run_count, group_counts.active_run_count, qj.completed_at, qj.created_at'
            . ' FROM keyed_groups group_counts JOIN query_jobs qj ON qj.id = ('
            . ' SELECT candidate.id FROM query_jobs candidate'
            . ' WHERE candidate.user_id = group_counts.user_id AND candidate.history_group_key = group_counts.history_group_key'
            . ' AND ' . $candidateWhere
            . ' ORDER BY candidate.created_at DESC, candidate.id DESC LIMIT 1)'
            . '), singleton_jobs AS (' . $this->singletonJobsSelect($singleWhere, true) . '), visible_jobs AS ('
            . ' SELECT * FROM keyed_representatives UNION ALL SELECT * FROM singleton_jobs)';
    }

    private function singletonListCte(string $where): string
    {
        return 'WITH visible_jobs AS (' . $this->singletonJobsSelect($where, false) . ')';
    }

    private function singletonJobsSelect(string $where, bool $onlyUngrouped): string
    {
        $historyGroupKey = $this->historyGroupKeyColumn();
        $ungroupedFilter = $onlyUngrouped
            ? ' AND (qj.user_id IS NULL OR qj.history_group_key IS NULL)'
            : '';
        return 'SELECT qj.id, qj.user_id, ' . $historyGroupKey . ' AS history_group_key, 1 AS run_count,'
            . " CASE WHEN qj.status IN ('pending', 'pending_export', 'running', 'cancelling') THEN 1 ELSE 0 END AS active_run_count,"
            . ' qj.completed_at, qj.created_at FROM query_jobs qj WHERE ' . $where . $ungroupedFilter;
    }

    private function historyGroupKeyColumn(): string
    {
        if (!$this->historyGroupKeyColumnResolved) {
            $table = $this->db->schema->getTableSchema('query_jobs');
            $this->historyGroupKeyColumn = $table && $table->getColumn('history_group_key') !== null
                ? 'qj.history_group_key'
                : 'NULL';
            $this->historyGroupKeyColumnResolved = true;
        }
        return $this->historyGroupKeyColumn;
    }

    private function loadItemsByIds(array $ids, array $options): array
    {
        if (empty($ids)) {
            return [];
        }
        $params = [];
        $placeholders = [];
        foreach (array_values($ids) as $index => $id) {
            $placeholder = ':job' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }

        $hasUsers = $this->db->schema->getTableSchema('users', true) !== null;
        $hasGenerations = $this->db->schema->getTableSchema('ai_report_generations', true) !== null;
        $hasReviews = $this->db->schema->getTableSchema('ai_report_reviews', true) !== null;
        $select = 'qj.*';
        $joins = '';
        if ($hasUsers) {
            $select .= ', u.email AS runBy';
            $joins .= ' LEFT JOIN users u ON u.id = qj.user_id';
        } else {
            $select .= ', NULL AS runBy';
        }
        if ($hasGenerations && $hasReviews) {
            $advisoryReviewSubquery = '(SELECT r2.id FROM ai_report_generations linked_generation '
                . 'INNER JOIN ai_report_reviews r2 ON r2.generation_id = linked_generation.id '
                . 'OR r2.generation_id = linked_generation.parent_generation_id '
                . 'WHERE linked_generation.query_job_id = qj.id '
                . "AND r2.advisory_state IN ('cautioned', 'superseded') "
                . 'ORDER BY r2.updated_at DESC, r2.id DESC LIMIT 1)';
            $select .= ', r.advisory_state AS reviewAdvisoryState'
                . ', r.superseded_by_job_id AS reviewSupersededByJobId';
            $joins .= ' LEFT JOIN ai_report_reviews r ON r.id = ' . $advisoryReviewSubquery;
        } else {
            $select .= ', NULL AS reviewAdvisoryState, NULL AS reviewSupersededByJobId';
        }

        $rows = $this->db->createCommand(
            'SELECT ' . $select . ' FROM query_jobs qj' . $joins
            . ' WHERE qj.id IN (' . implode(', ', $placeholders) . ')',
            $params
        )->queryAll();
        $userId = $options['userId'] ?? null;
        $isAdmin = !empty($options['isAdmin']);
        $items = [];
        foreach ($rows as $row) {
            $items[] = HistoryItemSerializer::serialize($row, $userId, $isAdmin);
        }
        return $items;
    }
}
