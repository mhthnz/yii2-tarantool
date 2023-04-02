<?php

namespace mhthnz\tarantool\tests;

use Yii;
use mhthnz\tarantool\Connection;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\log\Logger;
use mhthnz\tarantool\tests\classes\EchoMigrateController;
use mhthnz\tarantool\tests\TestCase;

/**
 * @group db
 * @group log
 */
class TargetTest extends TestCase
{

    /**
     * @var Connection
     */
    protected static $db;

    protected static $logTable = 'log';


    public function setUp(): void
    {
        parent::setUp();

        static::runConsoleAction('migrate/up', ['migrationNamespaces' => ['\mhthnz\tarantool\log\migrations'], 'interactive' => false], ['log' => [
            'targets' => [
                'db' => [
                    'class' => '\mhthnz\tarantool\log\Target',
                    'levels' => ['warning'],
                    'logSpace' => self::$logTable,
                ],
            ],
        ]]);
    }

    protected function tearDown(): void
    {
        $res = static::runConsoleAction('migrate/down', ['migrationNamespaces' => ['\mhthnz\tarantool\log\migrations'], 'interactive' => false]);
        if (static::$db) {
            static::$db->close();
        }
        parent::tearDown();

    }

    /**
     * @throws \yii\base\InvalidParamException
     * @throws \yii\db\Exception
     * @throws \yii\base\InvalidConfigException
     * @return Connection
     */
    public static function getConnection()
    {
        self::$db = self::getDbStatic();
        return static::$db;
    }

    /**
     * Tests that precision isn't lost for log timestamps.
     * @see https://github.com/yiisoft/yii2/issues/7384
     */
    public function testTimestamp()
    {
        $logger = Yii::$app->log->logger;

        $time = 1424865393.0105;

        // forming message data manually in order to set time
        $messsageData = [
            'test',
            Logger::LEVEL_WARNING,
            'test',
            $time,
            [],
        ];

        $logger->messages[] = $messsageData;
        $logger->flush(true);

        $loggedTime = self::$db->createNosqlQuery()->from(self::$logTable)->where(['=', 'idx_log_category', 'test'])->column(3);
        static::assertEquals($time, $loggedTime[0]);
    }

    public function testFewMessages()
    {
        Yii::$app->log->targets['db']->levels = ['warning', 'error'];
        Yii::$app->log->logger->log("Error happened", Logger::LEVEL_ERROR, 'application');
        Yii::$app->log->logger->log("Warn happened", Logger::LEVEL_WARNING, 'application');
        Yii::$app->log->logger->log("Smth happened", Logger::LEVEL_INFO, 'application');

        Yii::$app->log->logger->flush(true);
        $msg = ArrayHelper::index(self::$db->createNosqlCommand()->call("box.space." . self::$logTable . ":select")->queryAll(), 5);
        $this->assertArrayHasKey("Error happened", $msg);
        $this->assertArrayHasKey("Warn happened", $msg);
        $this->assertArrayNotHasKey("Smth happened", $msg);

        self::$db->createCommand()->truncateTable(self::$logTable)->execute();

        Yii::$app->log->targets['db']->levels = ['warning', 'error', 'info'];
        Yii::$app->log->targets['db']->categories[] = 'a/b/c';
        Yii::$app->log->logger->log("Error happened", Logger::LEVEL_ERROR, 'a/b/c');
        Yii::$app->log->logger->log("Warn happened", Logger::LEVEL_WARNING, 'a/b/c');
        Yii::$app->log->logger->log("Smth happened", Logger::LEVEL_INFO, 'a/b/c');
        Yii::$app->log->logger->flush(true);
        $msg = ArrayHelper::index(self::$db->createNosqlCommand()->call("box.space." . self::$logTable . ":select")->queryAll(), 5);
        $this->assertArrayHasKey("Error happened", $msg);
        $this->assertArrayHasKey("Warn happened", $msg);
        $this->assertArrayHasKey("Smth happened", $msg);
    }

}