<?php

namespace mhthnz\tarantool\tests;


use mhthnz\tarantool\tests\classes\ActiveRecord;
use mhthnz\tarantool\tests\classes\ActiveRecordTestTrait;
use mhthnz\tarantool\tests\classes\ChildAR;
use mhthnz\tarantool\tests\classes\Customer;
use mhthnz\tarantool\tests\classes\Item;
use mhthnz\tarantool\tests\classes\Order;
use mhthnz\tarantool\tests\classes\OrderItem;
use mhthnz\tarantool\tests\classes\OrderItemWithNullFK;
use mhthnz\tarantool\tests\classes\OrderWithNullFK;
use mhthnz\tarantool\tests\classes\ParentAR;
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
        ActiveRecord::$db = self::getDb();
        $this->dropConstraints();
        self::getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        self::getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        $this->createStructure();
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropConstraints();
        self::getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        self::getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
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