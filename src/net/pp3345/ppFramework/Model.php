<?php

	/*
	 * 	This file is part of ppFramework.
	 *
	 *  ppFramework is free software: you can redistribute it and/or modify
	 *  it under the terms of the GNU General Public License as published by
	 *  the Free Software Foundation, either version 3 of the License, or
	 *  (at your option) any later version.
	 *
	 *  ppFramework is distributed in the hope that it will be useful,
	 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
	 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 *  GNU General Public License for more details.
	 *
	 *  You should have received a copy of the GNU General Public License
	 *  along with ppFramework.  If not, see <http://www.gnu.org/licenses/>.
	 */

	namespace net\pp3345\ppFramework;

	use net\pp3345\ppFramework\Exception\DataNotFoundException;
	use net\pp3345\ppFramework\Exception\DifferentDatabasesException;
	use net\pp3345\ppFramework\Exception\InvalidPropertyAccessException;
	use net\pp3345\ppFramework\SQL\Select;

	// This gives us some more performance since it allows resolving the functions compile-time
	use function \is_object;
	use function \is_array;
	use function \strlen;
	use function \count;
	use function \implode;
	use function \array_fill;
	use function \array_merge;
	use function \get_class;
	use function \gettype;

	trait Model {
		use TransparentPropertyAccessors {
			__get as __getTransparent;
			__set as __setTransparent;
		}

		/**
		 * @var \SplObjectStorage
		 */
		private static $_caches = null;
		private static $_cache = [];
		private static $_transactionalCacheActive = false;

		/**
		 * @var Database
		 */
		private static $_defaultDatabase = null;
		/**
		 * @var $_selectForUpdateStmt \PDOStatement
		 */
		private static $_selectForUpdateStmt = null;
		/**
		 * @var $_selectStmt \PDOStatement
		 */
		private static $_selectStmt = null;
		/**
		 * @var $_insertStmt \PDOStatement
		 */
		private static $_insertStmt = null;
		/**
		 * @var $_insertStmt \PDOStatement
		 */
		private static $_updateStmt = null;
		/**
		 * @var $_deleteStmt \PDOStatement
		 */
		private static $_deleteStmt = null;
		/**
		 * @var $_deleteRelationStmts \PDOStatement[]
		 */
		private static $_deleteRelationStmts = [];
		/**
		 * @var $_deleteOneWayRelationStmts \PDOStatement[]
		 */
		private static $_deleteOneWayRelationStmts = [];
		/**
		 * @var $_hasRelationStmts \PDOStatement[]
		 */
		private static $_hasRelationStmts = [];
		/**
		 * @var $_hasOneWayRelationStmts \PDOStatement[]
		 */
		private static $_hasOneWayRelationStmts = [];
		/**
		 * @var $_deleteAllRelationsStmts \PDOStatement[]
		 */
		private static $_deleteAllRelationsStmts = [];

		/**
		 * @var Database
		 */
		private $_database = null;
		public $id = 0;

		public function __construct($id = null, \stdClass $dataset = null, Database $database = null) {
			$this->_database = $database ?: self::$_defaultDatabase;

			if($id !== null) {
				if($dataset) {
					foreach($dataset as $name => $value)
						$this->$name = $value;
				} else {
					if($this->_database->selectForUpdate)
						$stmt = $this->_database == self::$_defaultDatabase ? self::$_selectForUpdateStmt : $database->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ? FOR UPDATE");
					else
						$stmt = $this->_database == self::$_defaultDatabase ? self::$_selectStmt : $database->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ?");

					if(!$stmt->execute([$id]) || !$stmt->rowCount())
						throw new DataNotFoundException(__CLASS__, $id);

					foreach($stmt->fetch(\PDO::FETCH_ASSOC) as $name => $value)
						$this->$name = $value;
				}

				if($this->_database == self::$_defaultDatabase)
					self::$_cache[$this->id] = $this;
				else {
					// We can't modify the array inside the ObjectStorage directly
					$cache = self::$_caches[$database];
					$cache[$this->id] = $this;
					self::$_caches[$database] = $cache;
				}
			}
		}

		public function __get($name) {
			if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name])) {
				if($this->$name instanceof self::$foreignKeys[$name])
					return $this->$name;

				$class = self::$foreignKeys[$name];

				return $this->$name ? $this->$name = $class::get($this->$name, null, $this->_database) : null;
			}

			return $this->__getTransparent($name);
		}

		public function __set($name, $value) {
			try {
				$this->__setTransparent($name, $value);
			} catch(InvalidPropertyAccessException $e) {
				if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name])) {
					if($value !== null && !($value instanceof self::$foreignKeys[$name]))
						throw new \UnexpectedValueException("Value must be instance of " . self::$foreignKeys[$name] . " or null, " . gettype($value) . " given");

					$this->$name = $value;
					return;
				}

				throw $e;
			}
		}

		public function __debugInfo() {
			static $recursion = [];

			if(isset($recursion[$this->id]))
				return "[" . __CLASS__ . ":" . $this->id . "] *RECURSION*";

			$recursion[$this->id] = true;

			$retval = [];

			foreach($this as $name => $value) {
				if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name])) {
					$retval[$name] = $this->__get($name);

					if($retval[$name] instanceof self::$foreignKeys[$name])
						$retval[$name] = $retval[$name]->__debugInfo();
				} else
					$retval[$name] = $this->$name;
			}

			unset($recursion[$this->id]);

			return $retval;
		}

		public function __sleep() {
			return ["id", "_database"];
		}

		public function __wakeup() {
			self::__construct($this->id, null, Database::getByDSN($this->_database->getDSN()));
		}

		public function __isset($name) {
			return isset($this->$name) || (isset(self::$foreignKeys) && isset(self::$foreignKeys[$name]));
		}

		public function save() {
			if(!self::$_insertStmt || $this->_database != self::$_defaultDatabase) {
				$query = "INSERT INTO `" . self::TABLE . "` SET ";
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$query .= "`{$field}` = ?,";
					$parameters[] = is_object($value) ? $value->id : $value; // Assume objects are models
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = "";

				$stmt = $this->_database->prepare($query);
				$stmt->execute($parameters);

				if($this->_database == self::$_defaultDatabase)
					self::$_insertStmt = $stmt;
			} else {
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$parameters[] = is_object($value) ? $value->id : $value;
				}

				self::$_insertStmt->execute($parameters);
			}

			// ID might have been set explicitly (no AUTO_INCREMENT)
			if(!$this->id)
				$this->id = $this->_database->lastInsertId();

			if($this->_database == self::$_defaultDatabase)
				self::$_cache[$this->id] = $this;
			else {
				$cache = self::$_caches[$this->_database];
				$cache[$this->id] = $this;
				self::$_caches[$this->_database] = $cache;
			}
		}

		public function update() {
			if(!$this->id) {
				$this->save();

				return;
			}

			if(!self::$_updateStmt || $this->_database != self::$_defaultDatabase) {
				// Build UPDATE query
				$query = "UPDATE `" . self::TABLE . "` SET ";
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$query .= "`{$field}` = ?,";
					$parameters[] = is_object($value) ? $value->id : $value;
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = " ";

				$query .= "WHERE `id` = ?";
				$parameters[] = $this->id;

				$stmt = $this->_database->prepare($query);
				$stmt->execute($parameters);

				if($this->_database == self::$_defaultDatabase)
					self::$_updateStmt = $stmt;
			} else {
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$parameters[] = is_object($value) ? $value->id : $value;
				}

				$parameters[] = $this->id;
				self::$_updateStmt->execute($parameters);
			}
		}

		public function delete() {
			if(!self::$_deleteStmt || $this->_database != self::$_defaultDatabase) {
				$stmt = $this->_database->prepare("DELETE FROM `" . self::TABLE . "` WHERE `id` = ?");
				$stmt->execute([$this->id]);

				if($this->_database == self::$_defaultDatabase)
					self::$_deleteStmt = $stmt;
			} else
				self::$_deleteStmt->execute([$this->id]);

			if(self::$_defaultDatabase == $this->_database)
				unset(self::$_cache[$this->id]);
			else {
				$cache = self::$_caches[$this->_database];
				unset($cache[$this->id]);
				self::$_caches[$this->_database] = $cache;
			}
		}

		public function lock() {
			try {
				if(!$this->_database->selectForUpdate) {
					$resetSelectForUpdate = true;
					$this->_database->selectForUpdate = true;
				}

				$this->__construct($this->id, null, $this->_database);
			} finally {
				if(isset($resetSelectForUpdate))
					$this->_database->selectForUpdate = false;
			}
		}

		public function refetch() {
			$this->__construct($this->id, null,$this->_database);
		}

		public function loadForeignKeys() {
			foreach(self::$foreignKeys as $property => $class)
				$this->__get($property);
		}

		/**
		 * @param           $id
		 * @param \stdClass $dataset
		 * @param Database $database
		 * @return $this
		 */
		public static function get($id, \stdClass $dataset = null, Database $database = null) {
			if($database && $database != self::$_defaultDatabase)
				return isset(self::$_caches[$database][$id]) ? self::$_caches[$database][$id] : new static($id, $dataset, $database);
			else if(self::$_transactionalCacheActive && isset(self::$_caches[self::$_defaultDatabase][$id]))
				return self::$_caches[self::$_defaultDatabase][$id];

			return isset(self::$_cache[$id]) ? self::$_cache[$id] : new static($id, $dataset);
		}

		public static function getBulk(array $ids, Database $database = null) {
			static $stmts = [];

			if(!$ids)
				return [];

			if(!$database)
				$database = self::$_defaultDatabase;

			$stmt = $database->prepare(isset($stmts[count($ids)])
				                                         ? $stmts[count($ids)]
				                                         : ($stmts[count($ids)] = "SELECT * FROM `" . self::TABLE . "` WHERE `id` IN(" . implode(",", array_fill(0, count($ids), "?")) . ")"));
			$stmt->execute($ids);

			$retval = [];

			while($dataset = $stmt->fetchObject())
				$retval[$dataset->id] = self::get($dataset->id, $dataset, $database);

			return $retval;
		}

		public static function getBulkOrdered(array $ids, Database $database = null) {
			static $stmts = [];

			if(!$ids)
				return [];

			if(!$database)
				$database = self::$_defaultDatabase;

			// http://stackoverflow.com/questions/1631723/maintaining-order-in-mysql-in-query
			$stmt = $database->prepare(isset($stmts[count($ids)])
				                                         ? $stmts[count($ids)]
				                                         : ($stmts[count($ids)] = "SELECT * FROM `" . self::TABLE . "` WHERE `id` IN(" . ($filled = implode(",", array_fill(0, count($ids), "?"))) . ") ORDER BY FIELD(`id`," . $filled . ")"));
			$stmt->execute(array_merge($ids, $ids));

			$retval = [];

			while($dataset = $stmt->fetchObject())
				$retval[$dataset->id] = self::get($dataset->id, $dataset, $database);

			return $retval;
		}

		/**
		 * @param Database $database
		 * @return Select
		 */
		public static function lookup(Database $database = null) {
			return (new Select())->model(static::class)->database($database ?: self::$_defaultDatabase);
		}

		public static function getForeignKeys() {
			return isset(self::$foreignKeys) ? self::$foreignKeys : [];
		}

		public function addRelation($relationTable, $object, $fields = []) {
			$parameters = [$this->id, $object->id];

			if($object->getDatabase() != $this->_database)
				throw new DifferentDatabasesException();

			if($object instanceof $this) {
				$relationField        = self::$relations[$relationTable][0];
				$foreignRelationField = self::$relations[$relationTable][1];
			} else {
				$relationField        = self::$relations[$relationTable];
				$foreignRelationField = $object::$relations[$relationTable];
			}

			$query = "INSERT INTO `{$relationTable}` SET `{$relationField}` = ?, `{$foreignRelationField}` = ?";

			foreach($fields as $name => $value) {
				$query .= ", `$name` = ?";
				$parameters[] = $value;
			}

			$stmt = $this->_database->prepare($query);
			$stmt->execute($parameters);
		}

		public function deleteRelation($relationTable, $object) {
			if($object->getDatabase() != $this->_database)
				throw new DifferentDatabasesException();

			if(!isset(self::$_deleteRelationStmts[$relationTable]) || self::$_defaultDatabase != $this->_database) {
				if($object instanceof $this) {
					$relationField = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];
					$stmt = $this->_database->prepare("DELETE FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else
					$stmt = $this->_database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable] . "` = :id AND `" . $object::$relations[$relationTable] . "` = :fid");

				if(self::$_defaultDatabase == $this->_database)
					self::$_deleteRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$_deleteRelationStmts[$relationTable];

			$stmt->execute([":id" => $this->id, ":fid" => $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function deleteOneWayRelation($relationTable, self $object) {
			if($object->getDatabase() != $this->_database)
				throw new DifferentDatabasesException();

			if(!isset(self::$_deleteOneWayRelationStmts[$relationTable]) || $this->_database != self::$_defaultDatabase) {
				$stmt = $this->_database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable][0] . "` = ? AND `" . self::$relations[$relationTable][1] . "` = ?");

				if($this->_database == self::$_defaultDatabase)
					self::$_deleteOneWayRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$_deleteOneWayRelationStmts[$relationTable];

			$stmt->execute([$this->id, $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function hasRelation($relationTable, $object) {
			if($object->getDatabase() != $this->_database)
				throw new DifferentDatabasesException();

			if(!isset(self::$_hasRelationStmts[$relationTable]) || $this->_database != self::$_defaultDatabase) {
				if($object instanceof $this) {
					$relationField = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];
					$stmt = $this->_database->prepare("SELECT 1 FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else
					$stmt = $this->_database->prepare("SELECT 1 FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable] . "` = :id AND `" . $object::$relations[$relationTable] . "` = :fid");

				if($this->_database == self::$_defaultDatabase)
					self::$_hasRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$_hasRelationStmts[$relationTable];

			$stmt->execute([":id" => $this->id, ":fid" => $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function hasOneWayRelation($relationTable, self $object) {
			if($object->getDatabase() != $this->_database)
				throw new DifferentDatabasesException();

			if(!isset(self::$_hasOneWayRelationStmts[$relationTable]) || $this->_database != self::$_defaultDatabase) {
				$stmt = $this->_database->prepare("SELECT 1 FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable][0] . "` = ? AND `" . self::$relations[$relationTable][1] . "` = ?");

				if($this->_database == self::$_defaultDatabase)
					self::$_hasOneWayRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$_hasOneWayRelationStmts[$relationTable];

			$stmt->execute([$this->id, $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function deleteAllRelations($relationTable) {
			if(!isset(self::$_deleteAllRelationsStmts[$relationTable]) || $this->_database != self::$_defaultDatabase) {
				$stmt = is_array(self::$relations[$relationTable])
					? $this->_database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable][0] . "` = :id OR `" . self::$relations[$relationTable][1] . "` = :id")
					: $this->_database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable] . "` = :id");

				if($this->_database == self::$_defaultDatabase)
					self::$_deleteAllRelationsStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$_deleteAllRelationsStmts[$relationTable];

			$stmt->execute([":id" => $this->id]);
		}

		/**
		 * @param $relationTable
		 * @param Model $object
		 * @param $additionalFields
		 * @return $this[]
		 * @throws \RuntimeException
		 */
		public static function getByRelation($relationTable, $object, &$additionalFields = []) {
			if($self = get_class($object) == __CLASS__) {
				$relationField        = self::$relations[$relationTable][0];
				$foreignRelationField = self::$relations[$relationTable][1];

				$query = "SELECT `" . $relationField . "`, `" . $foreignRelationField . "`";

				foreach($additionalFields as $name) {
					$query .= ", `$name`";
				}

				$stmt = $object->getDatabase()->prepare($query . " FROM `{$relationTable}` WHERE `{$foreignRelationField}` = :fid OR `{$relationField}` = :fid");
			} else {
				$relationField        = self::$relations[$relationTable];
				$foreignRelationField = $object::$relations[$relationTable];

				$query = "SELECT `" . self::TABLE . "`.*";

				foreach($additionalFields as $name) {
					$query .= ", `" . $relationTable . "`.`$name` AS `__A" . $name . "`";
				}

				$stmt = $object->getDatabase()->prepare($query . " FROM `{$relationTable}` JOIN `" . self::TABLE . "` ON `" . self::TABLE . "`.`id` = `{$relationTable}`.`{$relationField}` WHERE `{$relationTable}`.`{$foreignRelationField}` = :fid");
			}

			$stmt->execute([":fid" => $object->id]);

			$elements = $aFields = [];

			while($dataset = $stmt->fetchObject()) {
				if($self) {
					$elements[$dataset->$relationField == $object->id ? $dataset->$foreignRelationField : $dataset->$relationField] = self::get($dataset->$relationField == $object->id ? $dataset->$foreignRelationField : $dataset->$relationField);

					foreach($additionalFields as $name) {
						$aFields[$dataset->$relationField == $object->id ? $dataset->$foreignRelationField : $dataset->$relationField][$name] = $dataset->$name;
					}
				} else {
					foreach($additionalFields as $name) {
						$aFields[$dataset->id][$name] = $dataset->{"__A" . $name};
						unset($dataset->{"__A" . $name});
					}

					$elements[$dataset->id] = self::get($dataset->id, $dataset, $object->getDatabase());
				}
			}

			$additionalFields = $aFields;

			return $elements;
		}

		public static function getAllRelations($relationTable, Database $database = null) {
			if(!$database)
				$database = self::$_defaultDatabase;

			$stmt = $database->prepare("SELECT * FROM `{$relationTable}`");
			$stmt->execute();

			$elements = [];

			while($dataset = $stmt->fetchObject()) {
				$element = [];

				foreach($dataset as $name => $value) {
					if($name == self::$relations[$relationTable][0]) {
						$element[0] = self::get($value, null, $database);
						continue;
					}

					if($name == self::$relations[$relationTable][1]) {
						$element[1] = self::get($value, null, $database);
						continue;
					}

					$element[$name] = $value;
				}

				$elements[] = $element;
			}

			return $elements;
		}

		public function getDatabase() {
			return $this->_database;
		}

		private static function initializeSelectQueries() {
			self::$_selectForUpdateStmt = self::$_defaultDatabase->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ? FOR UPDATE");
			self::$_selectStmt = self::$_defaultDatabase->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ?");
		}

		public static function initialize() {
			self::$_caches = new \SplObjectStorage();
			self::$_defaultDatabase = Database::getDefault();

			self::initializeSelectQueries();
		}

		public static function clearCache() {
			self::$_cache = [];
		}

		public static function clearDatabaseCache(Database $database) {
			self::$_caches[$database] = [];

			if($database == self::$_defaultDatabase)
				self::clearCache();
		}

		public static function clearAllCaches() {
			self::clearCache();

			self::$_caches->rewind();

			while(self::$_caches->valid()) {
				self::$_caches->setInfo([]);
				self::$_caches->next();
			}
		}

		public static function activateTransactionalCache() {
			self::$_transactionalCacheActive = true;

			self::$_caches[self::$_defaultDatabase] = self::$_cache;
			self::$_cache = [];
		}

		public static function deactivateTransactionalCache() {
			self::$_transactionalCacheActive = false;

			self::$_cache = self::$_caches[self::$_defaultDatabase];
		}

		public static function switchDatabase(Database $newDatabase) {
			self::$_caches[self::$_defaultDatabase] = self::$_cache;
			self::$_defaultDatabase = $newDatabase;
			self::$_cache = self::$_caches[self::$_defaultDatabase];

			self::initializeSelectQueries();

			self::$_insertStmt = null;
			self::$_updateStmt = null;
			self::$_deleteStmt = null;
			self::$_hasRelationStmts = [];
			self::$_hasOneWayRelationStmts = [];
			self::$_deleteRelationStmts = [];
			self::$_deleteOneWayRelationStmts = [];
			self::$_deleteAllRelationsStmts = [];
		}

		public static function registerDatabase(Database $database) {
			self::$_caches[$database] = [];
		}
	}
