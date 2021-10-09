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
use yii\db\Query;
use yii\helpers\ArrayHelper;



class ActiveRecordTest extends TestCase
{
    use DbTrait;


    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication();
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        ActiveRecord::$db = $this->getConnection();
        parent::setUp();
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

    /**
     * Test find conditions.
     * @throws \Exception
     */
    public function testFind()
    {
        $data = [
            [
                "name" => "My super name 1",
                "email" => "some.email@gmail.com",
                "created_at" => time(),
            ],
            [
                "name" => "My super name 2",
                "email" => "some-email@gmail.com",
                "created_at" => time(),
            ],
            [
                "name" => "My super name 3",
                "email" => "abc@gmail.com",
                "created_at" => time(),
            ],
        ];
        $this->createTableWithData('mytable', [
            'id' => $this->primaryKey()->notNull(),
            'name' => $this->string()->null(),
            'email' => $this->string()->unique(),
            'created_at' => $this->integer()->unsigned(),
        ], $data);

        // Find all
        $query = ActiveRecord::find();
        $this->assertTrue($query instanceof ActiveQuery);
        $all = $query->all();
        $this->assertTrue(count($all) === 3);
        foreach ($all as $key => $ar) {
            $this->assertTrue($ar instanceof ActiveRecord);
            $expected = $data[$key];
            foreach ($expected as $field => $value) {
                $this->assertTrue($ar->{$field} === $value);
            }
        }

        // Find one
        $ar = ActiveRecord::find()->one();
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($data[0]["name"] === $ar->name);
        $this->assertTrue($data[0]["email"] === $ar->email);
        $this->assertTrue($data[0]["created_at"] === $ar->created_at);

        // Find by pk
        $ar = ActiveRecord::findOne(2);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($ar->id === 2);

        // Find by conditions
        $ar = ActiveRecord::findOne(['id' => 3]);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($ar->id === 3);
        $ar = ActiveRecord::findOne(['email' => 'some-email@gmail.com']);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($ar->email === 'some-email@gmail.com');
        $ar = ActiveRecord::findOne(['name' => 'My super name 3']);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($ar->name === 'My super name 3');
        $this->assertEquals(null, ActiveRecord::findOne(10));

        // Find by where
        $ar = ActiveRecord::find()->where(['id' => 3])->one();
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($ar->id === 3);
        $ar = ActiveRecord::find()->where(['email' => 'some-email@gmail.com'])->one();
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($ar->email === 'some-email@gmail.com');
        $this->assertTrue(ActiveRecord::find()->where('"id" = :id')->addParams([':id' => 3])->one() instanceof ActiveRecord);
        $this->assertTrue(ActiveRecord::find()->where('"id" = :id', [':id' => 3])->one() instanceof ActiveRecord);
        $this->assertEquals(1, count(ActiveRecord::find()->where(['like', 'email', 'some-email'])->all()));
        $this->assertTrue(ActiveRecord::find()->where(['like', 'email', 'some-email'])->one() instanceof ActiveRecord);
        $this->assertEquals(3, count(ActiveRecord::find()->where(['like', 'email', 'gmail'])->all()));
        $this->assertEquals(null, ActiveRecord::find()->where(['id' => 5])->one());
        $this->assertEquals(null, ActiveRecord::find()->where('"id" > 5')->one());

        // As Array
        $ar = ActiveRecord::find()->where(['id' => 3])->asArray()->one();
        $row = $data[2];
        $row['id'] = 3;
        $this->assertEquals($row, $ar);
        $this->assertEquals(3, count(ActiveRecord::find()->asArray()->all()));

        // Find by sql
        $all = ActiveRecord::findBySql('SELECT * FROM "mytable" WHERE "id" > 1')->all();
        $this->assertEquals(2, count(ActiveRecord::findBySql('SELECT * FROM "mytable" WHERE "id" > 1')->all()));
        $this->assertTrue($all[0] instanceof ActiveRecord);
        $this->assertEquals(0, count(ActiveRecord::findBySql('SELECT * FROM "mytable" WHERE "id" > 11')->all()));
        $this->assertEquals(null, ActiveRecord::findBySql('SELECT * FROM "mytable" WHERE "id" > 11')->one());
        $this->assertEquals(3, ActiveRecord::findBySql('SELECT * FROM "mytable"')->count());
        $this->assertEquals(0, ActiveRecord::findBySql('SELECT * FROM "mytable" WHERE "id" > 100')->count());
        $this->assertEquals(1, count(ActiveRecord::findBySql('SELECT * FROM "mytable" WHERE "id" = :id', [':id' => 1])->all()));

        // find count, sum, average, min, max, scalar
        $this->assertEquals(3, ActiveRecord::find()->count());
        $this->assertEquals(1, ActiveRecord::find()->where('"id" = 1')->count());
        $this->assertEquals(2, ActiveRecord::find()->where('"id" > :id', [':id' => 1])->count());
        $this->assertEquals(6, ActiveRecord::find()->sum('"id"'));
        $this->assertEquals(2, ActiveRecord::find()->average('"id"'));
        $this->assertEquals(1, ActiveRecord::find()->min('"id"'));
        $this->assertEquals(3, ActiveRecord::find()->max('"id"'));
        $this->assertEquals(3, ActiveRecord::find()->select("count(*)")->scalar());

        // Scalar
        $this->assertEquals("My super name 3", ActiveRecord::find()->select(['name'])->where('"id" = 3')->scalar());

        // Exists
        $this->assertTrue(ActiveRecord::find()->select(['name'])->where('"id" = 3')->exists());
        $this->assertFalse(ActiveRecord::find()->select(['name'])->where('"id" = 33')->exists());
        $this->assertTrue(ActiveRecord::find()->where('"id" = 1')->exists());
        $this->assertFalse(ActiveRecord::find()->where('"id" = 11')->exists());

        // Column
        $this->assertEquals([1,2,3], ActiveRecord::find()->select(['id'])->column());
        $this->assertEquals([3,2,1], ActiveRecord::find()->select('id')->orderBy(['id' => SORT_DESC])->column());
        $this->assertEquals([], ActiveRecord::find()->select('id')->where('"id" > 100')->orderBy(['id' => SORT_DESC])->column());

        // Index by
        $res = ActiveRecord::find()->indexBy("name")->all();
        $this->assertTrue(array_key_exists($data[0]['name'], $res));
        $this->assertTrue(array_key_exists($data[1]['name'], $res));
        $this->assertTrue(array_key_exists($data[2]['name'], $res));
        $res = ActiveRecord::find()->indexBy(function ($ar) {
            return $ar->id . '-' . $ar->id;
        })->all();
        $this->assertTrue(array_key_exists('1-1', $res));
        $this->assertTrue(array_key_exists('2-2', $res));
        $this->assertTrue(array_key_exists('3-3', $res));
    }

    public function testInsert()
    {
        $this->createTableUsers();
        $ar = new ActiveRecord();
        $ar->name = "andrew me";
        $ar->email = "some-email@gmail.com";
        $time = $ar->created_at = time();
        $this->assertTrue($ar->isNewRecord);
        $this->assertTrue($ar->save());
        $this->assertFalse($ar->isNewRecord);
        $this->assertTrue(1 === $ar->id);
        $ar = ActiveRecord::find()->one();
        $this->assertTrue(1 === $ar->id);
        $this->assertTrue(1 === $ar->status);
        $this->assertTrue($time === $ar->created_at);
        $this->assertTrue("andrew me" === $ar->name);
        $this->assertTrue("some-email@gmail.com" === $ar->email);

        // Insert with binary field
        $this->createTable('tbl', [
            'id' => Schema::TYPE_PK,
            'bin' => Schema::TYPE_BINARY,
        ]);
        ActiveRecord::$tableName = 'tbl';
        $blob = pack("nvc*", 0x1234, 0x5678, 65, 66);
        $obj = new ActiveRecord(['bin' => $blob]);
        $this->assertTrue($obj->save());

        $r = ActiveRecord::findOne(1);
        $this->assertEquals($blob, $r->bin);
    }

    public function testUpdate()
    {
        $this->createTableUsers();
        $ar = new ActiveRecord();
        $ar->name = "andrew me";
        $ar->email = "some-email@gmail.com";
        $time = $ar->created_at = time();
        $this->assertTrue($ar->isNewRecord);
        $this->assertTrue($ar->save());
        $this->assertFalse($ar->isNewRecord);

        $ar = ActiveRecord::findOne(1);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertFalse($ar->isNewRecord);
        $ar->status = 10;
        $this->assertTrue($ar->save());
        $this->assertFalse($ar->isNewRecord);
        $this->assertEquals(10, $ar->status);

        $ar = ActiveRecord::findOne(1);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertEquals(10, $ar->status);
        $this->assertEquals("andrew me", $ar->name);

        ActiveRecord::updateAll(['status' => 100], ['id' => 1]);
        $ar = ActiveRecord::findOne(1);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertEquals(100, $ar->status);
    }

    public function testDelete()
    {
        $this->createTableUsers([
            [
                "id" => 1,
                "name" => "first name",
                "email" => "some-email@gmail.com",
                'created_at' => time()
            ],
            [
                "id" => 2,
                "name" => "first name",
                "email" => "some-email1@gmail.com",
                'created_at' => time()
            ],
            [
                "id" => 3,
                "name" => "first name",
                "email" => "some-email2@gmail.com",
                'created_at' => time()
            ],
        ]);
        $ar = ActiveRecord::findOne(1);
        $this->assertTrue($ar instanceof ActiveRecord);
        $this->assertTrue($ar->delete() !== false);
        $this->assertEquals(null, ActiveRecord::findOne(1));
        $this->assertEquals(2, ActiveRecord::find()->count());

        ActiveRecord::deleteAll(['name' => 'first name']);
        $this->assertEquals(0, ActiveRecord::find()->count());
    }

    public function testCounters()
    {
        $this->createTableUsers([
            [
                "id" => 1,
                "name" => "first name",
                "email" => "some-email@gmail.com",
                "status" => 10,
                'created_at' => time()
            ],
            [
                "id" => 2,
                "name" => "first name",
                "email" => "some-email1@gmail.com",
                "status" => 20,
                'created_at' => time()
            ],
            [
                "id" => 3,
                "name" => "first name",
                "email" => "some-email2@gmail.com",
                "status" => 30,
                'created_at' => time()
            ],
        ]);
        $this->assertEquals(3, ActiveRecord::updateAllCounters(['status' => 10], ['name' => "first name"]));
        $all = ActiveRecord::find()->all();
        $this->assertEquals(20, $all[0]->status);
        $this->assertEquals(30, $all[1]->status);
        $this->assertEquals(40, $all[2]->status);
        $this->assertNull(ActiveRecord::findOne(['status' => 10]));

        $ar = ActiveRecord::findOne(1);
        $originalCounter = $ar->status;
        $ar->updateCounters(['status' => 10]);
        $this->assertEquals($originalCounter + 10, $ar->status);
        $ar = ActiveRecord::findOne(1);
        $this->assertEquals($originalCounter + 10, $ar->status);
    }

    public function testAlias()
    {
        $this->createTableUsers([
            [
                "id" => 1,
                "name" => "first name",
                "email" => "some-email@gmail.com",
                "status" => 10,
                'created_at' => time()
            ],
            [
                "id" => 2,
                "name" => "first name",
                "email" => "some-email1@gmail.com",
                "status" => 20,
                'created_at' => time()
            ],
            [
                "id" => 3,
                "name" => "first name",
                "email" => "some-email2@gmail.com",
                "status" => 30,
                'created_at' => time()
            ],
        ]);
        $ar = ActiveRecord::find()->alias("t")->where(["t.id" => 1])->one();
        $this->assertEquals(1, $ar->id);
    }

    public function testCastValues()
    {
        $this->createTable("types", [
            'id' => $this->primaryKey()->notNull(),
            'name' => $this->string()->null(),
            'email' => $this->string()->unique(),
            'status' => $this->integer()->notNull()->defaultValue(1),
            'active' => $this->boolean()->notNull()->defaultValue(true),
            'counter' => $this->integer()->unsigned(),
            'created_at' => $this->integer()->unsigned(),
        ]);
        ActiveRecord::$tableName = 'types';

        $ar = new ActiveRecord();
        $ar->id = 1;
        $ar->name = "sdfsdf";
        $ar->email = "asdasd@asdasd.com";
        $ar->status = 100;
        $ar->active = false;
        $ar->counter = 1;
        $ar->created_at = time();
        $this->assertTrue($ar->save());

        $ar = ActiveRecord::find()->one();
        $this->assertSame(false, $ar->active);
        $this->assertSame(1, $ar->counter);
        $this->assertSame(100, $ar->status);
        $this->assertSame("sdfsdf", $ar->name);
        $this->assertSame("asdasd@asdasd.com", $ar->email);
    }

    public function testRelations()
    {
        // Null relation
        $this->createRelatedTables();
        $p = new ParentAR(['name' => 'Name1', 'created_at' => time()]);
        $p->save();
        $this->assertEquals([], $p->child);

        // Add relation
        $c = new ChildAR(['parent_id' => $p->id, 'created_at' => time()]);
        $this->assertTrue($c->save());
        $this->assertTrue($c->parent instanceof ParentAR);
        $this->assertEquals("Name1", $c->parent->name);

        // Child
        $p->refresh();
        $result = $p->child;
        $this->assertTrue(count($result) === 1);
        $this->assertTrue($result[0] instanceof ChildAR);

        // Link
        $c = new ChildAR(['created_at' => time()]);
        $c->link('parent', $p);
        $this->assertTrue($c->parent instanceof ParentAR);
        $p->refresh();
        $result = $p->child;
        $this->assertTrue(count($result) === 2);
        $this->assertTrue($result[0] instanceof ChildAR);
        $this->assertTrue($result[1] instanceof ChildAR);

        // Unlink
        $c->unlink("parent", $p, true);
        $this->assertNull($c->parent);
        $p->refresh();
        $result = $p->child;
        $this->assertTrue(count($result) === 1);
        $this->assertTrue($result[0] instanceof ChildAR);

        // Eager
        $c = new ChildAR(['created_at' => time()]);
        $p->refresh();
        $c->link('parent', $p);
        $result = ParentAR::find()->with('child')->all();
        $this->assertTrue($result[0]->isRelationPopulated('child'));
        $this->assertTrue($result[0] instanceof ParentAR);
        $this->assertTrue($result[0]->child[0] instanceof ChildAR);
        $this->assertTrue($result[0]->child[1] instanceof ChildAR);

        $child = ChildAR::find()->with('parent')->all();
        $this->assertTrue($child[0]->isRelationPopulated('parent'));
        $this->assertTrue($child[1]->isRelationPopulated('parent'));
        $this->assertTrue($child[0] instanceof ChildAR);
        $this->assertTrue($child[0]->parent instanceof ParentAR);
        $this->assertTrue($child[1] instanceof ChildAR);
        $this->assertTrue($child[1]->parent instanceof ParentAR);

        // Join with
        $p1 = new ParentAR(['name' => 'Name2', 'created_at' => time()]);
        $p1->save();
        $c = new ChildAR(['created_at' => time()]);
        $c->link('parent', $p1);
        $result = ParentAR::find()->joinWith('child')->orderBy(['child_table.id' => SORT_DESC])->all();
        $this->assertTrue($result[0] instanceof ParentAR && $result[1] instanceof ParentAR);
        $this->assertEquals(2, $result[0]->id);
        $this->assertTrue($result[0]->isRelationPopulated('child'));
        $this->assertTrue($result[0]->child[0] instanceof ChildAR);
        $this->assertEquals(4, $result[0]->child[0]->id);
        $this->assertEquals(1, $result[1]->id);
        $this->assertTrue($result[1]->isRelationPopulated('child'));
        $this->assertTrue($result[1]->child[0] instanceof ChildAR && $result[1]->child[1] instanceof ChildAR);
        $this->assertEquals(1, $result[1]->child[0]->id);
        $this->assertEquals(3, $result[1]->child[1]->id);

        // Inner join with
        $result = ParentAR::find()->innerJoinWith(['child' => function ($query) {
            $query->where(["child_table.id" => 4]);
        }])->all();
        $this->assertTrue(count($result) === 1);
        $this->assertTrue($result[0] instanceof ParentAR);
        $this->assertTrue($result[0]->isRelationPopulated('child'));

        // Inner join
        (new ParentAR(['name' => 'Name3', 'created_at' => time()]))->save();
        (new ParentAR(['name' => 'Name4', 'created_at' => time()]))->save();
        (new ParentAR(['name' => 'Name5', 'created_at' => time()]))->save();
        (new ParentAR(['name' => 'Name6', 'created_at' => time()]))->save();
        (new ChildAR(['created_at' => time()]))->save();
        (new ChildAR(['created_at' => time()]))->save();
        (new ChildAR(['created_at' => time()]))->save();
        (new ChildAR(['created_at' => time()]))->save();
        (new ChildAR(['created_at' => time()]))->save();
        $result = ChildAR::find()->innerJoinWith('parent')->all();
        $ids = ArrayHelper::getColumn($result, 'id');
        $this->assertTrue(in_array(1, $ids) && in_array(3, $ids) && in_array(4, $ids));

        // join with table alias
        $result = ParentAR::find()->innerJoinWith(['child' => function ($query) {
            $query->from('child_table c');
        }])->all();
        $this->assertTrue(count($result) === 2);

        // join with table alias
        $result = ParentAR::find()->innerJoinWith('child as c')->all();
        $this->assertTrue(count($result) === 2);
    }

    public function testIsPrimaryKey()
    {
        $this->createTable("types", [
            'id' => $this->primaryKey()->notNull(),
            'name' => $this->string()->null(),
            'email' => $this->string()->unique(),
            'status' => $this->integer()->notNull()->defaultValue(1),
            'active' => $this->boolean()->notNull()->defaultValue(true),
            'counter' => $this->integer()->unsigned(),
            'created_at' => $this->integer()->unsigned(),
        ]);
        ActiveRecord::$tableName = 'types';
        $this->assertTrue(ActiveRecord::isPrimaryKey(['id']));
        $this->assertFalse(ActiveRecord::isPrimaryKey(['status']));
        $this->assertFalse(ActiveRecord::isPrimaryKey(['id', 'name']));

        $this->createTable("types1", [
            'id' => $this->integer(),
            'name' => $this->string()->null(),
            'email' => $this->string()->unique(),
            'status' => $this->integer()->notNull()->defaultValue(1),
            'active' => $this->boolean()->notNull()->defaultValue(true),
            'counter' => $this->integer()->unsigned(),
            'created_at' => $this->integer()->unsigned(),
            'PRIMARY KEY ("id", "status")',
        ]);
        ActiveRecord::$tableName = 'types1';
        $this->assertFalse(ActiveRecord::isPrimaryKey(['id']));
        $this->assertFalse(ActiveRecord::isPrimaryKey(['status']));
        $this->assertFalse(ActiveRecord::isPrimaryKey(['id', 'name']));
        $this->assertTrue(ActiveRecord::isPrimaryKey(['id', 'status']));
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