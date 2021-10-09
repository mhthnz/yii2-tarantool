<?php

namespace mhthnz\tarantool\nosql;

use mhthnz\tarantool\Client;
use mhthnz\tarantool\client\SpaceInterface;
use mhthnz\tarantool\Connection;
use Tarantool\Client\Exception\ClientException;
use Tarantool\Client\Keys;
use Tarantool\Client\Request\CallRequest;
use Tarantool\Client\Request\DeleteRequest;
use Tarantool\Client\Request\EvaluateRequest;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Client\Request\ReplaceRequest;
use Tarantool\Client\Request\Request;
use Tarantool\Client\Request\SelectRequest;
use Tarantool\Client\Request\UpdateRequest;
use Tarantool\Client\Request\UpsertRequest;
use Tarantool\Client\RequestTypes;
use Tarantool\Client\Response;
use Tarantool\Client\Schema\Operations;
use yii\base\BaseObject;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\helpers\ArrayHelper;

/**
 * Command provides access to data using nosql requests.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class Command extends BaseObject
{
    /**
     * @var Connection
     */
    public $db;

    /**
     * Force set the type of request (true for read and false for write).
     * It needs for correct slaves supporting.
     *
     * @var bool|null
     */
    public $forRead;

    /**
     * Map for detecting what types are for read and can be used by slave.
     * @var array
     */
    public static $forReadTypesMap = [
        RequestTypes::SELECT => true,
        RequestTypes::CALL => true,
        RequestTypes::DELETE => false,
        RequestTypes::UPDATE => false,
        RequestTypes::UPSERT => false,
        RequestTypes::EVALUATE => false,
        RequestTypes::REPLACE => false,
    ];

    /**
     * @var Response
     */
    private $_response;

    /**
     * @var Request
     */
    private $_request;

    /**
     * @var string|null
     */
    private $_stringRequest;

    /**
     * @param Request $request
     */
    public function setRequest(Request $request)
    {
        $this->_request = $request;

        return $this;
    }

    /**
     * @return array|null
     */
    public function getResponseData()
    {
        if ($this->_response === null) {
            return null;
        }
        return $this->_response->tryGetBodyField(Keys::DATA);
    }

    /**
     * @return $this
     * @throws ClientException
     * @throws \Throwable
     */
    public function execute()
    {
        [$profile, $rawRequest] = $this->logQuery('mhthnz\\tarantool\\nosql\\Command::' . __METHOD__);
        if (!$this->_request instanceof Request) {
            return $this;
        }

        try {
            $profile and Yii::beginProfile($rawRequest, 'mhthnz\\tarantool\\nosql\\Command::' . __METHOD__);

            $this->internalExecute();

            $profile and Yii::endProfile($rawRequest, 'mhthnz\\tarantool\\nosql\\Command::' . __METHOD__);
        } catch (\Throwable $e) {
            $profile and Yii::endProfile($rawRequest, 'mhthnz\\tarantool\\nosql\\Command::' . __METHOD__);
            throw $e;
        }

        return $this;
    }

    /**
     * Internal executing request. Handling lua encoding errors.
     * It doesn't retry request because lua encoding errors may be thrown even when request is successfully done.
     * @throws \Throwable
     */
    protected function internalExecute()
    {
        if ($this->forRead === null) {
            if (isset(static::$forReadTypesMap[$this->_request->getType()])) {
                $forRead = static::$forReadTypesMap[$this->_request->getType()];
            } else {
                $forRead = false;
            }
        } else {
            $forRead = $this->forRead;
        }

        $client = $forRead ? $this->db->slaveClient : $this->db->masterClient;

        try {
            $this->_response = $client->getHandler()->handle($this->_request);
        } catch (\Throwable $e) {
            $this->handleException($e, $client);
        }
    }

    /**
     * Handling lua encoding errors.
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/net_box/#lua-function.conn.call
     * @param \Throwable $e
     * @param Client $client
     * @throws \Throwable
     */
    protected function handleException(\Throwable $e, Client $client)
    {
        if (stripos($e->getMessage(), 'unsupported lua type') !== false) {
            if (!$this->db->handleLuaEncodingErrors) {
                throw new \Exception("Lua encoding error, you may want to add to your tarantool config: 
                    msgpack = require('msgpack'); msgpack.cfg{encode_invalid_as_nil = true} or set Connection::\$handleLuaEncodingErrors = true", $e->getCode(), $e);
            }

            try {
                $client->evaluate("msgpack = require('msgpack'); msgpack.cfg{encode_invalid_as_nil = true}");
            } catch (\Throwable $exception) {
                throw new \Exception("Can not set msgpack.cfg.encode_invalid_as_nil probably tarantool user doesn't have rights for evaluating expressions. You may want to add to your tarantool config: 
                    msgpack = require('msgpack'); msgpack.cfg{encode_invalid_as_nil = true} or just eval rights to current user.", $exception->getCode(), $exception);
            }
        } else {
            throw $e;
        }
    }

    /**
     * Convert current request to count request.
     * @return Command
     * @throws ClientException
     * @throws InvalidConfigException
     */
    public function count()
    {
        $request = RequestHelper::countRequest($this->_request, $this->db);

        return $this->db->createNosqlCommand($request);
    }

    /**
     * Get max tuple from space by index.
     * Primary index will be used by default.
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_index/max/
     * @return Command
     * @throws ClientException
     * @throws InvalidConfigException
     */
    public function max()
    {
        $request = RequestHelper::maxRequest($this->_request, $this->db);

        return $this->db->createNosqlCommand($request);
    }

    /**
     * Get random tuple from space.
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_index/random/
     * @param mixed $seed
     * @return Command
     * @throws ClientException
     * @throws InvalidConfigException
     */
    public function random($seed = null)
    {
        $request = RequestHelper::randomRequest($this->_request, $this->db, $seed);

        return $this->db->createNosqlCommand($request);
    }

    /**
     * Get min tuple from space by index.
     * Primary index will be used by default.
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_index/min/
     * @return Command
     * @throws ClientException
     * @throws InvalidConfigException
     */
    public function min()
    {
        $request = RequestHelper::minRequest($this->_request, $this->db);

        return $this->db->createNosqlCommand($request);
    }

    /**
     * @param string $expr
     * @param array $params
     * @return $this
     */
    public function evaluate(string $expr, array $params = [])
    {
        $this->_request = new EvaluateRequest($expr, $params);

        return $this;
    }

    /**
     * @param string $func
     * @param array $params
     * @return $this
     */
    public function call(string $func, array $params = [])
    {
        $this->_request = new CallRequest($func, $params);

        return $this;
    }

    /**
     * @param string $name
     * @param array $format
     * @param string $engine
     * @param array $options Additional options
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_schema/space_create/#lua-function.box.schema.create_space
     * 
     * @return $this
     */
    public function createSpace(string $name, array $format = [], string $engine = 'memtx', array $options = [])
    {
        if (count($format)) {
            $options['format'] = $format;
        }
        $options['engine'] = $engine;
        $this->_request = new CallRequest("box.schema.create_space", [$name, $options]);

        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function dropSpace(string $name)
    {
        // Get space for validating name
        $this->db->getMasterClient()->getSpace($name);
        $this->_request = new CallRequest("box.space.$name:drop");

        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function truncateSpace(string $name)
    {
        $this->db->getMasterClient()->getSpace($name);
        $this->_request = new CallRequest("box.space.$name:truncate");

        return $this;
    }

    /**
     * Example:
     * createIndex('myspace', 'unique-index', [1 => 'unsigned', 3 => 'unsigned'], 'hash', true)
     *
     * @param string $space
     * @param string $indexName
     * @param array $fields
     * @param bool $unique
     * @return $this
     */
    public function createIndex(string $space, string $indexName, array $fields, bool $unique = false, string $type = "tree")
    {
        // keep this for validate space name for preventing vulnerabilities (like code injection)
        $this->db->getMasterClient()->getSpace($space);
        $opts = [];
        foreach ($fields as $field => $definition) {
            $opts['parts'][] = ['field' => $field, 'type' => $definition];
        }
        if (strtolower($type) === 'hash') {
            $opts['unique'] = true;
        } else {
            $opts['unique'] = $unique;
        }

        $opts['type'] = $type;
        $this->_request = new CallRequest("box.space.$space:create_index", [$indexName, $opts]);

        return $this;
    }

    /**
     * @param string $space
     * @param string $indexName
     * @return $this
     */
    public function dropIndex(string $space, string $indexName)
    {
        $spaceObj = $this->db->getMasterClient()->getSpace($space);
        $spaceObj->getIndexIDByName($indexName);
        $this->_request = new CallRequest("box.space.$space.index.$indexName:drop");

        return $this;
    }

    /**
     * @param string $space
     * @param mixed $condition Can be:
     *
     * Just key:
     * 1
     * 2
     *
     * Composite key:
     * [1, 2]
     * ['a', 'b']
     *
     * Condition using index name:
     * ['primary' => 1]
     * ['some-other-index-name' => 'value123']
     *
     * @param Operations $operations Operations that will perform
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_space/update/#box-space-update
     * @return $this
     */
    public function update(string $space, $condition, Operations $operations)
    {
        $spaceObj = $this->db->getMasterClient()->getSpace($space);
        [$indexID, $key] = $this->processCondition($spaceObj, $condition);
        $this->_request = new UpdateRequest($spaceObj->getID(), $indexID, $key, $operations->toArray());

        return $this;
    }

    /**
     * @param string $space
     * @param array $tuple
     *
     * if tuple's primary key exists then will be performed or tuple will be inserted.
     *
     * @param Operations $operations Operations that will perform
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_space/update/#box-space-update
     * @return $this
     */
    public function upsert(string $space, array $tuple, Operations $operations)
    {
        $spaceObj = $this->db->getMasterClient()->getSpace($space);
        $this->_request = new UpsertRequest($spaceObj->getID(), $tuple, $operations->toArray());

        return $this;
    }

    /**
     * @param string $space
     * @param array $tuple
     * @return $this
     */
    public function replace(string $space, array $tuple)
    {
        $spaceObj = $this->db->getMasterClient()->getSpace($space);
        $this->_request = new ReplaceRequest($spaceObj->getID(), $tuple);

        return $this;
    }

    /**
     * @param string $space
     * @param array $values
     * @return $this
     */
    public function insert(string $space, array $values)
    {
        $space = $this->db->getMasterClient()->getSpace($space);
        $this->_request = new InsertRequest($space->getID(), $values);

        return $this;
    }

    /**
     * @param string $space
     * @param mixed $condition Can be:
     * Scalar key:
     * 1
     * 2
     * 'val'
     *
     * Composite key:
     * [1, 2]
     * ['one', 'two']
     *
     * Condition using index name:
     * ['primary' => 1]
     * ['some-other-index-name' => 'value123']
     *
     * @return $this
     */
    public function delete(string $space, $condition)
    {
        $spaceObj = $this->db->getMasterClient()->getSpace($space);
        [$indexID, $key] = $this->processCondition($spaceObj, $condition);
        $this->_request = new DeleteRequest($spaceObj->getID(), $indexID, $key);

        return $this;
    }

    /**
     * Getting the first column of the first row.
     *
     * @return mixed|null
     * @throws ClientException
     * @throws \Throwable
     */
    public function queryScalar()
    {
        $this->execute();
        $data = $this->_response->tryGetBodyField(Keys::DATA);
        if (!isset($data[0])) {
            return null;
        }

        if (is_array($data[0]) && isset($data[0][0])) {
            if (is_array($data[0][0])) {
                return array_shift($data[0][0]);
            }
            return $data[0][0];
        }

        return $data[0];
    }

    /**
     * @return array<int, array>
     * @throws ClientException
     * @throws \Throwable
     */
    public function queryAll()
    {
        $this->execute();
        $data = $this->_response->tryGetBodyField(Keys::DATA, []);
        if (($this->_request instanceof CallRequest or $this->_request instanceof EvaluateRequest) && isset($data[0]) && count($data) === 1) {
            return $data[0];
        }

        return $data;
    }

    /**
     * @return array|null
     * @throws ClientException
     * @throws \Throwable
     */
    public function queryOne()
    {
        $this->execute();
        $data = $this->_response->tryGetBodyField(Keys::DATA);
        if (!$data) {
            return null;
        }
        if (isset($data[0]) && count($data) === 1) {
            if (RequestHelper::isMulti($data[0])) {
                return array_shift($data[0]);
            }

            return $data[0];
        }
        if (RequestHelper::isMulti($data)) {
            return array_shift($data);
        }

        return $data;
    }

    /**
     * @return array|null
     * @throws NotSupportedException
     * @throws ClientException
     * @throws \Throwable
     * @throws InvalidConfigException
     */
    public function queryGet()
    {
        if ($this->_request instanceof SelectRequest) {
            return $this->db->createNosqlCommand(RequestHelper::getRequest($this->_request, $this->db))->queryOne();
        }

        throw new NotSupportedException("Only select request supports queryGet.");
    }

    /**
     * @param int $fieldNo
     * @return array
     * @throws ClientException
     * @throws \Throwable
     */
    public function queryColumn($fieldNo = 0)
    {
        $this->execute();
        $data = $this->_response->tryGetBodyField(Keys::DATA);
        $result = [];
        if (($this->_request instanceof CallRequest or $this->_request instanceof EvaluateRequest) && isset($data[0]) && count($data) === 1) {
            $data = $data[0];
        }
        foreach ($data as $row) {
            if (isset($row[$fieldNo])) {
                $result[] = $row[$fieldNo];
            }
        }

        return $result;
    }

    /**
     * @return string|null
     */
    public function getStringRequest(): ?string
    {
        if ($this->_request === null) {
            return null;
        }
        if ($this->_stringRequest === null) {
            $this->_stringRequest = RequestHelper::stringifyRequestForDebug($this->_request);
        }

        return $this->_stringRequest;
    }

    /**
     * Logs the current database query if query logging is enabled and returns
     * the profiling token if profiling is enabled.
     * @param string $category the log category.
     * @return array array of two elements, the first is boolean of whether profiling is enabled or not.
     * The second is the rawSql if it has been created.
     */
    protected function logQuery($category)
    {
        if ($this->db->enableLogging) {
            $req = $this->getStringRequest();
            Yii::info($req, $category);
        }
        if (!$this->db->enableProfiling) {
            return [false, $req ?? null];
        }

        return [true, $req ?? $this->getStringRequest()];
    }

    /**
     * @param SpaceInterface $space
     * @param $condition
     * @return array
     */
    protected function processCondition(SpaceInterface $space, $condition)
    {
        $indexID = 0;
        $key = $condition;
        if (is_array($condition) && ArrayHelper::isAssociative($condition)) {
            reset($condition);
            $indexName = key($condition);
            $indexID = $space->getIndexIDByName($indexName);
            $key = $condition[$indexName];
        }
        if (!is_array($key)) {
            $key = [$key];
        }
        return [$indexID, $key];
    }

}