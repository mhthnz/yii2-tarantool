<?php

namespace mhthnz\tarantool\tests;


use mhthnz\tarantool\cache\expirationd\Cache;
use mhthnz\tarantool\Migration;
use mhthnz\tarantool\tests\classes\EchoMigrateController;
use mhthnz\tarantool\tests\classes\SetupMigrateTrait;
use yii\db\Query;

/**
 * Class for testing file cache backend.
 * @group db
 * @group caching
 */
class CacheExpirationdTest extends CacheTestCase
{
    use DbTrait;
    use SetupMigrateTrait;


    private $_cacheInstance;
    private $_connection;

    /**
     * @return array applied migration entries
     */
    protected function getMigrationHistory()
    {
        $query = new Query();
        return $query->from('migration')->all(self::getDb());
    }

    protected function setUp(): void
    {
        $this->mockApplication(['components' => [
            'tarantool' => [
                'class' => \mhthnz\tarantool\Connection::class,
                'dsn' => TestCase::getDsn(),
            ]
        ]]);
        $this->migrateControllerClass = EchoMigrateController::class;
        $this->migrationBaseClass = '\\'.Migration::class;
        $config = [
            'migrationPath' => [],
            'migrationNamespaces' => ['\mhthnz\tarantool\tests\classes\migrations']
        ];

        $this->runMigrateControllerAction('up', [1], $config);
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $config = [
            'migrationPath' => [],
            'migrationNamespaces' => ['\mhthnz\tarantool\tests\classes\migrations']
        ];

        $this->runMigrateControllerAction('down', [1], $config);
    }

    /**
     * @return Cache
     */
    protected function getCacheInstance()
    {
        if ($this->_cacheInstance === null) {
            $this->_cacheInstance = new Cache(['db' => $this->getConnection()]);
        }
        $this->_cacheInstance->db->client->flushSpaces();
        return $this->_cacheInstance;
    }

    public function testExpire()
    {
        $cache = $this->getCacheInstance();

        $this->assertTrue($cache->set('expire_test', 'expire_test', 2));
        $this->assertEquals('expire_test', $cache->get('expire_test'));
        sleep(2);
        $this->assertFalse($cache->get('expire_test'));
    }

    public function testExpireAdd()
    {
        $cache = $this->getCacheInstance();
        $this->assertTrue($cache->add('expire_testa', 'expire_testa', 2));
        $this->assertEquals('expire_testa', $cache->get('expire_testa'));
        sleep(2);
        $this->assertFalse($cache->get('expire_testa'));
    }

    public function testSynchronousSetWithTheSameKey()
    {
        $KEY = 'sync-test-key';
        $VALUE = 'sync-test-value';
        $NEWVALUE = '123123123';
        $cache = $this->getCacheInstance();

        $this->assertTrue($cache->set($KEY, $VALUE, 60));
        $this->assertTrue($cache->set($KEY, $NEWVALUE, 60));

        $this->assertEquals($NEWVALUE, $cache->get($KEY));
    }
}