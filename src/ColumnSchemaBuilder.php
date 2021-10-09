<?php

namespace mhthnz\tarantool;

use Yii;
use yii\base\BaseObject;
use yii\db\Expression;
use yii\helpers\StringHelper;

class ColumnSchemaBuilder extends BaseObject
{

    const CATEGORY_PK = 'pk';
    const CATEGORY_STRING = 'string';
    const CATEGORY_NUMERIC = 'numeric';
    const CATEGORY_OTHER = 'other';

    /**
     * @var string the column type definition such as INTEGER, VARCHAR, etc.
     */
    protected $type;
    /**
     * @var int|string|array column size or precision definition. This is what goes into the parenthesis after
     * the column type. This can be either a string, an integer or an array. If it is an array, the array values will
     * be joined into a string separated by comma.
     */
    protected $length;
    /**
     * @var bool|null whether the column is or not nullable. If this is `true`, a `NOT NULL` constraint will be added.
     * If this is `false`, a `NULL` constraint will be added.
     */
    protected $isNotNull;
    /**
     * @var bool whether the column values should be unique. If this is `true`, a `UNIQUE` constraint will be added.
     */
    protected $isUnique = false;
    /**
     * @var string the `CHECK` constraint for the column.
     */
    protected $check;
    /**
     * @var mixed default value of the column.
     */
    protected $default;
    /**
     * @var mixed SQL string to be appended to column schema definition.
     * @since 2.0.9
     */
    protected $append;
    /**
     * @var bool whether the column values should be unsigned. If this is `true`, an `UNSIGNED` keyword will be added.
     * @since 2.0.7
     */
    protected $isUnsigned = false;
    /**
     * @var string the column after which this column will be added.
     * @since 2.0.8
     */
    protected $after;

    /**
     * String column collation.
     * @var string|null
     */
    protected $collation;

    /**
     * Ability to add primary key to current column.
     * @var bool
     */
    protected $addedPk = false;


    /**
     * @var array mapping of abstract column types (keys) to type categories (values).
     * @since 2.0.43
     */
    public static $typeCategoryMap = [
        Schema::TYPE_PK => self::CATEGORY_PK,
        Schema::TYPE_UPK => self::CATEGORY_PK,
        Schema::TYPE_CHAR => self::CATEGORY_STRING,
        Schema::TYPE_STRING => self::CATEGORY_STRING,
        Schema::TYPE_TEXT => self::CATEGORY_STRING,
        Schema::TYPE_TINYINT => self::CATEGORY_NUMERIC,
        Schema::TYPE_INTEGER => self::CATEGORY_NUMERIC,
        Schema::TYPE_FLOAT => self::CATEGORY_NUMERIC,
        Schema::TYPE_DOUBLE => self::CATEGORY_NUMERIC,
        Schema::TYPE_DECIMAL => self::CATEGORY_NUMERIC,
        Schema::TYPE_BINARY => self::CATEGORY_OTHER,
        Schema::TYPE_BOOLEAN => self::CATEGORY_NUMERIC,
    ];
    /**
     * @var \mhthnz\tarantool\Connection the current database connection. It is used mainly to escape strings
     * safely when building the final column schema string.
     * @since 2.0.8
     */
    public $db;


    /**
     * Create a column schema builder instance giving the type and value precision.
     *
     * @param string $type type of the column. See [[$type]].
     * @param int|string|array $length length or precision of the column. See [[$length]].
     * @param \mhthnz\tarantool\Connection $db the current database connection. See [[$db]].
     * @param array $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct($type, $length = null, $db = null, $config = [])
    {
        $this->type = $type;
        $this->length = $length;
        $this->db = $db;
        parent::__construct($config);
    }

    /**
     * Adds a `NOT NULL` constraint to the column.
     * @return $this
     */
    public function notNull()
    {
        $this->isNotNull = true;
        return $this;
    }

    /**
     * Adding primary key to column.
     * @return $this
     */
    public function addPrimaryKey()
    {
        if ($this->type === Schema::TYPE_PK || $this->type === Schema::TYPE_UPK) {
            return $this;
        }
        if (in_array($this->type, [Schema::TYPE_BOOLEAN, Schema::TYPE_STRING, Schema::TYPE_CHAR, Schema::TYPE_TEXT, Schema::TYPE_FLOAT, Schema::TYPE_DOUBLE, Schema::TYPE_BINARY, Schema::TYPE_INTEGER])) {
            $this->addedPk = true;
        }
        return $this;
    }

    /**
     * Set collation for string fields.
     * @param string $collation
     * @return $this
     */
    public function collation($collation)
    {
        if (in_array($this->type, [Schema::TYPE_STRING, Schema::TYPE_CHAR, Schema::TYPE_TEXT])) {
            $this->collation = $collation;
        }
        return $this;
    }

    /**
     * Adds a `NULL` constraint to the column.
     * @return $this
     * @since 2.0.9
     */
    public function null()
    {
        $this->isNotNull = false;
        return $this;
    }

    /**
     * Adds a `UNIQUE` constraint to the column.
     * @return $this
     */
    public function unique()
    {
        $this->isUnique = true;
        return $this;
    }

    /**
     * Sets a `CHECK` constraint for the column.
     * @param string $check the SQL of the `CHECK` constraint to be added.
     * @return $this
     */
    public function check($check)
    {
        $this->check = $check;
        return $this;
    }

    /**
     * Specify the default value for the column.
     * @param mixed $default the default value.
     * @return $this
     */
    public function defaultValue($default)
    {
        if ($default === null) {
            $this->null();
        }

        $this->default = $default;
        return $this;
    }


    /**
     * Marks column as unsigned.
     * @return $this
     * @since 2.0.7
     */
    public function unsigned()
    {
        switch ($this->type) {
            case Schema::TYPE_PK:
                $this->type = Schema::TYPE_UPK;
                break;
        }
        $this->isUnsigned = true;
        return $this;
    }

    /**
     * Adds an `AFTER` constraint to the column.
     * Note: MySQL, Oracle and Cubrid support only.
     * @param string $after the column after which $this column will be added.
     * @return $this
     * @since 2.0.8
     */
    public function after($after)
    {
        $this->after = $after;
        return $this;
    }

    /**
     * Specify the default SQL expression for the column.
     * @param string $default the default value expression.
     * @return $this
     * @since 2.0.7
     */
    public function defaultExpression($default)
    {
        $this->default = new Expression($default);
        return $this;
    }

    /**
     * Specify additional SQL to be appended to column definition.
     * Position modifiers will be appended after column definition in databases that support them.
     * @param string $sql the SQL string to be appended.
     * @return $this
     * @since 2.0.9
     */
    public function append($sql)
    {
        $this->append = $sql;
        return $this;
    }

    /**
     * Builds the full string for the column's schema.
     * @return string
     */
    public function __toString()
    {
        switch ($this->getTypeCategory()) {
            // Custom processing autoincrement types because of expression order:
            // integer check(cond) primary key autoincrement
            case self::CATEGORY_PK:
                $format = '{type}{check} PRIMARY KEY AUTOINCREMENT {append}';
                break;
            default:
                $index = $this->addedPk ? '{pk}': '{unique}';
                $format = '{type}{length}'.$index.'{notnull}{default}{collation}{check}{append}';
        }

        return $this->buildCompleteString($format);
    }

    /**
     * @return array mapping of abstract column types (keys) to type categories (values).
     * @since 2.0.43
     */
    public function getCategoryMap()
    {
        return static::$typeCategoryMap;
    }

    /**
     * @param array $categoryMap mapping of abstract column types (keys) to type categories (values).
     * @since 2.0.43
     */
    public function setCategoryMap($categoryMap)
    {
        static::$typeCategoryMap = $categoryMap;
    }

    /**
     * Builds the length/precision part of the column.
     * @return string
     */
    protected function buildLengthString()
    {
        if ($this->length === null || $this->length === []) {
            return '';
        }
        if (is_array($this->length)) {
            $this->length = implode(',', $this->length);
        }

        return "({$this->length})";
    }

    /**
     * Builds the not null constraint for the column.
     * @return string returns 'NOT NULL' if [[isNotNull]] is true,
     * 'NULL' if [[isNotNull]] is false or an empty string otherwise.
     */
    protected function buildNotNullString()
    {
        if ($this->isNotNull === true) {
            return ' NOT NULL';
        } elseif ($this->isNotNull === false) {
            return ' NULL';
        }

        return '';
    }

    /**
     * Builds the unique constraint for the column.
     * @return string returns string 'UNIQUE' if [[isUnique]] is true, otherwise it returns an empty string.
     */
    protected function buildUniqueString()
    {
        return $this->isUnique ? ' UNIQUE' : '';
    }

    /**
     * Return the default value for the column.
     * @return string|null string with default value of column.
     */
    protected function buildDefaultValue()
    {
        if ($this->default === null) {
            return $this->isNotNull === false ? 'NULL' : null;
        }

        switch (gettype($this->default)) {
            case 'double':
                // ensure type cast always has . as decimal separator in all locales
                $defaultValue = StringHelper::floatToString($this->default);
                break;
            case 'boolean':
                $defaultValue = $this->default ? 'true' : 'false';;
                break;
            case 'integer':
                $defaultValue = (int) $this->default;
                break;
            case 'object':
                $defaultValue = (string) $this->default;
                break;
            default:
                $defaultValue = "'{$this->default}'";
        }

        return $defaultValue;
    }

    /**
     * Builds the default value specification for the column.
     * @return string string with default value of column.
     */
    protected function buildDefaultString()
    {
        $defaultValue = $this->buildDefaultValue();
        if ($defaultValue === null) {
            return '';
        }

        return ' DEFAULT ' . $defaultValue;
    }

    /**
     * Builds the check constraint for the column.
     * @return string a string containing the CHECK constraint.
     */
    protected function buildCheckString()
    {
        return $this->check !== null ? " CHECK ({$this->check})" : '';
    }

    /**
     * Changing field type based on properties.
     * @return string
     */
    protected function buildType()
    {
        if ($this->type === Schema::TYPE_PK) {
            return 'integer';
        }
        if ($this->type === Schema::TYPE_UPK || ($this->type === Schema::TYPE_INTEGER && $this->isUnsigned)) {
            return 'unsigned';
        }
        return $this->type;
    }

    /**
     * @return string|null
     */
    protected function buildCollation()
    {
        return !empty($this->collation) ? ' COLLATE "' . $this->collation . '"' : null;
    }

    /**
     * Builds the custom string that's appended to column definition.
     * @return string custom string to append.
     * @since 2.0.9
     */
    protected function buildAppendString()
    {
        return $this->append !== null ? ' ' . $this->append : '';
    }

    /**
     * Returns the category of the column type.
     * @return string a string containing the column type category name.
     * @since 2.0.8
     */
    protected function getTypeCategory()
    {
        return self::$typeCategoryMap[$this->type] ?? null;
    }


    /**
     * Returns the complete column definition from input format.
     * @param string $format the format of the definition.
     * @return string a string containing the complete column definition.
     * @since 2.0.8
     */
    protected function buildCompleteString($format)
    {
        $placeholderValues = [
            '{type}' => $this->buildType(),
            '{length}' => $this->buildLengthString(),
            '{notnull}' => $this->buildNotNullString(),
            '{unique}' => $this->buildUniqueString(),
            '{default}' => $this->buildDefaultString(),
            '{check}' => $this->buildCheckString(),
            '{append}' => $this->buildAppendString(),
            '{pk}' => $this->addedPk ? ' PRIMARY KEY' : null,
            '{collation}' => $this->buildCollation(),
        ];
        return strtr($format, $placeholderValues);
    }
}
