<?php

namespace mhthnz\tarantool;

use Tarantool\Client\Schema\Operations;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;

class Session extends \yii\web\Session {
	/** @var string Tarantool ActiveRecord class name for sessions.
	 * The space should be pre-created as follows:
	 *
	 * ```php
	 * public function up() {
	 *     $this->createTable('sessions', [
	 *         'id'     => $this->string()->notNull(),
	 *         'expire' => $this->integer()->notNull(),
	 *         'data'   => $this->text()->notNull(),
	 *
	 *         'CONSTRAINT "pk-sessions" PRIMARY KEY ("id")',
	 *     ]);
	 *
	 *     $this->createIndex('ix-sessions[expire]', 'sessions', ['expire']);
	 * }
	 * ```
	 */
	public $spaceClass;

	/** @var ActiveRecord Tarantool Space Model for sessions */
	private $spaceModel;

	/** @var string Raw Space Name in Tarantool */
	private $rawSpaceName;

	/**
	 * Initializes the Session component.
	 * @throws InvalidConfigException if [[db]] is invalid.
	 */
	public function init()
	{
		$this->spaceModel = Yii::createObject($this->spaceClass);
		if (!$this->spaceModel instanceof ActiveRecord) {
			throw new InvalidConfigException('Session space model must be instance of ' . ActiveRecord::class);
		}

		// Consider `tablePrefix`
		$this->rawSpaceName = $this->spaceModel::getDb()->getSchema()->getRawTableName($this->spaceModel::tableName());

		parent::init();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUseCustomStorage()
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function openSession($savePath, $sessionName)
	{
		if ($this->getUseStrictMode()) {
			$id = $this->getId();
			if (!$this->getReadQuery($id)->exists()) {
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

		if ($deleteOldSession) {
			$this->spaceModel::deleteAll(['id' => $oldID]);
		}

		$expire = time() + $this->getTimeout();

		// NoSQL instead of SQL, to prevent Schema/PK retrive
		$this->spaceModel::getDb()->createNosqlCommand()->upsert(
			$this->rawSpaceName,
			[$newID, $expire, ''],
			Operations::set(1, $expire)
		)->execute();
	}

	/**
	 * {@inheritdoc}
	 */
	public function close()
	{
		if ($this->getIsActive()) {
			YII_DEBUG ? session_write_close() : @session_write_close();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function readSession($id)
	{
		$data = $this->getReadQuery($id)->select(['data'])->scalar();

		return (false === $data ? '' : $data);
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
			$expire = time() + $this->getTimeout();

			// NoSQL instead of SQL, to prevent Schema/PK retrive
			$this->spaceModel::getDb()->createNosqlCommand()->upsert(
				$this->rawSpaceName,
				[$id, $expire, $data],
				Operations::set(1, $expire)->andSet(2, $data)
			)->execute();
		} catch (Throwable $e) {
			Yii::$app->errorHandler->handleException($e);

			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function destroySession($id)
	{
		$this->spaceModel::deleteAll(['id' => $id]);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function gcSession($maxLifetime)
	{
		// NoSQL haven't option for multi row delete
		$this->spaceModel::deleteAll('[[expire]]<=:expire', [':expire' => time()]);

		return true;
	}

	/**
	 * Generates a query to get the session from Tarantrool.
	 * @param string $id The id of the session
	 * @return ActiveRecord
	 */
	protected function getReadQuery($id)
	{
		return $this->spaceModel
			->find()
			->where('[[expire]]>:expire AND [[id]]=:id', [':expire' => time(), ':id' => $id])
		;
	}
}
