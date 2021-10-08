<?php

namespace mhthnz\tarantool\tests;


use MessagePack\BufferUnpacker;
use MessagePack\Packer;
use MessagePack\PackOptions;
use MessagePack\TypeTransformer\BinTransformer;
use MessagePack\UnpackOptions;
use mhthnz\tarantool\Client;
use mhthnz\tarantool\LastInsertIDMiddleware;
use Tarantool\Client\Keys;
use Tarantool\Client\Packer\Extension\ErrorExtension;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;

class ClientTest extends TestCase
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
        $this->makeSpaceForCmd();
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropSpacesIfExist(['myspace', 'myspace1', 'myspace2', 'myspace3']);
        parent::tearDown();
    }

    /**
     * @dataProvider clientProvider
     * @param callable $func
     */
    public function testClientFactory(callable $func)
    {
        /** @var Client $client */
        $client = $func();

        $thrown = false;
        try {
            $client->ping();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);

        // Eval
        $res = $client->evaluate("return box.stat()");
        $this->assertCount(12, $res[0]);

        // Call
        $res = $client->call('box.stat');
        $this->assertCount(12, $res[0]);

        // Execute query
        $res = $client->executeQuery('SELECT * FROM "myspace"')->count();
        $this->assertEquals(8, $res);

        // Update sql
        $client->executeUpdate('UPDATE "myspace" SET "name" = \'new name\' WHERE "id" = ?', 1);
        $res = $client->executeQuery('SELECT * FROM "myspace" WHERE "id" = 1')->getData();
        $this->assertEquals('new name', $res[0][1]);

        // Prepared statement
        $stmt = $client->prepare('SELECT * FROM "myspace" WHERE "id" = ?');
        $r = $stmt->execute(1);
        $this->assertEquals(1, $r->getBodyField(Keys::DATA)[0][0]);
        $this->assertEquals("new name", $r->getBodyField(Keys::DATA)[0][1]);
        $stmt->close();

        // Spaces
        $spaces = [
            ["myspace", "assertFalse"],
            ["myspacenotexists", "assertTrue"],
            [123, "assertFalse"],
            [123123, "assertTrue"]
        ];
        foreach ($spaces as $row) {
            $thrown = false;
            try {
                if (is_int($row[0])) {
                    $client->getSpaceById($row[0]);
                } else {
                    $client->getSpace($row[0]);
                }
            } catch (\Throwable $e) {
                $thrown = true;
            }
            $this->{$row[1]}($thrown);
            $client->flushSpaces();
        }

        // Space functionality
        $space = $client->getSpace("myspace");
        $this->assertEquals("myspace", $space->getName());
        $this->assertEquals(123, $space->getID());

        // Space indexes
        $this->assertEquals('pk', $space->getIndexNameByID(0));
        $this->assertEquals('stringindex', $space->getIndexNameByID(1));
        $this->assertEquals('intindex', $space->getIndexNameByID(2));
        $this->assertEquals('intcompositeindex', $space->getIndexNameByID(3));

        // From cache
        $this->assertEquals(0, $space->getIndexIDByName('pk'));
        $this->assertEquals(1, $space->getIndexIDByName('stringindex'));
        $this->assertEquals(2, $space->getIndexIDByName('intindex'));
        $this->assertEquals(3, $space->getIndexIDByName('intcompositeindex'));

        $space->flushIndexes();

        // From db
        $this->assertEquals(0, $space->getIndexIDByName('pk'));
        $this->assertEquals(1, $space->getIndexIDByName('stringindex'));
        $this->assertEquals(2, $space->getIndexIDByName('intindex'));
        $this->assertEquals(3, $space->getIndexIDByName('intcompositeindex'));

        $thrown = false;
        try {
            $space->insert([1010, "name", 1, 1]);
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);

        $thrown = false;
        try {
            $space->replace([1010, "name", 1, 1]);
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);

        $thrown = false;
        try {
            $space->upsert([1010, "name", 1, 1], Operations::add(2, 1));
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);

        $thrown = false;
        try {
            $resp = $space->select(Criteria::key([1010]));
            $this->assertNotEmpty($resp);
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);

        $thrown = false;
        try {
            $space->delete([1010]);
            $resp = $space->select(Criteria::key([1010]));
            $this->assertEmpty($resp);
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);
    }

    /**
     * @return \Closure[][]
     */
    public function clientProvider()
    {
        return[
            [
                function() {
                    return Client::fromDsn($this->getParsedDsn('/?persistent=false'));
                }
            ],
            [
                function() {
                    return Client::fromDsn($this->getParsedDsn('/?persistent=false'), new PurePacker(
                        new Packer(PackOptions::FORCE_STR, []),
                        new BufferUnpacker('', UnpackOptions::BIGINT_AS_DEC, [])
                    ));
                }
            ],
            [
                function() {
                    return Client::fromDsn($this->getParsedDsn('/?persistent=false'), new PurePacker(
                        new Packer(PackOptions::FORCE_STR, [new BinTransformer()]),
                        new BufferUnpacker('', UnpackOptions::BIGINT_AS_DEC, [new ErrorExtension()])
                    ))->withMiddleware(new LastInsertIDMiddleware($this->getDb()));
                }
            ],
            [
                function() {
                    return Client::fromDefaults();
                }
            ],
            [
                function() {
                    return Client::fromDefaults()->withMiddleware(new LastInsertIDMiddleware($this->getDb()));
                }
            ],
            [
                function() {
                    $url = parse_url($this->getDsn());
                    $dsn = $url['scheme'] . '://' . $url['host'] . ':' . $url['port'];
                    return Client::fromOptions([
                        'uri' => $dsn,
                        'persistent' => false,
                    ]);
                }
            ],
            [
                function() {
                    $url = parse_url($this->getDsn());
                    $dsn = $url['scheme'] . '://' . $url['host'] . ':' . $url['port'];
                    return Client::fromOptions([
                        'uri' => $dsn,
                    ])->withMiddleware(new LastInsertIDMiddleware($this->getDb()));
                }
            ],
        ];
    }
}
