<?php

namespace mhthnz\tarantool;


/**
 * Adding ESCAPE '\' to query.
 * {@inheritdoc}
 */
class LikeConditionBuilder extends \yii\db\conditions\LikeConditionBuilder
{
    protected $escapeCharacter = '\\';
}