Tarantool connector for yii2 framework
======================================
[![Latest Stable Version](http://poser.pugx.org/mhthnz/yii2-tarantool/v)](https://github.com/mhthnz/yii2-tarantool/releases/latest)
[![Latest Unstable Version](http://poser.pugx.org/mhthnz/yii2-tarantool/v/unstable)](https://packagist.org/packages/mhthnz/yii2-tarantool#dev-master)
![Master Branch Tests](https://github.com/mhthnz/yii2-tarantool/actions/workflows/php.yml/badge.svg?branch=master)
![Score](https://scrutinizer-ci.com/g/mhthnz/yii2-tarantool/badges/quality-score.png?b=master)
![Coverage](https://scrutinizer-ci.com/g/mhthnz/yii2-tarantool/badges/coverage.png?b=master)

[Tarantool](https://www.tarantool.io/en/doc/latest/) connector for yii2 framework. Allows to perform SQL and NoSQL queries, framework abstractions such as ActiveRecord, Schema, TableSchema, Query, ActiveQuery and etc using tarantool database.

Documentation is here: [docs/README.md](docs/README.md)

Check out yii2 basic tarantool application: [https://github.com/mhthnz/yii2-basic-tarantool-app](https://github.com/mhthnz/yii2-basic-tarantool-app)

Reqirements
------------

![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/mhthnz/yii2-tarantool)
![Tarantool version](https://img.shields.io/badge/tarantool-%3E%3D%202.4.1-blue)
![Yii2 version](https://img.shields.io/badge/yii2-%3E%3D%202.0.35-blue)

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
    'bootstrap' => ['debug'],
    'components' => [
        // Tarantool connection setup
        'tarantool' => [
            'class' => \mhthnz\tarantool\Connection::class,
            'dsn' => 'tcp://username:password@localhost:3301/?connect_timeout=5&max_retries=3',
        ],
    ],
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
* Tarantool [`Connection`](docs/CONNECTION.md) has `Command` and `QueryBuilder`
* `ActiveRecord` models with `ActiveQuery` support
* `Schema` abstraction, `TableSchema` and `ColumnSchema`
* Compatible with AR validators `UniqueValidator`, `ExistsValidator`
* Supports data widgets like `DetailView`, `ListView`, `GridView` using `ActiveDataProvider`
* Debug panel with explain is able to show SQL and NoSQL requests.
* [Migrations](docs/MIGRATIONS.md)
* Nosql [`Query`](docs/NOSQL.md#query) and [`Command`](docs/NOSQL.md#command) [for performing nosql requests](docs/NOSQL.md)
* [Lua validator](docs/LUA-VALIDATOR.md)
* [Rbac db source](docs/RBAC.md)
* [Session](docs/SESSION.md) 

Future plans
------------

* I18n source
* Transactions
* Gii code generator (models, crud, queries)
* Connection slaves support
* Queue
* Cache


Running tests
------------

* First of all you need to run tarantool and bind it to localhost:3301

```bash
$ docker run --name mytarantool -p3301:3301 -d tarantool/tarantool:2.4.1
```
* Install php deps
```bash
$ sudo apt install php-mbstring php-dom

$ sudo apt install php7.3-mbstring php7.3-dom # or specify php version 
```

* Install vendor
```bash
$ php composer install

$ php7.3 composer.phar install # or specify php version 
```

* Run phpunit tests
```bash
$ php ./vendor/phpunit/phpunit/phpunit --bootstrap ./tests/_bootstrap.php --configuration ./phpunit.xml.dist 
```
