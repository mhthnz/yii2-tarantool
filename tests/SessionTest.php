<?php

namespace mhthnz\tarantool\tests;

use mhthnz\tarantool\session\Session;
use yii\db\Query;

class SessionTest extends TestCase
{
	use DbTrait;
	use SessionTestTrait;

	protected function setUp(): void
	{
		parent::setUp();

		$this->mockApplication([
			'components' => [
				'tarantool' => [
					'class' => \mhthnz\tarantool\Connection::class,
					'dsn' => $this->getDsn(),
				],
			]
		]);
		$this->dropTableSession();
		$this->createTableSession();
	}

	protected function tearDown(): void
	{
		$this->dropTableSession();
		parent::tearDown();
	}

	// Tests :

	/**
	 * @runInSeparateProcess
	 */
	public function testReadWrite()
	{
		$session = new Session();

		$session->writeSession('test', 'session data');
		$this->assertEquals('session data', $session->readSession('test'));
		$session->destroySession('test');
		$this->assertEquals('', $session->readSession('test'));
	}

    public function testInitializeWithConfig()
    {
        // should produce no exceptions
        $session = new Session([
            'useCookies' => true,
        ]);

        $session->writeSession('test', 'session data');
        $this->assertEquals('session data', $session->readSession('test'));
        $session->destroySession('test');
        $this->assertEquals('', $session->readSession('test'));
    }

	/**
	 * @depends testReadWrite
	 * @runInSeparateProcess
	 */
	public function testGarbageCollection()
	{
		$session = new Session();

		$session->writeSession('new', 'new data');
		$session->writeSession('expire', 'expire data');

		$this->getDb()->createCommand()->update($session->sessionTable, ['expire' => time() - 10000], ['id' => 'expire'])->execute();

		$session->gcSession(0);

		$this->assertEquals('', $session->readSession('expire'));
		$this->assertEquals('new data', $session->readSession('new'));
	}

    /**
     * @depends testReadWrite
     */
    public function testWriteCustomField()
    {
        $session = new Session();

        $session->writeCallback = function ($session) {
            return ['data' => 'changed by callback data'];
        };

        $session->writeSession('test', 'session data');

        $this->assertSame('changed by callback data', $session->readSession('test'));
    }

    /**
     * @runInSeparateProcess
     */
    public function testWriteCustomFieldWithUserId()
    {
        $this->mockApplication([
            'components' => [
                'tarantool' => [
                    'class' => \mhthnz\tarantool\Connection::class,
                    'dsn' => $this->getDsn(),
                ],
            ]
        ]);
        $this->dropSpacesIfExist(['session_user']);
        $this->createTable('session_user', [
            'id' => $this->string()->notNull(),
            'expire' => $this->integer()->notNull(),
            'data' => $this->binary()->notNull(),
            'user_id' => $this->integer(),
            'CONSTRAINT "pk-session" PRIMARY KEY ("id")',
        ]);

        $session = new Session(['sessionTable' => 'session_user', 'rawSpaceName' => 'session_user']);
        $session->open();

        $session->set('user_id', 12345);

        $session->writeCallback = function ($session) {
            return ['user_id' => $session['user_id']];
        };

        // here used to be error, fixed issue #9438
        $session->close();
        $session->readCallback = function ($fields) {
            return ['user_id_new' => $fields['user_id']];
        };
        $this->assertEquals(12345, (new Query())->select('user_id')->from($session->sessionTable)->scalar($this->getDb()));

        // reopen & read session from DB
        $session->open();
        $this->assertEquals(12345, $session->get('user_id_new'));
        $loadedUserId = empty($session['user_id']) ? null : $session['user_id'];
        $this->assertSame($loadedUserId, 12345);
        $session->close();

        $this->dropSpacesIfExist(['session_user']);
    }

	protected function buildObjectForSerialization()
	{
		$object = new \stdClass();
		$object->nullValue = null;
		$object->floatValue = pi();
		$object->textValue = str_repeat('QweåßƒТест', 200);
		$object->array = [null, 'ab' => 'cd'];
		$object->binary = base64_decode('5qS2UUcXWH7rjAmvhqGJTDNkYWFiOGMzNTFlMzNmMWIyMDhmOWIwYzAwYTVmOTFhM2E5MDg5YjViYzViN2RlOGZlNjllYWMxMDA0YmQxM2RQ3ZC0in5ahjNcehNB/oP/NtOWB0u3Skm67HWGwGt9MA==');
		$object->with_null_byte = 'hey!' . "\0" . 'y"ûƒ^äjw¾bðúl5êù-Ö=W¿Š±¬GP¥Œy÷&ø';

		if (version_compare(PHP_VERSION, '5.5.0', '<')) {
			unset($object->binary);
			// Binary data can not be inserted on PHP <5.5
		}

		return $object;
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testSerializedObjectSaving()
	{
		$session = new Session();

		$object = $this->buildObjectForSerialization();
		$serializedObject = serialize($object);
		$session->writeSession('test', $serializedObject);
		$this->assertSame($serializedObject, $session->readSession('test'));

		$object->foo = 'modification checked';
		$serializedObject = serialize($object);
		$session->writeSession('test', $serializedObject);
		$this->assertSame($serializedObject, $session->readSession('test'));
	}

	/**
	 * @runInSeparateProcess
	 */
    public function testInstantiate()
    {
        $this->mockApplication([
            'components' => [
                'tarantool' => [
                    'class' => \mhthnz\tarantool\Connection::class,
                    'dsn' => $this->getDsn(),
                ],
            ]
        ]);
        $oldTimeout = ini_get('session.gc_maxlifetime');
        // unset Yii::$app->db to make sure that all queries are made against sessionDb
        \Yii::$app->set('sessionDb', \Yii::$app->tarantool);
        \Yii::$app->set('tarantool', null);

        $session = new Session([
            'timeout' => 300,
            'db' => 'sessionDb',
        ]);

        $this->assertSame(\Yii::$app->sessionDb, $session->db);
        $this->assertSame(300, $session->timeout);
        $session->close();

        \Yii::$app->set('db', \Yii::$app->sessionDb);
        \Yii::$app->set('sessionDb', null);
        ini_set('session.gc_maxlifetime', $oldTimeout);
    }

	/**
	 * @runInSeparateProcess
	 */
	public function testInitUseStrictMode()
	{
		$this->initStrictModeTest(Session::class);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testUseStrictMode()
	{
		$this->useStrictModeTest(Session::class);
	}
}
