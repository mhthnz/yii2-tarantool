<?php

namespace mhthnz\tarantool\tests\classes;

use mhthnz\tarantool\console\MigrateController;

class EchoMigrateController extends MigrateController
{
    /**
     * {@inheritdoc}
     */
    public function stdout($string)
    {
        echo $string;
    }
}