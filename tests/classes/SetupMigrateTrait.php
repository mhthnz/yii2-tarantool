<?php
namespace mhthnz\tarantool\tests\classes;

use mhthnz\tarantool\console\MigrateController;

trait SetupMigrateTrait
{
    /**
     * @var string test migration path.
     */
    protected $migrationPath;

    /**
     * @var string name of the migration controller class, which is under test.
     */
    protected $migrateControllerClass;

    /**
     * @var int|null migration controller exit code
     */
    protected $migrationExitCode;

    /**
     * Creates test migrate controller instance.
     * @param array $config controller configuration.
     * @return MigrateController migrate command instance.
     */
    protected function createMigrateController(array $config = [])
    {
        $module = $this->getMockBuilder('yii\\base\\Module');
        if (method_exists($module, 'setMethods')) {
            $module->setMethods(['fake']);
        } else {
            $module->addMethods(['fake']);
        }
        $mockObj = $module->setConstructorArgs(['console'])
            ->getMock();
        $class = $this->migrateControllerClass;
        $migrateController = new $class('migrate', $mockObj);
        $migrateController->interactive = false;
        $migrateController->migrationPath = $this->migrationPath;
        return \Yii::configure($migrateController, $config);
    }

    /**
     * Emulates running of the migrate controller action.
     * @param string $actionID id of action to be run.
     * @param array $args action arguments.
     * @param array $config controller configuration.
     * @return string command output.
     */
    protected function runMigrateControllerAction($actionID, array $args = [], array $config = [])
    {
        $controller = $this->createMigrateController($config);
        ob_start();
        ob_implicit_flush(false);
        try {
            $resp = $controller->run($actionID, $args);
            $this->migrationExitCode = $resp === null ? 0 : $resp; // Yii <= 2.0.40 compatability
        } catch (\Throwable $e) {
            ob_get_clean();
            throw $e;
        }

        return ob_get_clean();
    }
}