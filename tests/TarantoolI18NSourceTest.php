<?php

namespace mhthnz\tarantool\tests;


use mhthnz\tarantool\i18n\MessageSource;
use mhthnz\tarantool\tests\classes\SetupMigrateTrait;
use Yii;
use yii\base\Event;
use yii\db\Connection;
use mhthnz\tarantool\tests\classes\EchoMigrateController;

/**
 * @group i18n
 * @group db
 * @group mysql
 * @author Dmitry Naumenko <d.naumenko.a@gmail.com>
 * @since 2.0.7
 */
class TarantoolI18NSourceTest extends I18N
{
    use DbTrait;
    use SetupMigrateTrait;

    protected static $db;

    protected function setI18N()
    {
        $this->i18n = new \yii\i18n\I18N([
            'translations' => [
                'test' => [
                    'class' => $this->getMessageSourceClass(),
                    'db' => $this->getDb(),
                ],
            ],
        ]);
    }

    private function getMessageSourceClass()
    {
        return MessageSource::class;
    }

    public static function setUpBeforeClass(): void
    {
        self::$db = self::getDbStatic();
        parent::setUpBeforeClass();


        static::runConsoleAction('migrate/up', ['migrationNamespaces' => ['\mhthnz\tarantool\i18n\migrations'], 'interactive' => false]);

        static::$db->createCommand()->truncateTable('source_message');
        static::$db->createCommand()->batchInsert('source_message', ['category', 'message'], [
            ['test', 'Hello world!'], // id = 1
            ['test', 'The dog runs fast.'], // id = 2
            ['test', 'His speed is about {n} km/h.'], // id = 3
            ['test', 'His name is {name} and his speed is about {n, number} km/h.'], // id = 4
            ['test', 'There {n, plural, =0{no cats} =1{one cat} other{are # cats}} on lying on the sofa!'], // id = 5
        ])->execute();

        static::$db->createCommand()->insert('message', ['id' => 1, 'language' => 'de', 'translation' => 'Hallo Welt!'])->execute();
        static::$db->createCommand()->insert('message', ['id' => 2, 'language' => 'de-DE', 'translation' => 'Der Hund rennt schnell.'])->execute();
        static::$db->createCommand()->insert('message', ['id' => 2, 'language' => 'en-US', 'translation' => 'The dog runs fast (en-US).'])->execute();
        static::$db->createCommand()->insert('message', ['id' => 2, 'language' => 'ru', 'translation' => 'Собака бегает быстро.'])->execute();
        static::$db->createCommand()->insert('message', ['id' => 3, 'language' => 'de-DE', 'translation' => 'Seine Geschwindigkeit beträgt {n} km/h.'])->execute();
        static::$db->createCommand()->insert('message', ['id' => 4, 'language' => 'de-DE', 'translation' => 'Er heißt {name} und ist {n, number} km/h schnell.'])->execute();
        static::$db->createCommand()->insert('message', ['id' => 5, 'language' => 'ru', 'translation' => 'На диване {n, plural, =0{нет кошек} =1{лежит одна кошка} one{лежит # кошка} few{лежит # кошки} many{лежит # кошек} other{лежит # кошки}}!'])->execute();
    }

    public static function tearDownAfterClass(): void
    {
        static::runConsoleAction('migrate/down', ['migrationNamespaces' => ['\mhthnz\tarantool\i18n\migrations'], 'interactive' => false]);
        if (static::$db) {
            static::$db->close();
        }
        Yii::$app = null;
        parent::tearDownAfterClass();
    }

    /**
     * @return \yii\db\Connection
     * @throws \yii\db\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\InvalidParamException
     */
    public static function getConnection()
    {
        if (static::$db == null) {
            $db = new Connection();
            $db->dsn = static::$database['dsn'];
            if (isset(static::$database['username'])) {
                $db->username = static::$database['username'];
                $db->password = static::$database['password'];
            }
            if (isset(static::$database['attributes'])) {
                $db->attributes = static::$database['attributes'];
            }
            if (!$db->isActive) {
                $db->open();
            }
            static::$db = $db;
        }

        return static::$db;
    }

    public function testMissingTranslationEvent()
    {
        $this->assertEquals('Hallo Welt!', $this->i18n->translate('test', 'Hello world!', [], 'de-DE'));
        $this->assertEquals('Missing translation message.', $this->i18n->translate('test', 'Missing translation message.', [], 'de-DE'));
        $this->assertEquals('Hallo Welt!', $this->i18n->translate('test', 'Hello world!', [], 'de-DE'));

        Event::on(MessageSource::className(), MessageSource::EVENT_MISSING_TRANSLATION, function ($event) {});
        $this->assertEquals('Hallo Welt!', $this->i18n->translate('test', 'Hello world!', [], 'de-DE'));
        $this->assertEquals('Missing translation message.', $this->i18n->translate('test', 'Missing translation message.', [], 'de-DE'));
        $this->assertEquals('Hallo Welt!', $this->i18n->translate('test', 'Hello world!', [], 'de-DE'));
        Event::off(MessageSource::className(), MessageSource::EVENT_MISSING_TRANSLATION);

        Event::on(MessageSource::className(), MessageSource::EVENT_MISSING_TRANSLATION, function ($event) {
            if ($event->message == 'New missing translation message.') {
                $event->translatedMessage = 'TRANSLATION MISSING HERE!';
            }
        });
        $this->assertEquals('Hallo Welt!', $this->i18n->translate('test', 'Hello world!', [], 'de-DE'));
        $this->assertEquals('Another missing translation message.', $this->i18n->translate('test', 'Another missing translation message.', [], 'de-DE'));
        $this->assertEquals('Missing translation message.', $this->i18n->translate('test', 'Missing translation message.', [], 'de-DE'));
        $this->assertEquals('TRANSLATION MISSING HERE!', $this->i18n->translate('test', 'New missing translation message.', [], 'de-DE'));
        $this->assertEquals('Hallo Welt!', $this->i18n->translate('test', 'Hello world!', [], 'de-DE'));
        Event::off(MessageSource::className(), MessageSource::EVENT_MISSING_TRANSLATION);
    }
}