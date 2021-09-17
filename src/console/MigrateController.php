<?php

namespace mhthnz\tarantool\console;

use mhthnz\tarantool\Connection;
use mhthnz\tarantool\Migration;
use yii\console\controllers\BaseMigrateController;
use yii\db\Query;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\Inflector;
use Yii;

/**
 * Class MigrateController for Tarantool database.
 *
 * @author mhthnz <mhthnz@gmail.com>
  */
class MigrateController extends BaseMigrateController
{
    /**
     * It actually doesn't work right now because varchar(n) is just alias for string.
     * Maximum length of a migration name.
     */
    const MAX_NAME_LENGTH = 180;

    /**
     * @var string the name of the table for keeping applied migration information.
     */
    public $migrationTable = '{{%migration}}';

    /**
     * {@inheritdoc}
     */
    public $templateFile = '@mhthnz/tarantool/views/migration.php';


    /**
     * {@inheritdoc}
     * @var Connection $db
     */
    public $db = 'tarantool';

    /**
     * {@inheritdoc}
     */
    public $migrationPath = ['@app/tarantool/migrations'];

    /**
     * {@inheritdoc}
     */
    public function options($actionID)
    {
        return array_merge(
            parent::options($actionID),
            ['migrationTable', 'db'], // global for all actions
            $actionID === 'create'
                ? ['templateFile']
                : []
        );
    }

    /**
     * {@inheritdoc}
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'p' => 'migrationPath',
            't' => 'migrationTable',
            'F' => 'templateFile',
        ]);
    }

    /**
     * This method is invoked right before an action is to be executed (after all possible filters.)
     * It checks the existence of the [[migrationPath]].
     * @param \yii\base\Action $action the action to be executed.
     * @return bool whether the action should continue to be executed.
     */
    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            $this->db = Instance::ensure($this->db, Connection::className());
            return true;
        }

        return false;
    }

    /**
     * Creates a new migration instance.
     * @param string $class the migration class name
     * @return Migration the migration instance
     */
    protected function createMigration($class)
    {
        $this->includeMigrationFile($class);

        return Yii::createObject([
            'class' => $class,
            'db' => $this->db,
            'compact' => $this->compact,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getMigrationHistory($limit)
    {
        $tableName = $this->db->schema->getRawTableName($this->migrationTable);
        if ($this->db->schema->getTableSchema($tableName, true) === null) {
            $this->createMigrationHistoryTable();
        }
        $query = (new Query())
            ->select(['version', 'apply_time'])
            ->from($tableName)
            ->orderBy(['apply_time' => SORT_DESC, 'version' => SORT_DESC]);

        if (empty($this->migrationNamespaces)) {
            $query->limit($limit);
            $rows = $query->all($this->db);
            $history = ArrayHelper::map($rows, 'version', 'apply_time');
            unset($history[self::BASE_MIGRATION]);
            return $history;
        }

        $rows = $query->all($this->db);

        $history = [];
        foreach ($rows as $key => $row) {
            if ($row['version'] === self::BASE_MIGRATION) {
                continue;
            }
            if (preg_match('/m?(\d{6}_?\d{6})(\D.*)?$/is', $row['version'], $matches)) {
                $time = str_replace('_', '', $matches[1]);
                $row['canonicalVersion'] = $time;
            } else {
                $row['canonicalVersion'] = $row['version'];
            }
            $row['apply_time'] = (int)$row['apply_time'];
            $history[] = $row;
        }

        usort($history, function ($a, $b) {
            if ($a['apply_time'] === $b['apply_time']) {
                if (($compareResult = strcasecmp($b['canonicalVersion'], $a['canonicalVersion'])) !== 0) {
                    return $compareResult;
                }

                return strcasecmp($b['version'], $a['version']);
            }

            return ($a['apply_time'] > $b['apply_time']) ? -1 : +1;
        });

        $history = array_slice($history, 0, $limit);

        $history = ArrayHelper::map($history, 'version', 'apply_time');

        return $history;
    }

    /**
     * Creates the migration history table.
     */
    protected function createMigrationHistoryTable()
    {
        $tableName = $this->db->schema->getRawTableName($this->migrationTable);
        $this->stdout("Creating migration history table \"$tableName\"...", Console::FG_YELLOW);
        $this->db->createCommand()->createTable($tableName, [
            'version' => 'varchar(' . static::MAX_NAME_LENGTH . ') NOT NULL PRIMARY KEY',
            'apply_time' => 'integer',
        ])->execute();
        $this->db->createCommand()->insert($tableName, [
            'version' => self::BASE_MIGRATION,
            'apply_time' => time(),
        ])->execute();
        $this->stdout("Done.\n", Console::FG_GREEN);
    }

    /**
     * {@inheritdoc}
     */
    protected function addMigrationHistory($version)
    {
        $command = $this->db->createCommand();
        $tableName = $this->db->schema->getRawTableName($this->migrationTable);
        $command->insert($tableName, [
            'version' => $version,
            'apply_time' => time(),
        ])->execute();
    }

    /**
     * {@inheritdoc}
     */
    protected function truncateDatabase()
    {
        $db = $this->db;
        $schemas = $db->schema->getTableSchemas();

        // First drop all foreign keys,
        foreach ($schemas as $schema) {
            foreach ($schema->foreignKeys as $name => $foreignKey) {
                $db->createCommand()->dropForeignKey($name, $schema->name)->execute();
                $this->stdout("Foreign key $name dropped.\n");
            }
        }

        // Then drop the tables:
        foreach ($schemas as $schema) {
            try {
                $db->createCommand()->dropTable($schema->name)->execute();
                $this->stdout("Table {$schema->name} dropped.\n");
            } catch (\Exception $e) {
                if ($this->isViewRelated($e->getMessage())) {
                    $db->createCommand()->dropView($schema->name)->execute();
                    $this->stdout("View {$schema->name} dropped.\n");
                } else {
                    $this->stdout("Cannot drop {$schema->name} Table .\n");
                }
            }
        }
    }

    /**
     * Determines whether the error message is related to deleting a view or not
     * @param string $errorMessage
     * @return bool
     */
    private function isViewRelated($errorMessage)
    {
        $dropViewErrors = [
            'DROP VIEW to delete view', // SQLite
            'SQLSTATE[42S02]', // MySQL
        ];

        foreach ($dropViewErrors as $dropViewError) {
            if (strpos($errorMessage, $dropViewError) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function removeMigrationHistory($version)
    {
        $command = $this->db->createCommand();
        $tableName = $this->db->schema->getRawTableName($this->migrationTable);
        $command->delete($tableName, [
            'version' => $version,
        ])->execute();
    }

    private $_migrationNameLimit;

    /**
     * {@inheritdoc}
     */
    protected function getMigrationNameLimit()
    {
        if ($this->_migrationNameLimit !== null) {
            return $this->_migrationNameLimit;
        }
        $tableName = $this->db->schema->getRawTableName($this->migrationTable);
        $tableSchema = $this->db->schema ? $this->db->schema->getTableSchema($tableName, true) : null;
        if ($tableSchema !== null) {
            return $this->_migrationNameLimit = $tableSchema->columns['version']->size;
        }

        return static::MAX_NAME_LENGTH;
    }

}
