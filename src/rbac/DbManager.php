<?php

namespace mhthnz\tarantool\rbac;

use mhthnz\tarantool\Connection;
use yii\di\Instance;

/**
 * Rbac db manager for tarantool database.
 * Works like usual SQL-based db manager.
 * {@inheritdoc}
 * @author mhthnz <mhthnz@gmail.com>
 */
class DbManager extends \yii\rbac\DbManager
{
    /**
     * {@inheritdoc}
     * @var Connection|array|string
     */
    public $db = 'tarantool';

    /**
     * @var string the key used to store RBAC data in cache
     * @see cache
     * @since 2.0.3
     */
    public $cacheKey = 'rbac-tarantool';

    /**
     * Initializes the application component.
     * This method overrides the parent implementation by establishing the database connection.
     */
    public function init()
    {
        $this->db = Instance::ensure($this->db, Connection::class);
        if ($this->cache !== null) {
            $this->cache = Instance::ensure($this->cache, 'yii\caching\CacheInterface');
        }
    }

    /**
     * {@inheritdoc}
     * @return bool
     */
    protected function supportsCascadeUpdate()
    {
        return true;
    }
}
