<?php

namespace mhthnz\tarantool\tests;

use mhthnz\tarantool\tests\classes\ActiveRecord;
use mhthnz\tarantool\tests\classes\ChildAR;
use mhthnz\tarantool\tests\classes\ParentAR;
use yii\db\SchemaBuilderTrait;

trait DbTrait
{
    use SchemaBuilderTrait;

    /**
     * @return \mhthnz\tarantool\Connection
     * @throws \Exception
     */
    protected function getDb()
    {
        return $this->getConnection();
    }

    public function dropConstraints()
    {
        $this->getDb()->createCommand()->delete("_fk_constraint")->execute();
    }

    public function dropTables()
    {
        foreach($this->getDb()->getSchema()->getTableNames('', true) as $tableName) {
            $this->getDb()->createCommand()->dropTable($tableName)->execute();
        }
    }

    public function createTable($table, $columns, $options = null)
    {
        $this->getDb()->createCommand()->createTable($table, $columns, $options)->execute();
    }

    /**
     * @param string $tableName
     * @param array $columns
     * @param array $data
     * @throws \Exception
     */
    public function createTableWithData($tableName, $columns, $data, $opt = null)
    {
        if ($this->getDb()->getTableSchema($tableName) !== null) {
            $this->getDb()->createCommand()->dropTable($tableName)->execute();
        }
        $this->createTable($tableName, $columns, $opt);
        ActiveRecord::$tableName = $tableName;
        $this->fillData($data);
    }

    public function fillData($data)
    {
        foreach ($data as $row) {
            $ar = new ActiveRecord();
            $ar->setAttributes($row, false);
            $ar->save();
        }
    }

    public function createTableUsers($data = [], $opt = null)
    {
        $this->createTableWithData('user', [
            'id' => $this->primaryKey()->notNull(),
            'name' => $this->string()->null(),
            'email' => $this->string()->unique(),
            'status' => $this->integer()->notNull()->defaultValue(1),
            'created_at' => $this->integer()->unsigned(),
        ], $data, $opt);
    }


    public function createRelatedTables($opt = null)
    {
        $this->createTable(ParentAR::tableName(), [
            'id' => $this->primaryKey(),
            'name' => $this->string(),
            'created_at' => $this->integer()
        ], $opt);
        $this->createTable(ChildAR::tableName(), [
            'id' => $this->primaryKey(),
            'parent_id' => $this->integer(),
            'created_at' => $this->integer()
        ], $opt);
        $this->getDb()->createCommand()->addForeignKey('fk-child-parent', ChildAR::tableName(), 'parent_id', ParentAR::tableName(),'id')->execute();
    }

}
