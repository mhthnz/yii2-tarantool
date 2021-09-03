<?php

namespace mhthnz\ext;

trait Vinylable
{
    public function createTable($table, $columns, $options = null)
    {
        $this->getDb()->createCommand()->createTable($table, $columns, "WITH ENGINE='vinyl'".$options)->execute();
    }
}