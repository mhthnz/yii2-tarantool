<?php

namespace mhthnz\ext\classes;

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