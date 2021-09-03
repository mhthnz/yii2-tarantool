<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace mhthnz\tarantool;

use yii\base\BaseObject;
use yii\base\InvalidArgumentException;

/**
 * Table schema for tarantool database.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class TableSchema extends \yii\db\TableSchema
{
    /**
     * https://www.tarantool.io/en/doc/latest/book/box/engines/#differences-between-memtx-and-vinyl-storage-engines
     * @const string
     */
    const ENGINE_MEMTX = 'memtx';

    /**
     * https://www.tarantool.io/en/doc/latest/book/box/engines/#differences-between-memtx-and-vinyl-storage-engines
     * @const string
     */
    const ENGINE_VINYL = 'vinyl';

    /**
     * FieldNo => fieldName
     * @var array<int, string> $_columns
     */
    private $_columns = [];

    /**
     * @var string sequence ID for the primary key. Null if no sequence.
     */
    public $sequenceName;

    /**
     * Table engine.
     * @var string ENGINE_MEMTX | ENGINE_VINYL
     */
    public $engine;

    /**
     * Mapper from field number to field name.
     * @param int $number
     * @return string|null
     */
    public function getFieldByNum($number)
    {
        if (!isset($this->_columns[$number])) {
            return null;
        }
        return $this->_columns[$number];
    }

    /**
     * Mapper from field name to field number.
     * @param string $field
     * @return int|null
     */
    public function getNumByField($field)
    {
        foreach($this->_columns as $key => $column) {
            if ($field === $column) {
                return $key;
            }
        }
        return null;
    }

    /**
     * @param int $fieldNo
     * @param string $fieldName
     */
    public function addField($fieldNo, $fieldName)
    {
        $this->_columns[$fieldNo] = $fieldName;
    }
}
