<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */
namespace gipfl\ZfDb\Adapter\Pdo\Ibm;

use gipfl\ZfDb\Adapter\Adapter;
use gipfl\ZfDb\Adapter\Exception\AdapterException;
use gipfl\ZfDb\Db;

/**
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Db2
{
    /**
     * @var Adapter
     */
    protected $_adapter = null;

    /**
     * Construct the data server class.
     *
     * It will be used to generate non-generic SQL
     * for a particular data server
     *
     * @param Adapter $adapter
     */
    public function __construct($adapter)
    {
        $this->_adapter = $adapter;
    }

    /**
     * Returns a list of the tables in the database.
     *
     * @return array
     */
    public function listTables()
    {
        $sql = "SELECT tabname "
        . "FROM SYSCAT.TABLES ";
        return $this->_adapter->fetchCol($sql);
    }

    /**
     * DB2 catalog lookup for describe table
     *
     * @param string $tableName
     * @param string $schemaName OPTIONAL
     * @return array
     */
    public function describeTable($tableName, $schemaName = null)
    {
        $sql = "SELECT DISTINCT c.tabschema, c.tabname, c.colname, c.colno,
                c.typename, c.default, c.nulls, c.length, c.scale,
                c.identity, tc.type AS tabconsttype, k.colseq
                FROM syscat.columns c
                LEFT JOIN (syscat.keycoluse k JOIN syscat.tabconst tc
                 ON (k.tabschema = tc.tabschema
                   AND k.tabname = tc.tabname
                   AND tc.type = 'P'))
                 ON (c.tabschema = k.tabschema
                 AND c.tabname = k.tabname
                 AND c.colname = k.colname)
            WHERE "
            . $this->_adapter->quoteInto('UPPER(c.tabname) = UPPER(?)', $tableName);
        if ($schemaName) {
            $sql .= $this->_adapter->quoteInto(' AND UPPER(c.tabschema) = UPPER(?)', $schemaName);
        }
        $sql .= " ORDER BY c.colno";

        $desc = array();
        $stmt = $this->_adapter->query($sql);

        /**
         * To avoid case issues, fetch using FETCH_NUM
         */
        $result = $stmt->fetchAll(Db::FETCH_NUM);

        /**
         * The ordering of columns is defined by the query so we can map
         * to variables to improve readability
         */
        $tabschema      = 0;
        $tabname        = 1;
        $colname        = 2;
        $colno          = 3;
        $typename       = 4;
        $default        = 5;
        $nulls          = 6;
        $length         = 7;
        $scale          = 8;
        $identityCol    = 9;
        $tabconstype    = 10;
        $colseq         = 11;

        foreach ($result as $key => $row) {
            list ($primary, $primaryPosition, $identity) = array(false, null, false);
            if ($row[$tabconstype] == 'P') {
                $primary = true;
                $primaryPosition = $row[$colseq];
            }
            /**
             * In IBM DB2, an column can be IDENTITY
             * even if it is not part of the PRIMARY KEY.
             */
            if ($row[$identityCol] == 'Y') {
                $identity = true;
            }

            $desc[$this->_adapter->foldCase($row[$colname])] = array(
            'SCHEMA_NAME'      => $this->_adapter->foldCase($row[$tabschema]),
            'TABLE_NAME'       => $this->_adapter->foldCase($row[$tabname]),
            'COLUMN_NAME'      => $this->_adapter->foldCase($row[$colname]),
            'COLUMN_POSITION'  => $row[$colno]+1,
            'DATA_TYPE'        => $row[$typename],
            'DEFAULT'          => $row[$default],
            'NULLABLE'         => (bool) ($row[$nulls] == 'Y'),
            'LENGTH'           => $row[$length],
            'SCALE'            => $row[$scale],
            'PRECISION'        => ($row[$typename] == 'DECIMAL' ? $row[$length] : 0),
            'UNSIGNED'         => false,
            'PRIMARY'          => $primary,
            'PRIMARY_POSITION' => $primaryPosition,
            'IDENTITY'         => $identity
            );
        }

        return $desc;
    }

    /**
     * Adds a DB2-specific LIMIT clause to the SELECT statement.
     *
     * @param string $sql
     * @param integer $count
     * @param integer $offset OPTIONAL
     * @throws AdapterException
     * @return string
     */
    public function limit($sql, $count, $offset = 0)
    {
        $count = intval($count);
        if ($count < 0) {
            throw new AdapterException("LIMIT argument count=$count is not valid");
        } else {
            $offset = intval($offset);
            if ($offset < 0) {
                throw new AdapterException("LIMIT argument offset=$offset is not valid");
            }

            if ($offset == 0 && $count > 0) {
                $limit_sql = $sql . " FETCH FIRST $count ROWS ONLY";
                return $limit_sql;
            }
            /**
             * DB2 does not implement the LIMIT clause as some RDBMS do.
             * We have to simulate it with subqueries and ROWNUM.
             * Unfortunately because we use the column wildcard "*",
             * this puts an extra column into the query result set.
             */
            $limit_sql = "SELECT z2.*
              FROM (
                  SELECT ROW_NUMBER() OVER() AS \"ZEND_DB_ROWNUM\", z1.*
                  FROM (
                      " . $sql . "
                  ) z1
              ) z2
              WHERE z2.zend_db_rownum BETWEEN " . ($offset+1) . " AND " . ($offset+$count);
        }
        return $limit_sql;
    }

    /**
     * DB2-specific last sequence id
     *
     * @param string $sequenceName
     * @return integer
     */
    public function lastSequenceId($sequenceName)
    {
        $sql = 'SELECT PREVVAL FOR '.$this->_adapter->quoteIdentifier($sequenceName).' AS VAL FROM SYSIBM.SYSDUMMY1';
        $value = $this->_adapter->fetchOne($sql);
        return $value;
    }

    /**
     * DB2-specific sequence id value
     *
     *  @param string $sequenceName
     *  @return integer
     */
    public function nextSequenceId($sequenceName)
    {
        $sql = 'SELECT NEXTVAL FOR '.$this->_adapter->quoteIdentifier($sequenceName).' AS VAL FROM SYSIBM.SYSDUMMY1';
        $value = $this->_adapter->fetchOne($sql);
        return $value;
    }
}