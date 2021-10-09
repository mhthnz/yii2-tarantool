<?php

namespace mhthnz\tarantool\tests;


use mhthnz\tarantool\Connection;
use mhthnz\tarantool\DataReader;
use mhthnz\tarantool\Schema;
use yii\base\NotSupportedException;
use yii\caching\ArrayCache;
use yii\db\Query;

class ConditionTest extends TestCase
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

    public function testUnion()
    {
        $connection = $this->getConnection();
        $query = (new Query())
            ->select(['id', 'name'])
            ->from('item')
            ->where(['id' => [1,2,3]])
            ->union(
                (new Query())
                    ->select(['id', 'name'])
                    ->from(['category'])
                    ->where(['id' => 2])
            );
        $result = $query->all($connection);
        $this->assertNotEmpty($result);
        $this->assertCount(4, $result);
    }

    public function testGroupBy()
    {
        $connection = $this->getConnection();
        $query = (new Query())
            ->select(['category_id', 'count' => 'count(*)'])
            ->from("item")
            ->groupBy("category_id");

        $result = $query->all($connection);
        $this->assertEquals([['category_id' => 1, 'count' => 2], ['category_id' => 2, 'count' => 3]], $result);

        // having
        $result = $query->having(['>', 'category_id', 1])->all($connection);
        $this->assertEquals([['category_id' => 2, 'count' => 3]], $result);
    }

    /**
     * @param Connection $db
     * @param string $tableName
     * @param string $columnName
     * @param array $condition
     * @param string $operator
     * @return int
     */
    protected function countLikeQuery(Connection $db, $tableName, $columnName, array $condition, $operator = 'or')
    {
        $whereCondition = [$operator];
        foreach ($condition as $value) {
            $whereCondition[] = ['like', $columnName, $value];
        }
        $result = (new Query())
            ->from($tableName)
            ->where($whereCondition);

        $a = $result->count('*', $db);
        if (is_numeric($a)) {
            $result = (int) $a;
        }

        return $result;
    }

    public function testMultipleLikeConditions()
    {
        $db = $this->getConnection();
        $tableName = 'like_test';
        $columnName = 'col';

        if ($db->getSchema()->getTableSchema($tableName) !== null) {
            $db->createCommand()->dropTable($tableName)->execute();
        }
        $db->createCommand()->createTable($tableName, [
            'id' => Schema::TYPE_PK,
            $columnName => $db->getSchema()->createColumnSchemaBuilder(Schema::TYPE_STRING, 64),
        ])->execute();
        $db->createCommand()->batchInsert($tableName, ['col'], [
            ['test0'],
            ['test\1'],
            ['test\2'],
            ['foo%'],
            ['%bar'],
            ['%baz%'],
        ])->execute();


        // Basic tests
        $this->assertSame(1, $this->countLikeQuery($db, $tableName, $columnName, ['test0']));
        $this->assertSame(2, $this->countLikeQuery($db, $tableName, $columnName, ['test\\']));
        $this->assertSame(0, $this->countLikeQuery($db, $tableName, $columnName, ['test%']));
        $this->assertSame(3, $this->countLikeQuery($db, $tableName, $columnName, ['%']));

        // Multiple condition tests
        $this->assertSame(2, $this->countLikeQuery($db, $tableName, $columnName, [
            'test0',
            'test\1',
        ]));
        $this->assertSame(3, $this->countLikeQuery($db, $tableName, $columnName, [
            'test0',
            'test\1',
            'test\2',
        ]));
        $this->assertSame(3, $this->countLikeQuery($db, $tableName, $columnName, [
            'foo',
            '%ba',
        ]));
    }

    public function testExpressionInFrom()
    {
        $db = $this->getConnection();
        $query = (new Query())
            ->from(
                new \yii\db\Expression(
                    '(SELECT [[id]], [[name]], [[email]], [[address]], [[status]] FROM {{customer}}) c'
                )
            )
            ->where(['status' => 2]);

        $result = $query->one($db);
        $this->assertEquals('user3', $result['name']);
    }

    public function testBetween()
    {
        $connection = $this->getConnection();
        $query = (new Query())
            ->select(['id'])
            ->from("item")
            ->where(['between', 'id', 2, 4]);
        $result = $query->count('*', $connection);
        $this->assertEquals(3, $result);
    }

    public function testWith()
    {
        $connection = $this->getConnection();
        $query = (new Query())
            ->from("a")
            ->withQuery((new Query)->from('item')->limit(2), 'a');
        $this->assertEquals(2, count($query->all($connection)));
        $this->assertEquals(1, count($query->where(['id' => 1])->all($connection)));

    }
}