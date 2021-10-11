<?php

namespace mhthnz\tarantool\validators;

use mhthnz\tarantool\ActiveRecord;
use mhthnz\tarantool\Connection;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\base\NotSupportedException;
use yii\validators\Validator;

/**
 * Lua validator helps to combine php and tarantool lua logic for validating user input.
 *
 * @property string $function Can be:
 * function (value, params)
 *    -- some lua logic
 *    return value == 1
 * end
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class LuaValidator extends Validator
{
    /**
     * Lua function that will be executed. For example:
     *
     *      function (value, params)
     *          -- Some lua logic that have to return bool
     *          return true
     *      end
     *
     * @var string
     */
    public $function = '';

    /**
     * @var mixed additional parameters that are passed to the lua validation function
     */
    public $params = [];

    /**
     * Force use tarantool component id or db will be grabbed from \mhthnz\tarantool\ActiveRecord class.
     * @var Connection|string|null
     */
    public $db;

    /**
     * When got non-boolean result.
     * @var string
     */
    public $nonBooleanMessage = "Lua function of {attribute} must return boolean, but returned '{result}'";

    /**
     * @var string
     */
    public $wrapper = 'return ({function})(...)';

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        if (empty($this->function)) {
            throw new InvalidConfigException('The "function" property must be set.');
        }
        if ($this->message === null) {
            $this->message = '{attribute} is not valid.';
        }
    }

    /**
     * Trying to get db from LuaValidator::db if is set or using $model param ActiveRecord::getDb().
     * @param Model|ActiveRecord $model
     * @param string $attribute
     * @throws InvalidConfigException
     * @throws NotSupportedException
     */
    public function validateAttribute($model, $attribute)
    {
        if ($this->db === null) {
            if (!$model instanceof ActiveRecord) {
                throw new InvalidConfigException("Model has to be extended from \\mhthnz\\tarantool\\ActiveRecord or set LuaValidator::db manually");
            }
            $this->db = $model::getDb();
        } else {
            $this->checkDb();
        }

        $result = $this->validateValue($model->$attribute);
        if (!empty($result)) {
            $this->addError($model, $attribute, $result[0], $result[1]);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function validateValue($value)
    {
        $func = strtr($this->wrapper, ['{function}' => $this->function]);
        $this->checkDb();
        $result = $this->db->createNosqlCommand()->evaluate($func, [$value, $this->params])->queryScalar();
        if (!is_bool($result)) {
            return [$this->nonBooleanMessage, ['result' => $this->filterResult($result)]];
        }

        if (!$result) {
            return [$this->message, []];
        }

        return null;
    }

    /**
     * @throws InvalidConfigException
     */
    protected function checkDb()
    {
        if (is_string($this->db)) {
            $this->db = Yii::$app->get($this->db);
        }

        if (!$this->db instanceof Connection) {
            throw new InvalidConfigException("LuaValidator::db must be instance of mhthnz\\tarantool\\Connection");
        }
    }

    /**
     * Filter returned value from lua function.
     * @param $result
     * @return string
     */
    protected function filterResult($result)
    {
        if (is_array($result)) {
            return 'array';
        }
        if (is_object($result)) {
            return 'object';
        }
        if (is_bool($result)) {
            return $result ? 'true' : 'false';
        }

        if (is_string($result)) {
            if (strlen($result) > 20) {
                return htmlspecialchars(substr($result, 0, 20)) . '...';
            }
            return htmlspecialchars($result);
        }

        return $result;
    }
}
