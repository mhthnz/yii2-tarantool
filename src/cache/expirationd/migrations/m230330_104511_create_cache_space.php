<?php
namespace mhthnz\tarantool\cache\expirationd\migrations;

use mhthnz\tarantool\Migration;
use yii\base\NotSupportedException;

/**
 * This migration creates space and expirationd task for removing expired tuples from space.
 * If you need to change some space options like engine, full_scan_time, etc.. You have to inherit this class from your new migration:
 * ```php
 * class m123123_123123_create_cache_space extends \mhthnz\tarantool\cache\expirationd\migrations\m230330_104511_create_cache_space
 * {
 *      public $engine = 'vinyl';
 *      public $fullScanTime = 1000;
 * ```
 * @see https://tarantool.github.io/expirationd/
 */
class m230330_104511_create_cache_space extends Migration
{
    /**
     * Use false to skip replicas. True - runs expirationd on replicas as well as on master.
     * @var bool
     */
    protected $force = false;

    /**
     * How many tuples will be processed per one iteration.
     * @var int
     */
    protected $tuplesPerIteration = 1024;

    /**
     * Fullscan time in seconds.
     * @var int
     */
    protected $fullScanTime = 3600;

    /**
     * Space engine, could be 'vinyl' or 'memtx'.
     * @see https://www.tarantool.io/en/doc/latest/concepts/engines/
     * @var string
     */
    protected $engine = "memtx";

    /**
     * Space name for storing cache data. Starting with _ to avoid schema indexing.
     * @var string
     */
    protected $spaceName = '_yii2_expirationd_cache';

    /**
     * @var string
     */
    protected $expirationdTaskName = "_yii2_expiration_task";

    /**
     * @var string
     */
    protected $expirationTupleFunctionName = 'yii2_cache_tuple_expired';

    /**
     * Expiration function.
     * @var string
     */
    protected $luaExpirationFunction = <<<LUA
    function {{expiration_tuple_function_name}}(args, tuple)
        if (tuple[2] < math.floor(fiber.time())) then return true end
        return false
    end
LUA;

    /**
     * Create cache space and expirationd task.
     * @return bool|void|null
     * @throws NotSupportedException
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    public function up()
    {
        // Can we use expirationd ?
        $this->isExpirationdAvailable();

        // Create cache space
        $this->createSpace($this->spaceName, [
            ['name' => 'id', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'expire', 'type' => 'integer', 'is_nullable' => false],
            ['name' => 'data', 'type' => 'varbinary', 'is_nullable' => false],
        ], $this->engine);
        $this->createSpaceIndex($this->spaceName, $this->spaceName . "-pk", [1 => 'string'], true, 'hash');

        // Start expirationd fiber
        $this->startExpirationdTask();
    }

    /**
     * Rollback cache space and expirationd task.
     * @return bool|void|null
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    public function down()
    {
        $this->killExpirationdTask();
        $this->dropSpace($this->spaceName);
    }

    /**
     * Checks that we can use expirationd. If we can't load expirationd, we get an exception.
     * @throws NotSupportedException
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     */
    protected function isExpirationdAvailable(): void
    {
        try {
            $this->evaluate("return require('expirationd')");
        } catch (\Exception $e) {
            throw new NotSupportedException("Can't load expirationd extension. Make sure you installed it.", 0, $e);
        }
    }

    /**
     * Start expirationd fiber that will remove expired items.
     * @return void
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    protected function startExpirationdTask()
    {
        $luaToEvaluate = <<<LUA
        fiber = require("fiber")
        expirationd = require("expirationd")
        
        {{lua_expirationd_func}}
        
        expirationd.start('{{expirationd_task_name}}', '{{space_name}}', {{expiration_tuple_function_name}}, {
            tuples_per_iteration = {{tuples_per_iteration}}, 
            full_scan_time = {{full_scan_time}},
            force = {{force}},
        })
LUA;
        $func = $this->bindValues($this->luaExpirationFunction);
        $this->evaluate($this->bindValues($luaToEvaluate, ['{{lua_expirationd_func}}' => $func]));
    }

    /**
     * @return void
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    protected function killExpirationdTask()
    {
        $luaToEvaluate = <<<LUA
        expirationd = require("expirationd")
        expirationd.kill('{{expirationd_task_name}}')
LUA;
        $this->evaluate($this->bindValues($luaToEvaluate));
    }

    /**
     * @param $lua
     * @param $placeholders
     * @return string
     */
    protected function bindValues($lua, $placeholders = []): string
    {
        return strtr($lua, array_merge([
            '{{expirationd_task_name}}'          => $this->expirationdTaskName,
            '{{space_name}}'                     => $this->spaceName,
            '{{expiration_tuple_function_name}}' => $this->expirationTupleFunctionName,
            '{{force}}' => $this->force ? 'true' : 'false',
            '{{tuples_per_iteration}}' => $this->tuplesPerIteration,
            '{{full_scan_time}}' => $this->fullScanTime,
        ], $placeholders));
    }
}
