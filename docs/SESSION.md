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
If you want to use different storage like vinyl, or change session table name - you have make new migration inherited from `mhthnz\tarantool\session\migrations\m230214_190000_create_table_session`
to your migration directory and change protected properties:
```php 
class m230214_210000_create_table_session extends \mhthnz\tarantool\session\migrations\m230214_190000_create_table_session
{
    protected $tableName = "{{%another_session_table}}";
    protected $storage = "vinyl";
```

More about session
----------------
- [https://www.yiiframework.com/doc/guide/2.0/en/runtime-sessions-cookies](https://www.yiiframework.com/doc/guide/2.0/en/runtime-sessions-cookies)
