<?php

namespace mhthnz\tarantool\tests;

use mhthnz\tarantool\tests\classes\LuaValidatorActiveRecord;
use mhthnz\tarantool\tests\classes\LuaValidatorBaseModel;
use mhthnz\tarantool\validators\LuaValidator;
use yii\base\DynamicModel;
use yii\db\Connection;

class LuaValidatorTest extends TestCase
{
    use DbTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication(['components' => [
            'tarantool' => [
                'class' => \mhthnz\tarantool\Connection::class,
                'dsn' => 'tcp://guest@localhost:3301',
            ]
        ]]);
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        $this->makeSpaceForCmd();
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropSpacesIfExist(['myspace', 'myspace1', 'myspace2', 'myspace3']);
        parent::tearDown();
    }

    public function testLuaValidator()
    {
        $func = <<<LUA
        function(value, params)
            return value == params['val'];
        end
LUA;
        foreach ([$this->getDb(), 'tarantool'] as $val) {
            $validator = new LuaValidator(['db' => $val, 'function' => $func, 'params' => ['val' => 2]]);
            $this->assertFalse($validator->validate(1));
            $this->assertTrue($validator->validate(2));
            $this->assertFalse($validator->validate('asdasd'));
            $this->assertFalse($validator->validate([]));
        }

        // Without db
        $validator = new LuaValidator(['function' => $func, 'params' => ['val' => 2]]);
        $thrown = false;
        $msg = '';
        try {
            $validator->validate(1);
        } catch (\Throwable $e) {
            $thrown = true;
            $msg = $e->getMessage();
        }
        $this->assertEquals('LuaValidator::db must be instance of mhthnz\\tarantool\\Connection', $msg);
        $this->assertTrue($thrown);

        $validator = new LuaValidator(['db' => 'non-existent', 'function' => $func, 'params' => ['val' => 2]]);
        $thrown = false;
        $msg = '';
        try {
            $validator->validate(1);
        } catch (\Throwable $e) {
            $thrown = true;
            $msg = $e->getMessage();
        }
        $this->assertEquals('Unknown component ID: non-existent', $msg);
        $this->assertTrue($thrown);

        $validator = new LuaValidator(['db' => new Connection(), 'function' => $func, 'params' => ['val' => 2]]);
        $thrown = false;
        $msg = '';
        try {
            $validator->validate(1);
        } catch (\Throwable $e) {
            $thrown = true;
            $msg = $e->getMessage();
        }
        $this->assertEquals('LuaValidator::db must be instance of mhthnz\\tarantool\\Connection', $msg);
        $this->assertTrue($thrown);

        $func = <<<LUA
        function(value, params)
            return value;
        end
LUA;
        foreach (['array' => [], 1 => 1, 'text' => 'text'] as $key => $val) {
            $validator = new LuaValidator(['db' => 'tarantool', 'function' => $func, 'params' => ['val' => 2]]);
            $err = '';
            $this->assertFalse($validator->validate($val, $err));
            $this->assertEquals('Lua function of the input value must return boolean, but returned \''.$key.'\'', $err);
        }

        // Negative
        $validator = new LuaValidator(['db' => 'tarantool', 'function' => $func, 'params' => ['val' => 2]]);
        $err = '';
        $this->assertFalse($validator->validate(false, $err));
        $this->assertEquals('the input value is not valid.', $err);
    }

    public function testLuaValidatorDynamicModel()
    {
        $model = new DynamicModel([
            'field1', 'field2'
        ]);
        $lua = <<<LUA
            function (val, params) 
                return box.space.myspace:get(val)[2] == params['val']
            end
LUA;
        $model->addRule('field1', LuaValidator::class, ['params' => ['val' => 'text 2'], 'db' => 'tarantool', 'function' => $lua]);
        $this->assertTrue($model->validate());
        $this->assertEmpty($model->getErrors());

        $model->field1 = 2;
        $this->assertTrue($model->validate());
        $this->assertEmpty($model->getErrors());

        $model->field1 = 3;
        $this->assertFalse($model->validate());
        $this->assertEquals(['field1' => ['Field1 is not valid.']], $model->getErrors());

        $model = new DynamicModel([
            'field1', 'field2'
        ]);

        $lua = <<<LUA
            function (val, params) 
                return box.space.myspace:get(val)[2] == params['val']
            end
LUA;
        $model->addRule('field1', LuaValidator::class, ['skipOnEmpty' => false, 'params' => ['val' => 'text 2'], 'db' => 'tarantool', 'function' => $lua]);

        $thrown = false;
        try {
            $model->validate();
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testBaseModel()
    {
        $model = new LuaValidatorBaseModel();
        $model->field2 = 1;
        $model->field1 = 'text 2';
        $thrown = false;
        try {
            $model->validate();
        } catch (\Throwable $e) {
            $this->assertEquals('The "function" property must be set.', $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $model = new LuaValidatorBaseModel();
        $model->field2 = 1;
        $model->field1 = 1;
        $thrown = false;
        $lua = <<<LUA
            function (val, params) 
                return box.space.myspace:get(val)[2] == params['val']
            end
LUA;
        $model->setLuaFunc($lua);

        try {
            $model->validate();
        } catch (\Throwable $e) {
            $this->assertEquals('Model has to be extended from \mhthnz\tarantool\ActiveRecord or set LuaValidator::db manually', $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $model = new LuaValidatorBaseModel();
        $model->field2 = 1;
        $model->field1 = 'test';
        $lua = <<<LUA
            function (val, params) 
                return box.space.myspace:get(val)[2] == params['field1']
            end
LUA;
        $model->setLuaFunc($lua);
        $model->setDB($this->getDb());
        $this->assertFalse($model->validate());
        $this->assertEquals(['field2' => ['Field2 is not valid.']], $model->getErrors());

        $model->clearErrors();
        $model->field2 = 2;
        $model->field1 = 'text 2';
        $this->assertEmpty($model->getErrors());
    }

    public function testActiveRecord()
    {
        $lua = <<<LUA
            function (value, params)
                return os.time() > value
            end
LUA;
        $rules = [
            ['id', 'required'],
            ['id', 'integer'],
            ['field', 'integer'],
            ['field1', LuaValidator::class, 'function' => $lua],
        ];
        $model = new LuaValidatorActiveRecord(['rules' => $rules]);
        $model->setAttributes([
            'id' => 1,
            'name' => "text 123",
            'field' => 123,
            'field1' => 111,
        ]);
        $this->assertTrue($model->validate());

        $model->clearErrors();

        $model->field1 = 9999999999999999;
        $this->assertFalse($model->validate());
        $this->assertEquals(['field1' => ['Field1 is not valid.']], $model->getErrors());

        $lua = <<<LUA
            function (value, params)
                return os.time()
            end
LUA;

        $rules = [
            ['id', 'required'],
            ['id', 'integer'],
            ['field', 'integer'],
            ['field1', LuaValidator::class, 'function' => $lua],
            ];
        $model = new LuaValidatorActiveRecord(['rules' => $rules]);
        $model->setAttributes([
            'id' => 1,
            'name' => "text 123",
            'field' => 123,
            'field1' => 111,
        ]);
        $this->assertFalse($model->validate());
        $this->assertNotEmpty($model->getErrors());
    }
}
