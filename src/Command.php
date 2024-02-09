<?php

namespace mhthnz\tarantool;

use MessagePack\Type\Bin;
use Tarantool\Client\Keys;
use Tarantool\Client\PreparedStatement;
use Tarantool\Client\Response;
use Tarantool\Client\SqlQueryResult;
use Yii;
use yii\base\NotSupportedException;
use yii\helpers\ArrayHelper;
use Exception;

/**
 * {@inheritdoc}
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class Command extends \yii\db\Command
{
    /**
     * @var PreparedStatement
     */
    public $preparedStatement;

    /**
     * @var Response
     */
    public $response;
    /**
     * @var Connection the DB connection that this command is associated with
     */
    public $db;

    /**
     * @var bool whether to enable using of prepared statements
     *
     * @see enablePreparedStatements
     */
    public $enablePreparedStatements;

    /**
     * @var callable a callable (e.g. anonymous function) that is called when [[\yii\db\Exception]] is thrown
     * when executing the command.
     */
    private $_retryHandler;


    /**
     * {@inheritdoc}
     */
    protected function setRetryHandler(callable $handler)
    {
        $this->_retryHandler = $handler;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function query()
    {
        return $this->queryInternal('');
    }

    /**
     * {@inheritdoc}
     */
    public function queryAll($fetchMode = null)
    {
        return $this->queryInternal('fetchAll');
    }

    /**
     * {@inheritdoc}
     */
    public function queryOne($fetchMode = null)
    {
        return $this->queryInternal('fetch');
    }

    /**
     * {@inheritdoc}
     */
    public function queryScalar()
    {
        return $this->queryInternal('fetchColumn');
    }

    /**
     * {@inheritdoc}
     */
    public function queryColumn()
    {
        $data = $this->queryInternal('fetchAll');
        if (!count($data)) {
            return [];
        }
        foreach($data[0] as $key => $unused) {
            $firstField = $key;
            break;
        }
        return ArrayHelper::getColumn($data, $firstField);
    }

    /**
     * {@inheritdoc}
     */
    public function execute()
    {
        $sql = $this->getSql();
        list($profile, $rawSql) = $this->logQuery('mhthnz\\tarantool\\Command::' . __METHOD__);

        if ($sql == '') {
            return 0;
        }

        $this->prepare(false);

        try {
            $profile and Yii::beginProfile($rawSql, 'mhthnz\\tarantool\\Command::' . __METHOD__);

            $this->internalExecute($rawSql);
            $data = $this->response->tryGetBodyField(Keys::SQL_INFO);
            $n = $data !== null && isset($data[Keys::SQL_INFO_ROW_COUNT]) ? $data[Keys::SQL_INFO_ROW_COUNT] : 0;

            $profile and Yii::endProfile($rawSql, 'mhthnz\\tarantool\\Command::' . __METHOD__);
            $this->refreshTableSchema();

            return $n;
        } catch (\Exception $e) {
            $profile and Yii::endProfile($rawSql, 'mhthnz\\tarantool\\Command::' . __METHOD__);
            throw $e;
        }
    }


    /**
     * {@inheritdoc}
     */
    public function bindValue($name, $value, $dataType = null)
    {
        if ($dataType === Schema::TYPE_BINARY) {
            $this->pendingParams[$name] = new Bin($value);
            $this->params[$name] = new Bin($value);
            return $this;
        }
        $this->pendingParams[$name] = $value;
        $this->params[$name] = $value;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function bindValues($values)
    {
        if (empty($values)) {
            return $this;
        }

        foreach ($values as $name => $value) {
            if (is_array($value)) {
                $this->pendingParams[$name] = $value;
                $this->params[$name] = $value[0];
            } else {
                $this->pendingParams[$name] = $value;
                $this->params[$name] = $value;
            }
        }

        return $this;
    }


    /**
     * {@inheritdoc}
     */
    protected function internalExecute($rawSql)
    {
        $attempt = 0;
        while (true) {
            try {
                $attempt++;
                if (null !== $this->preparedStatement) {
                    $this->response = $this->preparedStatement->execute(...$this->formatParams());
                }
                else {
                    $sql = $this->getSql();
                    if ($this->db->getSchema()->isReadQuery($sql)) {
                        $client = $this->db->getSlaveClient();
                    } else {
                        $client = $this->db->getMasterClient();
                    }
                    $this->response = $client->execute($sql, ... $this->formatParams());
                }
                break;
            } catch (\Exception $e) {
                $rawSql = $rawSql ?: $this->getRawSql();
                $e = $this->db->getSchema()->convertException($e, $rawSql);
                if ($this->_retryHandler === null || !call_user_func($this->_retryHandler, $e, $attempt)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return array
     */
    public function formatParams()
    {
        $result = [];
        if (ArrayHelper::isAssociative($this->pendingParams)) {
            foreach ($this->pendingParams as $key => $pendingParam) {
                $result[] = [$key => $pendingParam];
            }
            return $result;
        }
        return $this->pendingParams;
    }

    /**
     * @param bool|null $forRead
     * @throws \Exception
     */
    public function prepare($forRead = null)
    {
        if ($this->preparedStatement || false === $this->enablePreparedStatements) {
            return;
        }

        $sql = $this->getSql();
        if ($sql === '') {
            return;
        }

        if ($forRead || $forRead === null && $this->db->getSchema()->isReadQuery($sql)) {
            $client = $this->db->getSlaveClient();
        } else {
            $client = $this->db->getMasterClient();
        }

        try {
            $this->preparedStatement = $client->prepare($sql);
        } catch (\Exception $e) {
            $message = $e->getMessage() . "\nFailed to prepare SQL: $sql";
            throw new \Exception($message, (int) $e->getCode(), $e);
        } catch (\Throwable $e) {
            $message = $e->getMessage() . "\nFailed to prepare SQL: $sql";
            throw new \Exception($message, (int) $e->getCode(), $e);
        }
    }

    /**
     * Cancels the execution of the SQL statement.
     * This method mainly sets [[pdoStatement]] to be null.
     */
    public function cancel()
    {
        $this->preparedStatement = null;
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($name, &$value, $dataType = null, $length = null, $driverOptions = null)
    {
        $this->prepare();
        if ($dataType === Schema::TYPE_BINARY) {
            $this->params[$name] = new Bin($value);
            $this->pendingParams[$name] = new Bin($value);
        } else {
            $this->params[$name] = &$value;
            $this->pendingParams[$name] = &$value;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function getCacheKey($method, $fetchMode, $rawSql)
    {
        $params = $this->params;
        ksort($params);
        return [
            __CLASS__,
            $method,
            $this->db->dsn,
            $this->db->instanceUuid,
            $this->getSql(),
            json_encode($params),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function queryInternal($method = '', $fetchMode = null)
    {
        list($profile, $rawSql) = $this->logQuery('mhthnz\\tarantool\\Command::query');
        $resultArray = [];

        // Trying to get from cache
        if ($method !== '') {
            $resultArray = $this->queryInternalFromCache($method);
            if ($resultArray['result'] !== null) {
                return $resultArray['result'];
            }
        }
        
        $this->prepare(true);
        $cache = $resultArray['cache'] ?? null;
        $result = $this->queryInternalFetch($method, $profile, $rawSql, $cache);

        return $result;
    }

    /**
     * @param string $method
     * @return array
     */
    protected function queryInternalFromCache(string $method)
    {
        $info = $this->db->getQueryCacheInfo($this->queryCacheDuration, $this->queryCacheDependency);
        $resultArray = [];
        if (is_array($info)) {
            /* @var $cache \yii\caching\CacheInterface */
            $cache = $info[0];
            $cacheKey = $this->getCacheKey($method, null, '');
            $resultArray['cache']['cache'] = $cache;
            $resultArray['cache']['cacheKey'] = $cacheKey;
            $resultArray['cache']['info'] = $info;
            $result = $cache->get($cacheKey);

            if (is_array($result) && isset($result[0])) {
                Yii::debug('Query result served from cache', 'mhthnz\\tarantool\\Command::query');
                $resultArray['result'] = $result[0];
                return $resultArray;
            }
        }
        $resultArray['result'] = null;
        return $resultArray;
    }

    /**
     * @param string $method
     * @param bool $profile
     * @param string $rawSql
     * @param array|null $cache
     * @throws \yii\db\Exception
     */
    protected function queryInternalFetch($method, $profile, $rawSql, $cache)
    {
        try {
            $profile and Yii::beginProfile($rawSql, 'mhthnz\\tarantool\\Command::query');

            $this->internalExecute($rawSql);
            if ($method === '') {
                $result = new DataReader($this);
            } else {
                $result = FetchMethod::parseResponse($method, $this->response);
            }

            $profile and Yii::endProfile($rawSql, 'mhthnz\\tarantool\\Command::query');
        } catch (\Exception $e) {
            $profile and Yii::endProfile($rawSql, 'mhthnz\\tarantool\\Command::query');
            throw $e;
        }

        if ($cache !== null) {
            $cacheObj = $cache['cache'];
            $info = $cache['info'];
            $cacheKey = $cache['cacheKey'];

            $cacheObj->set($cacheKey, [$result], $info[1], $info[2]);
            Yii::debug('Saved query result in cache', 'mhthnz\\tarantool\\Command::query');
        }

        return $result;
    }

    public function __destruct()
    {
        if ($this->preparedStatement !== null) {
            $this->preparedStatement->close();
        }
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function alterColumn($table, $column, $type)
    {
        throw new NotSupportedException("Tarantool doesn't support changing column definition");
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function dropColumn($table, $column)
    {
        throw new NotSupportedException("Tarantool doesn't support dropping columns");
    }
    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function renameColumn($table, $oldName, $newName)
    {
        throw new NotSupportedException("Tarantool doesn't support renaming columns");
    }

    /**
     * {@inheritdoc}
     */
    public function dropPrimaryKey($name, $table)
    {
        $sql = $this->db->getQueryBuilder()->dropIndex($name, $table);
        return $this->setSql($sql)->requireTableSchemaRefresh($table);
    }

    /**
     * Creates an INSERT Command that works like upsert.
     *
     * For example,
     *
     * ```php
     * $connection->createCommand()->insertOrReplace('user', [
     *     'id' => 10,
     *     'name' => 'Sam',
     *     'age' => 30,
     * ])->execute();
     * ```
     *
     * The method will properly escape the table and column names.
     * If the row with id 10 already exists, other fields will be replaced.
     * @see https://github.com/tarantool/tarantool/wiki/SQL%3A-ON-CONFLICT-clause-for-INSERT,-UPDATE-statements
     *
     *
     * Note that the created command is not executed until [[execute()]] is called.
     *
     * @param string $table the table that new rows will be inserted into.
     * @param array|\yii\db\Query $columns the column data (name => value) to be inserted into the table or instance
     * of [[yii\db\Query|Query]] to perform INSERT INTO ... SELECT SQL statement.
     * Passing of [[yii\db\Query|Query]] is available since version 2.0.11.
     * @param bool $loadSchema table schema will be load for making insert statement
     * @return $this the command object itself
     */
    public function insertOrReplace($table, $columns, $loadSchema = true)
    {
        $params = [];
        $sql = $this->db->getQueryBuilder()->insertOrReplace($table, $columns, $params, $loadSchema);

        return $this->setSql($sql)->bindValues($params);
    }
}
