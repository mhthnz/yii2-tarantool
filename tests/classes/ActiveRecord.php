<?php

namespace mhthnz\tarantool\tests\classes;

use mhthnz\tarantool\Connection;

class ActiveRecord extends \mhthnz\tarantool\ActiveRecord
{
    /**
     * @var Connection
     */
    public static $db;

    /**
     * @var string
     */
    public static $tableName;

    /**
     * @return \mhthnz\tarantool\Connection
     */
    public static function getDb()
    {
        return static::$db;
    }

    public static function tableName()
    {
        return static::$tableName;
    }
}