Migrations
======================================

In many aspects it works like standard [yii2 migrations](https://www.yiiframework.com/doc/guide/2.0/en/db-migrations) but has some restrictions and new abilities.


#####  <span style="color:red;font-weight:bold;">!</span> Tarantool migrations changes:
* Does not support generating migrations template based on fields
* Does not support generating  migrations based on migration name such as: `junction`, `create_table`, `drop_index` and etc.
* Restricted some methods such as: `DropColumn()`, `renameColumn()`... because of tarantool doesn't support it for now
* Reduced amount of column types (`string`, `integer`, `double`, `binary`)
* Added addition methods for creating tables with engine (`memtx`, `vinyl`)
* Added ability for creating composite primary key (ColumnSchemaBuilder)
* No transaction support (only `up()` and `down()`)
* Supports check and unique constraints

Configuration
------------
`console/config/main.php` or `config/console.php`

```php
<?php
return [
    'controllerMap' => [
        'tarantool-migrate' => [
            'class'         => \mhthnz\tarantool\console\MigrateController::class,
            'migrationPath' => '@app/tarantool/migrations', // Migrations directory
            // 'db'         => 'tarantool', // Connection component key default 'tarantool'
        ],
    ]
];
```

Usage
------------

```bash
# Run migrations
hostname@user:~$ php yii tarantool-migrate/up
# With limit
hostname@user:~$ php yii tarantool-migrate/up 2

# Revert migrations
hostname@user:~$ php yii tarantool-migrate/down 
# With limit
hostname@user:~$ php yii tarantool-migrate/down 2

# Create migration
hostname@user:~$ php yii tarantool-migrate/create create_table_users

# Using namespace
hostname@user:~$ yii tarantool-migrate/create app\\tarantool\\migrations\\CreateTableUsers

# Set exact date which database should be migrated
hostname@user:~$ php yii tarantool-migrate/to 150101_185401                      # using timestamp to specify the migration
hostname@user:~$ php yii tarantool-migrate/to "2015-01-01 18:54:01"              # using a string that can be parsed by strtotime()
hostname@user:~$ php yii tarantool-migrate/to m150101_185401_create_news_table   # using full name
hostname@user:~$ php yii tarantool-migrate/to 1392853618                         # using UNIX timestamp

# Redo applied migrations
hostname@user:~$ yii tarantool-migrate/redo
hostname@user:~$ yii tarantool-migrate/redo 3

# It have to work, but didn't test it (truncate database)
hostname@user:~$ yii tarantool-migrate/fresh

# History
hostname@user:~$ yii tarantool-migrate/history     # showing the last 10 applied migrations
hostname@user:~$ yii tarantool-migrate/history 5   # showing the last 5 applied migrations
hostname@user:~$ yii tarantool-migrate/history all # showing all applied migrations

# Check new migrations
hostname@user:~$ yii tarantool-migrate/new         # showing the first 10 new migrations
hostname@user:~$ yii tarantool-migrate/new 5       # showing the first 5 new migrations
hostname@user:~$ yii tarantool-migrate/new all     # showing all new migrations

# Mark migration as applied
hostname@user:~$ yii tarantool-migrate/mark 150101_185401
hostname@user:~$ yii tarantool-migrate/mark m150101_185401_create_news_table

# You can also specify migrationPath and db
hostname@user:~$ yii tarantool-migrate --migrationPath=@app/tarantool/migrations/db1 --db=tarantool1
hostname@user:~$ yii tarantool-migrate --migrationPath=@app/tarantool/migrations/db2 --db=tarantool2
```

Migration class
------------
* [More column options](https://github.com/mhthnz/yii2-tarantool/blob/master/tests/MigrationTest.php#L182)
```php
<?php

use \mhthnz\tarantool\Migration;

class m150101_185401_create_tables extends Migration
{
    public function up()
    {
    	// Primary key with autoincrement 'id'
        $this->createTable('table', [
            'id' => $this->primaryKey(), // Synonym \\mhthnz\\tarantool\\Schema::TYPE_PK
            'name' => $this->string()->collation('unicode'),
            'time' => $this->integer()->notNull(),
            'binarySecret' => $this->binary()->notNull(),
            'balance' => $this->double()->notNull(),
        ]);
        
        // Composite primary key
        // Will create PRIMARY KEY ('id', 'time')
        $this->createTable('table1', [
            'id' => $this->integer(),
            'name' => $this->string()->collation('unicode'),
            'time' => $this->integer(),
            'binarySecret' => $this->binary()->notNull(),
            'balance' => $this->double()->notNull(),
            'CONSTRAINT "pk1-t1" PRIMARY KEY ("id", "time")',
        ]);
        
        // Unique and check
        // Unique emails that match gmail.com
        $this->createTable('table2', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->collation('unicode'),
            'time' => $this->integer()->null(),
            'email' => $this->string()->unique()->check('"email" like \'%gmail.com\'') ,
            'balance' => $this->double()->notNull(),
        ]);
        
        // Custom indexes and fk
        $this->createTable('table3', [
            'id' => $this->integer(),
            'name' => $this->string()->collation('unicode'),
            'time' => $this->integer()->null(),
            'email' => $this->string()->unique()->check('"email" like \'%gmail.com\''),
            'balance' => $this->double()->notNull(),
            'table2_id' => $this->integer(),
            'CONSTRAINT "pk1" PRIMARY KEY ("id")',
            'CONSTRAINT "FK_table2_table3" FOREIGN KEY ("table2_id") REFERENCES "table2" ("id")',
        ]);
        
        // Create memtx engine table
        $this->createMemtxTable('table4', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->collation('unicode'),
        ]);
        
        // Create vinyl engine table
        $this->createVinylTable('table5', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->collation('unicode'),
        ]);
        
        // Rename table
        $this->renameTable('table5', 'table6');
        
        // Drop table
        $this->dropTable('table6');
        
        // Insert, Batch insert, Update
        $this->insert('table4', ['name' => 'my_name', 'id' => 11]);
        $this->update('table4', ['name' => 'new_name'], ['id' => 11]);
        $this->batchInsert('table4', ['name', 'id'], [
            ['name1', 1231231111], 
            ['name2', 2], 
            ['name3', 9], 
            ['name4', 9999]
        ]);
        
        // Truncate table (doesn't reset sequence, at least for now)
        $this->truncateTable('table4');
        
        // Add/Drop primary key
        $this->dropPrimaryKey('pk1', 'table3');
        $this->addPrimaryKey('pk_new', 'table3', ['id', 'table2_id']);
        
        // Add column (only tarantool ver >= 2.7)
        $this->addColumn('table4', 'new_col', $this->binary()->notNull()->check('length("new_col") > 1'));
        
        // Add/Drop foreign key
        $this->dropForeignKey('FK_table2_table3', 'table3');
        $this->addForeignKey('FK_table2_table3_new', 'table3', ['table2_id'], 'table2', ['id'], 'CASCADE', 'CASCADE');
        
        // Add/Drop unique index
        $this->createIndex('table4-name-unique', 'table4', ['name'], true);
        $this->dropIndex('table4-name-unique', 'table4');
        
        // Add/Drop index
        $this->createIndex('table4-name-unique', 'table4', ['name']);
        $this->dropIndex('table4-name-unique', 'table4');
        
        // Any sql code execution
        $this->execute('INSERT INTO "table4"("name") VALUES (\'my name\')');
    }

    public function down()
    {
        $this->dropForeignKey('FK_table2_table3', 'table3');
        $this->dropTable('table1');
        $this->dropTable('table2');
        $this->dropTable('table3');
        $this->dropTable('table4');
    }
}
```