<?php

namespace mhthnz\tarantool\tests;

trait Vinylable
{
    public function createTable($table, $columns, $options = null)
    {
        self::getDb()->createCommand()->createTable($table, $columns, "WITH ENGINE='vinyl'".$options)->execute();
    }
}