<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * ReportTemplate model — a parameterized SQL report stored in MySQL.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $category
 * @property string $sql_template
 * @property string $parameters   JSON array of parameter definitions
 * @property string $data_source  folio|local|composite
 * @property string $composite_config JSON config for cross-DB reports
 * @property int $default_limit
 * @property int $is_active
 * @property string $created_by   'manual' or 'ai'
 * @property string $created_at
 * @property string $updated_at
 */
class ReportTemplate extends ActiveRecord
{
    // Category constants
    const CAT_ACQUISITIONS = 'acquisitions';
    const CAT_CIRCULATION  = 'circulation';
    const CAT_INVENTORY    = 'inventory';
    const CAT_FINANCE      = 'finance';
    const CAT_USERS        = 'users';
    const CAT_OTHER        = 'other';

    public static function tableName()
    {
        return 'report_templates';
    }

    public function rules()
    {
        return [
            [['slug', 'name', 'sql_template', 'parameters'], 'required'],
            [['slug'], 'string', 'max' => 100],
            [['slug'], 'unique', 'filter' => ['is_active' => 1]],
            [['name'], 'string', 'max' => 255],
            [['description', 'sql_template', 'composite_config'], 'string'],
            [['category'], 'in', 'range' => [
                self::CAT_ACQUISITIONS, self::CAT_CIRCULATION,
                self::CAT_INVENTORY, self::CAT_FINANCE,
                self::CAT_USERS, self::CAT_OTHER,
            ]],
            [['data_source'], 'in', 'range' => ['folio', 'local', 'composite']],
            [['default_limit'], 'integer', 'min' => 1, 'max' => 100000],
            [['is_active'], 'boolean'],
            [['created_by'], 'in', 'range' => ['manual', 'ai']],
        ];
    }

    /**
     * Decode the parameters JSON into an array of parameter definitions.
     * Each element: {name, type, label, required, default, placeholder, description, wrap?, options_sql?}
     */
    public function getDecodedParameters()
    {
        $raw = $this->parameters;
        if (is_array($raw)) {
            return $raw;
        }
        $params = json_decode($raw, true);
        return is_array($params) ? $params : [];
    }

    /**
     * Resolve default value macros like $fiscal_year_start, $today, etc.
     * @param string $defaultVal The raw default value string
     * @return string The resolved value
     */
    public static function resolveDefaultMacro($defaultVal)
    {
        if (empty($defaultVal) || $defaultVal[0] !== '$') {
            return $defaultVal;
        }

        $today = new \DateTime();
        $currentYear = (int) $today->format('Y');

        switch ($defaultVal) {
            case '$today':
                return $today->format('Y-m-d');

            case '$yesterday':
                return (clone $today)->modify('-1 day')->format('Y-m-d');

            case '$30_days_ago':
                return (clone $today)->modify('-30 days')->format('Y-m-d');

            case '$90_days_ago':
                return (clone $today)->modify('-90 days')->format('Y-m-d');

            case '$current_year':
                return (string) $currentYear;

            case '$fiscal_year_start':
                // Fiscal year starts July 1
                $fyStart = new \DateTime("{$currentYear}-07-01");
                if ($today < $fyStart) {
                    $fyStart->modify('-1 year');
                }
                return $fyStart->format('Y-m-d');

            case '$fiscal_year_end':
                // Fiscal year ends June 30
                $fyEnd = new \DateTime("{$currentYear}-06-30");
                if ($today > $fyEnd) {
                    $fyEnd->modify('+1 year');
                }
                return $fyEnd->format('Y-m-d');

            default:
                return $defaultVal;
        }
    }

    /**
     * Resolve all parameter defaults and return the full parameter definitions
     * with resolved default values.
     */
    public function getResolvedParameters()
    {
        $params = $this->getDecodedParameters();
        foreach ($params as &$param) {
            if (isset($param['default'])) {
                $param['resolvedDefault'] = self::resolveDefaultMacro($param['default']);
            } else {
                $param['resolvedDefault'] = '';
            }
        }
        return $params;
    }

    /**
     * Bind user-provided parameter values to the SQL template.
     * Handles LIKE wrapping, type casting, and returns an array of PDO-bindable params.
     *
     * @param array $userInputs Key-value map of user inputs
     * @return array ['sql' => string, 'params' => array] Ready for PDO execution
     */
    public function bindParams($userInputs)
    {
        $paramDefs = $this->getDecodedParameters();
        $boundParams = [];
        $sql = $this->sql_template;

        foreach ($paramDefs as $def) {
            $name = $def['name'];
            $value = isset($userInputs[$name]) ? $userInputs[$name] : null;

            // Use resolved default if no value provided
            if ($value === null || $value === '') {
                $value = self::resolveDefaultMacro($def['default'] ?? '');
            }

            // Type-specific handling
            switch ($def['type'] ?? 'text') {
                case 'number':
                    $value = is_numeric($value) ? $value + 0 : 0;
                    break;
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                    break;
            }

            // Handle 'list' type — expand comma/newline-separated values into IN clause
            if (($def['type'] ?? 'text') === 'list' && !empty($value)) {
                $items = array_filter(array_map('trim', preg_split('/[\n,]+/', $value)));
                if (!empty($items)) {
                    $placeholders = [];
                    foreach (array_values($items) as $i => $item) {
                        $key = ':' . $name . '_' . $i;
                        $placeholders[] = $key;
                        $boundParams[$key] = $item;
                    }
                    // Replace :paramName with the expanded IN list
                    $inList = implode(', ', $placeholders);
                    $sql = str_replace(':' . $name, $inList, $sql);
                }
                continue;
            }

            // Wrap LIKE values
            if (isset($def['wrap']) && $def['wrap'] === 'like') {
                $value = '%' . $value . '%';
            }

            $boundParams[':' . $name] = $value;
        }

        // Append LIMIT if not already present
        $limit = $this->default_limit ?: 100;
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql = rtrim($sql, "; \n") . "\nLIMIT {$limit}";
        }

        return [
            'sql' => $sql,
            'params' => $boundParams,
        ];
    }

    /**
     * Fetch dropdown options for 'select' type parameters by running their options_sql.
     * For local/composite reports, select options run against the local MySQL db.
     * @return array Parameter name => [{value, label}, ...]
     */
    public function fetchSelectOptions()
    {
        $params = $this->getDecodedParameters();
        $options = [];
        $useLocalDb = $this->hasAttribute('data_source') && in_array($this->data_source, ['local', 'composite']);

        foreach ($params as $def) {
            if (($def['type'] ?? '') === 'select' && !empty($def['options_sql'])) {
                try {
                    // For local/composite templates, options that reference local tables come from MySQL.
                    // Options that explicitly target folio can still use folioDb.
                    $db = (!empty($def['options_db']) && $def['options_db'] === 'local')
                        ? Yii::$app->db
                        : ($useLocalDb ? Yii::$app->db : Yii::$app->folioDb);
                    $rows = $db->createCommand($def['options_sql'])->queryAll();
                    $opts = [];
                    foreach ($rows as $row) {
                        $vals = array_values($row);
                        $opts[] = [
                            'value' => $vals[0] ?? '',
                            'label' => $vals[1] ?? $vals[0] ?? '',
                        ];
                    }
                    $options[$def['name']] = $opts;
                } catch (\Exception $e) {
                    $options[$def['name']] = [];
                    Yii::warning("Failed to load options for param '{$def['name']}': " . $e->getMessage());
                }
            }
        }

        return $options;
    }

    /**
     * Decode the composite_config JSON.
     * @return array|null
     */
    public function getCompositeConfig()
    {
        if (!$this->hasAttribute('composite_config') || empty($this->composite_config)) {
            return null;
        }
        $raw = $this->composite_config;
        $config = is_array($raw) ? $raw : json_decode($raw, true);
        return is_array($config) ? $config : null;
    }

    /**
     * Serialize for API response.
     */
    public function toDetailArray()
    {
        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'dataSource' => $this->hasAttribute('data_source') ? ($this->data_source ?: 'folio') : 'folio',
            'sqlTemplate' => $this->sql_template,
            'parameters' => $this->getResolvedParameters(),
            'defaultLimit' => (int) $this->default_limit,
            'isActive' => (bool) $this->is_active,
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }

    /**
     * Summary for list view (no SQL template body).
     */
    public function toSummary()
    {
        $params = $this->getDecodedParameters();
        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'dataSource' => $this->hasAttribute('data_source') ? ($this->data_source ?: 'folio') : 'folio',
            'parameterCount' => count($params),
            'defaultLimit' => (int) $this->default_limit,
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at,
        ];
    }
}
