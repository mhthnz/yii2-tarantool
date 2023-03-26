Session
======================================

Configuration:
-----------------
```php 
return [
    'components' => [
        'session' => [
            'class' => '\mhthnz\tarantool\session\Session',
            // 'db' => 'mytarantool',  // Tarantool component ID, 'tarantool' by default
            // 'sessionTable' => 'my_session', // Table name for storing session data. '{{%session}}' by default
        ],
    ],
];
```

Init session table migration
----------------------
* [Set up tarantool migrations](MIGRATIONS.md)
* Run session migration
```bash
$ php yii tarantool-migrate --migrationNamespaces=\\mhthnz\\tarantool\\session\\migrations
```

Storage
----------------------
If you want to use different storage like vinyl - you have to copy migration:
`yii2-tarantool/src/session/migrations/m230214_190000_create_table_session.php`
To your migration directory and:
```php 
# Replace this
$this->createTable
# To this
$this->createVinylTable
```

More about session
----------------
- [https://www.yiiframework.com/doc/guide/2.0/en/runtime-sessions-cookies](https://www.yiiframework.com/doc/guide/2.0/en/runtime-sessions-cookies)
