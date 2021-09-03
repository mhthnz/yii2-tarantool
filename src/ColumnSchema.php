<?php

namespace mhthnz\tarantool;

use MessagePack\Type\Bin;
use yii\db\ExpressionInterface;

/**
 * Class ColumnSchema for Tarantool database.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class ColumnSchema extends \yii\db\ColumnSchema
{
    /**
     * {@inheritdoc}
     */
    public function dbTypecast($value)
    {
        if ($value === null) {
            return $value;
        }

        if ($value instanceof ExpressionInterface) {
            return $value;
        }

        // Process varbinary fields
        if ($this->type === Schema::TYPE_BINARY && !$value instanceof Bin) {
            return new Bin($value);
        } else if ($value instanceof Bin) {
            return $value;
        }

        return $this->typecast($value);
    }

    /**
     * {@inheritdoc}
     */
    public function phpTypecast($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        return parent::phpTypecast($value);
    }

}
