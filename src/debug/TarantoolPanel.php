<?php

namespace mhthnz\tarantool\debug;

use yii\debug\panels\DbPanel;
use yii\log\Logger;

/**
 * Panel for tarantool queries.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class TarantoolPanel extends DbPanel
{
    /**
     * {@inheritdoc}
     */
    public $db = 'tarantool';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'Tarantool';
    }

    /**
     * {@inheritdoc}
     */
    public function getSummaryName()
    {
        return 'Tarantool';
    }

    /**
     * Returns all profile logs of the current request for this panel.
     * @return array
     */
    public function getProfileLogs()
    {
        $target = $this->module->logTarget;

        return $target->filterMessages($target->messages, Logger::LEVEL_PROFILE, [
            'mhthnz\tarantool\Command::*',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function hasExplain()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function canBeExplained($type)
    {
        return strtolower($type) === 'select';
    }
}