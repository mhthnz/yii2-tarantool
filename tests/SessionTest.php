<?php

namespace mhthnz\tarantool\tests;

use mhthnz\tarantool\session\Session;

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

	/**
	 * @depends testReadWrite
	 * @runInSeparateProcess
	 */
	public function testGarbageCollection()
	{
		$session = new Session();

		$session->writeSession('new', 'new data');
		$session->writeSession('expire', 'expire data');

		(new $session->spaceClass())::updateAll(['expire' => time() - 10000], ['id' => 'expire']);

		$session->gcSession(0);

		$this->assertEquals('', $session->readSession('expire'));
		$this->assertEquals('new data', $session->readSession('new'));
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
		$oldTimeout = ini_get('session.gc_maxlifetime');

		$session = new Session([
			'timeout' => 300,
		]);

		$this->assertSame(300, $session->timeout);
		$session->close();

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
