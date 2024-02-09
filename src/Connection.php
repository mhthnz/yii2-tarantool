<?php

namespace mhthnz\tarantool;

use MessagePack\BufferUnpacker;
use MessagePack\Packer;
use MessagePack\PackOptions;
use MessagePack\TypeTransformer\StreamTransformer;
use MessagePack\UnpackOptions;
use mhthnz\tarantool\nosql\Query;
use Tarantool\Client\Exception\ClientException;
use Tarantool\Client\Packer\Extension\DecimalExtension;
use Tarantool\Client\Packer\Extension\ErrorExtension;
use Tarantool\Client\Packer\Extension\UuidExtension;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Client\Request\Request;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;

/**
 * Connection represents a connection to a [Tarantool database](https://docs.tarantool.io/en/doc/2.4/singlehtml.html).
 *
 * Connection works together with [[Command]], [[DataReader]].
 *
 *
 * To establish a DB connection, set [[dsn]] and then
 * call [[open()]] to connect to the database server. The current state of the connection can be checked using [[$isActive]].
 *
 * The following example shows how to create a Connection instance and establish
 * the DB connection:
 *
 * ```php
 * $connection = new \yii\db\Connection([
 *     'dsn' => $dsn,
 * ]);
 * $connection->open();
 * ```
 *
 * After the DB connection is established, one can execute SQL statements like the following:
 *
 * ```php
 * $command = $connection->createCommand('SELECT * FROM "post"');
 * $posts = $command->queryAll();
 * $command = $connection->createCommand('UPDATE "post" SET "status"=1');
 * $command->execute();
 * ```
 *
 * One can also do prepared SQL execution and bind parameters to the prepared SQL.
 * When the parameters are coming from user input, you should use this approach
 * to prevent SQL injection attacks. The following is an example:
 *
 * ```php
 * $command = $connection->createCommand('SELECT * FROM "post" WHERE "id"=:id');
 * $command->bindValue(':id', $_GET['id']);
 * $post = $command->query();
 * ```
 *
 * For more information about how to perform various DB queries, please refer to [[Command]].
 *
 * Connection is often used as an application component and configured in the application
 * configuration like the following:
 *
 * ```php
 * 'components' => [
 *     'tarantool' => [
 *         'class' => '\mhthnz\tarantool\Connection',
 *         'dsn' => 'tcp://username:password@localhost:3301',
 *     ],
 * ],
 * ```
 *
 * @property-read bool $isActive Whether the DB connection is established. This property is read-only.
 * @property string $lastInsertID The row ID of the last row inserted, or the last value retrieved from
 * the sequence object. This property is read-only.
 * @property-read Connection $master The currently active master connection. `null` is returned if there is no
 * master available. This property is read-only.
 * @property-read Client $masterClient The tarantool client instance for the currently active master connection. This property is
 * read-only.
 * @property QueryBuilder $queryBuilder The query builder for the current DB connection. Note that the type of
 * this property differs in getter and setter. See [[getQueryBuilder()]] and [[setQueryBuilder()]] for details.
 * @property-read Schema $schema The schema information for the database opened by this connection. This
 * property is read-only.
 * @property-read string $serverVersion Server version as a string. This property is read-only.
 * @property-read Connection $slave The currently active slave connection. `null` is returned if there is no
 * slave available and `$fallbackToMaster` is false. This property is read-only.
 * @property-read Client $slaveClient The tarantool client instance for the currently active slave connection. `null` is returned
 * if no slave connection is available and `$fallbackToMaster` is false. This property is read-only.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class Connection extends Component
{
    /**
     * @event \yii\base\Event an event that is triggered after a DB connection is established
     */
    const EVENT_AFTER_OPEN = 'afterOpen';

    /**
     * @var string the Data Source Name, or DSN, contains the information required to connect to the database.
     * Please refer to the [Manual](https://github.com/tarantool-php/client#creating-a-client) on
     * the format of the DSN string.
     */
    public $dsn;

    /**
     * @var string|null Tarantool client class, original class can not be inheritance, but you still can use decorator for some reason.
     */
    public $clientClass;

    /**
     * @var Client
     */
    public $client;

    /**
     * @var bool whether to enable schema caching.
     * Note that in order to enable truly schema caching, a valid cache component as specified
     * by [[schemaCache]] must be enabled and [[enableSchemaCache]] must be set true.
     * @see schemaCacheDuration
     * @see schemaCacheExclude
     * @see schemaCache
     */
    public $enableSchemaCache = false;

    /**
     * @var int number of seconds that table metadata can remain valid in cache.
     * Use 0 to indicate that the cached data will never expire.
     * @see enableSchemaCache
     */
    public $schemaCacheDuration = 3600;

    /**
     * @var array list of tables whose metadata should NOT be cached. Defaults to empty array.
     * The table names may contain schema prefix, if any. Do not quote the table names.
     * @see enableSchemaCache
     */
    public $schemaCacheExclude = [];

    /**
     * @var string the common prefix or suffix for table names. If a table name is given
     * as `{{%TableName}}`, then the percentage character `%` will be replaced with this
     * property value. For example, `{{%post}}` becomes `{{tbl_post}}`.
     */
    public $tablePrefix = '';

    /**
     * @var CacheInterface|string the cache object or the ID of the cache application component that
     * is used to cache the table metadata.
     * @see enableSchemaCache
     */
    public $schemaCache = 'cache';

    /**
     * @var bool whether to enable query caching.
     * Note that in order to enable query caching, a valid cache component as specified
     * by [[queryCache]] must be enabled and [[enableQueryCache]] must be set true.
     * Also, only the results of the queries enclosed within [[cache()]] will be cached.
     * @see queryCache
     * @see cache()
     * @see noCache()
     */
    public $enableQueryCache = true;

    /**
     * @var int the default number of seconds that query results can remain valid in cache.
     * Defaults to 3600, meaning 3600 seconds, or one hour. Use 0 to indicate that the cached data will never expire.
     * The value of this property will be used when [[cache()]] is called without a cache duration.
     * @see enableQueryCache
     * @see cache()
     */
    public $queryCacheDuration = 3600;

    /**
     * @var CacheInterface|string the cache object or the ID of the cache application component
     * that is used for query caching.
     * @see enableQueryCache
     */
    public $queryCache = 'cache';

    /**
     * @var CacheInterface|string|false the cache object or the ID of the cache application component that is used to store
     * the health status of the DB servers specified in [[masters]] and [[slaves]].
     * This is used only when read/write splitting is enabled or [[masters]] is not empty.
     * Set boolean `false` to disabled server status caching.
     * @see openFromPoolSequentially() for details about the failover behavior.
     * @see serverRetryInterval
     */
    public $serverStatusCache = 'cache';

    /**
     * @var int the retry interval in seconds for dead servers listed in [[masters]] and [[slaves]].
     * This is used together with [[serverStatusCache]].
     */
    public $serverRetryInterval = 600;

    /**
     * @var bool whether to enable read/write splitting by using [[slaves]] to read data.
     * Note that if [[slaves]] is empty, read/write splitting will NOT be enabled no matter what value this property takes.
     */
    public $enableSlaves = true;

    /**
     * @var array list of slave connection configurations. Each configuration is used to create a slave DB connection.
     * When [[enableSlaves]] is true, one of these configurations will be chosen and used to create a DB connection
     * for performing read queries only.
     * @see enableSlaves
     * @see slaveConfig
     */
    public $slaves = [];

    /**
     * @var array the configuration that should be merged with every slave configuration listed in [[slaves]].
     * For example,
     *
     * ```php
     * [
     *      'dsn' => 'tcp://username:password@localhost:3301'
     * ]
     * ```
     */
    public $slaveConfig = [];

    /**
     * @var array list of master connection configurations. Each configuration is used to create a master DB connection.
     * When [[open()]] is called, one of these configurations will be chosen and used to create a DB connection
     * which will be used by this object.
     * Note that when this property is not empty, the connection setting (e.g. "dsn") of this object will
     * be ignored.
     * @see masterConfig
     * @see shuffleMasters
     */
    public $masters = [];

    /**
     * @var array the configuration that should be merged with every master configuration listed in [[masters]].
     * For example,
     *
     * ```php
     * [
     *      'dsn' => 'tcp://username:password@localhost:3301'
     * ]
     * ```
     */
    public $masterConfig = [];

    /**
     * @var bool whether to shuffle [[masters]] before getting one.
     * @see masters
     */
    public $shuffleMasters = true;

    /**
     * @var bool whether to enable logging of database queries. Defaults to true.
     * You may want to disable this option in a production environment to gain performance
     * if you do not need the information being logged.
     * @see enableProfiling
     */
    public $enableLogging = true;

    /**
     * @var bool whether to enable profiling of opening database connection and database queries. Defaults to true.
     * You may want to disable this option in a production environment to gain performance
     * if you do not need the information being logged.
     * @see enableLogging
     */
    public $enableProfiling = true;

    /**
     * @var bool whether to enable using of SQL prepared statements. Defaults to true.
     *
     * @see enablePreparedStatements
     */
    public $enablePreparedStatements = true;

    /**
     * @var string
     */
    public $version;

    /**
     * @var string
     */
    public $instanceUuid;

    /**
     * Automatically resolve `unsupported lua type` using eval.
     * Tarantool user must have eval right or to avoid encoding problems you can manually set in tarantool config:
     * msgpack = require('msgpack');
     * msgpack.cfg{encode_invalid_as_nil = true}
     *
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/net_box/#lua-function.conn.call
     *
     * @var bool
     */
    public $handleLuaEncodingErrors = true;

    /**
     * Store last insert id.
     * @var int|null
     */
    private $_lastInsertID;

    /**
     * @var array An array of [[setQueryBuilder()]] calls, holding the passed arguments.
     * Is used to restore a QueryBuilder configuration after the connection close/open cycle.
     *
     * @see restoreQueryBuilderConfiguration()
     */
    private $_queryBuilderConfigurations = [];

    /**
     * @var Schema the database schema
     */
    private $_schema;

    /**
     * @var Connection|false the currently active master connection
     */
    private $_master = false;

    /**
     * @var Connection|false the currently active slave connection
     */
    private $_slave = false;

    /**
     * @var array query cache parameters for the [[cache()]] calls
     */
    private $_queryCacheInfo = [];

    /**
     * @var string[] quoted table name cache for [[quoteTableName()]] calls
     */
    private $_quotedTableNames;

    /**
     * @var string[] quoted column name cache for [[quoteColumnName()]] calls
     */
    private $_quotedColumnNames;


    /**
     * Returns a value indicating whether the DB connection is established.
     * @return bool whether the DB connection is established
     */
    public function getIsActive()
    {
        return $this->client !== null;
    }

    /**
     * @return Query
     */
    public function createNosqlQuery(): Query
    {
        return new Query(['db' => $this]);
    }

    /**
     * Uses query cache for the queries performed with the callable.
     *
     * When query caching is enabled ([[enableQueryCache]] is true and [[queryCache]] refers to a valid cache),
     * queries performed within the callable will be cached and their results will be fetched from cache if available.
     * For example,
     *
     * ```php
     * // The customer will be fetched from cache if available.
     * // If not, the query will be made against DB and cached for use next time.
     * $customer = $db->cache(function (Connection $db) {
     *     return $db->createCommand('SELECT * FROM customer WHERE id=1')->queryOne();
     * });
     * ```
     *
     * Note that query cache is only meaningful for queries that return results. For queries performed with
     * [[Command::execute()]], query cache will not be used.
     *
     * @param callable $callable a PHP callable that contains DB queries which will make use of query cache.
     * The signature of the callable is `function (Connection $db)`.
     * @param int $duration the number of seconds that query results can remain valid in the cache. If this is
     * not set, the value of [[queryCacheDuration]] will be used instead.
     * Use 0 to indicate that the cached data will never expire.
     * @param \yii\caching\Dependency $dependency the cache dependency associated with the cached query results.
     * @return mixed the return result of the callable
     * @throws \Exception|\Throwable if there is any exception during query
     * @see enableQueryCache
     * @see queryCache
     * @see noCache()
     */
    public function cache(callable $callable, $duration = null, $dependency = null)
    {
        $this->_queryCacheInfo[] = [$duration === null ? $this->queryCacheDuration : $duration, $dependency];
        try {
            $result = call_user_func($callable, $this);
            array_pop($this->_queryCacheInfo);
            return $result;
        } catch (\Exception $e) {
            array_pop($this->_queryCacheInfo);
            throw $e;
        } catch (\Throwable $e) {
            array_pop($this->_queryCacheInfo);
            throw $e;
        }
    }

    /**
     * Disables query cache temporarily.
     *
     * Queries performed within the callable will not use query cache at all. For example,
     *
     * ```php
     * $db->cache(function (Connection $db) {
     *
     *     // ... queries that use query cache ...
     *
     *     return $db->noCache(function (Connection $db) {
     *         // this query will not use query cache
     *         return $db->createCommand('SELECT * FROM customer WHERE id=1')->queryOne();
     *     });
     * });
     * ```
     *
     * @param callable $callable a PHP callable that contains DB queries which should not use query cache.
     * The signature of the callable is `function (Connection $db)`.
     * @return mixed the return result of the callable
     * @throws \Exception|\Throwable if there is any exception during query
     * @see enableQueryCache
     * @see queryCache
     * @see cache()
     */
    public function noCache(callable $callable)
    {
        $this->_queryCacheInfo[] = false;
        try {
            $result = call_user_func($callable, $this);
            array_pop($this->_queryCacheInfo);
            return $result;
        } catch (\Exception $e) {
            array_pop($this->_queryCacheInfo);
            throw $e;
        } catch (\Throwable $e) {
            array_pop($this->_queryCacheInfo);
            throw $e;
        }
    }

    /**
     * Returns the current query cache information.
     * This method is used internally by [[Command]].
     * @param int $duration the preferred caching duration. If null, it will be ignored.
     * @param \yii\caching\Dependency $dependency the preferred caching dependency. If null, it will be ignored.
     * @return array|null the current query cache information, or null if query cache is not enabled.
     * @internal
     */
    public function getQueryCacheInfo($duration, $dependency)
    {
        if (!$this->enableQueryCache) {
            return null;
        }

        $info = end($this->_queryCacheInfo);
        if (is_array($info)) {
            if ($duration === null) {
                $duration = $info[0];
            }
            if ($dependency === null) {
                $dependency = $info[1];
            }
        }

        if ($duration === 0 || $duration > 0) {
            if (is_string($this->queryCache) && Yii::$app) {
                $cache = Yii::$app->get($this->queryCache, false);
            } else {
                $cache = $this->queryCache;
            }
            if ($cache instanceof CacheInterface) {
                return [$cache, $duration, $dependency];
            }
        }

        return null;
    }

    /**
     * Flush sql schema and nosql space cache.
     * @return void
     */
    public function flushSchema()
    {
        $this->client->flushSpaces();
        if ($this->_schema !== null) {
            $this->_schema->refresh();
        }
    }

    /**
     * Establishes a DB connection.
     * It does nothing if a DB connection has already been established.
     * @throws \Exception if connection fails
     */
    public function open()
    {
        if ($this->client !== null) {
            return;
        }

        if (!empty($this->masters)) {
            $db = $this->getMaster();
            if ($db !== null) {
                $this->client = $db->client;
                return;
            }

            throw new InvalidConfigException('None of the master DB servers is available.');
        }

        if (empty($this->dsn)) {
            throw new InvalidConfigException('Connection::dsn cannot be empty.');
        }

        $token = 'Opening DB connection: ' . $this->dsn;
        $enableProfiling = $this->enableProfiling;
        try {
            if ($this->enableLogging) {
                Yii::info($token, __METHOD__);
            }

            if ($enableProfiling) {
                Yii::beginProfile($token, __METHOD__);
            }

            $this->client = $this->createTarantoolClientInstance();
            $this->initConnection();

            if ($enableProfiling) {
                Yii::endProfile($token, __METHOD__);
            }
        } catch (ClientException $e) {
            if ($enableProfiling) {
                Yii::endProfile($token, __METHOD__);
            }

            throw new \Exception($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Closes the currently active DB connection.
     * It does nothing if the connection is already closed.
     */
    public function close()
    {
        if ($this->_master) {
            if ($this->client === $this->_master->client) {
                $this->client = null;
            }

            $this->_master->close();
            $this->_master = false;
        }

        if ($this->client !== null) {
            Yii::debug('Closing DB connection: ' . $this->dsn, __METHOD__);
            $this->client = null;
        }

        if ($this->_slave) {
            $this->_slave->close();
            $this->_slave = false;
        }

        $this->_schema = null;
        $this->_queryCacheInfo = [];
        $this->_quotedTableNames = null;
        $this->_quotedColumnNames = null;
    }

    /**
     * @return Client the tarantool instance
     * @throws ClientException
     */
    protected function createTarantoolClientInstance()
    {
        $clientClass = $this->clientClass;
        if ($clientClass === null) {
            $clientClass = 'mhthnz\tarantool\Client';
        }
        return $clientClass::fromDsn($this->dsn, $this->getPacker([new StreamTransformer()]))
            ->withMiddleware(new LastInsertIDMiddleware($this));
    }

    /**
     * @param array<int, StreamTransformer> $transformers
     * @return PurePacker
     */
    protected function getPacker($transformers = [])
    {
        $extensions = [new ErrorExtension()];
        if (\class_exists(Uuid::class)) {
            $extensions[] = new UuidExtension();
        }
        if (\extension_loaded('decimal')) {
            $extensions[] = new DecimalExtension();

            return new PurePacker(
                new Packer(PackOptions::FORCE_STR, array_merge($extensions, $transformers)),
                new BufferUnpacker('', UnpackOptions::BIGINT_AS_DEC, $extensions)
            );
        }

        return new PurePacker(
            new Packer(PackOptions::FORCE_STR, array_merge($extensions, $transformers)),
            new BufferUnpacker('', null, $extensions)
        );
    }

    /**
     * Initializes the DB connection.
     * It then triggers an [[EVENT_AFTER_OPEN]] event.
     */
    protected function initConnection()
    {
        $data = $this->client->call("box.info");
        $version = $data[0]["version"];
        $this->version = substr($version, 0, strpos($version, '-'));
        $this->instanceUuid = $data[0]["uuid"];
        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    /**
     * Creates a command for execution.
     * @param string $sql the SQL statement to be executed
     * @param array $params the parameters to be bound to the SQL statement
     * @return Command the DB command
     */
    public function createCommand($sql = null, $params = [])
    {
        $config = [
            'class' => 'mhthnz\tarantool\Command',
            'db' => $this,
            'sql' => $sql,
            'enablePreparedStatements' => $this->enablePreparedStatements,
        ];
        /** @var Command $command */
        $command = Yii::createObject($config);
        return $command->bindValues($params);
    }

    /**
     * Create nosql command.
     * @param Request|null $request
     * @param array $params the parameters to be bound to the request
     * @return \mhthnz\tarantool\nosql\Command the DB command
     * @throws InvalidConfigException
     */
    public function createNosqlCommand(?Request $request = null)
    {
        $config = [
            'class' => 'mhthnz\tarantool\nosql\Command',
            'db' => $this,
        ];

        /** @var \mhthnz\tarantool\nosql\Command $command */
        $command = Yii::createObject($config);
        if ($request !== null) {
            $command->setRequest($request);
        }

        return $command;
    }

    /**
     * Returns the schema information for the database opened by this connection.
     * @return Schema the schema information for the database opened by this connection.
     */
    public function getSchema()
    {
        if ($this->_schema !== null) {
            return $this->_schema;
        }
        $config['class'] = 'mhthnz\tarantool\Schema';
        $config['db'] = $this;
        $this->_schema = Yii::createObject($config);
        $this->restoreQueryBuilderConfiguration();
        return $this->_schema;
    }

    /**
     * Returns the query builder for the current DB connection.
     * @return QueryBuilder the query builder for the current DB connection.
     */
    public function getQueryBuilder()
    {
        return $this->getSchema()->getQueryBuilder();
    }

    /**
     * Can be used to set [[QueryBuilder]] configuration via Connection configuration array.
     *
     * @param array $value the [[QueryBuilder]] properties to be configured.
     * @since 2.0.14
     */
    public function setQueryBuilder($value)
    {
        Yii::configure($this->getQueryBuilder(), $value);
        $this->_queryBuilderConfigurations[] = $value;
    }

    /**
     * Restores custom QueryBuilder configuration after the connection close/open cycle
     */
    private function restoreQueryBuilderConfiguration()
    {
        if ($this->_queryBuilderConfigurations === []) {
            return;
        }

        $queryBuilderConfigurations = $this->_queryBuilderConfigurations;
        $this->_queryBuilderConfigurations = [];
        foreach ($queryBuilderConfigurations as $queryBuilderConfiguration) {
            $this->setQueryBuilder($queryBuilderConfiguration);
        }
    }

    /**
     * Obtains the schema information for the named table.
     * @param string $name table name.
     * @param bool $refresh whether to reload the table schema even if it is found in the cache.
     * @return TableSchema|null table schema information. Null if the named table does not exist.
     */
    public function getTableSchema($name, $refresh = false)
    {
        return $this->getSchema()->getTableSchema($name, $refresh);
    }

    /**
     * Returns the ID of the last inserted row or sequence value.
     * @param string $sequenceName doesn't work for now
     * @return int|null the row ID of the last row inserted, or the last value retrieved from the sequence object
     */
    public function getLastInsertID($sequenceName = '')
    {
        return $this->_lastInsertID;
    }

    /**
     * Storing last insert id.
     * @param int $id
     */
    public function setLastInsertID($id)
    {
        $this->_lastInsertID = $id;
    }
    /**
     * Quotes a string value for use in a query.
     * Note that if the parameter is not a string, it will be returned without change.
     * @param string $value string to be quoted
     * @return string the properly quoted string
     */
    public function quoteValue($value)
    {
        return $this->getSchema()->quoteValue($value);
    }

    /**
     * Quotes a table name for use in a query.
     * If the table name contains schema prefix, the prefix will also be properly quoted.
     * If the table name is already quoted or contains special characters including '(', '[[' and '{{',
     * then this method will do nothing.
     * @param string $name table name
     * @return string the properly quoted table name
     */
    public function quoteTableName($name)
    {
        if (isset($this->_quotedTableNames[$name])) {
            return $this->_quotedTableNames[$name];
        }
        return $this->_quotedTableNames[$name] = $this->getSchema()->quoteTableName($name);
    }

    /**
     * Quotes a column name for use in a query.
     * If the column name contains prefix, the prefix will also be properly quoted.
     * If the column name is already quoted or contains special characters including '(', '[[' and '{{',
     * then this method will do nothing.
     * @param string $name column name
     * @return string the properly quoted column name
     */
    public function quoteColumnName($name)
    {
        if (isset($this->_quotedColumnNames[$name])) {
            return $this->_quotedColumnNames[$name];
        }
        return $this->_quotedColumnNames[$name] = $this->getSchema()->quoteColumnName($name);
    }

    /**
     * Processes a SQL statement by quoting table and column names that are enclosed within double brackets.
     * Tokens enclosed within double curly brackets are treated as table names, while
     * tokens enclosed within double square brackets are column names. They will be quoted accordingly.
     * Also, the percentage character "%" at the beginning or ending of a table name will be replaced
     * with [[tablePrefix]].
     * @param string $sql the SQL to be quoted
     * @return string the quoted SQL
     */
    public function quoteSql($sql)
    {
        return preg_replace_callback(
            '/(\\{\\{(%?[\w\-\. ]+%?)\\}\\}|\\[\\[([\w\-\. ]+)\\]\\])/',
            function ($matches) {
                if (isset($matches[3])) {
                    return $this->quoteColumnName($matches[3]);
                }

                return str_replace('%', $this->tablePrefix, $this->quoteTableName($matches[2]));
            },
            $sql
        );
    }


    /**
     * Returns a server version as a string comparable by [[\version_compare()]].
     * @return string server version as a string.
     * @since 2.0.14
     */
    public function getServerVersion()
    {
        return $this->version;
    }

    /**
     * Returns the tarantool client instance for the currently active slave connection.
     * When [[enableSlaves]] is true, one of the slaves will be used for read queries, and its tarantool client instance
     * will be returned by this method.
     * @param bool $fallbackToMaster whether to return a master tarantool client in case none of the slave connections is available.
     * @return Client the tarantool client instance for the currently active slave connection. `null` is returned if no slave connection
     * is available and `$fallbackToMaster` is false.
     */
    public function getSlaveClient($fallbackToMaster = true)
    {
        $db = $this->getSlave(false);
        if ($db === null) {
            return $fallbackToMaster ? $this->getMasterClient() : null;
        }

        return $db->client;
    }

    /**
     * Returns the tarantool client instance for the currently active master connection.
     * This method will open the master DB connection and then return.
     * @return Client the tarantool client instance for the currently active master connection.
     */
    public function getMasterClient()
    {
        $this->open();
        return $this->client;
    }

    /**
     * Returns the currently active slave connection.
     * If this method is called for the first time, it will try to open a slave connection when [[enableSlaves]] is true.
     * @param bool $fallbackToMaster whether to return a master connection in case there is no slave connection available.
     * @return Connection the currently active slave connection. `null` is returned if there is no slave available and
     * `$fallbackToMaster` is false.
     */
    public function getSlave($fallbackToMaster = true)
    {
        if (!$this->enableSlaves) {
            return $fallbackToMaster ? $this : null;
        }

        if ($this->_slave === false) {
            $this->_slave = $this->openFromPool($this->slaves, $this->slaveConfig);
        }

        return !$this->_slave && $fallbackToMaster ? $this : $this->_slave;
    }

    /**
     * Returns the currently active master connection.
     * If this method is called for the first time, it will try to open a master connection.
     * @return Connection the currently active master connection. `null` is returned if there is no master available.
     * @since 2.0.11
     */
    public function getMaster()
    {
        if ($this->_master === false) {
            $this->_master = $this->shuffleMasters
                ? $this->openFromPool($this->masters, $this->masterConfig)
                : $this->openFromPoolSequentially($this->masters, $this->masterConfig);
        }

        return $this->_master;
    }

    /**
     * Executes the provided callback by using the master connection.
     *
     * This method is provided so that you can temporarily force using the master connection to perform
     * DB operations even if they are read queries. For example,
     *
     * ```php
     * $result = $db->useMaster(function ($db) {
     *     return $db->createCommand('SELECT * FROM user LIMIT 1')->queryOne();
     * });
     * ```
     *
     * @param callable $callback a PHP callable to be executed by this method. Its signature is
     * `function (Connection $db)`. Its return value will be returned by this method.
     * @return mixed the return value of the callback
     * @throws \Exception|\Throwable if there is any exception thrown from the callback
     */
    public function useMaster(callable $callback)
    {
        if ($this->enableSlaves) {
            $this->enableSlaves = false;
            try {
                $result = call_user_func($callback, $this);
            } catch (\Exception $e) {
                $this->enableSlaves = true;
                throw $e;
            } catch (\Throwable $e) {
                $this->enableSlaves = true;
                throw $e;
            }
            // TODO: use "finally" keyword when miminum required PHP version is >= 5.5
            $this->enableSlaves = true;
        } else {
            $result = call_user_func($callback, $this);
        }

        return $result;
    }

    /**
     * Opens the connection to a server in the pool.
     *
     * This method implements load balancing and failover among the given list of the servers.
     * Connections will be tried in random order.
     * For details about the failover behavior, see [[openFromPoolSequentially]].
     *
     * @param array $pool the list of connection configurations in the server pool
     * @param array $sharedConfig the configuration common to those given in `$pool`.
     * @return Connection the opened DB connection, or `null` if no server is available
     * @throws InvalidConfigException if a configuration does not specify "dsn"
     * @see openFromPoolSequentially
     */
    protected function openFromPool(array $pool, array $sharedConfig)
    {
        shuffle($pool);
        return $this->openFromPoolSequentially($pool, $sharedConfig);
    }

    /**
     * Opens the connection to a server in the pool.
     *
     * This method implements failover among the given list of servers.
     * Connections will be tried in sequential order. The first successful connection will return.
     *
     * If [[serverStatusCache]] is configured, this method will cache information about
     * unreachable servers and does not try to connect to these for the time configured in [[serverRetryInterval]].
     * This helps to keep the application stable when some servers are unavailable. Avoiding
     * connection attempts to unavailable servers saves time when the connection attempts fail due to timeout.
     *
     * If none of the servers are available the status cache is ignored and connection attempts are made to all
     * servers (Since version 2.0.35). This is to avoid downtime when all servers are unavailable for a short time.
     * After a successful connection attempt the server is marked as available again.
     *
     * @param array $pool the list of connection configurations in the server pool
     * @param array $sharedConfig the configuration common to those given in `$pool`.
     * @return Connection the opened DB connection, or `null` if no server is available
     * @throws InvalidConfigException if a configuration does not specify "dsn"
     * @since 2.0.11
     * @see openFromPool
     * @see serverStatusCache
     */
    protected function openFromPoolSequentially(array $pool, array $sharedConfig)
    {
        if (empty($pool)) {
            return null;
        }
        if (!isset($sharedConfig['class'])) {
            $sharedConfig['class'] = get_class($this);
        }

        $cache = is_string($this->serverStatusCache) ? Yii::$app->get($this->serverStatusCache, false) : $this->serverStatusCache;
        if (($result = $this->tryOpenConnections($pool, $cache, $sharedConfig)) !== null) {
            return $result;
        }

        return $this->tryOpenConnectionsCached($pool, $cache, $sharedConfig);
    }

    /**
     * @param array $pool
     * @param $cache
     * @param $sharedConfig
     * @return Connection|null
     * @throws InvalidConfigException
     */
    protected function tryOpenConnectionsCached(array $pool, $cache, $sharedConfig)
    {
        if ($cache instanceof CacheInterface) {
            // if server status cache is enabled and no server is available
            // ignore the cache and try to connect anyway
            // $pool now only contains servers we did not already try in the loop above
            foreach ($pool as $config) {

                /* @var $db Connection */
                $db = Yii::createObject($config);
                try {
                    $db->open();
                } catch (\Exception $e) {
                    Yii::warning("Connection ({$config['dsn']}) failed: " . $e->getMessage(), __METHOD__);
                    continue;
                }

                // mark this server as available again after successful connection
                $cache->delete([__METHOD__, $config['dsn']]);

                return $db;
            }
        }

        return null;
    }

    /**
     * @param array $pool
     * @param $cache
     * @param $sharedConfig
     * @return Connection|null
     * @throws InvalidConfigException
     */
    protected function tryOpenConnections(array $pool, $cache, $sharedConfig)
    {
        foreach ($pool as $i => $config) {
            $pool[$i] = $config = array_merge($sharedConfig, $config);
            if (empty($config['dsn'])) {
                throw new InvalidConfigException('The "dsn" option must be specified.');
            }

            $key = [__METHOD__, $config['dsn']];
            if ($cache instanceof CacheInterface && $cache->get($key)) {
                // should not try this dead server now
                continue;
            }

            /* @var $db Connection */
            $db = Yii::createObject($config);

            try {
                $db->open();
                return $db;
            } catch (\Exception $e) {
                Yii::warning("Connection ({$config['dsn']}) failed: " . $e->getMessage(), __METHOD__);
                if ($cache instanceof CacheInterface) {
                    // mark this server as dead and only retry it after the specified interval
                    $cache->set($key, 1, $this->serverRetryInterval);
                }
                // exclude server from retry below
                unset($pool[$i]);
            }
        }

        return null;
    }

    /**
     * Close the connection before serializing.
     * @return array
     */
    public function __sleep()
    {
        $fields = (array) $this;

        unset($fields['client']);
        unset($fields["\000" . __CLASS__ . "\000" . '_master']);
        unset($fields["\000" . __CLASS__ . "\000" . '_slave']);
        unset($fields["\000" . __CLASS__ . "\000" . '_schema']);

        return array_keys($fields);
    }

    /**
     * Reset the connection after cloning.
     */
    public function __clone()
    {
        parent::__clone();

        $this->_master = false;
        $this->_slave = false;
        $this->_schema = null;
        $this->client = null;

    }
}
