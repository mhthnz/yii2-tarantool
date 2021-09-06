<?php

namespace mhthnz\tarantool\tests\classes;

class ParentAR extends ActiveRecord
{
    public static function tableName()
    {
        return 'parent_table';
    }

    public function getChild()
    {
        return $this->hasMany(ChildAR::className(), ['parent_id' => 'id']);
    }
}