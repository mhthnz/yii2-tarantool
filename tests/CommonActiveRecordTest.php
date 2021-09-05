<?php

namespace mhthnz\ext;


use mhthnz\ext\classes\ActiveRecord;
use mhthnz\ext\classes\ActiveRecordTestTrait;
use mhthnz\ext\classes\ChildAR;
use mhthnz\ext\classes\Customer;
use mhthnz\ext\classes\Item;
use mhthnz\ext\classes\Order;
use mhthnz\ext\classes\OrderItem;
use mhthnz\ext\classes\OrderItemWithNullFK;
use mhthnz\ext\classes\OrderWithNullFK;
use mhthnz\ext\classes\ParentAR;
use mhthnz\tarantool\Schema;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;



class CommonActiveRecordTest extends TestCase
{
    use DbTrait;
    use ActiveRecordTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication();
        ActiveRecord::$db = $this->getDb();
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        $this->createStructure();
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        parent::tearDown();
    }


    public function getCustomerClass()
    {
        return Customer::className();
    }

    public function getOrderClass()
    {
        return Order::className();
    }

    public function getOrderItemClass()
    {
        return OrderItem::className();
    }

    public function getItemClass()
    {
        return Item::className();
    }

    public function getOrderWithNullFKClass()
    {
        return OrderWithNullFK::className();
    }

    public function getOrderItemWithNullFKmClass()
    {
        return OrderItemWithNullFK::className();
    }
}