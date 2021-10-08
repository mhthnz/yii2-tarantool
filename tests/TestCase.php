<?php

namespace mhthnz\tarantool\tests;



use mhthnz\tarantool\Connection;
use mhthnz\tarantool\Schema;
use Yii;
use yii\base\NotSupportedException;
use yii\base\UnknownMethodException;
use yii\di\Container;
use yii\helpers\ArrayHelper;
use yii\test\FixtureTrait;
use yii\test\BaseActiveFixture;

/**
 * This is the base class for all unit tests.
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Connection
     */
    protected $conn;


    use FixtureTrait;

    /**
     * @return array|false|string
     */
    protected function getDsn()
    {
        // Docker support
        // Possible dsn: tcp://user:pass@127.0.0.1/?connect_timeout=5.0&max_retries=3
        if ($dsn = getenv('TARANTOOL_DSN')) {
            return $dsn;
        }
        return 'tcp://guest@localhost:3301';
    }

    /**
     * @return Connection
     * @throws \Exception
     */
    public function getConnection()
    {
        if (!$this->conn) {
            $this->conn = new Connection(['dsn' => $this->getDsn()]);
            $this->conn->open();
        }
        return $this->conn;
    }

    /**
     * Compatability between phpunit versions.
     * @param $p
     * @param $v
     */
    public function checkRegex($p, $v)
    {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($p, $v);
        } else {
            $this->assertRegExp($p, $v);
        }
    }

    protected function invokeMethod($object, $method, $args = [], $revoke = true)
    {
        $reflection = new \ReflectionObject($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        $result = $method->invokeArgs($object, $args);
        if ($revoke) {
            $method->setAccessible(false);
        }

        return $result;
    }

    /**
     * This method is called before the first test of this test class is run.
     * Attempts to load vendor autoloader.
     * @throws \yii\base\NotSupportedException
     */
    public static function setUpBeforeClass(): void
    {
        $vendorDir = VENDOR_PATH;
        $vendorAutoload = $vendorDir . '/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once($vendorAutoload);
        } else {
            throw new NotSupportedException("Vendor autoload file '{$vendorAutoload}' is missing.");
        }
        require_once($vendorDir . '/yiisoft/yii2/Yii.php');
        Yii::setAlias('@vendor', $vendorDir);
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->unloadFixtures();
        $this->destroyApplication();
        parent::tearDown();
    }

    /**
     * Destroys the application instance created by [[mockApplication]].
     */
    protected function destroyApplication()
    {
        if (\Yii::$app) {
            if (\Yii::$app->has('session', true)) {
                \Yii::$app->session->close();
            }
            if (\Yii::$app->has('db', true)) {
                Yii::$app->db->close();
            }
            if (\Yii::$app->has('tarantool', true)) {
                Yii::$app->tarantool->close();
            }
        }
        Yii::$app = null;
        Yii::$container = new Container();
    }

    /**
     * Populates Yii::$app with a new application
     * The application will be destroyed on tearDown() automatically.
     * @param array $config The application configuration, if needed
     * @param string $appClass name of the application class to create
     */
    protected function mockApplication($config = [], $appClass = '\yii\console\Application')
    {
        new $appClass(ArrayHelper::merge([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => VENDOR_PATH,
            'runtimePath' => dirname(__DIR__) . '/runtime',
        ], $config));
    }

    public function createStructure()
    {
        $this->createTable('animal', [
            'id' => 'integer PRIMARY KEY AUTOINCREMENT',
            'type' => 'VARCHAR(255) NOT NULL'
        ]);
        $this->createTable('constraints', [
            'id' => 'integer PRIMARY KEY',
            'field1' => 'varchar(255)'
        ]);
        $this->createTable('profile', [
            'id' => 'int PRIMARY KEY AUTOINCREMENT',
            'description' => 'varchar(128) NOT NULL',
        ]);
        $this->createTable('customer', [
            'id' => 'int PRIMARY KEY AUTOINCREMENT',
            'email' => 'varchar(128) NOT NULL',
            'name' => 'varchar(128)',
            'address' => 'text',
            'status' => 'int DEFAULT 0',
            'bool_status' => 'boolean DEFAULT FALSE',
            'profile_id' => 'int',
            'CONSTRAINT "FK_customer_profile_id" FOREIGN KEY ("profile_id") REFERENCES "profile" ("id")'
        ]);

        $this->createTable('type', [
            'int_col' => 'integer PRIMARY KEY NOT NULL',
            'int_col2' => 'integer DEFAULT 1',
            'int_col3' => 'unsigned DEFAULT 1',
            'bigint_col' => 'unsigned',
            'char_col' => 'varchar(100) NOT NULL',
            'char_col2' => 'varchar(100) DEFAULT \'something\'',
            'char_col3' => 'text',
            'float_col' => 'double NOT NULL',
            'float_col2' => 'double DEFAULT 1.23',
            'blob_col' => 'varbinary',
            'numeric_col' => 'double DEFAULT 33.22',
            'bool_col' => 'boolean NOT NULL',
            'bool_col2' => 'boolean DEFAULT true',
        ]);

        $this->createTable('order', [
            'id' => 'integer PRIMARY KEY AUTOINCREMENT',
            'customer_id' => 'integer NOT NULL',
            'created_at' => 'integer NOT NULL',
            'total' => 'double NOT NULL',
            'CONSTRAINT "FK_order_customer_id" FOREIGN KEY ("customer_id") REFERENCES "customer" ("id") ON DELETE CASCADE',
        ]);

        $this->createTable('order_with_null_fk', [
            'id' => 'integer PRIMARY KEY AUTOINCREMENT',
            'customer_id' => 'integer',
            'created_at' => 'integer NOT NULL',
            'total' => 'double NOT NULL',
        ]);

        $this->createTable('category', [
            'id' => Schema::TYPE_PK,
            'name' => 'varchar(128) NOT NULL',
        ]);

        $this->createTable('item', [
            'id' => Schema::TYPE_PK,
            'name' => 'varchar(128) NOT NULL',
            'category_id' => 'integer NOT NULL',
            'CONSTRAINT "FK_item_category_id" FOREIGN KEY ("category_id") REFERENCES "category" ("id") ON DELETE CASCADE',
        ]);


        $this->createTable('order_item', [
            'order_id' => 'integer NOT NULL',
            'item_id' => 'integer NOT NULL',
            'quantity' => 'integer NOT NULL',
            'subtotal' => 'double NOT NULL',
            'PRIMARY KEY ("order_id", "item_id")',
            //'CONSTRAINT "FK_order_item_item_id" KEY ("item_id")',
            'CONSTRAINT "FK_order_item_order_id" FOREIGN KEY ("order_id") REFERENCES "order" ("id") ON DELETE CASCADE',
            'CONSTRAINT "FK_order_item_item_id" FOREIGN KEY ("item_id") REFERENCES "item" ("id") ON DELETE CASCADE',
        ]);

        $this->createTable('order_item_with_null_fk', [
            'id' => Schema::TYPE_PK,
            'order_id' => 'integer',
            'item_id' => 'integer',
            'quantity' => 'integer NOT NULL',
            'subtotal' => 'double NOT NULL',
        ]);

        $this->createTable('composite_fk', [
            'id' => Schema::TYPE_PK,
            'order_id' => 'integer not null',
            'item_id' => 'integer not null',
            'CONSTRAINT "FK_composite_fk_order_item" FOREIGN KEY ("order_id","item_id") REFERENCES "order_item" ("order_id","item_id") ON DELETE CASCADE'
        ]);

        $this->createTable('negative_default_values', [
            'id' => Schema::TYPE_PK,
            'int_col' => 'integer default -123',
            'float_col' => 'double default -12345.6789',
        ]);

        $this->createTable('T_constraints_1', [
            'C_id' => Schema::TYPE_PK,
            'C_not_null' => 'INT NOT NULL',
            'C_check' => 'VARCHAR(255) NULL CHECK ("C_check" <> \'\')',
            'C_unique' => 'INT NOT NULL',
            'C_default' => "INT NOT NULL DEFAULT 0",
            'CONSTRAINT "CN_unique" UNIQUE ("C_unique")'
        ]);

        $this->createTable('T_constraints_2', [
            'C_id_1' => 'INT NOT NULL',
            'C_id_2' => 'INT NOT NULL',
            'C_index_1' => 'INT NULL',
            'C_index_2_1' => 'INT NULL',
            'C_index_2_2' => 'INT NULL',
            'CONSTRAINT "CN_pk" PRIMARY KEY ("C_id_1", "C_id_2")',
            'CONSTRAINT "CN_constraints_2_multi" UNIQUE ("C_index_2_1", "C_index_2_2")',
        ]);

        $this->getConnection()->createCommand()->createIndex('CN_constraints_2_single', 'T_constraints_2', 'C_index_1')->execute();

        $this->createTable('T_constraints_3', [
            'id' => 'int',
            'C_id' => 'INT NOT NULL',
            'C_fk_id_1' => 'INT NULL',
            'C_fk_id_2' => 'INT NULL',
            'CONSTRAINT "pkkkk" PRIMARY KEY ("id")',
            'CONSTRAINT "CN_constraints_3" FOREIGN KEY ("C_fk_id_1", "C_fk_id_2") REFERENCES "T_constraints_2" ("C_id_1", "C_id_2") ON DELETE CASCADE ON UPDATE CASCADE',
        ]);
        $this->getConnection()->createCommand()->dropPrimaryKey('pkkkk', 'T_constraints_3')->execute();

        $this->createTable('T_constraints_4', [
            'C_id' => Schema::TYPE_PK,
            'C_col_1' => 'INT NULL',
            'C_col_2' => 'INT NULL',
            'CONSTRAINT "CN_constraints_4" UNIQUE ("C_col_1", "C_col_2")',
        ]);

        $this->getDb()->createCommand('INSERT INTO "category" ("name") VALUES (\'Books\');')->execute();
        $this->getDb()->createCommand('INSERT INTO "category" ("name") VALUES (\'Movies\');')->execute();
        $this->getDb()->createCommand('INSERT INTO "profile" ("description") VALUES (\'profile customer 1\');')->execute();
        $this->getDb()->createCommand('INSERT INTO "profile" ("description") VALUES (\'profile customer 3\');')->execute();
        $this->getDb()->createCommand('INSERT INTO "customer" ("email", "name", "address", "status", "profile_id") VALUES (\'user1@example.com\', \'user1\', \'address1\', 1, 1);')->execute();
        $this->getDb()->createCommand('INSERT INTO "customer" ("email", "name", "address", "status") VALUES (\'user2@example.com\', \'user2\', \'address2\', 1);')->execute();
        $this->getDb()->createCommand('INSERT INTO "customer" ("email", "name", "address", "status", "profile_id") VALUES (\'user3@example.com\', \'user3\', \'address3\', 2, 2);')->execute();

        $this->getDb()->createCommand('INSERT INTO "order" ("customer_id", "created_at", "total") VALUES (1, 1325282384, 110.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order" ("customer_id", "created_at", "total") VALUES (2, 1325334482, 33.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order" ("customer_id", "created_at", "total") VALUES (2, 1325502201, 40.0);')->execute();

        $this->getDb()->createCommand('INSERT INTO "order_with_null_fk" ("customer_id", "created_at", "total") VALUES (1, 1325282384, 110.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_with_null_fk" ("customer_id", "created_at", "total") VALUES (2, 1325334482, 33.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_with_null_fk" ("customer_id", "created_at", "total") VALUES (2, 1325502201, 40.0);')->execute();

        $this->getDb()->createCommand('INSERT INTO "animal" ("type") VALUES (\'yiiunit\data\ar\Cat\');')->execute();
        $this->getDb()->createCommand('INSERT INTO "animal" ("type") VALUES (\'yiiunit\data\ar\Dog\');')->execute();

        $this->getDb()->createCommand('INSERT INTO "item" ("name", "category_id") VALUES (\'Agile Web Application Development with Yii1.1 and PHP5\', 1);')->execute();
        $this->getDb()->createCommand('INSERT INTO "item" ("name", "category_id") VALUES (\'Yii 1.1 Application Development Cookbook\', 1);')->execute();
        $this->getDb()->createCommand('INSERT INTO "item" ("name", "category_id") VALUES (\'Ice Age\', 2);')->execute();
        $this->getDb()->createCommand('INSERT INTO "item" ("name", "category_id") VALUES (\'Toy Story\', 2);')->execute();
        $this->getDb()->createCommand('INSERT INTO "item" ("name", "category_id") VALUES (\'Cars\', 2);')->execute();

        $this->getDb()->createCommand('INSERT INTO "order_item" ("order_id", "item_id", "quantity", "subtotal") VALUES (1, 1, 1, 30.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item" ("order_id", "item_id", "quantity", "subtotal") VALUES (1, 2, 2, 40.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item" ("order_id", "item_id", "quantity", "subtotal") VALUES (2, 4, 1, 10.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item" ("order_id", "item_id", "quantity", "subtotal") VALUES (2, 5, 1, 15.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item" ("order_id", "item_id", "quantity", "subtotal") VALUES (2, 3, 1, 8.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item" ("order_id", "item_id", "quantity", "subtotal") VALUES (3, 2, 1, 40.0);')->execute();

        $this->getDb()->createCommand('INSERT INTO "order_item_with_null_fk" ("order_id", "item_id", "quantity", "subtotal") VALUES (1, 1, 1, 30.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item_with_null_fk" ("order_id", "item_id", "quantity", "subtotal") VALUES (1, 2, 2, 40.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item_with_null_fk" ("order_id", "item_id", "quantity", "subtotal") VALUES (2, 4, 1, 10.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item_with_null_fk" ("order_id", "item_id", "quantity", "subtotal") VALUES (2, 5, 1, 15.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item_with_null_fk" ("order_id", "item_id", "quantity", "subtotal") VALUES (2, 5, 1, 8.0);')->execute();
        $this->getDb()->createCommand('INSERT INTO "order_item_with_null_fk" ("order_id", "item_id", "quantity", "subtotal") VALUES (3, 2, 1, 40.0);')->execute();

    }

    public function makeSpaceForCmd()
    {
        $format = [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'field', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'field1', 'type' => 'integer', 'is_nullable' => true],
        ];

        $this->getConnection()->createNosqlCommand()->createSpace('myspace', $format, 'memtx', ['id' => 123])->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'pk', ['id' => 'unsigned'], true)->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'stringindex', ['name' => 'string'])->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'intindex', ['field' => 'integer'])->execute();
        $this->getConnection()->createNosqlCommand()->createIndex('myspace', 'intcompositeindex', ['field' => 'integer', 'field1' => 'integer'])->execute();

        $this->getConnection()->createNosqlCommand()->insert('myspace', [1, "text 1", 11, 13])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [2, "text 2", 11, 13])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [3, "text 22", 11, 14])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [4, "text 22", 12, 15])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [5, "text 22", 12, 14])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [6, "text 3", 12, 11])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [7, "text 22", 13, 0])->execute();
        $this->getConnection()->createNosqlCommand()->insert('myspace', [8, "text 3", 15, 0])->execute();

        return [
            [1, "text 1", 11, 13],
            [2, "text 2", 11, 13],
            [3, "text 22", 11, 14],
            [4, "text 22", 12, 15],
            [5, "text 22", 12, 14],
            [6, "text 3", 12, 11],
            [7, "text 22", 13, 0],
            [8, "text 3", 15, 0],
        ];
    }

    /**
     * @param string $space
     * @return bool
     */
    public function spaceExists(string $space): bool
    {
        try {
            $this->getConnection()->client->getSpace($space);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array $spaces
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    public function dropSpacesIfExist(array $spaces)
    {
        foreach ($spaces as $space) {
            if ($this->spaceExists($space)) {
                $this->getConnection()->createNosqlCommand()->dropSpace($space)->execute();
            }
        }
    }
}
