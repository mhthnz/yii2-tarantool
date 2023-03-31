<?php
namespace mhthnz\tarantool\tests\classes\migrations;


/**
 * Test cache migration.
 */
class m230330_104511_create_cache_space extends \mhthnz\tarantool\cache\expirationd\migrations\m230330_104511_create_cache_space
{
    /**
     * How many tuples will be processed per one iteration.
     * @var int
     */
    protected $tuplesPerIteration = 10;

    /**
     * Fullscan time in seconds.
     * @var int
     */
    protected $fullScanTime = 5;
}
