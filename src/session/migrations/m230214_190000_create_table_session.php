<?php

namespace mhthnz\tarantool\session\migrations;

use mhthnz\tarantool\Migration;

/**
 * Initialize Session space/table.
 */
class m230214_190000_create_table_session extends Migration
{
	private $tableName = '{{%session}}';

	/**
	 * {@inheritdoc}
	 */
	public function up()
	{
		$this->createTable($this->tableName, [
			'id' => $this->string()->notNull(),
			'expire' => $this->integer()->notNull(),
			'data' => $this->text()->notNull(),

			'CONSTRAINT "pk-session" PRIMARY KEY ("id")',
		]);

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
