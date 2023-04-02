Tarantool log target
======================================
Tarantool database can be used as log storage by using `\mhthnz\tarantool\log\Target`.

* [Check out yii2 logging documentation](https://www.yiiframework.com/doc/guide/2.0/en/runtime-logging)

Configuration
--------------------------------------

```php
return [
    'components' => [
        'log' => [
            'targets' => [
                'tarantool' => [
                    'class' => '\mhthnz\tarantool\log\Target',
                    'levels' => ['error', 'warning'],
                    // 'categories' => ['app\category\*'],
                    // 'except' => ['yii\web\HttpException:404'],
                ],
            ],
        ],
    ],
]
```

Init log target space
-------------------------------------
* [Set up tarantool migrations](MIGRATIONS.md)
* Then run log target migrations
```bash
hostname@user:~$ php yii tarantool-migrate --migrationNamespaces=\\mhthnz\\tarantool\\log\\migrations
```

### You may want to change some migration settings such as:
- Log space name
- Sequence start id
- Space engine

Just create a new migration and inherit the migration class from `\mhthnz\tarantool\log\migrations\m230401_153114_create_log_target_space`:
```php
class m230330_105111_create_log_target_tables extends \mhthnz\tarantool\log\migrations\m230401_153114_create_log_target_space
{
    protected $spaceName = 'other_log';
    protected $engine = 'vinyl';
}
```

Usage
-------------------------
Check out logging documentation above. Here are some examples:

```php 
\Yii::error('Error log message', ['app\category\err']);

\Yii::warning('Warning log message', ['app\category\warn']);

\Yii::info('Info log message', ['app\category\info']);

\Yii::debug('Debug log message', ['app\category\debug']);
```