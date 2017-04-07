<?php
/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2015 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package    Phinx
 * @subpackage Phinx\Db\Adapter
 */
namespace Phinx\Db\Adapter;

use Phinx\Db\Table;
use Phinx\Db\Table\Column;
use Phinx\Db\Table\Index;
use Phinx\Db\Table\ForeignKey;

/**
 * Phinx Oracle Adapter.
 *
 * @author Rob Morgan <robbym@gmail.com>
 */
class OracleAdapter extends PdoAdapter implements AdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if (null === $this->connection) {
            if (!class_exists('PDO') || !in_array('oci', \PDO::getAvailableDrivers(), true)) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('You need to enable the PDO_Oci extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }

            $db = null;
            $options = $this->getOptions();

            $dsn = 'oci:';

            // use network connection
            $oci_connectinString = '(PROTOCOL=TCP)(Host=' . $options['host'] . ')';
            if (!empty($options['port'])) {
                $oci_connectinString .= '(Port=' . $options['port'] . ')';
            }

            $dsn .= 'dbname=(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=' . $oci_connectinString . '))(CONNECT_DATA=(SID=' . $options['name'] . ')))';

            // charset support
            if (!empty($options['charset'])) {
                $dsn .= ';charset=' . $options['charset'];
            }

            $driverOptions = array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION);

            try {
                $db = new \PDO($dsn, $options['user'], $options['pass'], $driverOptions);
            } catch (\PDOException $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'There was a problem connecting to the database: %s',
                    $exception->getMessage()
                ));
            }

            $this->setConnection($db);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->connection = null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasTransactions()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        $this->execute('START TRANSACTION');
    }

    /**
     * {@inheritdoc}
     */
    public function commitTransaction()
    {
        $this->execute('COMMIT');
    }

    /**
     * {@inheritdoc}
     */
    public function rollbackTransaction()
    {
        $this->execute('ROLLBACK');
    }

    /**
     * {@inheritdoc}
     */
    public function quoteTableName($tableName)
    {
        return str_replace('.', '`.`', $this->quoteColumnName($tableName));
    }

    /**
     * {@inheritdoc}
     */
    public function quoteColumnName($columnName)
    {
        return '`' . str_replace('`', '``', $columnName) . '`';
    }

    /**
     * {@inheritdoc}
     */
    public function hasTable($tableName)
    {
        $exists = $this->fetchRow(sprintf(
            "SELECT table_name
            FROM user_tables
            WHERE table_name = '%s'",
            $tableName
        ));

        return !empty($exists);
    }

    /**
     * {@inheritdoc}
     */
    public function createTable(Table $table)
    {
        $this->notImplemented();
    }

    /**
     * {@inheritdoc}
     */
    public function renameTable($tableName, $newTableName)
    {
        $this->startCommandTimer();
        $this->writeCommand('renameTable', array($tableName, $newTableName));
        $this->execute(sprintf('RENAME TABLE %s TO %s', $this->quoteTableName($tableName), $this->quoteTableName($newTableName)));
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function dropTable($tableName)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropTable', array($tableName));
        $this->execute(sprintf('DROP TABLE %s', $this->quoteTableName($tableName)));
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function truncateTable($tableName)
    {
        $sql = sprintf(
            'TRUNCATE TABLE %s',
            $this->quoteTableName($tableName)
        );

        $this->execute($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function getColumns($tableName)
    {
        $columns = array();
        $rows = $this->fetchAll(sprintf("SELECT column_name, nullable, data_default, data_precision, data_scale, data_type FROM user_tab_columns WHERE table_name = '%s'", $this->quoteTableName($tableName)));
        foreach ($rows as $columnInfo) {

            $column = new Column();
            $column->setName($columnInfo['column_name'])
                ->setNull($columnInfo['nullable'] == 'Y')
                ->setDefault($columnInfo['data_default'])
                ->setPrecision($columnInfo['data_precision'])
                ->setScale($columnInfo['data_scale'])
                ->setType($this->getPhinxType($columnInfo['data_type']));

            if (isset($columnInfo['data_length'])) {
                $column->setLimit($columnInfo['data_length']);
            }

            // Prior to Oracle 12c, the auto_increment can only be implemented by workaround (i.e create a trriger to
            // increment the field)
            // So we won't handle the auto_increment feature.
//            if ($columnInfo['Extra'] === 'auto_increment') {
//                $column->setIdentity(true);
//            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function hasColumn($tableName, $columnName)
    {
        $rows = $this->fetchAll(sprintf("SELECT column_name FROM user_tab_columns WHERE table_name = '%s'", $this->quoteTableName($tableName)));
        foreach ($rows as $column) {
            if (strcasecmp($column['column_name'], $columnName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the defintion for a `DEFAULT` statement.
     *
     * @param  mixed $default
     * @return string
     */
    protected function getDefaultValueDefinition($default)
    {
        if (is_string($default) && 'CURRENT_TIMESTAMP' !== $default) {
            $default = $this->getConnection()->quote($default);
        } elseif (is_bool($default)) {
            $default = $this->castToBool($default);
        }
        return isset($default) ? ' DEFAULT ' . $default : '';
    }

    /**
     * {@inheritdoc}
     */
    public function addColumn(Table $table, Column $column)
    {
        $this->startCommandTimer();
        $sql = sprintf(
            'ALTER TABLE %s ADD (%s %s)',
            $this->quoteTableName($table->getName()),
            $this->quoteColumnName($column->getName()),
            $this->getColumnSqlDefinition($column)
        );

        if ($column->getAfter()) {
            $sql .= ' AFTER ' . $this->quoteColumnName($column->getAfter());
        }

        $this->writeCommand('addColumn', array($table->getName(), $column->getName(), $column->getType()));
        $this->execute($sql);
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function renameColumn($tableName, $columnName, $newColumnName)
    {
        $this->startCommandTimer();

        if (!$this->hasColumn($tableName, $columnName)) {
            throw new \InvalidArgumentException("The specified column does not exist: $columnName");
        }
        $this->writeCommand('renameColumn', array($tableName, $columnName, $newColumnName));
        $this->execute(
            sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName),
                $this->quoteColumnName($newColumnName)
            )
        );
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function changeColumn($tableName, $columnName, Column $newColumn)
    {
        $this->startCommandTimer();
        $this->writeCommand('changeColumn', array($tableName, $columnName, $newColumn->getType()));
        $after = $newColumn->getAfter() ? ' AFTER ' . $this->quoteColumnName($newColumn->getAfter()) : '';
        $this->execute(
            sprintf(
                'ALTER TABLE %s MODIFY (%s %s %s)',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($newColumn->getName()),
                $this->getColumnSqlDefinition($newColumn),
                $after
            )
        );
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function dropColumn($tableName, $columnName)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropColumn', array($tableName, $columnName));
        $this->execute(
            sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName)
            )
        );
        $this->endCommandTimer();
    }

    /**
     * Get an array of indexes from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getIndexes($tableName)
    {
        $indexes = array();
        $rows = $this->fetchAll(sprintf(
                "SELECT index_name, column_name FROM usr_ind_columns WHERE table_name = '%s'",
                $this->quoteTableName($tableName)
            )
        );
        foreach ($rows as $row) {
            if (!isset($indexes[$row['index_name']])) {
                $indexes[$row['index_name']] = array('columns' => array());
            }
            $indexes[$row['index_name']]['columns'][] = strtolower($row['column_name']);
        }
        return $indexes;
    }

    /**
     * {@inheritdoc}
     */
    public function hasIndex($tableName, $columns)
    {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }

        $columns = array_map('strtolower', $columns);
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $index) {
            if ($columns == $index['columns']) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function hasIndexByName($tableName, $indexName)
    {
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $name => $index) {
            if ($name === $indexName) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function addIndex(Table $table, Index $index)
    {
        $this->startCommandTimer();
        $this->writeCommand('addIndex', array($table->getName(), $index->getColumns()));
        $sql = $this->getIndexSqlDefinition($index, $table->getName());
        $this->execute($sql);
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function dropIndex($tableName, $columns)
    {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }

        $this->writeCommand('dropIndex', array($tableName, $columns));
        $indexes = $this->getIndexes($tableName);
        $columns = array_map('strtolower', $columns);

        foreach ($indexes as $indexName => $index) {
            if ($columns == $index['columns']) {
                $this->execute(
                    sprintf(
                        'DROP INDEX %s',
                        $this->quoteColumnName($indexName)
                    )
                );
                $this->endCommandTimer();
                return;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function dropIndexByName($tableName, $indexName)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropIndexByName', array($tableName, $indexName));
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $name => $index) {
            if ($name === $indexName) {
                $this->execute(
                    sprintf(
                        'DROP INDEX %s',
                        $this->quoteColumnName($indexName)
                    )
                );
                $this->endCommandTimer();
                return;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasForeignKey($tableName, $columns, $constraint = null)
    {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        $foreignKeys = $this->getForeignKeys($tableName);
        if ($constraint) {
            if (isset($foreignKeys[$constraint])) {
                return !empty($foreignKeys[$constraint]);
            }
            return false;
        } else {
            foreach ($foreignKeys as $key) {
                $a = array_diff($columns, $key['columns']);
                if (empty($a)) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Get an array of foreign keys from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getForeignKeys($tableName)
    {
        $foreignKeys = array();
        $rows = $this->fetchAll(sprintf(
            "SELECT
             c_list.TABLE_NAME as TABLE_NAME
             c_list.CONSTRAINT_NAME as CONSTRAINT_NAME,
             c_src.COLUMN_NAME as COLUMN_NAME,
             c_dest.TABLE_NAME as REFERENCED_TABLE_NAME,
             c_dest.COLUMN_NAME as REFERENCED_COLUMN_NAME
            FROM ALL_CONSTRAINTS c_list, ALL_CONS_COLUMNS c_src, ALL_CONS_COLUMNS c_dest
            WHERE c_list.CONSTRAINT_NAME   = c_src.CONSTRAINT_NAME
             AND  c_list.OWNER             = c_src.OWNER
             AND  c_list.R_CONSTRAINT_NAME = c_dest.CONSTRAINT_NAME
             AND  c_list.OWNER             = c_dest.OWNER
             AND  c_list.CONSTRAINT_TYPE = 'R'
             AND  c_src.OWNER      = '%s'
             AND  c_src.TABLE_NAME = '%s'
            GROUP BY c_list.CONSTRAINT_NAME, c_src.TABLE_NAME,
                c_src.COLUMN_NAME, c_dest.TABLE_NAME, c_dest.COLUMN_NAME",
            $tableName
        ));
        foreach ($rows as $row) {
            $foreignKeys[$row['CONSTRAINT_NAME']]['table'] = $row['TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['columns'][] = $row['COLUMN_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
            $foreignKeys[$row['CONSTRAINT_NAME']]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        return $foreignKeys;
    }

    /**
     * {@inheritdoc}
     */
    public function addForeignKey(Table $table, ForeignKey $foreignKey)
    {
        $this->startCommandTimer();
        $this->writeCommand('addForeignKey', array($table->getName(), $foreignKey->getColumns()));
        $this->execute(
            sprintf(
                'ALTER TABLE %s ADD %s',
                $this->quoteTableName($table->getName()),
                $this->getForeignKeySqlDefinition($foreignKey, $table->getName())
            )
        );
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function dropForeignKey($tableName, $columns, $constraint = null)
    {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }

        $this->writeCommand('dropForeignKey', array($tableName, $columns));

        if ($constraint) {
            $this->execute(
                sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT %s',
                    $this->quoteTableName($tableName),
                    $constraint
                )
            );
            $this->endCommandTimer();
            return;
        } else {
            foreach ($columns as $column) {
                $rows = $this->fetchAll(sprintf(
                    "SELECT
                     c_list.CONSTRAINT_NAME as CONSTRAINT_NAME,
                    FROM ALL_CONSTRAINTS c_list, ALL_CONS_COLUMNS c_src, ALL_CONS_COLUMNS c_dest
                    WHERE c_list.CONSTRAINT_NAME   = c_src.CONSTRAINT_NAME
                     AND  c_list.OWNER             = c_src.OWNER
                     AND  c_list.R_CONSTRAINT_NAME = c_dest.CONSTRAINT_NAME
                     AND  c_list.OWNER             = c_dest.OWNER
                     AND  c_list.CONSTRAINT_TYPE = 'R'
                     AND  c_src.TABLE_NAME = '%s'
                     AND  c_src.COLUMN_NAME = '%s'
                    GROUP BY c_list.CONSTRAINT_NAME, c_src.TABLE_NAME,
                        c_src.COLUMN_NAME, c_dest.TABLE_NAME, c_dest.COLUMN_NAME;",
                    $tableName,
                    $column
                ));
                foreach ($rows as $row) {
                    $this->dropForeignKey($tableName, $columns, $row['CONSTRAINT_NAME']);
                }
            }
        }
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function getSqlType($type, $limit = null)
    {
        switch ($type) {
            case static::PHINX_TYPE_STRING:
                return array('name' => 'VARCHAR2', 'limit' => 4000);
                break;
            case static::PHINX_TYPE_CHAR:
                return array('name' => 'CHAR', 'limit' => 2000);
                break;
            case static::PHINX_TYPE_TEXT:
                return array('name' => 'CLOB');
                break;
            case static::PHINX_TYPE_INTEGER:
                return array('name' => 'NUMBER');
                break;
            case static::PHINX_TYPE_BIG_INTEGER:
                return array('name' => 'NUMBER');
                break;
            case static::PHINX_TYPE_FLOAT:
                return array('name' => 'NUMBER');
                break;
            case static::PHINX_TYPE_DECIMAL:
                return array('name' => 'NUMBER');
                break;
            case static::PHINX_TYPE_DATETIME:
            case static::PHINX_TYPE_TIMESTAMP:
                return array('name' => 'TIMESTAMP');
                break;
            case static::PHINX_TYPE_TIME:
                return array('name' => 'TIMESTAMP');
                break;
            case static::PHINX_TYPE_DATE:
                return array('name' => 'DATE');
                break;
            case static::PHINX_TYPE_BLOB:
            case static::PHINX_TYPE_BINARY:
                return array('name' => 'BLOB');
                break;
            case static::PHINX_TYPE_BOOLEAN:
                return array('name' => 'NUMBER');
                break;
            default:
                throw new \RuntimeException('The type: "' . $type . '" is not supported.');
        }
    }

    /**
     * Returns Phinx type by SQL type
     *
     * @param string $sqlTypeDef
     * @throws \RuntimeException
     * @internal param string $sqlType SQL type
     * @returns string Phinx type
     */
    public function getPhinxType($sqlTypeDef)
    {
        switch ($sqlTypeDef) {
            case 'VARCHAR2':
            case 'NVARCHAR2':
            case 'VARCHAR':
                return static::PHINX_TYPE_STRING;
            case 'CHAR':
            case 'NCHAR':
                return static::PHINX_TYPE_CHAR;
            case 'INT':
            case 'INTEGER':
            case 'SMALLINT':
            case 'NUMBER':
                return static::PHINX_TYPE_INTEGER;
            case 'DECIMAL':
            case 'NUMERIC':
                return static::PHINX_TYPE_DECIMAL;
            case 'REAL':
            case 'FLOAT':
                return static::PHINX_TYPE_FLOAT;
            case 'BLOB':
                return static::PHINX_TYPE_BINARY;
            case 'DATE':
                return static::PHINX_TYPE_DATE;
            case 'TIMESTAMP':
                return static::PHINX_TYPE_DATETIME;
            case 'BOOLEAN':
                return static::PHINX_TYPE_BOOLEAN;
            default:
                throw new \RuntimeException('The type: "' . $sqlTypeDef . '" is not supported');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDatabase($name, $options = array())
    {
        $this->notImplemented();
    }

    /**
     * {@inheritdoc}
     */
    public function hasDatabase($name)
    {
        $this->notImplemented();
    }

    /**
     * {@inheritdoc}
     */
    public function dropDatabase($name)
    {
        $this->notImplemented();
    }

    /**
     * Gets the MySQL Column Definition for a Column object.
     *
     * @param Column $column Column
     * @return string
     */
    protected function getColumnSqlDefinition(Column $column)
    {
        $sqlType = $this->getSqlType($column->getType(), $column->getLimit());

        $def = '';
        $def .= strtoupper($sqlType['name']);
        if ($column->getPrecision() && $column->getScale()) {
            $def .= '(' . $column->getPrecision() . ',' . $column->getScale() . ')';
        } elseif (isset($sqlType['limit'])) {
            $def .= '(' . $sqlType['limit'] . ')';
        }
        if (($values = $column->getValues()) && is_array($values)) {
            $def .= "('" . implode("', '", $values) . "')";
        }
        $def .= ($column->isNull() == false) ? ' NOT NULL' : ' NULL';
        $def .= $this->getDefaultValueDefinition($column->getDefault());

        if ($column->getComment()) {
            $def .= ' COMMENT ' . $this->getConnection()->quote($column->getComment());
        }

        return $def;
    }

    /**
     * Gets the MySQL Index Definition for an Index object.
     *
     * @param Index $index Index
     * @return string
     */
    protected function getIndexSqlDefinition(Index $index, $tableName)
    {
        if (is_string($index->getName())) {
            $indexName = $index->getName();
        } else {
            $columnNames = $index->getColumns();
            if (is_string($columnNames)) {
                $columnNames = array($columnNames);
            }
            $indexName = sprintf('%s_%s', $tableName, implode('_', $columnNames));
        }
        $def = sprintf(
            "CREATE %s INDEX %s ON %s (%s);",
            ($index->getType() === Index::UNIQUE ? 'UNIQUE' : ''),
            $indexName,
            $this->quoteTableName($tableName),
            implode(',', $index->getColumns())
        );
        return $def;
    }

    /**
     * Gets the MySQL Foreign Key Definition for an ForeignKey object.
     *
     * @param ForeignKey $foreignKey
     * @return string
     */
    protected function getForeignKeySqlDefinition(ForeignKey $foreignKey)
    {
        $def = '';
        if ($foreignKey->getConstraint()) {
            $def .= ' CONSTRAINT ' . $this->quoteColumnName($foreignKey->getConstraint());
        } else {
            $def .= ' CONSTRAINT fk_' . $foreignKey->getReferencedTable()->getName() . '_' . implode('_', $foreignKey->getColumns());
        }
        $columnNames = array();
        foreach ($foreignKey->getColumns() as $column) {
            $columnNames[] = $this->quoteColumnName($column);
        }
        $def .= ' FOREIGN KEY (' . implode(',', $columnNames) . ')';
        $refColumnNames = array();
        foreach ($foreignKey->getReferencedColumns() as $column) {
            $refColumnNames[] = $this->quoteColumnName($column);
        }
        $def .= ' REFERENCES ' . $this->quoteTableName($foreignKey->getReferencedTable()->getName()) . ' (' . implode(',', $refColumnNames) . ')';
        if ($foreignKey->getOnDelete()) {
            $def .= ' ON DELETE ' . $foreignKey->getOnDelete();
        }
        if ($foreignKey->getOnUpdate()) {
            $def .= ' ON UPDATE ' . $foreignKey->getOnUpdate();
        }
        return $def;
    }

    /**
     * Returns MySQL column types (inherited and MySQL specified).
     * @return array
     */
    public function getColumnTypes()
    {
        return array_merge(parent::getColumnTypes(), array ('enum', 'set', 'year', 'json'));
    }

    private function notImplemented()
    {
        throw new \RuntimeException('The method has not been implemented yet.');
    }
}
