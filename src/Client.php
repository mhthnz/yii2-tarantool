<?php

namespace mhthnz\tarantool;

use mhthnz\tarantool\client\Space;
use mhthnz\tarantool\client\SpaceInterface;
use Tarantool\Client\Exception\ClientException;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Middleware\Middleware;
use Tarantool\Client\Packer\Packer;
use Tarantool\Client\PreparedStatement;
use Tarantool\Client\Response;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\SqlQueryResult;
use Tarantool\Client\SqlUpdateResult;

/**
 * Client decorator for overriding some original functionality.
 * @see \Tarantool\Client\Client
 *
 * @method Handler getHandler()
 * @method void ping()
 * @method array call(string $funcName, ...$args)
 * @method array evaluate(string $expr, ...$args)
 * @method Response execute(string $sql, ...$params)
 * @method SqlQueryResult executeQuery(string $sql, ...$params)
 * @method SqlUpdateResult executeUpdate(string $sql, ...$params)
 * @method PreparedStatement prepare(string $sql)
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class Client
{
    /**
     * Ability to override Space class.
     * @var string
     */
    public $spaceClass = '\mhthnz\tarantool\client\Space';

    /**
     * @var \Tarantool\Client\Client
     */
    private $_client;

    /**
     * Space cache.
     * @var SpaceInterface[]
     */
    private $_spaces = [];


    /**
     * Creating new Client from handler or from another client class.
     * @param Handler|null $handler
     * @param \Tarantool\Client\Client|null $client
     */
    public function __construct(Handler $handler, ?\Tarantool\Client\Client $client)
    {
        if ($client !== null) {
            $this->_client = $client;
            return;
        }
        $this->_client = new \Tarantool\Client\Client($handler);
    }

    /**
     * @see \Tarantool\Client\Client::fromDefaults()
     * @return static
     */
    public static function fromDefaults() : self
    {
        $client = \Tarantool\Client\Client::fromDefaults();

        return new self($client->getHandler(), $client);
    }

    /**
     * @see \Tarantool\Client\Client::fromOptions()
     * @param array $options
     * @param Packer|null $packer
     * @return static
     */
    public static function fromOptions(array $options, ?Packer $packer = null) : self
    {
        $client = \Tarantool\Client\Client::fromOptions($options, $packer);

        return new self($client->getHandler(), $client);
    }

    /**
     * @see \Tarantool\Client\Client::fromDsn()
     * @param string $dsn
     * @param Packer|null $packer
     * @return static
     */
    public static function fromDsn(string $dsn, ?Packer $packer = null) : self
    {
        $client = \Tarantool\Client\Client::fromDsn($dsn, $packer);

        return new self($client->getHandler(), $client);
    }

    /**
     * @see \Tarantool\Client\Client::withMiddleware()
     * @param Middleware ...$middleware
     * @return $this
     */
    public function withMiddleware(Middleware ...$middleware) : self
    {
        $client = clone $this->_client->withMiddleware(...$middleware);

        return new self($client->getHandler(), $client);
    }

    /**
     * @see \Tarantool\Client\Client::withPrependedMiddleware()
     * @param Middleware ...$middleware
     * @return $this
     */
    public function withPrependedMiddleware(Middleware ...$middleware) : self
    {
        $client = clone $this->_client->withPrependedMiddleware(...$middleware);

        return new self($client->getHandler(), $client);
    }

    /**
     * Getting space by name using cache.
     * @param string $spaceName
     * @return SpaceInterface
     * @throws ClientException
     */
    public function getSpace(string $spaceName) : SpaceInterface
    {
        if (isset($this->_spaces[$spaceName])) {
            return $this->_spaces[$spaceName];
        }

        $spaceId = $this->getSpaceIDByName($spaceName);

        return $this->_spaces[$spaceName] = $this->_spaces[$spaceId] = new $this->spaceClass($this->_client->getHandler(), $spaceId, $spaceName);
    }

    /**
     * Getting space by ID using cache.
     * @param int $spaceId
     * @return SpaceInterface
     * @throws ClientException
     */
    public function getSpaceById(int $spaceId) : SpaceInterface
    {
        if (isset($this->_spaces[$spaceId])) {
            return $this->_spaces[$spaceId];
        }

        $schema = new Space($this->getHandler(), Space::VSPACE_ID, '_vspace');
        $data = $schema->select(Criteria::key([$spaceId])->andIndex(Space::VSPACE_ID_INDEX));

        if (empty($data)) {
            throw RequestFailed::unknownSpace($spaceId);
        }
        $spaceName = $data[0][2];

        return $this->_spaces[$spaceId] = $this->_spaces[$spaceName] = new $this->spaceClass($this->_client->getHandler(), $spaceId, $spaceName);
    }

    /**
     * @param string $spaceName
     * @return int
     * @throws ClientException
     */
    public function getSpaceIDByName(string $spaceName) : int
    {
        $schema = $this->getSpaceById(Space::VSPACE_ID);
        $data = $schema->select(Criteria::key([$spaceName])->andIndex(Space::VSPACE_NAME_INDEX));

        if (!empty($data)) {
            return $data[0][0];
        }

        throw RequestFailed::unknownSpace($spaceName);
    }

    /**
     * @param int $spaceID
     * @return string
     * @throws ClientException
     */
    public function getSpaceNameByID(int $spaceID): string
    {
        $schema = $this->getSpaceById($spaceID);

        return $schema->getName();
    }

    /**
     * Clear spaces cache.
     */
    public function flushSpaces() : void
    {
        $this->_spaces = [];
    }

    public function __clone()
    {
        $this->_spaces = [];
    }

    /**
     * Overloading client methods.
     * @param string $name
     * @param array $args
     * @return mixed
     */
    public function __call($name, $args)
    {
        return $this->_client->{$name}(...$args);
    }
}