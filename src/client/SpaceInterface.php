<?php
namespace mhthnz\tarantool\client;

use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;

/**
 * Interface for overriding Space class if needed.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
interface SpaceInterface
{
    /**
     * Getting space id.
     * @return int
     */
    public function getID(): int;

    /**
     * Getting space name.
     * @return string
     */
    public function getName(): string;

    /**
     * @see \Tarantool\Client\Schema\Space::select()
     *
     * @param Criteria $criteria
     * @return array
     */
    public function select(Criteria $criteria) : array;

    /**
     * @see \Tarantool\Client\Schema\Space::replace()
     *
     * @param array $tuple
     * @return array
     */
    public function replace(array $tuple) : array;

    /**
     * @see \Tarantool\Client\Schema\Space::update()
     *
     * @param array $key
     * @param Operations $operations
     * @param int $index
     * @return array
     */
    public function update(array $key, Operations $operations, $index = 0) : array;

    /**
     * @see \Tarantool\Client\Schema\Space::upsert()
     *
     * @param array $tuple
     * @param Operations $operations
     */
    public function upsert(array $tuple, Operations $operations) : void;

    /**
     * @see \Tarantool\Client\Schema\Space::delete()
     *
     * @param array $key
     * @param int $index
     * @return array
     */
    public function delete(array $key, $index = 0) : array;

    /**
     * @param int $id
     * @return string
     */
    public function getIndexNameByID(int $id): string;

    /**
     * @param string $name
     * @return int
     */
    public function getIndexIDByName(string $name): int;

    /**
     * Flushing index cache for space.
     */
    public function flushIndexes(): void;
}