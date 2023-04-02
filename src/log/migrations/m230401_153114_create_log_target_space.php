<?php

namespace mhthnz\tarantool\log\migrations;

use yii\base\InvalidConfigException;
use mhthnz\tarantool\Migration;

/**
 * Init log target space.
 */
class m230401_153114_create_log_target_space extends Migration
{
    /**
     * @var string
     */
    protected $spaceName = 'log';

    /**
     * @var string memtx|vinyl
     */
    protected $engine = 'memtx';

    /**
     * @var string
     */
    protected $sequenceName = 'log-primary-key-sequence';

    /**
     * Start id for sequence, by default we have negative number: -9223372036854775808 in 64bit system.
     * We use it because logs may have a lot of rows. If you need to start from 1 - change it.
     *```php
     *   $minID = 1;
     *```
     * @var int
     */
    protected $minID = PHP_INT_MIN;


    public function up()
    {
        $this->createSpace($this->spaceName, [
            ['name' => 'id', 'type' => 'integer', 'is_nullable' => false],
            ['name' => 'level', 'type' => 'integer', 'is_nullable' => false],
            ['name' => 'category', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'log_time', 'type' => 'double', 'is_nullable' => false],
            ['name' => 'prefix', 'type' => 'string', 'is_nullable' => false],
            ['name' => 'message', 'type' => 'string', 'is_nullable' => false],
        ], $this->engine);

        $this->createSequence($this->sequenceName, null, $this->minID, null, true);

        $this->createSpaceIndex($this->spaceName,'idx_primary_id', [1 => 'integer'], true, $this->engine == "memtx" ? "hash":"tree", $this->sequenceName);
        $this->createSpaceIndex($this->spaceName, 'idx_log_level', [2 => 'integer']);
        $this->createSpaceIndex($this->spaceName, 'idx_log_category', [3 => 'string']);

    }

    public function down()
    {
        $this->dropSpace($this->spaceName);
        $this->dropSequence($this->sequenceName);
    }
}
