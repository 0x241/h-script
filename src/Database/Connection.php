<?php

namespace HScript\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO-backed database adapter preserving the H-Script query-builder API.
 *
 * Placeholder expansion is converted to native prepared statements, while
 * mutation callbacks allow catalog caches to invalidate affected tables.
 */
class Connection
{
	const DEFAULT_ID_FIELD = '_ID_';

	var $link;
	var $char_set;

	var $last_query;

	var $params;

	private ?PDO $pdo = null;
	private ?PDOStatement $last_statement = null;
	private ?Throwable $last_exception = null;
	private array $bound_values = array();
	private $mutation_listener = null;

	function onMutation(?callable $listener): void
	{
		$this->mutation_listener = $listener;
	}

	function lastError() {
		if (!$this->pdo && !$this->last_exception)
			return "PDO error: Connection not opened";
		if ($this->last_exception)
			return 'PDO error: (' . $this->last_exception->getCode() . ') ' . $this->last_exception->getMessage() .
				($this->last_query ? HS2_NL . 'Query: ' . $this->last_query : '');
		$info = $this->pdo->errorInfo();
		return 'PDO error: (' . (isset($info[1]) ? $info[1] : $info[0]) . ') ' . (isset($info[2]) ? $info[2] : '') .
			($this->last_query ? HS2_NL . 'Query: ' . $this->last_query : '');
	}

	function checkLink()
	{
		if ($this->pdo)
			return true;
		xSysError($this->lastError());
	}

	function open($host, $db, $login, $pass, $char_set = 'utf8mb4')
	{
		try
		{
			$this->char_set = $this->_normalizeCharset($char_set);
			$this->pdo = new PDO($this->_dsn($host, $db, $this->char_set), $login, $pass, array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
			));
			$this->link = $this->pdo;
			if ($this->char_set)
				$this->pdo->exec('SET NAMES ' . $this->char_set);
			return true;
		}
		catch (Throwable $e)
		{
			$this->last_exception = $e;
			$this->pdo = null;
			$this->link = null;
		}
		return false;
	}

	function close()
	{
		$this->last_statement = null;
		$this->pdo = null;
		$this->link = null;
	}

	private function _normalizeCharset($char_set)
	{
		$char_set = trim((string)$char_set);
		if ($char_set === '')
			return '';
		if (!preg_match('/^[A-Za-z0-9_]+$/', $char_set))
			throw new InvalidArgumentException('Invalid database charset');
		return $char_set;
	}

	private function _dsn($host, $db, $char_set)
	{
		$host = (string)$host;
		$db = (string)$db;
		$parts = array();
		if (preg_match('/^([^:]+):([0-9]+)$/', $host, $m))
		{
			$parts[] = 'host=' . $m[1];
			$parts[] = 'port=' . $m[2];
		}
		else
			$parts[] = 'host=' . $host;
		$parts[] = 'dbname=' . $db;
		if ($char_set)
			$parts[] = 'charset=' . $char_set;
		return 'mysql:' . implode(';', $parts);
	}

	function _doQuery($query, $values = array())
	{
		$this->last_query = $query;
		$this->last_exception = null;
		$t = time();
		try
		{
			$stmt = $this->pdo->prepare($query);
			$n = 1;
			foreach ($values as $param)
				$stmt->bindValue($n++, $param['value'], $param['type']);
			$stmt->execute();
		}
		catch (Throwable $e)
		{
			$this->last_exception = $e;
			return false;
		}
		if (($t = abs(time() - $t)) >= 3)
			xAddToLog("$t: $query", 'db');
		$this->last_statement = $stmt;
		return $stmt;
	}

	function _freeQuery($qr)
	{
		if ($qr instanceof PDOStatement)
			$qr->closeCursor();
	}

	function affCount()
	{
		$this->checkLink();
		return ($this->last_statement ? $this->last_statement->rowCount() : 0);
	}

	function rowCount()
	{
		$this->checkLink();
		return ($this->last_statement ? $this->last_statement->rowCount() : false);
	}

	function colCount()
	{
		$this->checkLink();
		return ($this->last_statement ? $this->last_statement->columnCount() : false);
	}

	function newID()
	{
		$this->checkLink();
		return (int)$this->pdo->lastInsertId();
	}

	function _filter($values, $fields = '') // empty fields = no filter
	{
		if (!is_array($values) or !$fields)
			return $values;
		$fields = asArray($fields);
		$v = array();
		foreach ($fields as $f)
			$v[$f] = (array_key_exists($f, $values) ? $values[$f] : null);
		return $v;
	}

	function _numericFields($table)
	{
		static $cache = array();
		if (!preg_match('/^[A-Za-z0-9_]+$/', $table))
			return array();
		if (isset($cache[$table]))
			return $cache[$table];
		$cache[$table] = array();
		try
		{
			$qr = $this->_doQuery('SHOW COLUMNS FROM ' . $this->field($table));
			if ($qr)
			{
				while ($row = $qr->fetch(PDO::FETCH_ASSOC))
					if (preg_match('/\b(?:tinyint|smallint|mediumint|int|bigint|decimal|float|double|real|bit)\b/i', $row['Type']))
						$cache[$table][$row['Field']] = true;
				$qr->closeCursor();
			}
		}
		catch (Throwable $e)
		{
			$this->last_exception = $e;
		}
		return $cache[$table];
	}

	function _normalizeValues($table, &$values)
	{
		if (!is_array($values))
			return;
		$numeric = $this->_numericFields($table);
		if (!$numeric)
			return;
		foreach ($values as $field => $value)
		{
			$name = trim($field);
			if (substr($name, -1) === '=')
				$name = trim(substr($name, 0, -1));
			if (isset($numeric[$name]))
			{
				if (is_bool($value))
					$values[$field] = $value ? 1 : 0;
				elseif ($value === '' || $value === null)
					$values[$field] = 0;
			}
		}
	}

	function _escape($value, $fields = '', $as_array = false)
	{
		$this->checkLink();
		if (!$as_array or !is_array($value))
			return $this->pdo->quote($this->_stringValue($value));
		$a = array();
		foreach ($this->_filter($value, $fields) as $f => $v)
		{
			if (is_string($f) && substr($f, -1) === '=')
			{
				$f = trim(substr($f, 0, -1));
				$v = (string)$v;
			}
			else
				$v = $this->_escape($v);
			if (!is_int($f))
				$f = $this->field($f);
			$a[$f] = $v;
		}
		return $a;
	}

	function value($value, $fields = '', $as_array = false)
	{
		$value = $this->_escape($value, $fields, $as_array);
		if (!$as_array or !is_array($value))
			return $value;
		$a = array();
		foreach ($value as $f => $v)
			if (!is_int($f))
				$a[] = "$f=$v";
			else
				$a[] = $v;
		return implode(',', $a);
	}

	function field($name)
	{
		if (!is_array($name))
			return "`" . str_replace('`', '``', $name) . "`";
		$a = array();
		foreach ($name as $n)
			$a[] = $this->field($n);
		return implode(',', $a);
	}

	function query($query, $ph_values = null)
	{
		$this->checkLink();
		$this->params = array_reverse(asArray($ph_values));
		$this->bound_values = array();
		$p = '{(\?)([\?\#dfail%]?)|(\#)(\#|\!|[\w_]+)}';
		$query = preg_replace_callback($p, array(&$this, '_parseParamCallback'), $query);
		$qr = $this->_doQuery($query, $this->bound_values);
		if ($qr === false)
			xSysError($this->lastError());
		$this->_notifyMutation($query);
		if ($qr->columnCount() > 0)
			return $qr;
		if (preg_match('/^\s*INSERT\s+/six', $query))
			return $this->newID();
		return $this->affCount();
	}

	private function _notifyMutation(string $query): void
	{
		if (!$this->mutation_listener)
			return;
		if (!preg_match(
			'/^\s*(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE\s+TABLE)\s+`?([A-Za-z0-9_]+)`?/i',
			$query,
			$match
		))
			return;
		try
		{
			($this->mutation_listener)($match[1]);
		}
		catch (Throwable $e)
		{
			error_log('Cache invalidation failed for table ' . $match[1] . ': ' . $e->getMessage());
		}
	}

	function _parseParamCallback($m)
	{
		if (isset($m[1]) && $m[1] === '?')
		{
			if ($m[2] == '?')
				return '?';
			$v = array_pop($this->params);
			switch ($m[2])
			{
				case '#':
					return $this->field($v);
				case 'd':
					return $this->_addBoundValue((int)$v, PDO::PARAM_INT);
				case 'f':
					return $this->_addBoundValue(str_replace(',', '.', (string)(float)$v), PDO::PARAM_STR);
				case 'a':
					return $this->_arrayValues($v);
				case 'i':
					if (is_array($v) and (count($v) > 0))
						return 'IN (' . $this->_listValues($v) . ')';
					else
						return 'IS NULL';
				case 'l':
				case '%':
					if (!is_string($v) or ($v === ''))
						return 'NOT IS NULL';
					if ($m[2] == '%')
						$v = '%' . $v . '%';
					return 'LIKE ' . $this->_addBoundValue($v);
			}
			return $this->_addBoundValue($v);
		}
		if (isset($m[3]) && $m[3] === '#')
		{
			if ($m[4] == '#')
				return '#';
			if ($m[4] == '!')
				return ' AS ' . self::DEFAULT_ID_FIELD;
			return $this->field($m[4]);
		}
		return $m[0];
	}

	private function _addBoundValue($value, $type = PDO::PARAM_STR)
	{
		if ($type == PDO::PARAM_STR)
			$value = $this->_stringValue($value);
		$this->bound_values[] = array('value' => $value, 'type' => $type);
		return '?';
	}

	private function _stringValue($value)
	{
		if ($value === null)
			return '';
		if (is_bool($value))
			return ($value ? '1' : '');
		if (is_array($value))
			return 'Array';
		return (string)$value;
	}

	private function _listValues($values)
	{
		$a = array();
		foreach ($values as $v)
			$a[] = $this->_addBoundValue($v);
		return implode(',', $a);
	}

	private function _arrayValues($values, $fields = '')
	{
		if (!is_array($values))
			return $this->_addBoundValue($values);
		$a = array();
		foreach ($this->_filter($values, $fields) as $f => $v)
		{
			if (is_string($f) && substr($f, -1) === '=')
			{
				$f = trim(substr($f, 0, -1));
				$v = (string)$v;
			}
			else
				$v = $this->_addBoundValue($v);
			if (!is_int($f))
				$a[] = $this->field($f) . '=' . $v;
			else
				$a[] = $v;
		}
		return implode(',', $a);
	}

	function beginJob()
	{
		return $this->query('START TRANSACTION');
	}

	function endJob()
	{
		return $this->query('COMMIT');
	}

	function cancelJob()
	{
		return $this->query('ROLLBACK');
	}

	function select($table, $fields = '*', $filter = '', $ph_values = null, $order = '', $limit = '', $group = '')
	{
		return $this->query(
			"SELECT $fields FROM $table" .
			($filter ? " WHERE $filter" : '') .
			($group ? " GROUP BY $group" : '') .
			($order ? " ORDER BY $order" : '') .
			($limit ? " LIMIT $limit" : ''),
			$ph_values
		);
	}

	function insert($table, $fields_and_values, $fields = '', $as_replace = false)
	{
		$fields_and_values = $this->_filter($fields_and_values, $fields);
		if (!is_array($fields_and_values) || count($fields_and_values) == 0)
			return 0;
		$this->_normalizeValues($table, $fields_and_values);
		return $this->query(
			($as_replace ? 'REPLACE' : 'INSERT') . " INTO $table (?#) VALUES (?a)",
			array(array_keys($fields_and_values), array_values($fields_and_values))
		);
	}

	function replace($table, $fields_and_values, $fields = '')
	{
		return $this->insert($table, $fields_and_values, $fields, true);
	}

	function update($table, $fields_and_values, $fields = '', $filter = '', $ph_values = null)
	{
		$fields_and_values = $this->_filter($fields_and_values, $fields);
		if (!is_array($fields_and_values) || count($fields_and_values) == 0)
			return 0;
		$this->_normalizeValues($table, $fields_and_values);
		$ph_values = asArray($ph_values);
		array_unshift($ph_values, $fields_and_values);
		return $this->query(
			"UPDATE $table SET ?a WHERE $filter",
			$ph_values
		);
	}

	function delete($table, $filter = '', $ph_values = null, $order = '', $limit = '')
	{
		return $this->query(
			"DELETE FROM $table" .
			($filter ? " WHERE $filter" : '') .
			($order ? " ORDER BY $order" : '') .
			($limit ? " LIMIT $limit" : ''),
			$ph_values
		);
	}

	function count($table, $filter = '', $ph_values = null, $field = '')
	{
		return 0 + $this->fetch1(
			$this->select($table, 'COUNT(' . ($field ? $this->field($field) : '*') . ') AS _cnt_',
				$filter, $ph_values)
		);
	}

	function save($table, $fields_and_values, $fields = '', $id_field = self::DEFAULT_ID_FIELD)
	{
		$id = intval(isset($fields_and_values[$id_field]) ? $fields_and_values[$id_field] : 0);
		if ($id > 0)
		{
			$this->update($table, $fields_and_values, $fields, "$id_field=?d", array($id));
			return $id;
		}
		else
			return $this->insert($table, $fields_and_values, $fields);
	}

	function fetch($qr)
	{
		if (!($qr instanceof PDOStatement))
			return false;
		return $qr->fetch(PDO::FETCH_ASSOC);
	}

	function fetch1Row($qr)
	{
		$res = $this->fetch($qr);
		$this->_freeQuery($qr);
		return (is_array($res) ? $res : array());
	}

	function fetch1($qr)
	{
		$res = $this->fetch1Row($qr);
		return (is_array($res) ? reset($res) : null);
	}

	function fetchRows($qr, $single_field = false)
	{
		if (!($qr instanceof PDOStatement))
			return false;
		$res = array();
		while ($r = $this->fetch($qr))
			if ($single_field === 1)
				$res[] = reset($r);
			elseif ($single_field)
				$res[] = $r[$single_field];
			else
				$res[] = $r;
		$this->_freeQuery($qr);
		return $res;
	}

	function fetchIDRows($qr, $single_field = false, $id_field = self::DEFAULT_ID_FIELD) // single_field == '2' for '[field1]' => [field2]
	{
		if (!($qr instanceof PDOStatement))
			return false;
		$res = array();
		while ($r = $this->fetch($qr))
			if ($single_field === 2)
				$res[reset($r)] = next($r);
			elseif ($single_field)
				$res[$r[$id_field]] = $r[$single_field];
			else
				$res[$r[$id_field]] = $r;
		$this->_freeQuery($qr);
		return $res;
	}

}

?>
