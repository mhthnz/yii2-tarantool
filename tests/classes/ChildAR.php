<?php

namespace mhthnz\tarantool\tests\classes;

class ChildAR extends ActiveRecord
{
    public static function tableName()
    {
        return 'child_table';
    }

    public function getParent()
    {
        return $this->hasOne(ParentAR::className(), ['id' => 'parent_id']);
    }
}