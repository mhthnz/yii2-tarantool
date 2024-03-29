LuaValidator
======================================
`LuaValidator` allows to use tarantool lua function for validating values and model's properties.

You can use as ad hoc validator or with models inherited from: `yii\base\Model`, `yii\db\ActiveRecord`, `mhthnz\tarantool\ActiveRecord` and with `yii\base\DynamicModel`.

Property `value` and some additional `params` will be sent to lua function. Function has to return `boolean` value, or error will be generated.

You can also force set `db` for `mhthnz\tarantool\ActiveRecord`, `db` can be string component ID or instance of `mhthnz\tarantool\Connection`

The simplest case:

```php
function rules()
{
	return[
    	['property', 'required'],
        ['property', 'integer']
    	['property', mhthnz\tarantool\LuaValidator::class, 'function' => <<<LUA
	function(value, params)
		return value == 10
	end
LUA
]
    ];
}
```

For `yii\base\Model`, `yii\db\ActiveRecord`, `yii\base\DynamicModel` - `db` property is required:
```php
function rules()
{
	$lua = <<<LUA
	function(value, params)
		return box.space.myspace:get(value)[2] > params['greater']
	end
LUA;
	return[
    	['property', 'required'],
        ['property', 'integer']
    	['property', mhthnz\tarantool\LuaValidator::class, 'function' => $lua, 'params' => ['greater' => 100], 'db' => 'tarantool']
    ];
}
```
