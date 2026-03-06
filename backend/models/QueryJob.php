<?php

namespace app\models;

use yii\db\ActiveRecord;

/**
 * QueryJob — represents an asynchronous query execution job.
 *
 * @property string $id UUID
 * @property string $sql_text
 * @property string $sql_hash SHA-256 hash for dedup
 * @property string $params JSON
 * @property string $source builder|nl|manual
 * @property string $data_source folio|local|composite
 * @property string $name  human-readable label
 * @property int    $user_id
 * @property string $status pending|running|completed|failed|cancelled|pending_export
 * @property string $result_columns JSON column names
 * @property string $result_rows JSON row data
 * @property int $row_count
 * @property int $execution_time_ms
 * @property string $error_message
 * @property string $progress_message
 * @property string $output_mode table|file
 * @property string|null $export_file_path
 * @property int|null $estimated_rows
 * @property float|null $estimated_cost
 * @property string $created_at
 * @property string $started_at
 * @property string $completed_at
 */
class QueryJob extends ActiveRecord
{
    public static function tableName()
    {
        return 'query_jobs';
    }

    public function rules()
    {
        $rules = [
            [['id', 'sql_text'], 'required'],
            [['sql_text', 'result_rows', 'error_message'], 'string'],
            [['params', 'result_columns'], 'string'],
            [['sql_hash'], 'string', 'max' => 64],
            [['source'], 'in', 'range' => ['builder', 'nl', 'manual', 'report']],
            [['status'], 'in', 'range' => ['pending', 'running', 'completed', 'failed', 'cancelled', 'pending_export']],
            [['row_count', 'execution_time_ms', 'user_id'], 'integer'],
            [['progress_message'], 'string', 'max' => 255],
            [['name'], 'string', 'max' => 255],
            [['id'], 'string', 'max' => 36],
            [['output_mode'], 'in', 'range' => ['table', 'file']],
            [['export_file_path'], 'string', 'max' => 500],
            [['pg_backend_pid'], 'integer'],
            [['pg_backend_pid'], 'default', 'value' => null],
        ];

        if ($this->hasAttribute('estimated_rows')) {
            $rules[] = [['estimated_rows'], 'integer'];
        }
        if ($this->hasAttribute('estimated_cost')) {
            $rules[] = [['estimated_cost'], 'number'];
        }

        return $rules;
    }

    /**
     * Create a new pending job.
     *
     * @param string $sql
     * @param array $params
     * @param string $source
     * @return static
     */
    public static function createJob($sql, $params = [], $source = 'builder', $dataSource = 'folio', $metadata = null)
    {
        $job = new static();
        $job->id = self::generateUuid();
        $job->sql_text = $sql;
        $job->params = json_encode($params);
        $job->source = $source;
        if ($job->hasAttribute('data_source')) {
            $job->data_source = in_array($dataSource, ['folio', 'local', 'composite']) ? $dataSource : 'folio';
        }
        if ($metadata !== null && $job->hasAttribute('metadata')) {
            $job->metadata = is_array($metadata) ? json_encode($metadata) : $metadata;
        }
        $job->status = 'pending';
        $job->progress_message = 'Queued';
        if ($job->hasAttribute('output_mode')) {
            $job->output_mode = 'table';
        }
        return $job;
    }

    /**
     * Generate a UUID v4.
     * @return string
     */
    private static function generateUuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Get the decoded params.
     * @return array
     */
    public function getDecodedParams()
    {
        return json_decode($this->params ?: '{}', true) ?: [];
    }

    /**
     * Get the decoded result columns.
     * @return array
     */
    public function getDecodedColumns()
    {
        return json_decode($this->result_columns ?: '[]', true) ?: [];
    }

    /**
     * Get the decoded result rows.
     * @return array
     */
    public function getDecodedRows()
    {
        return json_decode($this->result_rows ?: '[]', true) ?: [];
    }

    /**
     * Transition to running state.
     */
    public function markRunning()
    {
        $this->status = 'running';
        $this->started_at = date('Y-m-d H:i:s');
        $this->progress_message = 'Executing query…';
        $this->save(false);
    }

    /**
     * Transition to completed state.
     *
     * @param array $columns
     * @param array $rows
     * @param int $executionTimeMs
     */
    public function markCompleted($columns, $rows, $executionTimeMs)
    {
        $this->status = 'completed';
        $this->result_columns = json_encode($columns);
        $this->result_rows = json_encode($rows);
        $this->row_count = count($rows);
        $this->execution_time_ms = $executionTimeMs;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->progress_message = 'Completed';
        if ($this->hasAttribute('pg_backend_pid')) {
            $this->pg_backend_pid = null;
        }
        $this->save(false);
    }

    /**
     * Transition to completed state for file exports.
     *
     * @param string $filePath
     * @param int $rowCount
     * @param int $executionTimeMs
     */
    public function markExportCompleted($filePath, $rowCount, $executionTimeMs)
    {
        $this->status = 'completed';
        if ($this->hasAttribute('output_mode')) {
            $this->output_mode = 'file';
        }
        if ($this->hasAttribute('export_file_path')) {
            $this->export_file_path = $filePath;
        }
        $this->result_columns = null;
        $this->result_rows = null;
        $this->row_count = (int) $rowCount;
        $this->execution_time_ms = $executionTimeMs;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->progress_message = 'Completed';
        if ($this->hasAttribute('pg_backend_pid')) {
            $this->pg_backend_pid = null;
        }
        $this->save(false);
    }

    /**
     * Transition to failed state.
     *
     * @param string $error
     * @param int $executionTimeMs
     */
    public function markFailed($error, $executionTimeMs = 0)
    {
        $this->status = 'failed';
        $this->error_message = $error;
        $this->execution_time_ms = $executionTimeMs;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->progress_message = 'Failed';
        if ($this->hasAttribute('pg_backend_pid')) {
            $this->pg_backend_pid = null;
        }
        $this->save(false);
    }

    /**
     * Return the public-facing status object.
     * @param bool $includeResults Whether to include row data
     * @return array
     */
    public function toStatusArray($includeResults = false)
    {
        $outputMode = $this->hasAttribute('output_mode') ? ($this->output_mode ?: 'table') : 'table';
        $data = [
            'jobId'           => $this->id,
            'name'            => $this->name ?? null,
            'status'          => $this->status,
            'source'          => $this->source,
            'sql'             => $this->sql_text,
            'dataSource'      => $this->hasAttribute('data_source') ? ($this->data_source ?: 'folio') : 'folio',
            'outputMode'      => $outputMode,
            'progressMessage' => $this->progress_message,
            'createdAt'       => $this->created_at,
            'startedAt'       => $this->started_at,
            'completedAt'     => $this->completed_at,
        ];

        if ($this->hasAttribute('estimated_rows') && $this->estimated_rows !== null) {
            $data['estimatedRows'] = (int) $this->estimated_rows;
        }
        if ($this->hasAttribute('estimated_cost') && $this->estimated_cost !== null) {
            $data['estimatedCost'] = (float) $this->estimated_cost;
        }

        if ($this->status === 'completed' && $includeResults && $outputMode !== 'file') {
            $data['columns'] = $this->getDecodedColumns();
            $data['rows'] = $this->getDecodedRows();
            $data['rowCount'] = (int)$this->row_count;
            $data['executionTimeMs'] = (int)$this->execution_time_ms;
        }

        if ($this->status === 'completed' && $outputMode === 'file' && $this->hasAttribute('export_file_path')) {
            $data['downloadUrl'] = '/api/query/export/' . $this->id;
        }

        if ($this->status === 'failed') {
            $data['error'] = $this->error_message;
        }

        if ($this->status === 'completed') {
            $data['rowCount'] = (int)$this->row_count;
            $data['executionTimeMs'] = (int)$this->execution_time_ms;
        }

        return $data;
    }
}
