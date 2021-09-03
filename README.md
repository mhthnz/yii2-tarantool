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


Features
------------
* Tarantool `Connection` has `Command` and `QueryBuilder`
* `ActiveRecord` models with `ActiveQuery` support
* `Schema` abstraction, `TableSchema` and `ColumnSchema`
* Model validators `UniqueValidator`, `ExistsValidator`
* Data widgets like `DetailView`, `ListView`, `GridView` using `ActiveDataProvider`

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