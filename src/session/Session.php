<?php

namespace mhthnz\tarantool\session;

use MessagePack\Type\Bin;
use mhthnz\tarantool\Connection;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\Query;
use yii\di\Instance;
use yii\web\MultiFieldSession;
use Exception;

class Session extends MultiFieldSession {

    /**
     * @var string the name of the DB table that stores the session data.
     * The table should be pre-created as follows:
     *
     * The space should be pre-created as follows:
     *
     * ```php
     * public function up() {
     *     $this->createTable('session', [
     *         'id'     => $this->string()->notNull(),
     *         'expire' => $this->integer()->notNull(),
     *         'data'   => $this->binary()->notNull(),
     *
     *         'CONSTRAINT "pk-sessions" PRIMARY KEY ("id")',
     *     ]);
     *
     *     $this->createIndex('ix-sessions[expire]', 'session', ['expire']);
     * }
     * ```
     */
    public $sessionTable = '{{%session}}';

    /**
     * @var Connection
     */
    public $db = 'tarantool';

    /**
     * @var array Session fields to be written into session table columns
     */
    protected $fields = [];


    /**
     * Initializes the DbSession component.
     * This method will initialize the [[db]] property to make sure it refers to a valid DB connection.
     * @throws InvalidConfigException if [[db]] is invalid.
     */
    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, \mhthnz\tarantool\Connection::class);
    }

    /**
     * {@inheritdoc}
     */
    public function openSession($savePath, $sessionName)
    {
        if ($this->getUseStrictMode()) {
            $id = $this->getId();
            if (!$this->getReadQuery($id)->exists($this->db)) {
                //This session id does not exist, mark it for forced regeneration
                $this->_forceRegenerateId = $id;
            }
        }

        return parent::openSession($savePath, $sessionName);
    }

    /**
     * {@inheritdoc}
     */
    public function regenerateID($deleteOldSession = false)
    {
        $oldID = session_id();

        // if no session is started, there is nothing to regenerate
        if (empty($oldID)) {
            return;
        }

        parent::regenerateID(false);
        $newID = session_id();
        // if session id regeneration failed, no need to create/update it.
        if (empty($newID)) {
            Yii::warning('Failed to generate new session ID', __METHOD__);
            return;
        }

        $row = $this->db->useMaster(function() use ($oldID) {
            return (new Query())->from($this->sessionTable)
                ->where(['id' => $oldID])
                ->createCommand($this->db)
                ->queryOne();
        });

        if ($row !== false && $this->getIsActive()) {
            if ($deleteOldSession) {
                $this->db->createCommand()
                    ->delete($this->sessionTable, ['id' => $oldID])
                    ->execute();
            }
            $row = $this->typecastFields($row);
            $row['id'] = $newID;
            $this->db->createCommand()
                ->insert($this->sessionTable, $row)
                ->execute();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->getIsActive()) {
            // prepare writeCallback fields before session closes
            $this->fields = $this->composeFields();
            YII_DEBUG ? session_write_close() : @session_write_close();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function readSession($id)
    {
        $query = $this->getReadQuery($id);

        if ($this->readCallback !== null) {
            $fields = $query->one($this->db);
            return $fields === false ? '' : $this->extractData($fields);
        }

        $data = $query->select(['data'])->scalar($this->db);
        return $data === false ? '' : $data;
    }

    /**
     * {@inheritdoc}
     */
    public function writeSession($id, $data)
    {
        if ($this->getUseStrictMode() && $id === $this->_forceRegenerateId) {
            //Ignore write when forceRegenerate is active for this id
            return true;
        }

        // exception must be caught in session write handler
        // https://www.php.net/manual/en/function.session-set-save-handler.php#refsect1-function.session-set-save-handler-notes
        try {
            // ensure backwards compatability (fixed #9438)
            if ($this->writeCallback && !$this->fields) {
                $this->fields = $this->composeFields();
            }
            // ensure data consistency
            if (!isset($this->fields['data'])) {
                $this->fields['data'] = $data;
            } else {
                $_SESSION = $this->fields['data'];
            }
            // ensure 'id' and 'expire' are never affected by [[writeCallback]]
            $this->fields = array_merge($this->fields, [
                'id' => $id,
                'expire' => time() + $this->getTimeout(),
            ]);
            $this->fields = $this->typecastFields($this->fields);
            $this->db->createCommand()->insertOrReplace(
                $this->sessionTable,
                $this->fields
            )->execute();
            $this->fields = [];
        } catch (\Exception $e) {
            Yii::$app->errorHandler->handleException($e);
            return false;
        }
        return true;
    }

    /**
     * Session destroy handler.
     * @internal Do not call this method directly.
     * @param string $id session ID
     * @return bool whether session is destroyed successfully
     * @throws Exception
     */
    public function destroySession($id)
    {
        $this->db->createCommand()
            ->delete($this->sessionTable, ['id' => $id])
            ->execute();

        return true;
    }

    /**
     * Session GC (garbage collection) handler.
     * @internal Do not call this method directly.
     * @param int $maxLifetime the number of seconds after which data will be seen as 'garbage' and cleaned up.
     * @return bool whether session is GCed successfully
     * @throws \yii\db\Exception
     */
    public function gcSession($maxLifetime)
    {
        $this->db->createCommand()
            ->delete($this->sessionTable, '[[expire]]<:expire', [':expire' => time()])
            ->execute();

        return true;
    }

    /**
     * Generates a query to get the session from Tarantrool.
     * @param string $id The id of the session
     * @return Query
     */
    protected function getReadQuery($id)
    {
        return (new Query())
            ->select("*")
            ->from($this->sessionTable)
            ->where('[[expire]]>:expire AND [[id]]=:id', [':expire' => time(), ':id' => $id]);
    }

    /**
     * Convert serialized session data string into binary.
     * @param array $fields Fields, that will be passed to Tarantool. Key - name, Value - value
     * @return array
     */
    protected function typecastFields($fields)
    {
        if (isset($fields['data']) && !is_array($fields['data']) && !is_object($fields['data'])) {
            $fields['data'] = new Bin($fields['data']);
        }

        return $fields;
    }
}
