Configure connection
======================================

```php
return [
    'components' => [
        // Tarantool connection setup
        'tarantool' => [
            'class' => \mhthnz\tarantool\Connection::class,
            'dsn' => 'tcp://username:password@localhost:3301/?connect_timeout=5&max_retries=3',
            
            // Convertation SQL queries and NoSQL requests to string for debugging or logging
            // Probably you want to disable these options on production for having better performance
            'enableLogging' => true,
    	    'enableProfiling' => true,
            
            // https://www.tarantool.io/en/doc/latest/reference/reference_lua/net_box/#lua-function.conn.call
            // Msg pack doesn't support function lua type, keep this property true for autoresolving that problem
            // It enables setting tarantool config param internally:
            // Or you can add to your tarantool config manually:
            // msgpack = require('msgpack');
     	    // msgpack.cfg{encode_invalid_as_nil = true}
            'handleLuaEncodingErrors' => true,
        ],
    ],
]
```