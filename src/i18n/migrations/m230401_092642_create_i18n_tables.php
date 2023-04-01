<?php

namespace mhthnz\tarantool\i18n\migrations;

use mhthnz\tarantool\Migration;

class m230401_092642_create_i18n_tables extends Migration
{
    /**
     * @var string
     */
    protected $sourceMessageTable = '{{%source_message}}';

    /**
     * @var string
     */
    protected $messageTable = '{{%message}}';

    /**
     * @var string
     */
    protected $engine = 'memtx';

    /**
     * @return bool|void|null
     */
    public function up()
    {
        $this->createTable($this->sourceMessageTable, [
            'id' => $this->primaryKey(),
            'category' => $this->string(),
            'message' => $this->text(),
        ], "WITH ENGINE='{$this->engine}'");

        $this->createTable($this->messageTable, [
            'id' => $this->integer()->notNull(),
            'language' => $this->string(16)->notNull(),
            'translation' => $this->text(),
            'CONSTRAINT "pk_message_id_language" PRIMARY KEY ("id", "language")',
        ], "WITH ENGINE='{$this->engine}'");

        $this->addForeignKey('fk_message_source_message', $this->messageTable, 'id', $this->sourceMessageTable, 'id', 'CASCADE', 'RESTRICT');
        $this->createIndex('idx_source_message_category', $this->sourceMessageTable, 'category');
        $this->createIndex('idx_message_language', $this->messageTable, 'language');
    }

    /**
     * @return bool|void|null
     */
    public function down()
    {
        $this->dropForeignKey('fk_message_source_message', $this->messageTable);
        $this->dropTable($this->messageTable);
        $this->dropTable($this->sourceMessageTable);
    }
}