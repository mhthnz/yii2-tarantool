<?php

namespace mhthnz\tarantool\log;


use yii\base\InvalidConfigException;
use mhthnz\tarantool\Connection;
use yii\di\Instance;
use yii\helpers\VarDumper;
use yii\log\LogRuntimeException;

/**
 * Tarantool log target that allows to store logs in tarantool database.
 *
 * It required `log/migrations/m230401_153114_create_log_target_space.php` migration to be installed:
 * ```bash
 * $ ./yii tarantool-migration --migrationNamespaces=\\mhthnz\\tarantool\\log\\migrations
 * ```
 *
 * You may want to change engine or space name. To do that make a new migration that is inherited from `mhthnz\tarantool\log\migrations\m230401_153114_create_log_target_space`:
 *```php
 * class m230401_154567_create_log_target_space extends mhthnz\tarantool\log\migrations\m230401_153114_create_log_target_space
 * {
 *      protected $spaceName = 'other_log';
 *      protected $engine = 'vinyl';
 *```
 *
 * Configure tarantool log target:
 * ```php
 * return [
 *      'components' => [
 *          'log' => [
 *              'targets' => [
 *                   'tarantool' => [
 *                      'class' => '\mhthnz\tarantool\log\Target',
 *                      'levels' => ['error', 'warning'],
 *                  ]
 *              ]
 *          ]
 *      ],
 * ]
 *```
 */
class Target extends \yii\log\Target
{
    /**
     * @var Connection|array|string the DB connection object or the application component ID of the DB connection.
     * After the Target object is created, if you want to change this property, you should only assign it
     * with a DB connection object.
     * Starting from version 2.0.2, this can also be a configuration array for creating the object.
     */
    public $db = 'tarantool';

    /**
     * @var string name of the DB space to store cache content. Defaults to "log".
     */
    public $logSpace = 'log';


    /**
     * Initializes the Target component.
     * This method will initialize the [[db]] property to make sure it refers to a valid DB connection.
     * @throws InvalidConfigException if [[db]] is invalid.
     */
    public function init()
    {
        parent::init();
        $this->db = Instance::ensure($this->db, Connection::class);
    }

    /**
     * Stores log messages to DB.
     * Starting from version 2.0.14, this method throws LogRuntimeException in case the log can not be exported.
     * @return void
     * @throws InvalidConfigException
     * @throws LogRuntimeException
     * @throws \Tarantool\Client\Exception\ClientException
     * @throws \Throwable
     */
    public function export()
    {
        $command = $this->db->createNosqlCommand();
        foreach ($this->messages as $message) {
            list($text, $level, $category, $timestamp) = $message;
            if (!is_string($text)) {
                // exceptions may not be serializable if in the call stack somewhere is a Closure
                if ($text instanceof \Exception || $text instanceof \Throwable) {
                    $text = (string) $text;
                } else {
                    $text = VarDumper::export($text);
                }
            }
            $resp = $command->insert($this->logSpace, [null, $level, $category, $timestamp, $this->getMessagePrefix($message), $text])->execute()->getResponseData();
            if (count($resp) > 0) {
                continue;
            }

            throw new LogRuntimeException('Unable to export log through database!');
        }
    }
}
