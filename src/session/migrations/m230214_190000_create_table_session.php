<?php

namespace mhthnz\tarantool\session\migrations;

use mhthnz\tarantool\Migration;

/**
 * Initialize Session space/table.
 */
class m230214_190000_create_table_session extends Migration
{
    /**
     * @var string
     */
	protected $tableName = '{{%session}}';

    /**
     * @var string memtx|vinyl
     */
    protected $engine = 'memtx';

	/**
	 * {@inheritdoc}
	 */
	public function up()
	{
		$this->createTable($this->tableName, [
			'id' => $this->string()->notNull(),
			'expire' => $this->integer()->notNull(),
			'data' => $this->binary()->notNull(),

			'CONSTRAINT "pk-session" PRIMARY KEY ("id")',
		], "WITH ENGINE='{$this->engine}'");

		$this->createIndex('ix-session[expire]', $this->tableName, ['expire']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function down()
	{
		$this->dropTable($this->tableName);
	}
}
