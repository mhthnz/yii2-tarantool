<?php

namespace mhthnz\tarantool\gii;

use mhthnz\tarantool\Connection;
use Yii;
use yii\db\ActiveRecord;

class Generator extends \yii\gii\generators\model\Generator
{
    public $baseClass = '\mhthnz\tarantool\ActiveRecord';
    public $db = 'tarantool';
    public $generateLabelsFromComments = false;
    public $useSchemaName = false;

    /**
     * Override AR class.
     * @return array
     */
    public function rules()
    {
        $rules = parent::rules();
        foreach ($rules as &$rule) {
            if ($rule[1] == 'validateClass' && $rule['params']['extends'] == ActiveRecord::class) {
                $rule['params']['extends'] = \mhthnz\tarantool\ActiveRecord::class;
            }
        }

        return $rules;
    }

    /**
     * Validates the [[db]] attribute.
     */
    public function validateDb()
    {
        if (!Yii::$app->has($this->db)) {
            $this->addError('db', 'There is no application component named "db".');
        } elseif (!Yii::$app->get($this->db) instanceof Connection) {
            $this->addError('db', 'The "db" application component must be a mhthnz\tarantool\Connection instance.');
        }
    }

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'Tarantool Model Generator';
    }

    /**
     * @inheritdoc
     */
    public function getDescription()
    {
        return 'This generator generates an ActiveRecord class for the specified tarantool table.';
    }
}