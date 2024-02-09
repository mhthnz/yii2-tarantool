<?php

namespace mhthnz\tarantool;

use Tarantool\Client\Keys;
use Tarantool\Client\SqlQueryResult;
use yii\base\InvalidCallException;

/**
 * Data reader.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class DataReader extends \yii\base\BaseObject implements \Iterator, \Countable
{
    /**
     * @var SqlQueryResult
     */
    private $_result;
    private $_closed = false;
    /**
     * @var \Generator
     */
    private $_generator;
    /**
     * @var \Tarantool\Client\PreparedStatement
     */
    private $_stmt;

    /**
     * Constructor.
     * @param Command $command the command generating the query result
     * @param array $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct(Command $command, $config = [])
    {
        $this->_result = new SqlQueryResult(
            $command->response->getBodyField(Keys::DATA),
            $command->response->getBodyField(Keys::METADATA)
        );
        $this->_generator = $this->_result->getIterator();
        $this->_stmt = $command->preparedStatement;
        parent::__construct($config);
    }

    /**
     * TODO: Implement later...
     * @param $column
     * @param $value
     * @param string|null $dataType
     */
    public function bindColumn($column, &$value, $dataType = null)
    {
    }


    /**
     * Advances the reader to the next row in a result set.
     * @return array|false the current row, false if no more row available
     */
    public function read()
    {
        if (!$this->valid()) {
            return false;
        }
        $current = $this->_generator->current();
        $this->_generator->next();
        return $current;
    }

    /**
     * Returns a single column from the next row of a result set.
     * @param int $columnIndex zero-based column index
     * @return mixed the column of the current row, false if no more rows available
     */
    public function readColumn($columnIndex)
    {
        if (!$this->valid()) {
            return false;
        }
        $newArray = array_values($this->_generator->current());
        $result = $newArray[$columnIndex];
        $this->_generator->next();

        return $result;
    }

    /**
     * TODO: Will be later..
     * @param $className
     * @param $fields
     * @return false
     */
    public function readObject($className, $fields)
    {
        return false;
    }

    /**
     * Reads the whole result set into an array.
     * @return array the result set (each array element represents a row of data).
     * An empty array will be returned if the result contains no row.
     */
    public function readAll()
    {
        $result = [];
        foreach ($this->_result->getIterator() as $row) {
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Advances the reader to the next result when reading the results of a batch of statements.
     * This method is only useful when there are multiple result sets
     * returned by the query. Not all DBMS support this feature.
     * @return bool Returns true on success or false on failure.
     */
    public function nextResult()
    {
        $result = $this->_generator->current();
        $this->_generator->next();
        return $result;
    }

    /**
     * Closes the reader.
     * This frees up the resources allocated for executing this SQL statement.
     * Read attempts after this method call are unpredictable.
     */
    public function close()
    {
        if ($this->_stmt) {
            $this->_stmt->close();
        }
        $this->_closed = true;
    }

    /**
     * whether the reader is closed or not.
     * @return bool whether the reader is closed or not.
     */
    public function getIsClosed()
    {
        return $this->_closed;
    }

    /**
     * Returns the number of rows in the result set.
     * Note, most DBMS may not give a meaningful count.
     * In this case, use "SELECT COUNT(*) FROM tableName" to obtain the number of rows.
     * @return int number of rows contained in the result.
     */
    public function getRowCount()
    {
        return $this->_result->count();
    }

    /**
     * Returns the number of rows in the result set.
     * This method is required by the Countable interface.
     * Note, most DBMS may not give a meaningful count.
     * In this case, use "SELECT COUNT(*) FROM tableName" to obtain the number of rows.
     * @return int number of rows contained in the result.
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->_result->count();
    }

    /**
     * Returns the number of columns in the result set.
     * Note, even there's no row in the reader, this still gives correct column number.
     * @return int the number of columns in the result set.
     */
    public function getColumnCount()
    {
        return count($this->_result->getMetadata());
    }

    /**
     * Resets the iterator to the initial state.
     * This method is required by the interface [[\Iterator]].
     * @throws InvalidCallException if this method is invoked twice
     */
    #[\ReturnTypeWillChange]
    public function rewind()
    {
        $this->_generator->rewind();
    }

    /**
     * Returns the index of the current row.
     * This method is required by the interface [[\Iterator]].
     * @return int the index of the current row.
     */
    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->_generator->key();
    }

    /**
     * Returns the current row.
     * This method is required by the interface [[\Iterator]].
     * @return mixed the current row.
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->_generator->current();
    }

    /**
     * Moves the internal pointer to the next row.
     * This method is required by the interface [[\Iterator]].
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        $this->_generator->next();
    }

    /**
     * Returns whether there is a row of data at current position.
     * This method is required by the interface [[\Iterator]].
     * @return bool whether there is a row of data at current position.
     */
    #[\ReturnTypeWillChange]
    public function valid()
    {
        return $this->_generator->valid();
    }
}
