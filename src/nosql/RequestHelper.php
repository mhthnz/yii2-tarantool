<?php

namespace mhthnz\tarantool\nosql;

use MessagePack\Type\Bin;
use mhthnz\tarantool\Connection;
use Tarantool\Client\Exception\ClientException;
use Tarantool\Client\Keys;
use Tarantool\Client\Request\CallRequest;
use Tarantool\Client\Request\Request;
use Tarantool\Client\Request\SelectRequest;
use Tarantool\Client\RequestTypes;
use Tarantool\Client\Schema\IteratorTypes;

/**
 * Request helper uses for converting Request to string for using in call() method.
 * Helps to debug nosql requests.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class RequestHelper
{
    /**
     * Max string data length.
     * @var int
     */
    public static $MAX_STRING_LENGTH = 10;

    /**
     * @var string[]
     */
    public static $ITERATOR_MAP = [
        IteratorTypes::EQ => 'EQ',
        IteratorTypes::REQ => 'REQ',
        IteratorTypes::ALL => 'ALL',
        IteratorTypes::LT => 'LT',
        IteratorTypes::LE => 'LE',
        IteratorTypes::GE => 'GE',
        IteratorTypes::GT => 'GT',
    ];

    /**
     * Stringify nosql requests for debug panel and logs.
     * @param Request $request
     * @return string
     */
    public static function stringifyRequestForDebug(Request $request): string
    {
        switch ($request->getType()):
            case RequestTypes::SELECT:
                $arg = self::buildArgs(self::getBodyField($request, Keys::KEY));
                return self::buildCommon($request) . ":select(" . (empty($arg) ? '{}' : $arg) . ", " . self::buildIterator(self::getBodyField($request, Keys::ITERATOR), self::getBodyField($request, Keys::LIMIT), self::getBodyField($request, Keys::OFFSET)) . ")";
            case RequestTypes::CALL:
                return "CALL " . self::getBodyField($request, Keys::FUNCTION_NAME) . "(" . self::buildArgs(self::getBodyField($request, Keys::TUPLE)) . ")";
            case RequestTypes::EVALUATE:
                $args = self::buildArgs(self::getBodyField($request, Keys::TUPLE));
                return "EVAL " . self::getBodyField($request,Keys::EXPR) . (!empty($args) ? " | args: $args" : null);
            case RequestTypes::UPDATE:
                return self::buildCommon($request).":update(" . self::buildArgs(self::getBodyField($request, Keys::KEY)) . ", " . self::buildArgs(self::getBodyField($request,Keys::TUPLE)) . ")";
            case RequestTypes::UPSERT:
                return "box.space[" . self::getBodyField($request, Keys::SPACE_ID) . "]:upsert(" . self::buildArgs(self::getBodyField($request, Keys::TUPLE)) . ", " . self::buildArgs(self::getBodyField($request,Keys::OPERATIONS)) . ")";
            case RequestTypes::REPLACE:
                return "box.space[" . self::getBodyField($request, Keys::SPACE_ID) . "]:replace(" . self::buildArgs(self::getBodyField($request,Keys::TUPLE)) . ")";
            case RequestTypes::INSERT:
                return "box.space[" . self::getBodyField($request, Keys::SPACE_ID) . "]:insert(" . self::buildArgs(self::getBodyField($request,Keys::TUPLE)) . ")";
            case RequestTypes::DELETE:
                return self::buildCommon($request) . ":delete(" . self::buildArgs(self::getBodyField($request, Keys::KEY)) . ")";
        endswitch;

        return "Unknown request " . get_class($request);
    }

    /**
     * Create count request by select request or may be another request type.
     * @param SelectRequest $request
     * @param Connection $db
     * @return CallRequest
     * @throws ClientException
     */
    public static function countRequest(SelectRequest $request, Connection $db): CallRequest
    {
        $iterator = self::getBodyField($request, Keys::ITERATOR);
        $key = self::getBodyField($request, Keys::KEY);
        $args = [];

        if (!empty($key)) {
            $args[] = $key;
        }
        if (!empty($iterator)) {
            $args[]['iterator'] = $iterator;
        }

        return new CallRequest(self::buildCommon($request, $db).":count", $args);
    }

    /**
     * @param SelectRequest $request
     * @param Connection $db
     * @return CallRequest
     * @throws ClientException
     */
    public static function maxRequest(SelectRequest $request, Connection $db): CallRequest
    {
        $key = self::getBodyField($request, Keys::KEY);
        $args = [];

        if (!empty($key)) {
            $args[] = $key;
        }

        return new CallRequest(self::buildCommon($request, $db, true).":max", $args);
    }

    /**
     * @param SelectRequest $request
     * @param Connection $db
     * @return CallRequest
     * @throws ClientException
     */
    public static function minRequest(SelectRequest $request, Connection $db): CallRequest
    {
        $key = self::getBodyField($request, Keys::KEY);
        $args = [];

        if (!empty($key)) {
            $args[] = $key;
        }

        return new CallRequest(self::buildCommon($request, $db, true).":min", $args);
    }

    /**
     * @param SelectRequest $request
     * @param Connection $db
     * @return CallRequest
     * @throws ClientException
     */
    public static function getRequest(SelectRequest $request, Connection $db): CallRequest
    {
        $key = self::getBodyField($request, Keys::KEY);

        return new CallRequest(self::buildCommon($request, $db).":get", [$key]);
    }

    /**
     * @param SelectRequest $request
     * @param Connection $db
     * @param mixed $seed
     * @return CallRequest
     * @throws ClientException
     */
    public static function randomRequest(SelectRequest $request, Connection $db, $seed = null): CallRequest
    {
        if ($seed === null) {
            $seed = rand(0, \PHP_INT_MAX & 0xffffffff);
        }

        return new CallRequest(self::buildCommon($request, $db, true).":random", [$seed]);
    }

    /**
     * @param Request $request
     * @param Connection|null $db
     * @param bool $indexRequired
     * @return string
     * @throws ClientException
     */
    public static function buildCommon(Request $request, ?Connection $db = null, bool $indexRequired = false)
    {
        // Convert for debug
        if ($db === null) {
            return "box.space[" . self::getBodyField($request, Keys::SPACE_ID) . "].index[" . self::getBodyField($request, Keys::INDEX_ID) . "]";
        }

        // Convert for CALL
        $space = $db->client->getSpaceById(self::getBodyField($request, Keys::SPACE_ID));
        $indexPart = '';
        if (($index = self::getBodyField($request, Keys::INDEX_ID)) !== 0 || $indexRequired) {
            $indexPart = '.index.' . $space->getIndexNameByID($index);
        }

        return "box.space.{$space->getName()}" . $indexPart;
    }

    /**
     * @param int $iterator
     * @param int $limit
     * @param int $offset
     * @return string
     */
    public static function buildIterator($iterator, $limit, $offset)
    {
        $iter = 'EQ';
        if (isset(self::$ITERATOR_MAP[$iterator])) {
            $iter = self::$ITERATOR_MAP[$iterator];
        }
        $result = '{iterator=' . $iter;
        if ($limit !== 0 && $limit !== \PHP_INT_MAX & 0xffffffff) {
            $result .= ', limit=' . $limit;
        }
        if ($offset !== 0) {
            $result .= ', offset=' . $offset;
        }
        return $result . '}';
    }


    /**
     * @param mixed $args
     * @return mixed|string
     */
    public static function buildArgs($args)
    {
        // Process array key
        if (is_array($args)) {
            return static::buildArray($args);
        }

        // Trying to convert objects to scalar
        if (is_object($args)) {
            return static::buildObject($args);
        }

        // Cut string if it needs
        if (is_string($args)) {
            return static::buildString($args);
        }

        if (is_bool($args)) {
            return $args ? 'true' : 'false';
        }

        return $args;
    }

    /**
     * @param array $args
     * @return string
     */
    protected static function buildArray($args)
    {
        if (!count($args)) {
            return '';
        }
        $result = '{';
        $max = count($args) - 1;
        $i = 0;
        foreach ($args as $key => $value) {
            $assoc = null;
            if (!is_int($key)) {
                $assoc = $key . ' = ';
            }
            $result .= $assoc . self::buildArgs($value) . ($i !== $max ? ', ' : null);
            $i++;
        }
        return $result . '}';
    }

    /**
     * @param mixed $args
     * @return string
     */
    protected static function buildObject($args)
    {
        if ($args instanceof Bin) {
            return '[Binary]';
        }
        if (method_exists($args, '__toString')) {
            return "'" . self::buildArgs((string)$args) . "'";
        }
        return '[OBJECT]';
    }

    /**
     * @param string $args
     * @return string
     */
    protected static function buildString($args)
    {
        if (strlen($args) > self::$MAX_STRING_LENGTH) {
            return "'" . substr($args, 0, self::$MAX_STRING_LENGTH) . "...'";
        }
        return "'" . $args . "'";
    }

    /**
     * @param Request $request
     * @param $key
     * @return mixed
     */
    public static function getBodyField(Request $request, $key)
    {
        $arr = $request->getBody();
        if (isset($arr[$key])) {
            return $arr[$key];
        }
        return '';
    }

    /**
     * Detect multidimensional array.
     * @param mixed $data
     * @return bool
     */
    public static function isMulti($data): bool
    {
        if (!is_array($data)) {
            return false;
        }
        foreach ($data as $elem) {
            if (!is_array($elem)) {
                return false;
            }
        }

        return true;
    }
}