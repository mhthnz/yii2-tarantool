<?php

namespace mhthnz\tarantool\tests\classes;

use mhthnz\tarantool\Connection;
use mhthnz\tarantool\validators\LuaValidator;
use yii\base\Model;

class LuaValidatorBaseModel extends Model
{
    public $field1;

    public $field2;

    /**
     * @var string
     */
    private $_luaFunc = '';

    /**
     * @var Connection
     */
    private $_db;

    /**
     * @return array
     */
    public function rules()
    {
        $row = ['field2', LuaValidator::class, 'function' => $this->_luaFunc, 'params' => $this->getAttributes()];
        if ($this->_db !== null) {
            $row['db'] = $this->_db;
        }

        return [
            ['field1', 'required'],
            ['field1', 'string'],
            $row,
        ];
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