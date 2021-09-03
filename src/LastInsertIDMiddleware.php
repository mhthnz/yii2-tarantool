<?php

namespace mhthnz\tarantool;

use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Keys;
use Tarantool\Client\Middleware\Middleware;
use Tarantool\Client\Request\EvaluateRequest;
use Tarantool\Client\Request\ExecuteRequest;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Client\Request\Request;
use Tarantool\Client\Request\UpsertRequest;
use Tarantool\Client\Response;

/**
 * Middleware for getting last insert ID.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class LastInsertIDMiddleware implements Middleware
{
    /**
     * @var Connection
     */
    private $_db;

    /**
     * @param $db Connection
     */
    public function __construct($db)
    {
        $this->_db = $db;
    }

    /**
     * @param Request $request
     * @param Handler $handler
     * @return Response
     * @throws \Tarantool\Client\Exception\ClientException
     */
    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);
        if (
            $request instanceof ExecuteRequest || $request instanceof InsertRequest
        ) {
            $data = $response->tryGetBodyField(Keys::SQL_INFO);
            if ($data !== null && isset($data[Keys::SQL_INFO_AUTO_INCREMENT_IDS])) {
                $this->_db->lastInsertID = array_pop($data[Keys::SQL_INFO_AUTO_INCREMENT_IDS]);
            }
        }
        return $response;
    }
}