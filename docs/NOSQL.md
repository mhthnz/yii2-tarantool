Nosql query and command
======================================

Besides SQL queries you can use NoSQL interface for getting access to data. There are `Command` and `Query` classes for that. All requests will be shown in debug panel same way as SQL queries.


Query
------------
`Query` helps to perform nosql requests in traditional yii2 format. It converts query to select or call requests for retrieving data from tarantool.

```php
$conn = Yii::$app->tarantool;

// Two ways to create Query instance
$query = $conn->createNosqlQuery();
// or
$query = new mhthnz\tarantool\nosql\Query(['db' => $conn]);
// You don't have to specify db, `tarantool` service id will be used by default
$query = new mhthnz\tarantool\nosql\Query();


// Retrieve all data from space, ASC order (primary index)
$query->from('spacename')->all();

// Retrieve 10 tuples from space DESC order (primary index), using limit, offset and with specified connection
$query->from('spacename')->orderDesc()->offset(2)->limit(10)->all($conn);

// Retrieve 5 tuples which primary key is greater 10
$query->from('spacename')->where(['>', 10])->limit(5)->all();

// Other possible conditions (uses primary key)
$query->from('spacename')->where(['>=', 10])->limit(5)->all();
$query->from('spacename')->where(['<', 10])->limit(5)->all();
$query->from('spacename')->where(['<=', 10])->limit(5)->all();

// Retrieve tuples ordered DESC by `secondindex`
$query->from('spacename')->usingIndex('secondindex')->orderDesc()->all();
// Same effect as:
$query->from('spacename')->where(['=', 'secondindex', []])->orderDesc()->all();
// You can also use usingIndex with count/max/min
$query->from('spacename')->usingIndex('secondindex')->max();
$query->from('spacename')->usingIndex('secondindex')->min();

// Using non-primary indexes
$key = 10
$query->from('spacename')->where(['>', 'thirdindex', $key])->all();
$compositeKey = ["one", "two"];
$query->from('spacename')->where(['>', 'otherindex', $compositeKey])->all();
$query->from('spacename')->where(['<=', 'otherindex', "one"])->all(); // partial key usage of composite index
$query->from('spacename')->where(['=', 'otherindex', $compositeKey])->one();

// Methods for retrieving data
// Getting one tuple (works only with unique indexes) all do the same (perform :get call request)
$query->from('spacename')->where(1)->get();
$query->from('spacename')->where([1])->get();
$query->from('spacename')->where(['=', 1])->get();
$query->from('spacename')->where(['=', 'primaryindex', 1])->get(); 

// Getting the first tuple of result (doesn't set limit(1) internally)
$query->from('spacename')->where(1)->one();
$query->from('spacename')->orderDesc()->limit(1)->one();
$query->from('spacename')->where(['>', 1])->limit(1)->one();
$query->from('spacename')->where(['>', 'secondindex', 1])->offset(1)->one();
$query->from('spacename')->where(['<=', 'thirdindex', [1, 2]])->offset(1)->limit(1)->one();

// Getting column from all tuples, fieldNo can be specified
$query->from('spacename')->column(2); 
// [1, 1, 1, 1, 1]
$query->from('spacename')->where(['>', 'otherindex', [1, 2]])->column(); 
// [1, 2, 3, 4]
$query->from('spacename')->where(['<=', 'second', 11])->offset(1)->limit(11)->column(3); 
// ["field3 tuple1", "field3 tuple2", ...]

// Getting the first column of the first tuple
$query->from('spacename')->scalar(); 
// 1
$query->from('spacename')->orderDesc()->scalar(); 
// 10
$query->from('spacename')->where(['>', 'secondindex', 1])->offset(1)->scalar(); 
// 3

// Count tuples 
$query->from('spacename')->count();
// 10
$query->from('spacename')->where(['>', 'otherindex', ["one", "two"]])->count();
// 5
$query->from('spacename')->where(['<=', 'otherindex', "one"])->count();
// 3
$query->from('spacename')->where(1)->count();
// 1

// Min/Max/Random
$query->from('spacename')->max(); // Getting max tuple of primary index
$query->from('spacename')->(['=', 'otherindex', []])->max(); // Getting max tuple of otherindex
$query->from('spacename')->min(); // Getting min tuple of primary index
$query->from('spacename')->(['=', 'otherindex', []])->min(); // Getting min tuple of otherindex
$query->from('spacename')->random(); // Getting random tuple from space (seed will be generated randomly)
$seed = 111;
$query->from('spacename')->random($seed); // Getting random tuple from space using seed

// Exists (uses count internally)
$query->from('spacename')->where(['>', 'otherindex', ["one", "two"]])->exists();
$query->from('spacename')->where(['=', 123])->exists();

```

Command
------------
`Command` allows to perform `select`, `call`, `eval`, `update`, `upsert`, `insert`, `replace`.

Also `Command` can transform `select` requests to aggregate functions like `count`, `min`, `max`, `random`.

Here's some examples:

```php
$conn = Yii::$app->tarantool;

// Precreated requests
// insert
$conn->createNosqlCommand()->insert('spacename', [1, "name", "2021-03-12", 12])->execute(); 
// update
$conn->createNosqlCommand()->update('myspace', $key, Operations::add(3, 1)->andSet(1, "not my name")->andSet(2,"2020-03-11"))->execute();
// upsert
$conn->createNosqlCommand()->upsert('spacename', [1, "name", "2021-03-12"], Operations::add(3, 1))->execute();
// replace
$conn->createNosqlCommand()->replace('spacename', [1, "name 1", "2021-03-12"])->execute();
// delete
$conn->createNosqlCommand()->delete('spacename', [1])->execute();

// Call lua function
$arg1 = "Day: %a";
$conn->createNosqlCommand()->call('box.info')->execute()->queryAll();
$conn->createNosqlCommand()->call('os.date', $arg1)->execute()->queryScalar();
// Day: thu

// Evaluate lua code
$conn->createNosqlCommand()->evaluate('return box.info()')->execute()->queryAll();
$conn->createNosqlCommand()->evaluate('return os.date(...)', [$arg1])->execute()->queryScalar();
// Day: thu

// Some schema methods
$conn->createNosqlCommand()->createSpace('spacename', [
    ['name' => 'id', 'type' => 'unsigned', 'is_nullable' => false],
    ['name' => 'name', 'type' => 'string', 'is_nullable' => true],
    ['name' => 'date', 'type' => 'string', 'is_nullable' => false],
    ['name' => 'counter', 'type' => 'unsigned', 'is_nullable' => false],
])->execute();
$conn->createNosqlCommand()->truncateSpace('spacename')->execute();
$conn->createNosqlCommand()->dropSpace('spacename')->execute();

// Create/Drop index
$conn->createNosqlCommand()->createIndex('spacename', 'primaryIndex', ['id'], true)->execute();
$conn->createNosqlCommand()->dropIndex('spacename', 'primaryIndex')->execute();

// You can pass Request directly to command
$spaceID = 111;
$indexID = 0;
$key = [];
$limit = 10;
$offset = 0;

// Get one tuple by uniq undex
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->queryGet();

// Get all tuples that suite passed key
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->queryAll();

// Get first tuple of the result (it doesn't set ->limit(1) internally)
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->queryOne();

// Get one column of each touple
$fieldNo = 0;
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->queryColumn($fieldNo);
// [1, 2, 3]

// Returns first column of the first result
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->queryScalar();
// 1

// Returns number of touples based on space, index and key
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->count()->queryScalar();

// Returns min, max and random touple based on index value
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->min()->queryOne();
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->min()->queryOne();
$conn->createNosqlCommand(new SelectRequest($spaceID, $indexID, $key, $offset, $limit, IteratorTypes::EQ))
->random()->queryOne();
```