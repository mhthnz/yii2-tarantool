<?php

namespace mhthnz\tarantool;

use Tarantool\Client\Keys;
use Tarantool\Client\Response;
use Tarantool\Client\SqlQueryResult;
use yii\base\InvalidCallException;

class FetchMethod
{
    /**
     * @param string $method
     * @param Response $response
     * @return array|false|mixed|null
     * @throws \Exception
     */
    public static function parseResponse(string $method, Response $response)
    {
        $queryResult = new SqlQueryResult(
            $response->getBodyField(Keys::DATA),
            $response->getBodyField(Keys::METADATA)
        );

        switch ($method) {
            case 'fetch':
                return self::fetch($queryResult);
            case 'fetchAll':
                return self::fetchAll($queryResult);
            case 'fetchColumn':
                return self::fetchColumn($queryResult);
            default:
                throw new InvalidCallException($method . ' is not supported');
        }
    }

    /**
     * @param SqlQueryResult $result
     * @return array
     * @throws \Exception
     */
    public static function fetchAll(SqlQueryResult $result)
    {
        $generator = $result->getIterator();
        $result = [];
        foreach ($generator as $row) {
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param SqlQueryResult $result
     * @return false|mixed
     * @throws \Exception
     */
    public static function fetch(SqlQueryResult $result)
    {
        if (!$result->count()) {
            return false;
        }
        $generator = $result->getIterator();

        return $generator->current();
    }

    /**
     * @param SqlQueryResult $result
     * @return false|mixed|null
     * @throws \Exception
     */
    public static function fetchColumn(SqlQueryResult $result)
    {
        if (!$result->count()) {
            return false;
        }
        $generator = $result->getIterator();
        $r = $generator->current();

        return array_shift($r);
    }

}