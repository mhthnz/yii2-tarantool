<?php

namespace mhthnz\tarantool\tests;

use MessagePack\Type\Bin;
use mhthnz\tarantool\Connection;
use Tarantool\Client\Request\CallRequest;
use Tarantool\Client\Request\EvaluateRequest;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Client\Request\ReplaceRequest;
use Tarantool\Client\Request\SelectRequest;
use Tarantool\Client\Request\UpdateRequest;
use Tarantool\Client\Request\UpsertRequest;
use Tarantool\Client\Schema\IteratorTypes;
use Tarantool\Client\Schema\Operations;


class NosqlCommandTest extends TestCase
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
        self::getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        self::getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();

    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropSpacesIfExist(['myspace', 'myspace1', 'myspace2', 'myspace3']);
        self::getDb()->createNosqlCommand()->evaluate("box.schema.user.drop('tempuser',{if_exists=true})")->execute();
        parent::tearDown();
    }

    public function testCreateDropSpace()
    {

        // Memtx space with non empty schema
        $format = [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => true],
            ['name' => 'params', 'type' => 'array'],
            ['name' => 'val', 'type' => 'scalar'],
            ['name' => 'active', 'type' => 'boolean'],
            ['name' => 'sum', 'type' => 'double'],
            ['name' => 'sum1', 'type' => 'decimal'],
            ['name' => 'int', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'mp', 'type' => 'map', 'is_nullable' => true],
        ];

       self::getDb()->createNosqlCommand()->createSpace('myspace', $format, 'memtx', ['id' => 1111])->execute();

        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space[1111]")->execute()->getResponseData();
        $this->assertEquals("memtx", $resp[0]["engine"]);
        $this->assertEquals("myspace", $resp[0]["name"]);
        $this->assertEquals(false, $resp[0]["temporary"]);

        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space[1111]:format()")->execute()->getResponseData();
        $fields = $resp[0];
        $this->assertCount(9, $fields);

        $this->assertEquals('unsigned', $fields[0]["type"]);
        $this->assertEquals('id', $fields[0]["name"]);
        $this->assertEquals(false, $fields[0]["is_nullable"]);

        $this->assertEquals('string', $fields[1]["type"]);
        $this->assertEquals('name', $fields[1]["name"]);
        $this->assertEquals(true, $fields[1]["is_nullable"]);

        $this->assertEquals('array', $fields[2]["type"]);
        $this->assertEquals('params', $fields[2]["name"]);

        $this->assertEquals('scalar', $fields[3]["type"]);
        $this->assertEquals('val', $fields[3]["name"]);

        $this->assertEquals('boolean', $fields[4]["type"]);
        $this->assertEquals('active', $fields[4]["name"]);

        $this->assertEquals('double', $fields[5]["type"]);
        $this->assertEquals('sum', $fields[5]["name"]);

        $this->assertEquals('decimal', $fields[6]["type"]);
        $this->assertEquals('sum1', $fields[6]["name"]);

        $this->assertEquals('integer', $fields[7]["type"]);
        $this->assertEquals('int', $fields[7]["name"]);
        $this->assertEquals(true, $fields[7]["is_nullable"]);

        $this->assertEquals('map', $fields[8]["type"]);
        $this->assertEquals('mp', $fields[8]["name"]);
        $this->assertEquals(true, $fields[8]["is_nullable"]);

        // Drop space
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace")->execute()->getResponseData();
        $this->assertNotNull($resp[0]);
        self::getDb()->createNosqlCommand()->dropSpace('myspace')->execute()->getResponseData();
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace")->execute()->getResponseData();
        $this->assertNull($resp[0]);

        // Empty schema space
        self::getDb()->createNosqlCommand()->createSpace('myspace1')->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace1")->execute()->getResponseData();
        $this->assertEquals("memtx", $resp[0]["engine"]);
        $this->assertEquals("myspace1", $resp[0]["name"]);
        $this->assertEquals(false, $resp[0]["temporary"]);

        // Drop space
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace1")->execute()->getResponseData();
        $this->assertNotNull($resp[0]);
        self::getDb()->createNosqlCommand()->dropSpace('myspace1')->execute()->getResponseData();
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace1")->execute()->getResponseData();
        $this->assertNull($resp[0]);

        // Fixed field count vinyl
        self::getDb()->createNosqlCommand()->createSpace('myspace2', [], 'vinyl', ['field_count' => 3])->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace2")->execute()->getResponseData();
        $this->assertEquals("vinyl", $resp[0]["engine"]);
        $this->assertEquals("myspace2", $resp[0]["name"]);
        $this->assertEquals(3, $resp[0]["field_count"]);
        $this->assertEquals(false, $resp[0]["temporary"]);

        // Drop space
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace2")->execute()->getResponseData();
        $this->assertNotNull($resp[0]);
        self::getDb()->createNosqlCommand()->dropSpace('myspace2')->execute()->getResponseData();
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace2")->execute()->getResponseData();
        $this->assertNull($resp[0]);

        // Temporary
        self::getDb()->createNosqlCommand()->createSpace('myspace3', [['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false], ['name' => 'name', 'type' => 'string', 'is_nullable' => false]], 'memtx', ['temporary' => true])->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace3")->execute()->getResponseData();
        $this->assertEquals("memtx", $resp[0]["engine"]);
        $this->assertEquals("myspace3", $resp[0]["name"]);
        $this->assertEquals(true, $resp[0]["temporary"]);

        // Drop space
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace3")->execute()->getResponseData();
        $this->assertNotNull($resp[0]);
        self::getDb()->createNosqlCommand()->dropSpace('myspace3')->execute()->getResponseData();
        $resp = self::getDb()->createNosqlCommand()->evaluate("return box.space.myspace3")->execute()->getResponseData();
        $this->assertNull($resp[0]);
    }

    public function testInsertUpdateUpsertReplaceDelete()
    {
        $format = [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => true],
            ['name' => 'params', 'type' => 'array'],
            ['name' => 'val', 'type' => 'scalar'],
            ['name' => 'active', 'type' => 'boolean'],
            ['name' => 'sum', 'type' => 'double'],
            ['name' => 'int', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'mp', 'type' => 'map', 'is_nullable' => true],
            ['name' => 'bin', 'type' => 'varbinary', 'is_nullable' => true],
        ];

        self::getDb()->createNosqlCommand()->createSpace('myspace', $format, 'memtx', ['id' => 1111])->execute();
        self::getDb()->createNosqlCommand()->createIndex('myspace', 'myspacepk', ['id' => 'unsigned'], true)->execute();
        $blobCol = pack("nvc*", 0x1234, 0x5678, 65, 66);
        $row = [
            1,
            "myname",
            [1, 2, 3],
            0,
            true,
            2.0,
            -11,
            ["a" => "b", "c" => "d"],
            $blobCol,
        ];
        $insert = $row;
        $insert[8] =  new Bin($row[8]);
        $resp = self::getDb()->createNosqlCommand()->insert('myspace', $insert)->execute()->getResponseData();
        $this->assertEquals($row, $resp[0]);
        $resp = self::getDb()->createNosqlCommand()->call("box.space.myspace:get", [1])->execute()->getResponseData();
        $this->assertEquals($row, $resp[0]);

        // Update
        $resp = self::getDb()->createNosqlCommand()->update('myspace', [1], Operations::add(3, 1)->andSet(1, "not my name")->andSet(6, 10)->andSubtract(5, 1.0)->andSet(4, false))->execute()->getResponseData();
        $newRow = [
            1,
            "not my name",
            [1, 2, 3],
            1,
            false,
            1.0,
            10,
            ["a" => "b", "c" => "d"],
            $blobCol,
        ];
        $this->assertEquals($newRow, $resp[0]);

        // Upsert
        self::getDb()->createNosqlCommand()->upsert('myspace', [
            1,
            "not my name",
            [1, 2, 3],
            1,
            false,
            1.0,
            10,
            ["a" => "b", "c" => "d"],
            new Bin($blobCol),
        ],
            Operations::add(6, 1)

        )->execute();

        $updatedRow = $newRow;
        $updatedRow[6] = 11;

        $resp = self::getDb()->createNosqlCommand()->call("box.space.myspace:get", [1])->execute()->getResponseData();
        $this->assertEquals($updatedRow, $resp[0]);

        self::getDb()->createNosqlCommand()->upsert('myspace', [
            2,
            "not my name",
            [1, 2, 3],
            1,
            false,
            1.0,
            10,
            ["a" => "b", "c" => "d"],
            new Bin($blobCol),
        ],
            Operations::add(6, 1)

        )->execute();

        $newRow[0] = 2;
        $resp = self::getDb()->createNosqlCommand()->call("box.space.myspace:get", [2])->execute()->getResponseData();
        $this->assertEquals($newRow, $resp[0]);

        // Replace
        $replacedRowRaw = [
            2,
            "zzzzzzzzzzz",
            [1, 1, 1, 1],
            1321,
            true,
            1.1,
            101,
            ["a" => "b"],
            $blobCol,
        ];
        $replacedRow = $replacedRowRaw;
        $replacedRow[8] = new Bin($replacedRow[8]);
        $resp = self::getDb()->createNosqlCommand()->replace('myspace', $replacedRow)->execute()->getResponseData();
        $this->assertEquals($replacedRowRaw, $resp[0]);
        $resp = self::getDb()->createNosqlCommand()->call("box.space.myspace:get", [2])->execute()->getResponseData();
        $this->assertEquals($replacedRowRaw, $resp[0]);
        $replacedRow[0] = 3;
        $resp = self::getDb()->createNosqlCommand()->replace('myspace', $replacedRow)->execute()->getResponseData();
        $replacedRowRaw[0] = 3;
        $this->assertEquals($replacedRowRaw, $resp[0]);
        $resp = self::getDb()->createNosqlCommand()->delete('myspace', [1])->execute()->getResponseData();
        $this->assertEquals($updatedRow, $resp[0]);
        $resp = self::getDb()->createNosqlCommand()->delete('myspace', [1])->execute()->getResponseData();
        $this->assertEmpty($resp);
    }

    public function testCreateDropIndex()
    {
        $format = [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => true],
            ['name' => 'active', 'type' => 'boolean'],
            ['name' => 'sum', 'type' => 'double'],
            ['name' => 'int', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'int1', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'arr', 'type' => 'array', 'is_nullable' => false],
        ];

        // pk
        self::getDb()->createNosqlCommand()->createSpace('myspace', $format)->execute();
        self::getDb()->createNosqlCommand()->createIndex('myspace', 'myspacepk', ['id' => 'unsigned'], true, 'hash')->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate('return box.space.myspace.index')->execute()->getResponseData();
        $this->assertEquals(true, $resp[0][0]['unique']);
        $this->assertEquals('HASH', strtoupper($resp[0][0]['type']));
        $this->assertEquals('myspacepk', $resp[0][0]['name']);
        $this->assertEquals([[
            "type"=> "unsigned",
            "is_nullable"=> false,
            "fieldno" => 1
        ]], $resp[0][0]['parts']);

        // tree composite
        self::getDb()->createNosqlCommand()->createIndex('myspace', 'compositeindex', ['int' => 'integer', 'int1' => 'integer'])->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate('return box.space.myspace.index.compositeindex')->execute()->getResponseData();
        $this->assertEquals(false, $resp[0]['unique']);
        $this->assertEquals('TREE', strtoupper($resp[0]['type']));
        $this->assertEquals('compositeindex', $resp[0]['name']);
        $this->assertEquals([[
            "type"=> "integer",
            "is_nullable"=> true,
            "fieldno" => 5
        ],[
            "type"=> "integer",
            "is_nullable"=> true,
            "fieldno" => 6
        ]], $resp[0]['parts']);

        // drop index
        self::getDb()->createNosqlCommand()->dropIndex('myspace', 'compositeindex')->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate('return box.space.myspace.index.compositeindex')->execute()->getResponseData();
        $this->assertNull($resp[0]);

        // rtree index
        self::getDb()->createNosqlCommand()->createIndex('myspace', 'arrindex', ['arr' => 'array'], false, 'rtree')->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate('return box.space.myspace.index.arrindex')->execute()->getResponseData();
        $this->assertEquals('RTREE', strtoupper($resp[0]['type']));
        $this->assertEquals('arrindex', $resp[0]['name']);
        $this->assertEquals([[
            "type"=> "array",
            "is_nullable"=> false,
            "fieldno" => 7
        ]], $resp[0]['parts']);

        // drop index
        self::getDb()->createNosqlCommand()->dropIndex('myspace', 'arrindex')->execute();
        $resp = self::getDb()->createNosqlCommand()->evaluate('return box.space.myspace.index.arrindex')->execute()->getResponseData();
        $this->assertNull($resp[0]);
    }

    public function testTruncateSpace()
    {
        $format = [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => false],
        ];

        self::getDb()->createNosqlCommand()->createSpace('myspace', $format)->execute();
        self::getDb()->createNosqlCommand()->createIndex('myspace', 'pk', ['id' => 'unsigned'], true)->execute();
        foreach (range(1, 10) as $val) {
            self::getDb()->createNosqlCommand()->insert('myspace', [$val, "text " . $val])->execute();
        }
        $resp = self::getDb()->createNosqlCommand()->call('box.space.myspace:count')->execute()->getResponseData();
        $this->assertEquals(10, $resp[0]);

        self::getDb()->createNosqlCommand()->truncateSpace('myspace')->execute();

        $resp = self::getDb()->createNosqlCommand()->call('box.space.myspace:count')->execute()->getResponseData();
        $this->assertEquals(0, $resp[0]);
    }

    public function testCount()
    {
        $this->makeSpaceForCmd();

        // Total count
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(8, $cmd->count()->queryScalar());
        $this->assertEquals(8, $cmd->count()->queryOne());

        // Primary key
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [1], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->count()->queryScalar());
        $this->assertEquals(1, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(0, $cmd->count()->queryScalar());
        $this->assertEquals(0, $cmd->count()->queryOne());

        // String index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 3"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(2, $cmd->count()->queryScalar());
        $this->assertEquals(2, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 22"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(4, $cmd->count()->queryScalar());
        $this->assertEquals(4, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(0, $cmd->count()->queryScalar());
        $this->assertEquals(0, $cmd->count()->queryOne());

        // Int index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [11], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(3, $cmd->count()->queryScalar());
        $this->assertEquals(3, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [15], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->count()->queryScalar());
        $this->assertEquals(1, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(0, $cmd->count()->queryScalar());
        $this->assertEquals(0, $cmd->count()->queryOne());

        // Composite index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [11], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(3, $cmd->count()->queryScalar());
        $this->assertEquals(3, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [11, 13], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(2, $cmd->count()->queryScalar());
        $this->assertEquals(2, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [12, 14], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->count()->queryScalar());
        $this->assertEquals(1, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [110], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(0, $cmd->count()->queryScalar());
        $this->assertEquals(0, $cmd->count()->queryOne());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [110, 111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(0, $cmd->count()->queryScalar());
        $this->assertEquals(0, $cmd->count()->queryOne());
    }

    public function testMax()
    {
        $this->makeSpaceForCmd();

        // Default index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(8, $cmd->max()->queryOne()[0]);

        // Primary key
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [1], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->max()->queryOne()[0]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->max()->queryOne());

        // String index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(8, $cmd->max()->queryScalar());
        $this->assertEquals("text 3", $cmd->max()->queryOne()[1]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["aaaaaa"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->max()->queryOne());


        // Int index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(8, $cmd->max()->queryScalar());
        $this->assertEquals(15, $cmd->max()->queryOne()[2]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [11111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->max()->queryOne());


        // Composite index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [11], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(11, $cmd->max()->queryOne()[2]);
        $this->assertEquals(14, $cmd->max()->queryOne()[3]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(15, $cmd->max()->queryOne()[2]);
        $this->assertEquals(0, $cmd->max()->queryOne()[3]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [12], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(12, $cmd->max()->queryOne()[2]);
        $this->assertEquals(15, $cmd->max()->queryOne()[3]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [1111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->max()->queryOne());
    }

    public function testMin()
    {
        $this->makeSpaceForCmd();

        // Default index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->min()->queryOne()[0]);

        // Primary key
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [8], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(8, $cmd->min()->queryOne()[0]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->min()->queryOne());

        // String index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->min()->queryScalar());
        $this->assertEquals("text 1", $cmd->min()->queryOne()[1]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["aaaaaa"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->min()->queryOne());


        // Int index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->min()->queryScalar());
        $this->assertEquals(11, $cmd->min()->queryOne()[2]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [11111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->min()->queryOne());


        // Composite index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [13], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(13, $cmd->min()->queryOne()[2]);
        $this->assertEquals(0, $cmd->min()->queryOne()[3]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(11, $cmd->min()->queryOne()[2]);
        $this->assertEquals(13, $cmd->min()->queryOne()[3]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [12], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(12, $cmd->min()->queryOne()[2]);
        $this->assertEquals(11, $cmd->min()->queryOne()[3]);

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [1111], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNull($cmd->min()->queryOne());
    }

    public function testRandom()
    {
        $this->makeSpaceForCmd();

        // Default index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNotEquals($cmd->random(1000)->queryOne(), $cmd->random(1)->queryOne());

        // String index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNotEquals($cmd->random(1000)->queryOne(), $cmd->random(1)->queryOne());

        // Int index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 2, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNotEquals($cmd->random(1000)->queryOne(), $cmd->random(1)->queryOne());

        // Composite index
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertNotEquals($cmd->random(1000)->queryOne(), $cmd->random(1)->queryOne());
    }

    public function testQueryOne()
    {
        $this->makeSpaceForCmd();

        // Select
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([1, 'text 1', 11, 13], $cmd->queryOne());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 1, 1, IteratorTypes::EQ));
        $this->assertEquals([2, 'text 2', 11, 13], $cmd->queryOne());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [3], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([3, 'text 22', 11, 14], $cmd->queryOne());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 22"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([3, 'text 22', 11, 14], $cmd->queryOne());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 22"], 1, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([4, 'text 22', 12, 15], $cmd->queryOne());

        // Call
        $cmd = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:format'));
        $this->assertEquals(
            ['type' => 'unsigned', 'name' => 'id', 'is_nullable' => false],
         $cmd->queryOne());

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.stat'))->queryOne();
        $this->assertArrayHasKey('total', $resp);
        $this->assertArrayHasKey('rps', $resp);

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:select'))->queryOne();
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ))->queryOne();
        $this->assertEquals($resp, $cmd);

        // Evaluate
        $cmd = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:format()'));
        $this->assertEquals(
            ['type' => 'unsigned', 'name' => 'id', 'is_nullable' => false],
            $cmd->queryOne());

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.stat()'))->queryOne();
        $this->assertArrayHasKey('total', $resp);
        $this->assertArrayHasKey('rps', $resp);

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:select()'))->queryOne();
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ))->queryOne();
        $this->assertEquals($resp, $cmd);

        // Insert
        $resp = self::getDb()->createNosqlCommand(new InsertRequest(123, [10, "what", 123, 91]))->queryOne();
        $this->assertEquals([10, "what", 123, 91], $resp);

        // Update
        $resp = self::getDb()->createNosqlCommand(new UpdateRequest(123, 0, [10], Operations::add(2, 100)->toArray()))->queryOne();
        $this->assertEquals([10, "what", 223, 91], $resp);
        $resp = self::getDb()->createNosqlCommand(new UpdateRequest(123, 0, [1011], Operations::add(2, 100)->toArray()))->queryOne();
        $this->assertNull($resp);

        // Upsert doesn't respond
        $resp = self::getDb()->createNosqlCommand(new UpsertRequest(123, [10, "what", 223, 91], Operations::add(2, 100)->toArray()))->queryOne();
        $this->assertNull($resp);

        // Replace
        $resp = self::getDb()->createNosqlCommand(new ReplaceRequest(123, [10, "what111", 1223, 0]))->queryOne();
        $this->assertEquals([10, "what111", 1223, 0], $resp);
        $resp = self::getDb()->createNosqlCommand(new ReplaceRequest(123, [101, "what111", 1223, 0]))->queryOne();
        $this->assertEquals([101, "what111", 1223, 0], $resp);
    }

    public function testQueryGet()
    {
        $this->makeSpaceForCmd();

        // Select
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [1], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([1, 'text 1', 11, 13], $cmd->queryGet());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [2], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([2, "text 2", 11, 13], $cmd->queryGet());

        // Trying to use non-unique index (it's forbidden)
        $thrown = false;
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 1"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        try {
            $cmd->queryGet();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testQueryAll()
    {
        $this->makeSpaceForCmd();

        // Select
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([
            [1, "text 1", 11, 13],
            [2, "text 2", 11, 13],
            [3, "text 22", 11, 14],
            [4, "text 22", 12, 15],
            [5, "text 22", 12, 14],
            [6, "text 3", 12, 11],
            [7, "text 22", 13, 0],
            [8, "text 3", 15, 0],
        ], $cmd->queryAll());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 22"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([
            [3, "text 22", 11, 14],
            [4, "text 22", 12, 15],
            [5, "text 22", 12, 14],
            [7, "text 22", 13, 0],
        ], $cmd->queryAll());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 22"], 0, 2, IteratorTypes::EQ));
        $this->assertEquals([
            [3, "text 22", 11, 14],
            [4, "text 22", 12, 15],
        ], $cmd->queryAll());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 22"], 2, 1, IteratorTypes::EQ));
        $this->assertEquals([
            [5, "text 22", 12, 14],
        ], $cmd->queryAll());

        // Call
        $cmd = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:format'));
        $this->assertEquals([
            ['type' => 'unsigned', 'name' => 'id', 'is_nullable' => false],
            ['type' => 'string', 'name' => 'name', 'is_nullable' => false],
            ['type' => 'integer', 'name' => 'field', 'is_nullable' => true],
            ['type' => 'integer', 'name' => 'field1', 'is_nullable' => true],
        ], $cmd->queryAll());

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.stat'))->queryAll();
        $this->arrayHasKey('DELETE', $resp);
        $this->arrayHasKey('INSERT', $resp);
        $this->arrayHasKey('UPDATE', $resp);

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:select'))->queryAll();
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ))->queryAll();
        $this->assertEquals($resp, $cmd);

        // Eval
        $cmd = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:format()'));
        $this->assertEquals(
            [
                ['type' => 'unsigned', 'name' => 'id', 'is_nullable' => false],
                ['type' => 'string', 'name' => 'name', 'is_nullable' => false],
                ['type' => 'integer', 'name' => 'field', 'is_nullable' => true],
                ['type' => 'integer', 'name' => 'field1', 'is_nullable' => true],
            ],
            $cmd->queryAll());

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.stat()'))->queryAll();
        $this->arrayHasKey('DELETE', $resp);
        $this->arrayHasKey('INSERT', $resp);
        $this->arrayHasKey('UPDATE', $resp);

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:select()'))->queryAll();
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ))->queryAll();
        $this->assertEquals($resp, $cmd);

    }

    public function testQueryColumn()
    {
        $this->makeSpaceForCmd();

        // Select
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
        ], $cmd->queryColumn());

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([
            "text 1",
            "text 2",
            "text 22",
            "text 22",
            "text 22",
            "text 3",
            "text 22",
            "text 3",
        ], $cmd->queryColumn(1));

        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals([
            11,
            11,
            11,
            12,
            12,
            12,
            13,
            15,
        ], $cmd->queryColumn(2));

        // Call
        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:select'))->queryColumn();
        $this->assertEquals([
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
        ], $resp);

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:select'))->queryColumn(1);
        $this->assertEquals([
            "text 1",
            "text 2",
            "text 22",
            "text 22",
            "text 22",
            "text 3",
            "text 22",
            "text 3",
        ], $resp);

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:select'))->queryColumn(2);
        $this->assertEquals([
            11,
            11,
            11,
            12,
            12,
            12,
            13,
            15,
        ], $resp);

        // Eval
        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:select()'))->queryColumn();
        $this->assertEquals([
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
        ], $resp);

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:select()'))->queryColumn(1);
        $this->assertEquals([
            "text 1",
            "text 2",
            "text 22",
            "text 22",
            "text 22",
            "text 3",
            "text 22",
            "text 3",
        ], $resp);

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:select()'))->queryColumn(2);
        $this->assertEquals([
            11,
            11,
            11,
            12,
            12,
            12,
            13,
            15,
        ], $resp);

        $cmd = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:format()'));
        $this->assertEmpty($cmd->queryColumn());

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.stat()'))->queryColumn();
        $this->assertEmpty($resp);
    }

    public function testQueryScalar()
    {
        $this->makeSpaceForCmd();

        // Select
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(1, $cmd->queryScalar());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [3], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(3, $cmd->queryScalar());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 3"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals(6, $cmd->queryScalar());

        // Call
        $cmd = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:format'));
        $this->assertEquals('unsigned', $cmd->queryScalar());

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.stat'))->queryScalar();
        $this->arrayHasKey('DELETE', $resp);
        $this->arrayHasKey('INSERT', $resp);
        $this->arrayHasKey('UPDATE', $resp);

        $resp = self::getDb()->createNosqlCommand(new CallRequest('box.space.myspace:select'))->queryScalar();
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ))->queryScalar();
        $this->assertEquals($resp, $cmd);

        // Evaluate
        $cmd = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:format()'));
        $this->assertEquals('unsigned', $cmd->queryScalar());

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.stat()'))->queryScalar();
        $this->arrayHasKey('DELETE', $resp);
        $this->arrayHasKey('INSERT', $resp);
        $this->arrayHasKey('UPDATE', $resp);

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return box.space.myspace:select()'))->queryScalar();
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ))->queryScalar();
        $this->assertEquals($resp, $cmd);

        $resp = self::getDb()->createNosqlCommand(new EvaluateRequest('return 123'))->queryScalar();
        $this->assertEquals(123, $resp);
    }

    public function testStringRequest()
    {
        $this->makeSpaceForCmd();

        // Select
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals('box.space[123].index[0]:select({}, {iterator=EQ})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [3], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals('box.space[123].index[0]:select({3}, {iterator=EQ})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 3"], 0, PHP_INT_MAX, IteratorTypes::EQ));
        $this->assertEquals('box.space[123].index[1]:select({\'text 3\'}, {iterator=EQ})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [], 0, PHP_INT_MAX, IteratorTypes::REQ));
        $this->assertEquals('box.space[123].index[0]:select({}, {iterator=REQ})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 0, [3], 0, PHP_INT_MAX, IteratorTypes::LE));
        $this->assertEquals('box.space[123].index[0]:select({3}, {iterator=LE})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 1, ["text 3"], 0, PHP_INT_MAX, IteratorTypes::GT));
        $this->assertEquals('box.space[123].index[1]:select({\'text 3\'}, {iterator=GT})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [11, 13], 0, PHP_INT_MAX, IteratorTypes::REQ));
        $this->assertEquals('box.space[123].index[3]:select({11, 13}, {iterator=REQ})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [12], 0, PHP_INT_MAX, IteratorTypes::LE));
        $this->assertEquals('box.space[123].index[3]:select({12}, {iterator=LE})', $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new SelectRequest(123, 3, [15, 0], 0, PHP_INT_MAX, IteratorTypes::GT));
        $this->assertEquals('box.space[123].index[3]:select({15, 0}, {iterator=GT})', $cmd->getStringRequest());


        // Insert
        $cmd = self::getDb()->createNosqlCommand(new InsertRequest(123, [10, "what", 123, 91]));
        $this->assertEquals('box.space[123]:insert({10, \'what\', 123, 91})', $cmd->getStringRequest());

        // Update
        $cmd = self::getDb()->createNosqlCommand(new UpdateRequest(123, 0, [10], Operations::add(2, 100)->andSet(1, "text")->toArray()));
        $this->assertEquals("box.space[123].index[0]:update({10}, {{'+', 2, 100}, {'=', 1, 'text'}})", $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new UpdateRequest(123, 0, [1011], Operations::add(2, 100)->andSubtract(3, 10)->toArray()));
        $this->assertEquals("box.space[123].index[0]:update({1011}, {{'+', 2, 100}, {'-', 3, 10}})", $cmd->getStringRequest());

        // Upsert
        $cmd = self::getDb()->createNosqlCommand(new UpsertRequest(123, [10, "what", 223, 91], Operations::add(2, 100)->toArray()));
        $this->assertEquals("box.space[123]:upsert({10, 'what', 223, 91}, {{'+', 2, 100}})", $cmd->getStringRequest());

        // Replace
        $cmd = self::getDb()->createNosqlCommand(new ReplaceRequest(123, [10, "what111", 1223, 0]));
        $this->assertEquals("box.space[123]:replace({10, 'what111', 1223, 0})", $cmd->getStringRequest());

        // Call
        $cmd = self::getDb()->createNosqlCommand(new CallRequest("box.space.myspace:format"));
        $this->assertEquals("CALL box.space.myspace:format()", $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new CallRequest("box.space.myspace:select", [1]));
        $this->assertEquals("CALL box.space.myspace:select({1})", $cmd->getStringRequest());

        // Eval
        $cmd = self::getDb()->createNosqlCommand(new EvaluateRequest("return box.space.myspace:format()"));
        $this->assertEquals("EVAL return box.space.myspace:format()", $cmd->getStringRequest());
        $cmd = self::getDb()->createNosqlCommand(new EvaluateRequest("return box.space.myspace:select(...)", [1]));
        $this->assertEquals("EVAL return box.space.myspace:select(...) | args: {1}", $cmd->getStringRequest());

        // Test assoc params create table
        $req = self::getDb()->createNosqlCommand()->createSpace('space', [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => true],
            ['name' => 'date', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'counter', 'type' => 'unsigned', 'is_nullable' => false],
        ], 'vinyl', ['id' => 123, 'temporary' => true])->getStringRequest();
        $this->assertEquals("CALL box.schema.create_space({'space', {id = 123, temporary = true, format = {{name = 'id', type = 'unsigned', is_nullable = false}, {name = 'name', type = 'string', is_nullable = true}, {name = 'date', type = 'string', is_nullable = false}, {name = 'counter', type = 'unsigned', is_nullable = false}}, engine = 'vinyl'}})", $req);
    }

    public function testLuaEncodingErrorsAndRights()
    {
        $this->makeSpaceForCmd();

        $this->getConnection()->createNosqlCommand()->evaluate("msgpack = require('msgpack'); msgpack.cfg{encode_invalid_as_nil = false}")->execute();

        // Expecting exception
        $thrown = false;
        $message = "Lua encoding error, you may want to add to your tarantool config:";
        $conn = new Connection(['dsn' => TestCase::getDsn(), 'handleLuaEncodingErrors' => false]);
        $conn->open();
        try {
            $conn->createNosqlCommand()->evaluate("return box.space.myspace")->execute()->getResponseData();
        } catch (\Throwable $e) {
            $thrown = true;
            $this->assertTrue(stripos($e->getMessage(), $message) !== false);
        }
        $this->assertTrue($thrown);

        // Handle errors
        $thrown = false;
        $conn = new Connection(['dsn' => TestCase::getDsn(), 'handleLuaEncodingErrors' =>true]);
        $conn->open();
        try {
            $conn->createNosqlCommand()->evaluate("return box.space.myspace")->execute()->getResponseData();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);
    }

    public function testProcessCondition()
    {
        $format = [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'field', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'field1', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'uniq', 'type' => 'integer', 'is_nullable' => false],
        ];

        $this->getConnection()->createNosqlCommand()->createSpace('myspace', $format, 'memtx', ['id' => 123])->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'pk', ['id' => 'unsigned'], true)->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'stringindex', ['name' => 'string'])->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'intindex', ['field' => 'integer'])->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'intcompositeindex', ['field' => 'integer', 'field1' => 'integer'])->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'uniq', ['uniq' => 'integer'], true)->execute();

        $this->getConnection()->createNosqlCommand()->insert('myspace', [1, "text 1", 11, 13, 111])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [2, "text 2", 11, 13, 123])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [3, "text 22", 11, 14, 124])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [4, "text 22", 12, 15, 125])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [5, "text 22", 12, 14, 126])->execute();

        // Delete by condition
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [111], 0, 1, IteratorTypes::EQ))->queryOne();
        $this->assertNotNull($tuple);
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [111], 0, 1, IteratorTypes::EQ))->queryGet();
        $this->assertNotNull($tuple);
        self::getDb()->createNosqlCommand()->delete('myspace', ['uniq' => 111])->execute();
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [111], 0, 1, IteratorTypes::EQ))->queryOne();
        $this->assertNull($tuple);
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [111], 0, 1, IteratorTypes::EQ))->queryGet();
        $this->assertNull($tuple);

        // Update by condition
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [124], 0, 1, IteratorTypes::EQ))->queryOne();
        $this->assertEquals("text 22", $tuple[1]);
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [124], 0, 1, IteratorTypes::EQ))->queryGet();
        $this->assertEquals("text 22", $tuple[1]);
        $r = self::getDb()->createNosqlCommand()->update('myspace', ['uniq' => 124], Operations::set(1, "ww"))->execute()->getResponseData();
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [124], 0, 1, IteratorTypes::EQ))->queryOne();
        $this->assertEquals("ww", $tuple[1]);
        $tuple = self::getDb()->createNosqlCommand(new SelectRequest(123, 4, [124], 0, 1, IteratorTypes::EQ))->queryGet();
        $this->assertEquals("ww", $tuple[1]);
    }
}
