<?php

namespace mhthnz\ext\classes;

use yii\base\BaseObject;

class AnyValue extends BaseObject
{
    /**
     * @var self
     */
    private static $_instance;

    public static function getInstance()
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }
}