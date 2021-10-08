<?php

namespace mhthnz\tarantool\client;

use Tarantool\Client\Exception\ClientException;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Keys;
use Tarantool\Client\Request\DeleteRequest;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Client\Request\ReplaceRequest;
use Tarantool\Client\Request\SelectRequest;
use Tarantool\Client\Request\UpdateRequest;
use Tarantool\Client\Request\UpsertRequest;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;

/**
 * Space class that override original class functionality.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class Space implements SpaceInterface
{
    public const VSPACE_ID = 281;
    public const VSPACE_NAME_INDEX = 2;
    public const VSPACE_ID_INDEX = 0;
    public const VINDEX_ID = 289;
    public const VINDEX_NAME_INDEX = 2;
    public const VINDEX_ID_INDEX = 0;

    /**
     * @var Handler
     */
    private $handler;

    /**
     * @var int
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    /** @var array<string, int> */
    private $indexes = [];

    public function __construct(Handler $handler, int $id, string $name)
    {
        $this->handler = $handler;
        $this->id = $id;
        $this->name = $name;
    }

    /**
     * @return int
     */
    public function getID(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @see \Tarantool\Client\Schema\Space::select()
     *
     * @param Criteria $criteria
     * @return array
     * @throws ClientException
     */
    public function select(Criteria $criteria) : array
    {
        $index = $criteria->getIndex();
        if (\is_string($index)) {
            $index = $this->getIndexIdByName($index);
        }

        $request = new SelectRequest(
            $this->id,
            $index,
            $criteria->getKey(),
            $criteria->getOffset(),
            $criteria->getLimit(),
            $criteria->getIterator()
        );

        return $this->handler->handle($request)->getBodyField(Keys::DATA);
    }

    /**
     * @see \Tarantool\Client\Schema\Space::insert()
     *
     * @param array $tuple
     * @return array
     * @throws ClientException
     */
    public function insert(array $tuple) : array
    {
        $request = new InsertRequest($this->id, $tuple);

        return $this->handler->handle($request)->getBodyField(Keys::DATA);
    }

    /**
     * @see \Tarantool\Client\Schema\Space::replace()
     *
     * @param array $tuple
     * @return array
     * @throws ClientException
     */
    public function replace(array $tuple) : array
    {
        $request = new ReplaceRequest($this->id, $tuple);

        return $this->handler->handle($request)->getBodyField(Keys::DATA);
    }

    /**
     * @see \Tarantool\Client\Schema\Space::update()
     *
     * @param array $key
     * @param Operations $operations
     * @param int $index
     * @return array
     * @throws ClientException
     */
    public function update(array $key, Operations $operations, $index = 0) : array
    {
        if (\is_string($index)) {
            $index = $this->getIndexIdByName($index);
        }
        $request = new UpdateRequest($this->id, $index, $key, $operations->toArray());

        return $this->handler->handle($request)->getBodyField(Keys::DATA);
    }

    /**
     * @see \Tarantool\Client\Schema\Space::upsert()
     *
     * @param array $tuple
     * @param Operations $operations
     * @throws ClientException
     */
    public function upsert(array $tuple, Operations $operations) : void
    {
        $request = new UpsertRequest($this->id, $tuple, $operations->toArray());

        $this->handler->handle($request);
    }

    /**
     * @see \Tarantool\Client\Schema\Space::select()
     *
     * @param array $key
     * @param int $index
     * @return array
     * @throws ClientException
     */
    public function delete(array $key, $index = 0) : array
    {
        if (\is_string($index)) {
            $index = $this->getIndexIDByName($index);
        }
        $request = new DeleteRequest($this->id, $index, $key);

        return $this->handler->handle($request)->getBodyField(Keys::DATA);
    }

    /**
     * {@inheritdoc}
     */
    public function flushIndexes() : void
    {
        $this->indexes = [];
    }

    /**
     * {@inheritdoc}
     *
     * @param string $name
     * @return int
     * @throws ClientException
     */
    public function getIndexIDByName(string $name): int
    {
        if (isset($this->indexes[$name])) {
            return $this->indexes[$name];
        }
        $schema = new self($this->handler, self::VINDEX_ID, '_vindex');
        $data = $schema->select(Criteria::key([$this->id, $name])->andIndex(self::VINDEX_NAME_INDEX));
        if ($data) {
            return $this->indexes[$name] = $data[0][1];
        }

        throw RequestFailed::unknownIndex($name, $this->id);
    }

    /**
     * {@inheritdoc}
     *
     * @param int $id
     * @return string
     * @throws ClientException
     */
    public function getIndexNameByID(int $id): string
    {
        if (($key = array_search($id, $this->indexes)) !== false) {
            return $key;
        }
        $schema = new self($this->handler, self::VINDEX_ID, '_vindex');
        $data = $schema->select(Criteria::key([$this->id, $id])->andIndex(self::VINDEX_ID_INDEX));
        if ($data) {
            $this->indexes[$data[0][2]] = $id;
            return $data[0][2];
        }

        throw RequestFailed::unknownIndex($id, $this->id);
    }
}