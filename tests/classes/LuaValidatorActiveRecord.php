<?php

namespace mhthnz\tarantool\tests\classes;

use mhthnz\tarantool\Connection;

/**
 * @property int $id
 * @property string $name
 * @property int $field
 * @property int $field1
 */
class LuaValidatorActiveRecord extends \mhthnz\tarantool\ActiveRecord
{
    public $rules = [];

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'myspace';
    }

    /**
     * @return array
     */
    public function rules()
    {
        return $this->rules;
    }

    /**
     * @param string $func
     */
    public function setLuaFunc(string $func)
    {
        $this->_luaFunc = $func;
    }

    /**
     * @param string|Connection $db
     */
    public function setDB($db)
    {
        $this->_db = $db;
    }
}