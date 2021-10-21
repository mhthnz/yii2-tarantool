<?php

namespace mhthnz\tarantool\tests;


use mhthnz\tarantool\nosql\Query;
use Tarantool\Client\Schema\IteratorTypes;
use yii\helpers\ArrayHelper;

class NosqlQueryTest extends TestCase
{
    use DbTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication(['components' => [
            'tarantool' => [
                'class' => \mhthnz\tarantool\Connection::class,
                'dsn' => 'tcp://guest@localhost:3301',
            ]
        ]]);
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();

    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropSpacesIfExist(['myspace', 'myspace1', 'myspace2', 'myspace3']);
        parent::tearDown();
    }

    public function testBuildFrom()
    {
        $this->makeSpaceForCmd();

        // Existent space
        $q = new Query(['db' => $this->getDb()]);
        $q->from('myspace');
        $this->assertEquals(123, $q->buildFrom());

        // Non-existent space
        $q->from('myspace1');
        $thrown = false;
        try {
            $a = $q->buildFrom();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testBuildIndexKeyIterator()
    {
        $this->makeSpaceForCmd();

        $q = new Query(['db' => $this->getDb()]);
        $q->from('myspace');

        // By primary key
        $q->where(111);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals([111], $q->buildKey());
        $this->assertEquals(IteratorTypes::EQ, $q->buildIterator());

        // Sort desc
        $q->orderDesc();
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals([111], $q->buildKey());
        $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

        // Primary composite index
        $q = new Query(['db' => $this->getDb()]);
        $q->from('myspace');
        $q->where(['firstcol', 'secondcol']);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals(['firstcol', 'secondcol'], $q->buildKey());
        $this->assertEquals(IteratorTypes::EQ, $q->buildIterator());

        $q->where(['firstcol', 'secondcol', 'thirdcol']);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals(['firstcol', 'secondcol', 'thirdcol'], $q->buildKey());
        $this->assertEquals(IteratorTypes::EQ, $q->buildIterator());

        $q->where([1, 2, 3]);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals([1, 2, 3], $q->buildKey());
        $this->assertEquals(IteratorTypes::EQ, $q->buildIterator());

        // Sort desc
        $q->where(['firstcol', 'secondcol']);
        $q->orderDesc();
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals(['firstcol', 'secondcol'], $q->buildKey());
        $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

        $q->where(['firstcol', 'secondcol', 'thirdcol']);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals(['firstcol', 'secondcol', 'thirdcol'], $q->buildKey());
        $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

        $q->where([1, 2, 3]);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals([1, 2, 3], $q->buildKey());
        $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

        // Different conditions with other indexes
       foreach (Query::$ITERATOR_MAP as $cond => $iterator) {
           $q = new Query(['db' => $this->getDb()]);
           $q->from('myspace');
           $q->where([$cond, 123]);
           $this->assertEquals(0, $q->buildIndex());
           $this->assertEquals([123], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

           $q->where([$cond, [123, 122]]);
           $this->assertEquals(0, $q->buildIndex());
           $this->assertEquals([123, 122], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

           $q->where([$cond, [123, 122, 1]]);
           $this->assertEquals(0, $q->buildIndex());
           $this->assertEquals([123, 122, 1], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

           $q->where([$cond, 'stringindex', 'text value']);
           $this->assertEquals(1, $q->buildIndex());
           $this->assertEquals(['text value'], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

           $q->where([$cond, 'stringindex', ['text value', 'second col']]);
           $this->assertEquals(1, $q->buildIndex());
           $this->assertEquals(['text value', 'second col'], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

           $q->where([$cond, 'stringindex', ['text value', 'second col', 'third col']]);
           $this->assertEquals(1, $q->buildIndex());
           $this->assertEquals(['text value', 'second col', 'third col'], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

           $q->where([$cond, 'intcompositeindex', 111]);
           $this->assertEquals(3, $q->buildIndex());
           $this->assertEquals([111], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

           $q->where([$cond, 'intcompositeindex', [111, 122]]);
           $this->assertEquals(3, $q->buildIndex());
           $this->assertEquals([111, 122], $q->buildKey());
           $this->assertEquals($iterator, $q->buildIterator());

            if ($cond !== '=') {
                continue;
            }

           // Sort desc
           $q->orderDesc();
           $q->where([$cond, 123]);
           $this->assertEquals(0, $q->buildIndex());
           $this->assertEquals([123], $q->buildKey());
           $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

           $q->where([$cond, 'stringindex', 'text value']);
           $this->assertEquals(1, $q->buildIndex());
           $this->assertEquals(['text value'], $q->buildKey());
           $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

           $q->where([$cond, 'stringindex', ['text value', 'second col']]);
           $this->assertEquals(1, $q->buildIndex());
           $this->assertEquals(['text value', 'second col'], $q->buildKey());
           $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

           $q->where([$cond, 'stringindex', ['text value', 'second col', 'third col']]);
           $this->assertEquals(1, $q->buildIndex());
           $this->assertEquals(['text value', 'second col', 'third col'], $q->buildKey());
           $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

           $q->where([$cond, 'intcompositeindex', 120]);
           $this->assertEquals(3, $q->buildIndex());
           $this->assertEquals([120], $q->buildKey());
           $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());

           $q->where([$cond, 'intcompositeindex', [120, 121]]);
           $this->assertEquals(3, $q->buildIndex());
           $this->assertEquals([120, 121], $q->buildKey());
           $this->assertEquals(IteratorTypes::REQ, $q->buildIterator());
       }

       // non-existent index
        $q = new Query(['db' => $this->getDb()]);
        $q->from('myspace');
        $q->where(['=', 'non-existent-index', 11]);
        $thrown = false;
        try {
            $q->buildIndex();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);

        // Empty value
        $q = new Query(['db' => $this->getDb()]);
        $q->from('myspace');
        $q->where([]);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals([], $q->buildKey());
        $this->assertEquals(IteratorTypes::EQ, $q->buildIterator());

        $q = new Query(['db' => $this->getDb()]);
        $q->from('myspace');
        $q->where(['=', []]);
        $this->assertEquals(0, $q->buildIndex());
        $this->assertEquals([], $q->buildKey());
        $this->assertEquals(IteratorTypes::EQ, $q->buildIterator());

        $q = new Query(['db' => $this->getDb()]);
        $q->from('myspace');
        $q->where(['=', 'stringindex', []]);
        $this->assertEquals(1, $q->buildIndex());
        $this->assertEquals([], $q->buildKey());
        $this->assertEquals(IteratorTypes::EQ, $q->buildIterator());
    }

    public function testAll()
    {
        $rows = $this->makeSpaceForCmd();

        // Order second index
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $this->assertEquals(["text 3", "text 3", "text 22", "text 22", "text 22", "text 22", "text 2", "text 1"], $query->orderDesc()->where(['=', 'stringindex', []])->column(1));

        $query = $this->getDb()->createNosqlQuery()->from('myspace');

        // Without condition
        $all = $query->all();
        $this->assertCount(8, $all);
        $this->assertEquals($rows, $all);

        $all = $query->limit(2)->all();
        $this->assertCount(2, $all);
        $this->assertEquals([$rows[0], $rows[1]], $all);

        $all = $query->limit(1000)->offset(2)->all();
        $this->assertCount(6, $all);
        $this->assertEquals([$rows[2], $rows[3], $rows[4], $rows[5], $rows[6], $rows[7]], $all);

        // =
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $query->where(['=', 1]);
        $all = $query->all();
        $this->assertCount(1, $all);
        $this->assertEquals([$rows[0]], $all);

        $query->where(['=', 'pk', 1]);
        $all = $query->all();
        $this->assertCount(1, $all);
        $this->assertEquals([$rows[0]], $all);

        $query->where(['=', 'intcompositeindex', [11, 13]]);
        $all = $query->all();
        $this->assertCount(2, $all);
        $this->assertEquals([$rows[0], $rows[1]], $all);

        $query->where(['=', 'intcompositeindex', [11, 13]])->limit(1);
        $all = $query->all();
        $this->assertCount(1, $all);
        $this->assertEquals([$rows[0]], $all);

        $query->where(['=', 'intcompositeindex', [11, 13]])->limit(1)->offset(1);
        $all = $query->all();
        $this->assertCount(1, $all);
        $this->assertEquals([$rows[1]], $all);

        $query->where(['=', 'stringindex', ['text 1']])->limit(1)->offset(0);
        $all = $query->all();
        $this->assertCount(1, $all);
        $this->assertEquals([$rows[0]], $all);

        $query->where(['=', 'pk', 111]);
        $all = $query->all();
        $this->assertCount(0, $all);
        $this->assertEquals([], $all);

        $query->where([111]);
        $all = $query->all();
        $this->assertCount(0, $all);
        $this->assertEquals([], $all);

        $query->where(['=', 111]);
        $all = $query->all();
        $this->assertCount(0, $all);
        $this->assertEquals([], $all);

        // >, >=
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $query->where(['>', 1]);
        $all = $query->all();
        $this->assertCount(7, $all);

        $query->where(['>=', 1]);
        $all = $query->all();
        $this->assertCount(8, $all);

        $query->where(['>', 5])->offset(1);
        $all = $query->all();
        $this->assertCount(2, $all);

        $query->where(['>', 5])->limit(2);
        $all = $query->all();
        $this->assertCount(2, $all);

        $query->where(['>=', 5])->limit(2)->offset(3);
        $all = $query->all();
        $this->assertCount(1, $all);

        $query->where(['>=', 'intcompositeindex',  [12]]);
        $all = $query->all();
        $this->assertCount(2, $all);
        $this->assertEquals([$rows[6], $rows[7]], $all);

        $query->where(['>=', 'intcompositeindex',  [11, 13]]);
        $all = $query->all();
        $this->assertCount(2, $all);
        $this->assertEquals([$rows[5], $rows[4]], $all);

        // <=
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $query->where(['<', 5]);
        $all = $query->all();
        $this->assertCount(4, $all);

        $query->where(['<=', 111]);
        $all = $query->all();
        $this->assertCount(8, $all);

        $query->where(['<', 3])->offset(1);
        $all = $query->all();
        $this->assertCount(1, $all);

        $query->where(['<', 5])->limit(2);
        $all = $query->all();
        $this->assertCount(2, $all);

        $query->where(['<=', 7])->limit(2)->offset(6);
        $all = $query->all();
        $this->assertCount(1, $all);

        $query->where(['<=', 'intcompositeindex',  [11, 14]])->offset(0)->limit(111111);
        $all = $query->all();
        $this->assertCount(3, $all);
        $this->assertEquals([$rows[2], $rows[1], $rows[0]], $all);

        $query->where(['<', 'intcompositeindex',  [12]])->limit(2)->offset(2);
        $all = $query->all();
        $this->assertCount(1, $all);
        $this->assertEquals([$rows[0]], $all);

        // Test order
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $all = $query->all();
        $this->assertCount(8, $all);
        $this->assertEquals($rows, $all);

        $reversed = array_reverse($rows);
        $query->orderDesc();
        $all = $query->all();
        $this->assertCount(8, $all);
        $this->assertEquals($reversed, $all);

        $all = $query->limit(2)->all();
        $this->assertCount(2, $all);
        $this->assertEquals([$reversed[0], $reversed[1]], $all);
    }

    public function testGet()
    {
        $rows =  $this->makeSpaceForCmd();
        $query = $this->getDb()->createNosqlQuery()->from('myspace');

        // Empty key exception
        $thrown = false;
        try {
            $query->get();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $query->where(1);
        $this->assertEquals($rows[0], $query->get());
        $query->where([2]);
        $this->assertEquals($rows[1], $query->get());
        $query->where(['=', 'pk', 3]);
        $this->assertEquals($rows[2], $query->get());

        // Non-unique index
        $query->where(['=', 'stringindex', 3]);
        $thrown = false;
        try {
            $query->get();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);

        // Limit, offset, order don't affect
        $query->where(['=', 'pk', 3])->limit(0)->offset(1000)->orderDesc();
        $this->assertEquals($rows[2], $query->get());
    }

    public function testOne()
    {
        $rows = $this->makeSpaceForCmd();

        $query = $this->getDb()->createNosqlQuery()->from('myspace');

        // Without condition
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $all = $query->limit(2)->one();
        $this->assertEquals($rows[0], $all);

        $all = $query->limit(1000)->offset(2)->one();
        $this->assertEquals($rows[2], $all);

        // =
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $query->where(['=', 1]);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $query->where(['=', 'pk', 1]);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $query->where(['=', 'intcompositeindex', [11, 13]]);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $query->where(['=', 'intcompositeindex', [11, 13]])->limit(1);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $query->where(['=', 'intcompositeindex', [11, 13]])->limit(1)->offset(1);
        $all = $query->one();
        $this->assertEquals($rows[1], $all);

        $query->where(['=', 'stringindex', ['text 1']])->limit(1)->offset(0);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        // >, >=
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $query->where(['>', 1]);
        $all = $query->one();
        $this->assertEquals($rows[1], $all);

        $query->where(['>=', 1]);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $query->where(['>', 5])->offset(1);
        $all = $query->one();
        $this->assertEquals($rows[6], $all);

        $query->where(['>', 5])->limit(2);
        $all = $query->one();
        $this->assertEquals($rows[6], $all);

        $query->where(['>=', 5])->limit(2)->offset(3);
        $all = $query->one();
        $this->assertEquals($rows[7], $all);

        $query->where(['>=', 'intcompositeindex',  [12]]);
        $all = $query->one();
        $this->assertEquals($rows[6], $all);

        $query->where(['>=', 'intcompositeindex',  [11, 13]]);
        $all = $query->one();
        $this->assertEquals($rows[5], $all);

        // <=
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $query->where(['<', 5]);
        $all = $query->one();
        $this->assertEquals($rows[3], $all);

        $query->where(['<=', 111]);
        $all = $query->one();
        $this->assertEquals($rows[7], $all);

        $query->where(['<', 3])->offset(1);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $query->where(['<=', 'intcompositeindex',  [11, 14]])->offset(0)->limit(111111);
        $all = $query->one();
        $this->assertEquals($rows[2], $all);

        $query->where(['<', 'intcompositeindex',  [12]])->limit(2)->offset(2);
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        // Test order
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $all = $query->one();
        $this->assertEquals($rows[0], $all);

        $query->orderDesc();
        $all = $query->one();
        $this->assertEquals($rows[7], $all);

        $all = $query->offset(2)->one();
        $this->assertEquals($rows[5], $all);
    }

    public function testMinMaxRandomCountExists()
    {
        $rows = $this->makeSpaceForCmd();

        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $this->assertEquals($rows[0], $query->min());
        $this->assertEquals($rows[7], $query->max());
        $this->assertNotEquals($query->random(1000), $query->random(1));

        // Other index
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $tuple = $query->where(['=', 'stringindex', []])->max();
        $this->assertEquals("text 3", $tuple[1]);

        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $tuple = $query->where(['=', 'stringindex', []])->min();
        $this->assertEquals("text 1", $tuple[1]);


        // Count
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $this->assertEquals(8, $query->count());
        $this->assertEquals(1, $query->where(1)->count());
        $this->assertEquals(1, $query->where(['=', 2])->count());
        $this->assertEquals(1, $query->where(['=', 'pk', 2])->count());
        $this->assertEquals(4, $query->where(['=', 'stringindex', 'text 22'])->count());
        $this->assertEquals(3, $query->where(['>', 'pk', 5])->count());
        $this->assertEquals(4, $query->where(['>=', 'pk', 5])->count());
        $this->assertEquals(3, $query->where(['<', 'intcompositeindex', 12])->count());
        $this->assertEquals(2, $query->where(['<', 'intcompositeindex', [11, 14]])->count());
        $this->assertEquals(2, $query->where(['<', 'pk', 3])->count());
        $this->assertEquals(0, $query->where(['>=', 'intcompositeindex', [111, 14]])->count());

        // Exists
        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $this->assertTrue($query->where(1)->exists());
        $this->assertFalse($query->where(1111)->exists());
        $this->assertFalse($query->where(['<', 'intcompositeindex', 0])->exists());
        $this->assertFalse($query->where(['>', 'intcompositeindex', 100])->exists());
        $this->assertFalse($query->where(['<', 'intcompositeindex', 5])->exists());
        $this->assertTrue($query->where(['=', 'stringindex', 'text 22'])->exists());
        $this->assertFalse($query->where(['=', 'stringindex', 'text 221'])->exists());
    }

    public function testColumn()
    {
        $rows = $this->makeSpaceForCmd();

        $query = $this->getDb()->createNosqlQuery()->from('myspace');
        $this->assertEquals(ArrayHelper::getColumn($rows, 0), $query->column());
        $this->assertEquals(ArrayHelper::getColumn($rows, 1), $query->column(1));
        $this->assertEquals(ArrayHelper::getColumn($rows, 2), $query->column(2));
        $this->assertEquals(ArrayHelper::getColumn($rows, 3), $query->column(3));

        $this->assertEquals([2], $query->where(['=', 2])->column());
        $this->assertEquals([2, 1], $query->where(['<=', 2])->column());
        $query->where = null;
        $this->assertEquals(ArrayHelper::getColumn(array_reverse($rows), 0), $query->orderDesc()->column());
        $this->assertEquals(ArrayHelper::getColumn(array_reverse($rows), 2), $query->orderDesc()->column(2));
        $this->assertEquals([12, 12, 12, 13, 15], $query->where(['>', 3])->column(2));

        $this->assertEquals(['text 3'], $query->where(['>', 'intindex', 13])->column(1));
    }

    public function testUsingIndex()
    {
        $this->makeSpaceForCmd();

        $this->assertEquals(
            $this->getDb()->createNosqlQuery()->from('myspace')->all(),
            $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('pk')->all()
        );
        $this->assertEquals(
            $this->getDb()->createNosqlQuery()->from('myspace')->orderDesc()->all(),
            $this->getDb()->createNosqlQuery()->orderDesc()->from('myspace')->usingIndex('pk')->all()
        );

        $this->assertEquals(
            $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->column(1),
            $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('stringindex')->column(1)
        );

        $this->assertEquals(
            $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->orderDesc()->column(1),
            $this->getDb()->createNosqlQuery()->from('myspace')->orderDesc()->usingIndex('stringindex')->column(1)
        );

        // usingIndex doesn't affect (where > using index)
        $this->assertEquals(
            ['text 22'],
            $this->getDb()->createNosqlQuery()->from('myspace')->orderDesc()->usingIndex('stringindex')->where(['=', 'pk', 3])->column(1)
        );

        $res = $this->getDb()->createNosqlQuery()->from('myspace')->max();
        $this->assertEquals(8, $res[0]);

        $res = $this->getDb()->createNosqlQuery()->from('myspace')->min();
        $this->assertEquals(1, $res[0]);

        $res = $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('stringindex')->min();
        $this->assertEquals('text 1', $res[1]);

        $res = $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('stringindex')->max();
        $this->assertEquals('text 3', $res[1]);
    }

}
