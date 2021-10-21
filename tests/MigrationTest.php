<?php

namespace mhthnz\tarantool\tests;

use mhthnz\tarantool\console\MigrateController;
use mhthnz\tarantool\Migration;
use mhthnz\tarantool\QueryBuilder;
use mhthnz\tarantool\tests\classes\AnyCaseValue;
use mhthnz\tarantool\tests\classes\AnyValue;
use mhthnz\tarantool\tests\classes\Customer;
use mhthnz\tarantool\Connection;
use mhthnz\tarantool\tests\classes\EchoMigrateController;
use mhthnz\tarantool\tests\classes\MigrateControllerTestTrait;
use Tarantool\Client\Exception\RequestFailed;
use yii\caching\ArrayCache;
use yii\caching\FileCache;
use yii\console\ExitCode;
use yii\db\CheckConstraint;
use yii\db\ColumnSchema;
use yii\db\Constraint;
use yii\db\Expression;
use yii\db\ForeignKeyConstraint;
use yii\db\IndexConstraint;
use mhthnz\tarantool\Schema;
use yii\db\Query;
use mhthnz\tarantool\TableSchema;

class MigrationTest extends TestCase
{
    use DbTrait;
    use MigrateControllerTestTrait;

    private $_first = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication(['components' => [
            'tarantool' => [
                'class' => \mhthnz\tarantool\Connection::class,
                'dsn' => $this->getDsn(),
            ]
        ]]);
        $this->migrateControllerClass = EchoMigrateController::class;
        $this->migrationBaseClass = '\\'.Migration::class;
        if ($this->_first) {
            $this->dropConstraints();
            $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
            $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
            $this->dropTables();

            $this->_first = false;
        }

        $this->setUpMigrationPath();
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->tearDownMigrationPath();
        parent::tearDown();
    }

    /**
     * @return array applied migration entries
     */
    protected function getMigrationHistory()
    {
        $query = new Query();
        return $query->from('migration')->all($this->getDb());
    }

    /**
     * Check that the column definition has valid database syntax.
     * @param string $definition
     */
    protected function checkDbSyntax($key, $definition, $value = null)
    {
        $this->getDb()->createCommand('drop table if exists "tbl"')->execute();
        $isPk = false;
        if (stripos($key, 'pk') !== false) {
            $isPk = true;
        }
        $columns = [
            'column' => $definition
        ];
        if (!$isPk) {
            $columns['id'] = Schema::TYPE_PK;
        }
        $isThrown = false;
        $message = null;
        try {
            $this->getDb()->createCommand()->createTable('tbl', $columns)->execute();
            $this->getDb()->createCommand()->insert('tbl', ['column' => $value])->execute();
            $val = (new Query)->select("column")->from('tbl')->scalar($this->getDb());
            $this->assertEquals($value, $val);
        } catch (\Throwable $e) {
            $isThrown = true;
            $message = $e->getMessage();
        }

        $this->assertFalse($isThrown, $key . ' | ' . $definition . ' | ' . $message);
    }


    public function testCreateLongNamedMigration()
    {
        $this->setOutputCallback(function ($output) {
            return null;
        });

        $migrationName = str_repeat('a', 180);

        $this->expectException('yii\console\Exception');
        $this->expectExceptionMessage('The migration name is too long.');

        $controller = $this->createMigrateController([]);
        $params[0] = $migrationName;
        $controller->run('create', $params);
    }


    /**
     * @see https://github.com/yiisoft/yii2/issues/12980
     */
    public function testGetMigrationHistory()
    {
        $controllerConfig = [
            'migrationPath' => null,
            'migrationNamespaces' => [$this->migrationNamespace],
        ];
        $this->runMigrateControllerAction('history', [], $controllerConfig);

        $controller = $this->createMigrateController($controllerConfig);
        $controller->db = $this->getDb();

        $this->getDb()->createCommand()
            ->batchInsert(
                'migration',
                ['version', 'apply_time'],
                [
                    ['app\migrations\M140506102106One', 10],
                    ['app\migrations\M160909083544Two', 10],
                    ['app\modules\foo\migrations\M161018124749Three', 10],
                    ['app\migrations\M160930135248Four', 20],
                    ['app\modules\foo\migrations\M161025123028Five', 20],
                    ['app\migrations\M161110133341Six', 20],
                ]
            )
            ->execute();

        $rows = $this->invokeMethod($controller, 'getMigrationHistory', [10]);

        $this->assertSame(
            [
                'app\migrations\M161110133341Six',
                'app\modules\foo\migrations\M161025123028Five',
                'app\migrations\M160930135248Four',
                'app\modules\foo\migrations\M161018124749Three',
                'app\migrations\M160909083544Two',
                'app\migrations\M140506102106One',
            ],
            array_keys($rows)
        );

        $rows = $this->invokeMethod($controller, 'getMigrationHistory', [4]);

        $this->assertSame(
            [
                'app\migrations\M161110133341Six',
                'app\modules\foo\migrations\M161025123028Five',
                'app\migrations\M160930135248Four',
                'app\modules\foo\migrations\M161018124749Three',
            ],
            array_keys($rows)
        );
    }

    public function testColumnSchemaBuilder()
    {
        // String
        $strings = [];
        $strings['string'] = [(new Migration())->string(), 'string'];
        $strings['stringCollation'] = [(new Migration())->string()->collation('unicode'), 'string COLLATE "unicode"'];
        $strings['stringNull'] = [(new Migration())->string()->null(), 'string NULL DEFAULT NULL'];
        $strings['stringNotNull'] = [(new Migration())->string()->notNull(), 'string NOT NULL'];
        $strings['stringUnique'] = [(new Migration())->string()->unique(), 'string UNIQUE'];
        $strings['stringCheck'] = [(new Migration())->string()->check('"column" like \'%test%\''), 'string CHECK ("column" like \'%test%\')'];
        $strings['stringPk'] = [(new Migration())->string()->addPrimaryKey(), 'string PRIMARY KEY'];
        $strings['stringNullCollation'] = [(new Migration())->string()->null()->collation('unicode'), 'string NULL DEFAULT NULL COLLATE "unicode"'];
        $strings['stringNotNullCollation'] = [(new Migration())->string()->notNull()->collation('unicode'), 'string NOT NULL COLLATE "unicode"'];
        $strings['stringNullCollationCheck'] = [(new Migration())->string()->null()->collation('unicode')->check('"column" like \'%test%\''), 'string NULL DEFAULT NULL COLLATE "unicode" CHECK ("column" like \'%test%\')'];
        $strings['stringNotNullCollationCheck'] = [(new Migration())->string()->notNull()->collation('unicode')->check('"column" like \'%test%\''), 'string NOT NULL COLLATE "unicode" CHECK ("column" like \'%test%\')'];
        $strings['stringNotNullCollationPk'] = [(new Migration())->string()->notNull()->collation('unicode')->addPrimaryKey(), 'string PRIMARY KEY NOT NULL COLLATE "unicode"'];
        $strings['stringNotNullCollationCheckPk'] = [(new Migration())->string()->notNull()->collation('unicode')->check('"column" like \'%test%\'')->addPrimaryKey(), 'string PRIMARY KEY NOT NULL COLLATE "unicode" CHECK ("column" like \'%test%\')'];
        $strings['stringNotNullCollationUnique'] = [(new Migration())->string()->notNull()->collation('unicode')->unique(), 'string UNIQUE NOT NULL COLLATE "unicode"'];
        $strings['stringNotNullCollationCheckUnique'] = [(new Migration())->string()->notNull()->collation('unicode')->check('"column" like \'%test%\'')->unique(), 'string UNIQUE NOT NULL COLLATE "unicode" CHECK ("column" like \'%test%\')'];
        $strings['stringDefault'] = [(new Migration())->string()->defaultValue('atesta'), 'string DEFAULT \'atesta\''];
        $strings['stringCollationDefault'] = [(new Migration())->string()->collation('unicode')->defaultValue('atesta'), 'string DEFAULT \'atesta\' COLLATE "unicode"'];
        $strings['stringNullDefault'] = [(new Migration())->string()->null()->defaultValue('atesta'), 'string NULL DEFAULT \'atesta\''];
        $strings['stringNotNullDefault'] = [(new Migration())->string()->notNull()->defaultValue('atesta'), 'string NOT NULL DEFAULT \'atesta\''];
        $strings['stringUniqueDefault'] = [(new Migration())->string()->unique()->defaultValue('atesta'), 'string UNIQUE DEFAULT \'atesta\''];
        $strings['stringCheckDefault'] = [(new Migration())->string()->check('"column" like \'%test%\'')->defaultValue('atesta'), 'string DEFAULT \'atesta\' CHECK ("column" like \'%test%\')'];
        $strings['stringPkDefault'] = [(new Migration())->string()->addPrimaryKey()->defaultValue('atesta'), 'string PRIMARY KEY DEFAULT \'atesta\''];
        $strings['stringNullCollationDefault'] = [(new Migration())->string()->null()->collation('unicode')->defaultValue('atesta'), 'string NULL DEFAULT \'atesta\' COLLATE "unicode"'];
        $strings['stringNotNullCollationDefault'] = [(new Migration())->string()->notNull()->collation('unicode')->defaultValue('atesta'), 'string NOT NULL DEFAULT \'atesta\' COLLATE "unicode"'];
        $strings['stringNullCollationCheckDefault'] = [(new Migration())->string()->null()->collation('unicode')->defaultValue('atesta')->check('"column" like \'%test%\''), 'string NULL DEFAULT \'atesta\' COLLATE "unicode" CHECK ("column" like \'%test%\')'];
        $strings['stringNotNullCollationCheckDefault'] = [(new Migration())->string()->notNull()->collation('unicode')->defaultValue('atesta')->check('"column" like \'%test%\''), 'string NOT NULL DEFAULT \'atesta\' COLLATE "unicode" CHECK ("column" like \'%test%\')'];
        $strings['stringNotNullCollationPkDefault'] = [(new Migration())->string()->notNull()->collation('unicode')->defaultValue('atesta')->addPrimaryKey(), 'string PRIMARY KEY NOT NULL DEFAULT \'atesta\' COLLATE "unicode"'];
        $strings['stringNotNullCollationCheckPkDefault'] = [(new Migration())->string()->notNull()->collation('unicode')->defaultValue('atesta')->check('"column" like \'%test%\'')->addPrimaryKey(), 'string PRIMARY KEY NOT NULL DEFAULT \'atesta\' COLLATE "unicode" CHECK ("column" like \'%test%\')'];
        $strings['stringNotNullCollationUniqueDefault'] = [(new Migration())->string()->notNull()->collation('unicode')->defaultValue('atesta')->unique(), 'string UNIQUE NOT NULL DEFAULT \'atesta\' COLLATE "unicode"'];
        $strings['stringNotNullCollationCheckUniqueDefault'] = [(new Migration())->string()->notNull()->collation('unicode')->defaultValue('atesta')->check('"column" like \'%test%\'')->unique(), 'string UNIQUE NOT NULL DEFAULT \'atesta\' COLLATE "unicode" CHECK ("column" like \'%test%\')'];

        foreach ($strings as $key => $string) {
            $this->assertEquals($string[1], (string) $string[0]);
            $this->checkDbSyntax($key, (string) $string[0], 'atesta');
        }

        // Boolean
        $booleans = [];
        $booleans['boolean'] = [(new Migration())->boolean(), 'boolean'];
        $booleans['booleanNull'] = [(new Migration())->boolean()->null(), 'boolean NULL DEFAULT NULL'];
        $booleans['booleanNotNull'] = [(new Migration())->boolean()->notNull(), 'boolean NOT NULL'];
        $booleans['booleanUnique'] = [(new Migration())->boolean()->unique(), 'boolean UNIQUE'];
        $booleans['booleanCheck'] = [(new Migration())->boolean()->check('"column" = true'), 'boolean CHECK ("column" = true)'];
        $booleans['booleanPk'] = [(new Migration())->boolean()->addPrimaryKey(), 'boolean PRIMARY KEY'];
        $booleans['booleanCheckPk'] = [(new Migration())->boolean()->check('"column" = true')->addPrimaryKey(), 'boolean PRIMARY KEY CHECK ("column" = true)'];
        $booleans['booleanNotNullCheckUnique'] = [(new Migration())->boolean()->notNull()->check('"column" = true')->unique(), 'boolean UNIQUE NOT NULL CHECK ("column" = true)'];
        $booleans['booleanDefault'] = [(new Migration())->boolean()->defaultValue(false), 'boolean DEFAULT false'];
        $booleans['booleanNullDefault'] = [(new Migration())->boolean()->null()->defaultValue(false), 'boolean NULL DEFAULT false'];
        $booleans['booleanNotNullDefault'] = [(new Migration())->boolean()->notNull()->defaultValue(false), 'boolean NOT NULL DEFAULT false'];
        $booleans['booleanUniqueDefault'] = [(new Migration())->boolean()->unique()->defaultValue(false), 'boolean UNIQUE DEFAULT false'];
        $booleans['booleanCheckDefault'] = [(new Migration())->boolean()->defaultValue(false)->check('"column" = true'), 'boolean DEFAULT false CHECK ("column" = true)'];
        $booleans['booleanPkDefault'] = [(new Migration())->boolean()->addPrimaryKey()->defaultValue(false), 'boolean PRIMARY KEY DEFAULT false'];
        $booleans['booleanCheckPkDefault'] = [(new Migration())->boolean()->defaultValue(false)->check('"column" = true')->addPrimaryKey(), 'boolean PRIMARY KEY DEFAULT false CHECK ("column" = true)'];
        $booleans['booleanNotNullCheckUniqueDefault'] = [(new Migration())->boolean()->defaultValue(false)->notNull()->check('"column" = true')->unique(), 'boolean UNIQUE NOT NULL DEFAULT false CHECK ("column" = true)'];

        foreach ($booleans as $key => $b) {
            $this->assertEquals($b[1], (string) $b[0], "$key is invalid, {$b[1]} is not equal " . $b[0]);
            $this->checkDbSyntax($key, (string) $b[0], true);
        }

        // Integer
        $integers = [];
        $integers['integer'] = [(new Migration())->integer(), 'integer'];
        $integers['integerNull'] = [(new Migration())->integer()->null(), 'integer NULL DEFAULT NULL'];
        $integers['integerNotNull'] = [(new Migration())->integer()->notNull(), 'integer NOT NULL'];
        $integers['integerUnique'] = [(new Migration())->integer()->unique(), 'integer UNIQUE'];
        $integers['integerCheck'] = [(new Migration())->integer()->check('"column" != 111'), 'integer CHECK ("column" != 111)'];
        $integers['integerCheckNull'] = [(new Migration())->integer()->null()->check('"column" != 111'), 'integer NULL DEFAULT NULL CHECK ("column" != 111)'];
        $integers['integerCheckNotNull'] = [(new Migration())->integer()->notNull()->check('"column" != 111'), 'integer NOT NULL CHECK ("column" != 111)'];
        $integers['integerCheckPk'] = [(new Migration())->integer()->addPrimaryKey()->check('"column" != 111'), 'integer PRIMARY KEY CHECK ("column" != 111)'];
        $integers['integerCheckPkAutoincrement'] = [(new Migration())->primaryKey()->check('"column" != 111'), 'integer CHECK ("column" != 111) PRIMARY KEY AUTOINCREMENT'];
        $integers['integerCheckUnique'] = [(new Migration())->integer()->unique()->check('"column" != 111'), 'integer UNIQUE CHECK ("column" != 111)'];
        $integers['integerPk'] = [(new Migration())->integer()->addPrimaryKey(), 'integer PRIMARY KEY'];
        $integers['integerPkAutoincrement'] = [(new Migration())->primaryKey(), 'integer PRIMARY KEY AUTOINCREMENT'];
        $integers['integerUnsigned'] = [(new Migration())->integer()->unsigned(), 'unsigned'];
        $integers['integerUnsignedNull'] = [(new Migration())->integer()->unsigned()->null(), 'unsigned NULL DEFAULT NULL'];
        $integers['integerUnsignedNotNull'] = [(new Migration())->integer()->unsigned()->notNull(), 'unsigned NOT NULL'];
        $integers['integerUnsignedPk'] = [(new Migration())->integer()->unsigned()->addPrimaryKey(), 'unsigned PRIMARY KEY'];
        $integers['integerUnsignedPkAutoincrement'] = [(new Migration())->primaryKey()->unsigned(), 'unsigned PRIMARY KEY AUTOINCREMENT'];
        $integers['integerUnsignedUnique'] = [(new Migration())->integer()->unsigned()->unique(), 'unsigned UNIQUE'];
        $integers['integerUnsignedCheck'] = [(new Migration())->integer()->unsigned()->check('"column" != 111'), 'unsigned CHECK ("column" != 111)'];
        $integers['integerUnsignedCheckPk'] = [(new Migration())->integer()->unsigned()->addPrimaryKey()->check('"column" != 111'), 'unsigned PRIMARY KEY CHECK ("column" != 111)'];
        $integers['integerUnsignedCheckPkAutoincrement'] = [(new Migration())->primaryKey()->unsigned()->check('"column" != 111'), 'unsigned CHECK ("column" != 111) PRIMARY KEY AUTOINCREMENT'];
        $integers['integerUnsignedCheckUnique'] = [(new Migration())->integer()->unsigned()->unique()->check('"column" != 111'), 'unsigned UNIQUE CHECK ("column" != 111)'];
        $integers['integerDefault'] = [(new Migration())->integer()->defaultValue(123), 'integer DEFAULT 123'];
        $integers['integerNullDefault'] = [(new Migration())->integer()->null()->defaultValue(123), 'integer NULL DEFAULT 123'];
        $integers['integerNotNullDefault'] = [(new Migration())->integer()->notNull()->defaultValue(123), 'integer NOT NULL DEFAULT 123'];
        $integers['integerUniqueDefault'] = [(new Migration())->integer()->unique()->defaultValue(123), 'integer UNIQUE DEFAULT 123'];
        $integers['integerCheckDefault'] = [(new Migration())->integer()->defaultValue(123)->check('"column" != 111'), 'integer DEFAULT 123 CHECK ("column" != 111)'];
        $integers['integerCheckNullDefault'] = [(new Migration())->integer()->defaultValue(123)->null()->check('"column" != 111'), 'integer NULL DEFAULT 123 CHECK ("column" != 111)'];
        $integers['integerCheckNotNullDefault'] = [(new Migration())->integer()->defaultValue(123)->notNull()->check('"column" != 111'), 'integer NOT NULL DEFAULT 123 CHECK ("column" != 111)'];
        $integers['integerCheckPkDefault'] = [(new Migration())->integer()->defaultValue(123)->addPrimaryKey()->check('"column" != 111'), 'integer PRIMARY KEY DEFAULT 123 CHECK ("column" != 111)'];
        $integers['integerCheckUniqueDefault'] = [(new Migration())->integer()->unique()->defaultValue(123)->check('"column" != 111'), 'integer UNIQUE DEFAULT 123 CHECK ("column" != 111)'];
        $integers['integerPkDefault'] = [(new Migration())->integer()->addPrimaryKey()->defaultValue(123), 'integer PRIMARY KEY DEFAULT 123'];
        $integers['integerUnsignedDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123), 'unsigned DEFAULT 123'];
        $integers['integerUnsignedNullDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123)->null(), 'unsigned NULL DEFAULT 123'];
        $integers['integerUnsignedNotNullDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123)->notNull(), 'unsigned NOT NULL DEFAULT 123'];
        $integers['integerUnsignedPkDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123)->addPrimaryKey(), 'unsigned PRIMARY KEY DEFAULT 123'];
        $integers['integerUnsignedUniqueDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123)->unique(), 'unsigned UNIQUE DEFAULT 123'];
        $integers['integerUnsignedCheckDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123)->check('"column" != 111'), 'unsigned DEFAULT 123 CHECK ("column" != 111)'];
        $integers['integerUnsignedCheckPkDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123)->addPrimaryKey()->check('"column" != 111'), 'unsigned PRIMARY KEY DEFAULT 123 CHECK ("column" != 111)'];
        $integers['integerUnsignedCheckUniqueDefault'] = [(new Migration())->integer()->unsigned()->defaultValue(123)->unique()->check('"column" != 111'), 'unsigned UNIQUE DEFAULT 123 CHECK ("column" != 111)'];

        foreach ($integers as $key => $b) {
            $qb = new QueryBuilder($this->getDb(), []);
            $act = $qb->getColumnType((string) $b[0]);
            $this->assertEquals($b[1], trim($act), "$key is invalid, {$b[1]} is not equal " . $b[0]);
            $this->checkDbSyntax($key, $act, 1);
        }


        // Binary
        $binaries = [];
        $binaries['binary'] = [(new Migration())->binary(), 'binary'];
        $binaries['binaryPk'] = [(new Migration())->binary()->addPrimaryKey(), 'binary PRIMARY KEY'];
        $binaries['binaryNotNull'] = [(new Migration())->binary()->notNull(), 'binary NOT NULL'];
        $binaries['binaryNull'] = [(new Migration())->binary()->null(), 'binary NULL DEFAULT NULL'];
        $binaries['binaryUnique'] = [(new Migration())->binary()->unique(), 'binary UNIQUE'];
        $binaries['binaryCheck'] = [(new Migration())->binary()->check('length("column") > 1'), 'binary CHECK (length("column") > 1)'];
        $binaries['binaryCheckNull'] = [(new Migration())->binary()->null()->check('length("column") > 1'), 'binary NULL DEFAULT NULL CHECK (length("column") > 1)'];
        $binaries['binaryCheckNotNull'] = [(new Migration())->binary()->notNull()->check('length("column") > 1'), 'binary NOT NULL CHECK (length("column") > 1)'];
        $binaries['binaryCheckPk'] = [(new Migration())->binary()->addPrimaryKey()->check('length("column") > 1'), 'binary PRIMARY KEY CHECK (length("column") > 1)'];
        $binaries['binaryCheckUnique'] = [(new Migration())->binary()->unique()->check('length("column") > 1'), 'binary UNIQUE CHECK (length("column") > 1)'];

        foreach ($binaries as $key => $b) {
            $this->assertEquals($b[1], (string) $b[0], "$key is invalid, {$b[1]} is not equal " . $b[0]);
            $this->checkDbSyntax($key, (string) $b[0], pack("nvc*", 0x1234, 0x5678, 65, 66));
        }


        // Double (float is an alias for double type)
        $doubles = [];
        $doubles['doubleFloat'] = [(new Migration())->float(), 'double'];
        $doubles['double'] = [(new Migration())->double(), 'double'];
        $doubles['doublePk'] = [(new Migration())->double()->addPrimaryKey(), 'double PRIMARY KEY'];
        $doubles['doubleNotNull'] = [(new Migration())->double()->notNull(), 'double NOT NULL'];
        $doubles['doubleNull'] = [(new Migration())->double()->null(), 'double NULL DEFAULT NULL'];
        $doubles['doubleUnique'] = [(new Migration())->double()->unique(), 'double UNIQUE'];
        $doubles['doubleCheck'] = [(new Migration())->double()->check('"column" > 1.0'), 'double CHECK ("column" > 1.0)'];
        $doubles['doubleCheckNull'] = [(new Migration())->double()->null()->check('"column" > 1.0'), 'double NULL DEFAULT NULL CHECK ("column" > 1.0)'];
        $doubles['doubleCheckNotNull'] = [(new Migration())->double()->notNull()->check('"column" > 1.0'), 'double NOT NULL CHECK ("column" > 1.0)'];
        $doubles['doubleCheckPk'] = [(new Migration())->double()->addPrimaryKey()->check('"column" > 1.0'), 'double PRIMARY KEY CHECK ("column" > 1.0)'];
        $doubles['doubleCheckUnique'] = [(new Migration())->double()->unique()->check('"column" > 1.0'), 'double UNIQUE CHECK ("column" > 1.0)'];
        $doubles['doubleFloatDefault'] = [(new Migration())->float()->defaultValue(1.1), 'double DEFAULT 1.1'];
        $doubles['doubleDefault'] = [(new Migration())->double()->defaultValue(1.1), 'double DEFAULT 1.1'];
        $doubles['doubleNotNullDefault'] = [(new Migration())->double()->defaultValue(1.1)->notNull(), 'double NOT NULL DEFAULT 1.1'];
        $doubles['doubleNullDefault'] = [(new Migration())->double()->defaultValue(1.1)->null(), 'double NULL DEFAULT 1.1'];
        $doubles['doubleUniqueDefault'] = [(new Migration())->double()->defaultValue(1.1)->unique(), 'double UNIQUE DEFAULT 1.1'];
        $doubles['doubleCheckDefault'] = [(new Migration())->double()->defaultValue(1.1)->check('"column" > 1.0'), 'double DEFAULT 1.1 CHECK ("column" > 1.0)'];
        $doubles['doubleCheckNullDefault'] = [(new Migration())->double()->defaultValue(1.1)->null()->check('"column" > 1.0'), 'double NULL DEFAULT 1.1 CHECK ("column" > 1.0)'];
        $doubles['doubleCheckNotNullDefault'] = [(new Migration())->double()->defaultValue(1.1)->notNull()->check('"column" > 1.0'), 'double NOT NULL DEFAULT 1.1 CHECK ("column" > 1.0)'];
        $doubles['doubleCheckPkDefault'] = [(new Migration())->double()->defaultValue(1.1)->addPrimaryKey()->check('"column" > 1.0'), 'double PRIMARY KEY DEFAULT 1.1 CHECK ("column" > 1.0)'];
        $doubles['doubleCheckUniqueDefault'] = [(new Migration())->double()->defaultValue(1.1)->unique()->check('"column" > 1.0'), 'double UNIQUE DEFAULT 1.1 CHECK ("column" > 1.0)'];

        foreach ($doubles as $key => $b) {
            $qb = new QueryBuilder($this->getDb(), []);
            $act = $qb->getColumnType((string) $b[0]);
            $this->assertEquals($b[1], $act, "$key is invalid, {$b[1]} is not equal " . $b[0]);
            $this->checkDbSyntax($key, $act, 1.1);
        }
    }

    public function testEngine()
    {

        $this->createMigrationWithContent("CreateMemtxTable",
            <<<CODE
        \$this->createMemtxTable('memtx_table', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);
CODE
            , <<<CODE
        \$this->dropTable('memtx_table');
CODE
        );

        $this->createMigrationWithContent("CreateVinylTable",
            <<<CODE
        \$this->createVinylTable('vinyl_table', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);
CODE
            , <<<CODE
        \$this->dropTable('vinyl_table');
CODE
        );

        $this->runMigrateControllerAction('up');
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $memtxSchema = $this->getDb()->schema->getTableSchema('memtx_table');
        $vinylSchema = $this->getDb()->schema->getTableSchema('vinyl_table');
        $this->assertNotNull($memtxSchema);
        $this->assertNotNull($vinylSchema);
        $this->assertEquals(TableSchema::ENGINE_MEMTX, $memtxSchema->engine);
        $this->assertEquals(TableSchema::ENGINE_VINYL, $vinylSchema->engine);

        $this->runMigrateControllerAction('down', [2]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $memtxSchema = $this->getDb()->schema->getTableSchema('memtx_table', true);
        $vinylSchema = $this->getDb()->schema->getTableSchema('vinyl_table', true);
        $this->assertNull($memtxSchema);
        $this->assertNull($vinylSchema);
    }


    public function testInsertUpdateDeleteTruncateBatchInsert()
    {
        $this->createMigrationWithContent("CreateTable",
            <<<CODE
        \$this->createTable('table', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);
        \$this->createTable('table1', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);
        \$this->createTable('table2', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);
CODE
            , <<<CODE
        \$this->dropTable('table');
        \$this->dropTable('table1');
        \$this->dropTable('table2');
CODE
            , gmdate("ymd_Hi11"));

        // Insert - delete
        $this->createMigrationWithContent("InsertDeleteTable",
            <<<CODE
        \$this->insert('{{table}}', ['name' => 'myname', 'time' => 123123]);
        \$this->insert('{{table}}', ['name' => 'myname1', 'time' => 7776767]);
        \$this->insert('{{table}}', ['name' => 'myname123', 'time' => 754235]);
CODE
            , <<<CODE
        \$this->delete('table');
CODE
            , gmdate("ymd_Hi21"));

        // Insert - truncate
        $this->createMigrationWithContent("InsertTruncateTable",
            <<<CODE
        \$this->insert('{{table1}}', ['name' => 'myname', 'time' => 123123]);
        \$this->insert('{{table1}}', ['name' => 'myname1', 'time' => 7776767]);
CODE
            , <<<CODE
        \$this->truncateTable('table1');
CODE
            , gmdate("ymd_Hi31"));

        // Update
        $this->createMigrationWithContent("UpdateTable",
            <<<CODE
        \$this->update('{{table1}}', ['time' => 123123123], ['time' => 123123]);
CODE
            , <<<CODE
        \$this->update('{{table1}}', ['time' => 123123], ['time' => 123123123]);
CODE
            , gmdate("ymd_Hi41"));

        // Batch insert
        $this->createMigrationWithContent("InsertBatchTable",
            <<<CODE
        \$this->batchInsert('{{table2}}', ['name', 'time'], [['string1', 1231231111], ['string12', 0], ['string123', 9], ['string111', 9999]]);
CODE
            , <<<CODE
        \$this->delete('{{table2}}');
CODE
            , gmdate("ymd_Hi51"));

        $this->runMigrateControllerAction('up', [2]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertNotNull($this->getDb()->schema->getTableSchema('table', true));
        $this->assertNotNull($this->getDb()->schema->getTableSchema('table1', true));
        $this->assertNotNull($this->getDb()->schema->getTableSchema('table2', true));
        $this->assertEquals(3, $this->getDb()->createCommand('SELECT count(*) FROM "table"')->queryScalar());
        $this->assertEquals(0, $this->getDb()->createCommand('SELECT count(*) FROM "table1"')->queryScalar());
        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertEquals(0, $this->getDb()->createCommand('SELECT count(*) FROM "table"')->queryScalar());
        $this->assertEquals(0, $this->getDb()->createCommand('SELECT count(*) FROM "table1"')->queryScalar());
        $this->runMigrateControllerAction('up', [2]);
        $this->assertEquals(3, $this->getDb()->createCommand('SELECT count(*) FROM "table"')->queryScalar());
        $this->assertEquals(2, $this->getDb()->createCommand('SELECT count(*) FROM "table1"')->queryScalar());

        // Update
        $this->assertEquals(1, $this->getDb()->createCommand('SELECT count(*) FROM "table1" as "t" where "t"."time" = :time', [':time' => 123123])->queryScalar());
        $this->runMigrateControllerAction('up', [1]);
        $this->assertEquals(0, $this->getDb()->createCommand( 'SELECT count(*) FROM "table1" as "t" where "t"."time" = :time', [':time' => 123123])->queryScalar());
        $this->assertEquals(1, $this->getDb()->createCommand( 'SELECT count(*) FROM "table1" as "t" where "t"."time" = :time', [':time' => 123123123])->queryScalar());
        $this->runMigrateControllerAction('down', [1]);
        $this->assertEquals(1, $this->getDb()->createCommand( 'SELECT count(*) FROM "table1" as "t" where "t"."time" = :time', [':time' => 123123])->queryScalar());
        $this->assertEquals(0, $this->getDb()->createCommand( 'SELECT count(*) FROM "table1" as "t" where "t"."time" = :time', [':time' => 123123123])->queryScalar());

        // Batch insert
        $this->assertEquals(0, $this->getDb()->createCommand('SELECT count(*) FROM "table2" ')->queryScalar());
        $this->runMigrateControllerAction('up', [2]);
        $this->assertEquals(4, $this->getDb()->createCommand('SELECT count(*) FROM "table2" ')->queryScalar());
        $this->runMigrateControllerAction('down', [1]);
        $this->assertEquals(0, $this->getDb()->createCommand('SELECT count(*) FROM "table2" ')->queryScalar());
        $this->runMigrateControllerAction('down', [4]);
        $this->assertNull($this->getDb()->schema->getTableSchema('table', true));
        $this->assertNull($this->getDb()->schema->getTableSchema('table1', true));
        $this->assertNull($this->getDb()->schema->getTableSchema('table2', true));
    }

    public function testRenameDropTable()
    {
        $this->createMigrationWithContent("CreateTable",
            <<<CODE
        \$this->createTable('table', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);
CODE
            , <<<CODE
        \$this->dropTable('table');
CODE
            , gmdate("ymd_Hi11"));

        $this->createMigrationWithContent("RenameTable",
            <<<CODE
        \$this->renameTable('table', 'newtable');
CODE
            , <<<CODE
       \$this->renameTable('newtable', 'table');
CODE
            , gmdate("ymd_Hi12"));

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertNotNull($this->getDb()->schema->getTableSchema('table', true));
        $this->assertNull($this->getDb()->schema->getTableSchema('newtable', true));
        $this->runMigrateControllerAction('up', [1]);
        $this->assertNotNull($this->getDb()->schema->getTableSchema( 'newtable', true));
        $this->assertNull($this->getDb()->schema->getTableSchema( 'table',true));
        $this->runMigrateControllerAction('down', [1]);
        $this->assertNotNull($this->getDb()->schema->getTableSchema('table', true));
        $this->assertNull($this->getDb()->schema->getTableSchema('newtable', true));
        $this->runMigrateControllerAction('down', [1]);
        $this->assertNull($this->getDb()->schema->getTableSchema('table', true));
        $this->assertNull($this->getDb()->schema->getTableSchema('newtable', true));
    }

    public function testAddColumn()
    {
        if ($this->getDb()->version < 2.7) {
            $this->markTestSkipped("Tarantool version less than 2.7 doesn't support adding column");
        }

        $this->createMigrationWithContent("CreateTable",
            <<<CODE
        \$this->createTable('table', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);
CODE
            , <<<CODE
        \$this->dropTable('table');
CODE
            , gmdate("ymd_Hi01"));

        $this->createMigrationWithContent("AddColumnTable",
            <<<CODE
        \$this->addColumn('table', 'newcolumn', \$this->binary()->null());
CODE
            , <<<CODE
        // Tarantool doesn't support dropping columns
CODE
            , gmdate("ymd_Hi02"));

        $check = function($columns, $schema) {
            $this->assertNotNull($schema);
            $this->assertEquals($columns, count($schema->columns));
            $this->assertEquals('id', $schema->columns['id']->name);
            $this->assertEquals('integer', $schema->columns['id']->type);
            $this->assertEquals(true, $schema->columns['id']->autoIncrement);
            $this->assertEquals(true, $schema->columns['id']->isPrimaryKey);
            $this->assertEquals(false, $schema->columns['id']->allowNull);

            $this->assertEquals('name', $schema->columns['name']->name);
            $this->assertEquals('string', $schema->columns['name']->type);
            $this->assertEquals(false, $schema->columns['name']->autoIncrement);
            $this->assertEquals(false, $schema->columns['name']->isPrimaryKey);
            $this->assertEquals(true, $schema->columns['name']->allowNull);

            $this->assertEquals('time', $schema->columns['time']->name);
            $this->assertEquals('integer', $schema->columns['time']->type);
            $this->assertEquals(false, $schema->columns['time']->autoIncrement);
            $this->assertEquals(false, $schema->columns['time']->isPrimaryKey);
            $this->assertEquals(false, $schema->columns['time']->allowNull);
        };

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $schema = $this->getDb()->schema->getTableSchema('table', true);
        $check(3, $schema);

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $schema = $this->getDb()->schema->getTableSchema('table', true);
        $check(4, $schema);
        $this->assertEquals('newcolumn', $schema->columns['newcolumn']->name);
        $this->assertEquals('binary', $schema->columns['newcolumn']->type);
        $this->assertEquals(false, $schema->columns['newcolumn']->autoIncrement);
        $this->assertEquals(false, $schema->columns['newcolumn']->isPrimaryKey);
        $this->assertEquals(true, $schema->columns['newcolumn']->allowNull);
    }

    public function testAddDropPrimaryKey()
    {
        $this->createMigrationWithContent("CreateTable",
            <<<CODE
        \$this->createTable('table0', [
            'id' => \$this->integer()->addPrimaryKey(),
            'name' => \$this->string()->collation('unicode'),
            'time' => \$this->integer()->notNull()
        ]);

CODE
            , <<<CODE
        \$this->dropTable('table0');
CODE
            , gmdate("ymd_Hi91"));


        $o = $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $pk = $this->getDb()->schema->getTablePrimaryKey('table0', true);
        $this->assertEquals(['id'], $pk->columnNames);
        $pkName = $this->getDb()->schema->getTablePrimaryKey('table0', true)->name;

        $this->createMigrationWithContent("DropPrimaryKey",
            <<<CODE
        \$this->dropPrimaryKey('{$pkName}', 'table0');

CODE
            , <<<CODE
        \$this->addPrimaryKey('table-pk', 'table0', 'id');
CODE
            , gmdate("ymd_Hi99"));

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $pk = $this->getDb()->schema->getTablePrimaryKey('table0', true);
        $this->assertEquals(null, $pk);

        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $pk = $this->getDb()->schema->getTablePrimaryKey('table0', true);
        $this->assertEquals(['id'], $pk->columnNames);
    }

    public function testAddDropForeignKey()
    {

        $this->createMigrationWithContent("CreateTableOrder",
            <<<CODE
        \$this->createTable('customer', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'email' => \$this->string(128)->notNull(),
            'name' => \$this->string(128),
            'address' => \$this->text(),
            'status' => \$this->integer()->defaultValue(0),
            'bool_status' => \$this->boolean()->defaultValue(false),
            'profile_id' => \$this->integer(),
        ]);
        
        \$this->createTable('order', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'customer_id' => \$this->integer()->notNull(),
            'created_at' => \$this->integer()->notNull(),
            'total' => \$this->double()->notNull(),
        ]);

CODE
            , <<<CODE
        \$this->dropTable('customer');
        \$this->dropTable('order');
CODE
            , gmdate("ymd_Hi12"));

        $this->createMigrationWithContent("CreateForeignKey",
            <<<CODE
            \$this->addForeignKey('FK_order_customer_id', 'order', ['customer_id'], 'customer', ['id'], 'CASCADE');
CODE
            , <<<CODE
        \$this->dropForeignKey('FK_order_customer_id', 'order');
CODE
            , gmdate("ymd_Hi13"));

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertNotNull($this->getDb()->schema->getTableSchema('customer', true));
        $this->assertNotNull($this->getDb()->schema->getTableSchema('order', true));
        $fk = $this->getDb()->schema->getTableSchema('order', true)->foreignKeys;
        $this->assertEquals([], $fk);

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $fk = $this->getDb()->schema->getTableSchema('order', true)->foreignKeys;
        $this->assertEquals(['FK_order_customer_id' => ['customer', 'customer_id' => 'id']], $fk);

        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $fk = $this->getDb()->schema->getTableSchema('order', true)->foreignKeys;
        $this->assertEquals([], $fk);

        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertNull($this->getDb()->schema->getTableSchema('customer', true));
        $this->assertNull($this->getDb()->schema->getTableSchema('order', true));
    }

    public function testAddDropIndex()
    {
        $this->createMigrationWithContent("CreateTableOrder",
            <<<CODE

        \$this->createTable('order', [
            'id' => \\mhthnz\\tarantool\\Schema::TYPE_PK,
            'customer_id' => \$this->integer()->notNull(),
            'created_at' => \$this->integer()->notNull(),
            'total' => \$this->double()->notNull(),
        ]);

CODE
            , <<<CODE
        \$this->dropTable('order');
CODE
            , gmdate("ymd_Hi07"));

        $this->createMigrationWithContent("CreateUniqueIndex",
            <<<CODE

        \$this->createIndex('order-unique', 'order', ['customer_id'], true);

CODE
            , <<<CODE
        \$this->dropIndex('order-unique', 'order');
CODE
            , gmdate("ymd_Hi15"));
$date = gmdate("ymd_Hi18");

        $this->createMigrationWithContent("TestUniqueIndex",
            <<<CODE

        \$this->insert('order', ['customer_id' => 1, 'created_at' => 123123, 'total' => 1.1]);
        \$this->insert('order', ['customer_id' => 1, 'created_at' => 123123, 'total' => 1.1]);

CODE
            , <<<CODE
        // nothing
CODE
            , $date);

        $this->createMigrationWithContent("CreateIndex",
            <<<CODE

        \$this->createIndex('order-idx', 'order', ['created_at']);

CODE
            , <<<CODE
        \$this->dropIndex('order-idx', 'order');
CODE
            , gmdate("ymd_Hi19"));

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertNotNull($this->getDb()->schema->getTableSchema('order', true));
        $indexes = $this->getDb()->schema->getTableIndexes('order', true);
        $this->assertEquals(1, count($indexes));
        $this->assertEquals(true, $indexes[0]->isPrimary);
        $this->assertEquals(['id'], $indexes[0]->columnNames);

        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $indexes = $this->getDb()->schema->getTableIndexes('order', true);
        $this->assertEquals(2, count($indexes));
        $this->assertEquals(['customer_id'], $indexes[1]->columnNames);
        $this->assertEquals('order-unique', $indexes[1]->name);
        $this->assertEquals(false, $indexes[1]->isPrimary);
        $this->assertEquals(true, $indexes[1]->isUnique);

        // Check unique index duplicate
        $thrown = false;
        $message = "";
        try {
            $this->runMigrateControllerAction('up', [1]);
        } catch (\Throwable $e) {
            $thrown = true;
            $message = $e->getMessage();
        }
        $this->assertTrue($thrown);
        $this->checkRegex('/Duplicate key exists in unique index/', $message);

        // Drop index
        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $indexes = $this->getDb()->schema->getTableIndexes('order', true);
        $this->assertEquals(1, count($indexes));
        $this->assertEquals(true, $indexes[0]->isPrimary);
        $this->assertEquals(['id'], $indexes[0]->columnNames);
        $this->runMigrateControllerAction('up', [1]);
        $this->runMigrateControllerAction('mark', [$date]);
        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $indexes = $this->getDb()->schema->getTableIndexes('order', true);
        $this->assertEquals(3, count($indexes));
        $this->assertEquals(false, $indexes[2]->isPrimary);
        $this->assertEquals(['created_at'], $indexes[2]->columnNames);
        $this->assertEquals(false, $indexes[2]->isUnique);
    }

    public function testNosqlMigration()
    {
        $this->createMigrationWithContent("CreateSpaces",
            <<<CODE

        \$this->createMemtxSpace('myspace', [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'field', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'field1', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'uniq', 'type' => 'integer', 'is_nullable' => false],
        ], ['id' => 111]);
        
        \$this->createVinylSpace('myspace1', [
            ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
            ['name' => 'name', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'field', 'type' => 'integer', 'is_nullable' => false],
            ['name' => 'field1', 'type' => 'integer', 'is_nullable' => true],
            ['name' => 'uniq', 'type' => 'integer', 'is_nullable' => false],
        ], ['id' => 112]);


CODE
            , <<<CODE
        \$this->dropSpace('myspace');
        \$this->dropSpace('myspace1');
CODE
            , gmdate("ymd_Hi21"));

        $this->createMigrationWithContent("CreateSpacesIndexes",
            <<<CODE

        \$this->createSpaceIndex('myspace', 'pk', ['id' => 'unsigned'], true, 'HASH');
        \$this->createSpaceIndex('myspace', 'stringindex', ['name' => 'string']);
        \$this->createSpaceIndex('myspace1', 'pk', ['field' => 'integer'], true);
        \$this->createSpaceIndex('myspace1', 'intcompositeindex', ['field' => 'integer', 'field1' => 'integer']);
        \$this->createSpaceIndex('myspace', 'uniq', ['uniq' => 'integer'], true);
CODE
            , <<<CODE
        \$this->dropSpaceIndex('myspace', 'pk');
        \$this->dropSpaceIndex('myspace1', 'pk');
        \$this->dropSpaceIndex('myspace', 'stringindex');
        \$this->dropSpaceIndex('myspace1', 'intcompositeindex');
        \$this->dropSpaceIndex('myspace', 'uniq');
CODE
            , gmdate("ymd_Hi22"));

        $this->createMigrationWithContent("CreateSpacesIndexes",
            <<<CODE

        \$this->createSpaceIndex('myspace', 'pk', ['id' => 'unsigned'], true, 'HASH');
        \$this->createSpaceIndex('myspace', 'stringindex', ['name' => 'string']);
        \$this->createSpaceIndex('myspace1', 'pk', ['id' => 'unsigned'], true);
        \$this->createSpaceIndex('myspace1', 'intcompositeindex', ['field' => 'integer', 'field1' => 'integer']);
        \$this->createSpaceIndex('myspace', 'uniq', ['uniq' => 'integer'], true);
CODE
            , <<<CODE
        \$this->dropSpaceIndex('myspace', 'stringindex');
        \$this->dropSpaceIndex('myspace1', 'intcompositeindex');
        \$this->dropSpaceIndex('myspace', 'uniq');
        \$this->dropSpaceIndex('myspace', 'pk');
        \$this->dropSpaceIndex('myspace1', 'pk');
CODE
            , gmdate("ymd_Hi22"));

        $this->createMigrationWithContent("InsertDeleteTruncate",
            <<<CODE
        
        \$this->spaceInsert('myspace', [1, "text", 10, 11, 12]);
        \$this->spaceInsert('myspace', [2, "text 1", 11, 12, 13]);
        \$this->spaceInsert('myspace', [3, "text 2", 12, 13, 14]);
        \$this->spaceInsert('myspace1', [1, "text", 10, 11, 12]);
        \$this->spaceInsert('myspace1', [2, "text 1", 11, 12, 13]);
        \$this->spaceInsert('myspace1', [3, "text 2", 12, 13, 14]);
CODE
            , <<<CODE
        \$this->truncateSpace('myspace');
        \$this->spaceDelete('myspace1', ['pk' => 2]);
        \$this->spaceDelete('myspace1', 3);
CODE
            , gmdate("ymd_Hi23"));


        $this->createMigrationWithContent("ReplaceUpdateUpsert",
            <<<CODE
        
        \$this->spaceUpdate('myspace', 1, \Tarantool\Client\Schema\Operations::set(1, "new text"));
        \$this->spaceUpsert('myspace1', [2, "text 1", 11, 12, 13], \Tarantool\Client\Schema\Operations::set(1, "upsert text"));
        \$this->spaceUpsert('myspace1', [4, "text 4", 111, 121, 131], \Tarantool\Client\Schema\Operations::set(1, "upsert text"));
        \$this->spaceReplace('myspace', [3, "text replaced", 12, 13, 14111]);
        \$this->spaceReplace('myspace', [5, "text 5", 121, 131, 14121]);


CODE
            , <<<CODE

        \$this->spaceDelete('myspace1', 4);
        \$this->spaceDelete('myspace', 5);
        \$this->spaceUpdate('myspace', 1, \Tarantool\Client\Schema\Operations::set(1, "text"));
        \$this->spaceUpdate('myspace1', 2, \Tarantool\Client\Schema\Operations::set(1, "text 1"));
        \$this->spaceUpdate('myspace', 3, \Tarantool\Client\Schema\Operations::set(1, "text 2"));
        
CODE
            , gmdate("ymd_Hi24"));


        $this->createMigrationWithContent("EvalCall",
            <<<CODE
        
        \$this->call('box.space.myspace:insert', [[61, 'text 6', 777, 666, 555]]);
        \$this->evaluate('box.space.myspace1:insert(...)', [[61, 'text 6', 777, 666, 555]]);

CODE
            , <<<CODE
        
        \$this->call('box.space.myspace:delete', [61]);
        \$this->evaluate('box.space.myspace1:delete(...)', [61]);
        
CODE
            , gmdate("ymd_Hi25"));

        // Spaces
        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace")->queryAll();
        $this->assertNotEquals([null], $resp);
        $this->assertEquals('memtx', $resp['engine']);

        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace1")->queryAll();
        $this->assertNotEquals([null], $resp);
        $this->assertEquals('vinyl', $resp['engine']);

        // Indexes
        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace.index")->queryAll();
        $this->assertArrayHasKey('pk', $resp);
        $this->assertArrayHasKey('stringindex', $resp);
        $this->assertArrayHasKey('uniq', $resp);

        $this->assertEquals(true, $resp['pk']['unique']);
        $this->assertEquals('HASH', strtoupper($resp['pk']['type']));
        $this->assertEquals([['type' => 'unsigned', 'is_nullable' => false, 'fieldno' => 1]], $resp['pk']['parts']);

        $this->assertEquals(false, $resp['stringindex']['unique']);
        $this->assertEquals('TREE', strtoupper($resp['stringindex']['type']));
        $this->assertEquals([['type' => 'string', 'is_nullable' => false, 'fieldno' => 2]], $resp['stringindex']['parts']);

        $this->assertEquals(true, $resp['uniq']['unique']);
        $this->assertEquals('TREE', strtoupper($resp['stringindex']['type']));
        $this->assertEquals([['type' => 'integer', 'is_nullable' => false, 'fieldno' => 5]], $resp['uniq']['parts']);

        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace1.index")->queryAll();
        $this->assertArrayHasKey('pk', $resp);
        $this->assertArrayHasKey('intcompositeindex', $resp);
        $this->assertEquals(true, $resp['pk']['unique']);
        $this->assertEquals('TREE', strtoupper($resp['pk']['type']));
        $this->assertEquals([['type' => 'unsigned', 'is_nullable' => false, 'fieldno' => 1]], $resp['pk']['parts']);

        $this->assertEquals(false, $resp['intcompositeindex']['unique']);
        $this->assertEquals('TREE', strtoupper($resp['intcompositeindex']['type']));
        $this->assertEquals([['type' => 'integer', 'is_nullable' => false, 'fieldno' => 3], ['type' => 'integer', 'is_nullable' => true, 'fieldno' => 4]], $resp['intcompositeindex']['parts']);

        // Insert
        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $resp = $this->getDb()->createNosqlQuery()->from('myspace1')->column(1);
        $this->assertEquals(['text', 'text 1', 'text 2'], $resp);

        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->column(1);
        $this->assertEquals(['text', 'text 1', 'text 2'], $resp);

        // Replace upsert update
        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $resp = $this->getDb()->createNosqlQuery()->from('myspace1')->column(1);
        $this->assertEquals(['text', 'upsert text', 'text 2', 'text 4'], $resp);

        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->column(1);
        $this->assertEquals(['new text', 'text 1', 'text 5', 'text replaced'], $resp);

        // Call eval
        $this->runMigrateControllerAction('up', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $resp = $this->getDb()->createNosqlQuery()->from('myspace1')->column(1);
        $this->assertEquals(['text', 'upsert text', 'text 2', 'text 4', 'text 6'], $resp);

        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->column(1);
        $this->assertEquals(['new text', 'text 1', 'text 5', 'text 6', 'text replaced'], $resp);
        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('stringindex')->column(1);
        $this->assertEquals(['new text', 'text 1', 'text 5', 'text 6', 'text replaced'], $resp);

        // Call eval down
        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $resp = $this->getDb()->createNosqlQuery()->from('myspace1')->column(1);
        $this->assertEquals(['text', 'upsert text', 'text 2', 'text 4'], $resp);

        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->column(1);
        $this->assertEquals(['new text', 'text 1', 'text 5', 'text replaced'], $resp);
        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('stringindex')->column(1);
        $this->assertEquals(['new text', 'text 1', 'text 5', 'text replaced'], $resp);

        // Update replace upsert down
        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $resp = $this->getDb()->createNosqlQuery()->from('myspace1')->column(1);
        $this->assertEquals(['text', 'text 1', 'text 2'], $resp);

        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->column(1);
        $this->assertEquals(['text', 'text 1', 'text 2'], $resp);
        $resp = $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('stringindex')->column(1);
        $this->assertEquals(['text', 'text 1', 'text 2'], $resp);

        // Insert delete truncate down
        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());
        $this->assertEquals(0, $this->getDb()->createNosqlQuery()->from('myspace')->usingIndex('stringindex')->count());
        $this->assertEquals(0, $this->getDb()->createNosqlQuery()->from('myspace')->where(['=', 'stringindex', []])->count());
        $this->assertEquals(1, $this->getDb()->createNosqlQuery()->from('myspace1')->count());

        // Create drop index down
        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace.index")->queryAll();
        $this->assertEmpty($resp);

        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace1.index")->queryAll();
        $this->assertEmpty($resp);

        // Drop space
        $this->runMigrateControllerAction('down', [1]);
        $this->assertSame(ExitCode::OK, $this->getExitCode());

        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace")->queryAll();
        $this->assertEquals([null], $resp);

        $resp = $this->getDb()->createNosqlCommand()->evaluate("return box.space.myspace1")->queryAll();
        $this->assertEquals([null], $resp);
    }
}
