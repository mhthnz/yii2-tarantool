Cache with expirationd
======================================
Tarantool cache implementation uses an expirationd tarantool module. 
It runs an expirationd task `_yii2_expiration_task` that stores in `m230330_104511_create_cache_space::$expirationdTaskName`

* [Expirationd documentation](https://tarantool.github.io/expirationd/) ([https://github.com/tarantool/expirationd](https://github.com/tarantool/expirationd))

Configuration
--------------------------------------

```php
return [
    'components' => [
        'tarantoolCache' => [
            'class' => \mhthnz\tarantool\cache\expirationd\Cache::class,
            'db' => 'tarantool', // Tarantool service id 'tarantool' default
            'enableProfiling' => false, // Disabled by default
            'spaceName' => '_yii2_expirationd_cache', // _yii2_expirationd_cache by default
        ],
    ],
]
```

Init cache tables
-------------------------------------
* [Set up tarantool migrations](MIGRATIONS.md)
* Then run cache migrations
```bash
hostname@user:~$ php yii tarantool-migrate --migrationNamespaces=\\mhthnz\\tarantool\\cache\\expirationd\\migrations
```

### You may want to change some expirationd settings such as:
- Force run on replicas
- Tuples per iteration
- Fullscan time
- Space name
- Expirationd task name
- etc..

Just create a new migration and inherit the migration class from `mhthnz\tarantool\cache\expirationd\migrations\m230330_104511_create_cache_space`:
```php
class m230330_105111_create_cache_space extends \mhthnz\tarantool\cache\expirationd\migrations\m230330_104511_create_cache_space
{
    public $engine = 'vinyl';
    public $fullScanTime = 1000;
    //.....
}
```

Usage
-------------------------
It works as any other [yii2 cache](https://www.yiiframework.com/doc/guide/2.0/en/caching-data)

```php 
$cache = \Yii::$app->tarantoolCache;

// retrieves a data item from cache with a specified key. A false value will be returned if the data item is not found in the cache or is expired/invalidated.
$cache->get('item-key');

$key = 'item-key';
$durationInSec = 3600;
$dependency = null;

// stores a data item identified by a key in cache.
$cache->set($key, 'value', $durationInSec, $dependency);

// stores a data item identified by a key in cache if the key is not found in the cache.
$this->add($key, 'value', $durationInSec, $dependency);

// retrieves a data item from cache with a specified key or executes passed callback, stores return of the callback in a cache by a key and returns that data.
$cache->getOrSet($key, function () {
  return Category::find()->all();
}, $durationInSec, $dependency);

// retrieves multiple data items from cache with the specified keys.
$cache->multiGet(['key1', 'key2']);

// stores multiple data items in cache. Each item is identified by a key.
$cache->multiSet(['key1' => 'value1', 'key2' => 'value2'], $durationInSec, $dependency);

// stores multiple data items in cache. Each item is identified by a key. If a key already exists in the cache, the data item will be skipped.
$cache->multiAdd(['key1' => 'value1', 'key2' => 'value2'], $durationInSec, $dependency);

// returns a value indicating whether the specified key is found in the cache.
$cache->exists($key);

// removes a data item identified by a key from the cache.
$cache->delete($key);

// removes all data items from the cache.
$cache->flush();
```