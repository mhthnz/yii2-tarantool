Tarantool connector for yii2 framework
======================================
[Tarantool](https://www.tarantool.io/en/doc/latest/) connector for yii2 framework. Allow to use framework abstractions such as ActiveRecord, Schema, TableSchema, Query, ActiveQuery and etc using tarantool database.

Documentation is here: [docs/README.md](docs/README.md)

Reqirements
------------

`Tarantool >= 2.4.1`
`PHP >= 7.1 || PHP >= 8`
`Yii2 >= 2.0.14`

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist mhthnz/yii2-tarantool "*"
```

or add

```
"mhthnz/yii2-tarantool": "*"
```

to the require section of your `composer.json` file.

Configuration
------------
* [Dsn options](https://github.com/tarantool-php/client#dsn-string)
```php
return [
    'components' => [
        // Tarantool connection setup
        'tarantool' => [
            'class' => \mhthnz\tarantool\Connection::class,
            'dsn' => 'tcp://username:password@localhost:3301/?connect_timeout=5.0&max_retries=3',
        ],
        
    ],
    
    'bootstrap' => ['debug'],
    
    'modules' => [
        //Debug panel setup
        'debug' => [
            'class' => 'yii\debug\Module',
            'panels' => [
                'tarantool' => [
                    'class' => \mhthnz\tarantool\debug\TarantoolPanel::class,
                    'db' => 'tarantool', // Tarantool component id
                ],
            ],
            'allowedIPs' => ['127.0.0.1', '::1'],
        ],
        
    ],
];
```

Features
------------
* Tarantool `Connection` has `Command` and `QueryBuilder`
* `ActiveRecord` models with `ActiveQuery` support
* `Schema` abstraction, `TableSchema` and `ColumnSchema`
* Model validators `UniqueValidator`, `ExistsValidator`
* Data widgets like `DetailView`, `ListView`, `GridView` using `ActiveDataProvider`
* Debug panel

Future plans
------------
* Migrations
* Nosql query builder
* Lua validator
* I18n source
* Rbac db source
* Transactions
* Gii code generator (models, crud, queries)
* Connection slaves support
* Queue
* Cache