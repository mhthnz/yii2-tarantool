Rbac db source
======================================

* [Check out yii2 rbac docs](https://www.yiiframework.com/doc/guide/2.0/en/security-authorization#rbac)

Configuration
--------------------------------------

```php
return [
    'components' => [
        'authManager' => [
            'class' => \mhthnz\tarantool\rbac\DbManager::class,
            'db' => 'tarantool', // Tarantool service id 'tarantool' default
        ],
    ],
]
```

Init rbac tables
-------------------------------------
* [Set up tarantool migrations](MIGRATIONS.md)
* Then run rbac migrations
```bash
hostname@user:~$ php yii tarantool-migrate --migrationNamespaces=\\mhthnz\\tarantool\\rbac\\migrations
```