<?php

namespace mhthnz\tarantool\tests;

trait Vinylable
{
    public function createTable($table, $columns, $options = null)
    {
        $this->getDb()->createCommand()->createTable($table, $columns, "WITH ENGINE='vinyl'".$options)->execute();
    }
}