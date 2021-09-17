<?php
/**
 * This view is used by mhthnz\tarantool\console\MigrateController.
 *
 * The following variables are available in this view:
 */
/* @var $className string the new migration class name without namespace */
/* @var $namespace string the new migration class namespace */

echo "<?php\n";
if (!empty($namespace)) {
    echo "\nnamespace {$namespace};\n";
}
?>

use mhthnz\tarantool\Migration;

/**
 * Class <?= $className . "\n" ?>
 */
class <?= $className ?> extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function up()
    {

    }

    /**
     * {@inheritdoc}
     */
    public function down()
    {
        echo "<?= $className ?> cannot be reverted.\n";

        return false;
    }
}
