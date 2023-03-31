<?php

namespace mhthnz\tarantool\cache\expirationd;

use MessagePack\Type\Bin;
use mhthnz\tarantool\cache\expirationd\migrations\m230330_104511_create_cache_space;
use mhthnz\tarantool\Connection;
use Tarantool\Client\Exception\RequestFailed;
use yii\base\InvalidConfigException;
use yii\di\Instance;

/**
 * Cache class that implements Tarantool nosql commands.
 * This cache uses expirationd module for tarantool. It automatically removes expired items.
 * You can change expirationd config by inheriting from the original migration.
 * @see m230330_104511_create_cache_space
 *
 * Configuration:
 * '''php
 *  'components' => [
 *      'tarantoolCache' => [
 *          'class' => '\mhthnz\tarantool\cache\expirationd\Cache',
 *          // 'db' => 'tarantool',
 *          // 'enableProfiling' => false,
 *          // 'spaceName' => '_yii2_expirationd_cache',  - depends on migration m230330_104511_create_cache_space.php
 *      ]
 * ]
 *```
 */
class Cache extends \yii\caching\Cache
{
    /**
     * @var Connection|array|string
     */
    public $db = 'tarantool';

    /**
     * @var string
     */
    public $spaceName = '_yii2_expirationd_cache';

    /**
     * It enables nosql queries logging. Queries will be shown in debug panel.
     * @var bool
     */
    public $enableProfiling = false;

    /**
     * Initializes the Cache component.
     * This method will initialize the [[db]] property to make sure it refers to a valid Tarantool connection.
     * @throws InvalidConfigException if [[db]] is invalid.
     */
    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::class);
    }

    /**
     * @param $key
     * @return false|mixed
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\base\NotSupportedException
     */
    protected function getValue($key)
    {
        $cmd = $this->db->createNosqlCommand($this->db->createNosqlQuery()->from($this->spaceName)->where($key)->build());
        $cmd->enableProfiling = $this->enableProfiling;
        $result = $cmd->queryGet();
        if ($result === null) {
            return false;
        }

        return $result[2];
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     * @return bool
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    protected function setValue($key, $value, $duration)
    {
        $cmd = $this->db->createNosqlCommand()->replace($this->spaceName, [$key, $duration, $this->typecastData($value)]);
        $cmd->enableLogging = $this->enableProfiling;
        $cmd->execute();

        return true;
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     * @return bool
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    protected function addValue($key, $value, $duration)
    {
        try {
            $cmd = $this->db->createNosqlCommand()->insert($this->spaceName, [$key, $duration, $this->typecastData($value)]);
            $cmd->enableProfiling = $this->enableProfiling;
            $cmd->execute();
        } catch (RequestFailed $e) {
            return false;
        }

        return true;
    }

    /**
     * @param $key
     * @return bool
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    protected function deleteValue($key)
    {
        $cmd = $this->db->createNosqlCommand()->delete($this->spaceName, $key);
        $cmd->enableProfiling = $this->enableProfiling;
        $cmd->execute();

        return true;
    }

    /**
     * @return bool
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     * @throws \yii\base\InvalidConfigException
     */
    protected function flushValues()
    {
        $cmd = $this->db->createNosqlCommand()->truncateSpace($this->spaceName);
        $cmd->enableProfiling = $this->enableProfiling;
        $cmd->execute();

        return true;
    }

    /**
     * @param string $data
     * @return Bin
     */
    protected function typecastData($data)
    {
        return new Bin($data);
    }
}