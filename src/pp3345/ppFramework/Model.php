<?php

	/**
	 * Copyright (c) 2014 - 2016 Yussuf Khalil
	 *
	 * This file is part of ppFramework.
	 *
	 * ppFramework is free software: you can redistribute it and/or modify
	 * it under the terms of the GNU Lesser General Public License as published
	 * by the Free Software Foundation, either version 3 of the License, or
	 * (at your option) any later version.
	 *
	 * ppFramework is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 * GNU Lesser General Public License for more details.
	 *
	 * You should have received a copy of the GNU Lesser General Public License
	 * along with ppFramework.  If not, see <http://www.gnu.org/licenses/>.
	 */

	namespace pp3345\ppFramework;

	use pp3345\ppFramework\Exception\DataNotFoundException;
	use pp3345\ppFramework\Exception\DifferentDatabasesException;
	use pp3345\ppFramework\Exception\InvalidPropertyAccessException;
	use pp3345\ppFramework\SQL\Select;

	// This gives us some more performance since it allows resolving the functions compile-time
	use function \array_fill;
	use function \array_merge;
	use function \count;
	use function \get_class;
	use function \gettype;
	use function \implode;
	use function \is_array;
	use function \is_object;
	use function \strlen;

	trait Model {
		use TransparentPropertyAccessors {
			__get as __getTransparent;
			__set as __setTransparent;
		}

		/**
		 * @var \SplObjectStorage
		 */
		private static $__caches                   = null;
		private static $__cache                    = [];
		private static $__transactionalCacheActive = false;

		/**
		 * @var Database
		 */
		private static $__defaultDatabase = null;
		/**
		 * @var $__selectForUpdateStmt \PDOStatement
		 */
		private static $__selectForUpdateStmt = null;
		/**
		 * @var $__selectStmt \PDOStatement
		 */
		private static $__selectStmt = null;
		/**
		 * @var $__insertStmt \PDOStatement
		 */
		private static $__insertStmt = null;
		/**
		 * @var $_insertStmt \PDOStatement
		 */
		private static $__updateStmt = null;
		/**
		 * @var $__deleteStmt \PDOStatement
		 */
		private static $__deleteStmt = null;
		/**
		 * @var $__deleteRelationStmts \PDOStatement[]
		 */
		private static $__deleteRelationStmts = [];
		/**
		 * @var $__deleteOneWayRelationStmts \PDOStatement[]
		 */
		private static $__deleteOneWayRelationStmts = [];
		/**
		 * @var $__hasRelationStmts \PDOStatement[]
		 */
		private static $__hasRelationStmts = [];
		/**
		 * @var $__hasOneWayRelationStmts \PDOStatement[]
		 */
		private static $__hasOneWayRelationStmts = [];
		/**
		 * @var $__deleteAllRelationsStmts \PDOStatement[]
		 */
		private static $__deleteAllRelationsStmts = [];

		private static $__foreignKeys = [];
		private static $__relations   = [];

		/**
		 * @var Database
		 */
		private $__database         = null;
		private $__foreignKeyValues = [];
		public  $id                 = 0;

		public function __construct($id = null, \stdClass $dataset = null, Database $database = null) {
			$this->__database = $database ?: self::$__defaultDatabase;

			if($id !== null) {
				if($dataset) {
					foreach($dataset as $name => $value)
						$this->$name = $value;
				} else {
					if($this->__database->selectForUpdate)
						$stmt = $this->__database == self::$__defaultDatabase ? self::$__selectForUpdateStmt : $database->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ? FOR UPDATE");
					else
						$stmt = $this->__database == self::$__defaultDatabase ? self::$__selectStmt : $database->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ?");

					if(!$stmt->execute([$id]) || !$stmt->rowCount())
						throw new DataNotFoundException(__CLASS__, $id);

					foreach($stmt->fetch(\PDO::FETCH_ASSOC) as $name => $value) {
						if(isset(self::$__foreignKeys[$name]))
							$this->__foreignKeyValues[$name] = $value;
						else
							$this->$name = $value;
					}
				}

				if($this->__database == self::$__defaultDatabase)
					self::$__cache[$this->id] = $this;
				else {
					// We can't modify the array inside the ObjectStorage directly
					$cache                     = self::$__caches[$database];
					$cache[$this->id]          = $this;
					self::$__caches[$database] = $cache;
				}
			}
		}

		public function __get($name) {
			if(isset(self::$__foreignKeys[$name])) {
				if(isset($this->__foreignKeyValues[$name])) {
					if(is_object($this->__foreignKeyValues[$name]))
						return $this->__foreignKeyValues[$name];

					$class = self::$__foreignKeys[$name];

					return $this->__foreignKeyValues[$name] = $class::get($this->__foreignKeyValues[$name], null, $this->__database);
				} else
					return null;
			}

			return $this->__getTransparent($name);
		}

		public function __set($name, $value) {
			try {
				$this->__setTransparent($name, $value);
			} catch(InvalidPropertyAccessException $e) {
				if(isset(self::$__foreignKeys[$name])) {
					if($value !== null && !($value instanceof self::$__foreignKeys[$name]))
						throw new \UnexpectedValueException("Value must be instance of " . self::$__foreignKeys[$name] . " or null, " . gettype($value) . " given");

					$this->__foreignKeyValues[$name] = $value;

					return;
				}

				throw $e;
			}
		}

		public function __debugInfo() {
			static $recursion = [];

			$debugInfoRecursionHelper        = ModelRegistry::getInstance()->getDebugInfoRecursionHelper();
			$debugInfoRecursionHelper[$this] = true;

			$retval = [];

			foreach($this as $name => $value) {
				if($name == "__foreignKeyValues")
					continue;

				$retval[$name] = $value;
			}

			foreach(self::$__foreignKeys as $name => $class) {
				if(PHP_MAJOR_VERSION == 5) {
					if(($value = $this->__get($name)) !== null)
						$retval[$name] = isset($debugInfoRecursionHelper[$value]) ? "*RECURSION*" : $value->__debugInfo();
					else
						$retval[$name] = null;
				} else
					$retval[$name] = $this->__get($name);
			}

			unset($debugInfoRecursionHelper[$this]);

			return $retval;
		}

		public function __sleep() {
			return ["id", "_database"];
		}

		public function __wakeup() {
			self::__construct($this->id, null, Database::getByDSN($this->__database->getDSN()));
		}

		public function __isset($name) {
			return isset($this->$name) || (isset($this->__foreignKeyValues[$name]));
		}

		public function save() {
			if(!self::$__insertStmt || $this->__database != self::$__defaultDatabase) {
				$query      = "INSERT INTO `" . self::TABLE . "` SET ";
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$query .= "`{$field}` = ?,";
					$parameters[] = is_object($value) ? $value->id : $value; // Assume objects are models
				}

				foreach(self::$__foreignKeys as $key => $class) {
					$query .= "`{$key}` = ?,";
					$parameters[] = isset($this->__foreignKeyValues[$key]) ? $this->__foreignKeyValues[$key]->id : null;
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = "";

				$stmt = $this->__database->prepare($query);
				$stmt->execute($parameters);

				if($this->__database == self::$__defaultDatabase)
					self::$__insertStmt = $stmt;
			} else {
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$parameters[] = is_object($value) ? $value->id : $value;
				}

				foreach(self::$__foreignKeys as $key => $class)
					$parameters[] = isset($this->__foreignKeyValues[$key]) ? $this->__foreignKeyValues[$key]->id : null;

				self::$__insertStmt->execute($parameters);
			}

			// ID might have been set explicitly (no AUTO_INCREMENT)
			if(!$this->id)
				$this->id = $this->__database->lastInsertId();

			if($this->__database == self::$__defaultDatabase)
				self::$__cache[$this->id] = $this;
			else {
				$cache                             = self::$__caches[$this->__database];
				$cache[$this->id]                  = $this;
				self::$__caches[$this->__database] = $cache;
			}
		}

		public function update() {
			if(!$this->id) {
				$this->save();

				return;
			}

			if(!self::$__updateStmt || $this->__database != self::$__defaultDatabase) {
				// Build UPDATE query
				$query      = "UPDATE `" . self::TABLE . "` SET ";
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$query .= "`{$field}` = ?,";
					$parameters[] = is_object($value) ? $value->id : $value;
				}

				foreach(self::$__foreignKeys as $key => $class) {
					$query .= "`{$key}` = ?,";
					$parameters[] = isset($this->__foreignKeyValues[$key]) ? (is_object($this->__foreignKeyValues[$key]) ? $this->__foreignKeyValues[$key]->id : $this->__foreignKeyValues[$key]) : null;
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = " ";

				$query .= "WHERE `id` = ?";
				$parameters[] = $this->id;

				$stmt = $this->__database->prepare($query);
				$stmt->execute($parameters);

				if($this->__database == self::$__defaultDatabase)
					self::$__updateStmt = $stmt;
			} else {
				$parameters = [];

				foreach($this as $field => $value) {
					if($field[0] == "_")
						continue;

					$parameters[] = is_object($value) ? $value->id : $value;
				}

				foreach(self::$__foreignKeys as $key => $class)
					$parameters[] = isset($this->__foreignKeyValues[$key]) ? (is_object($this->__foreignKeyValues[$key]) ? $this->__foreignKeyValues[$key]->id : $this->__foreignKeyValues[$key]) : null;

				$parameters[] = $this->id;
				self::$__updateStmt->execute($parameters);
			}
		}

		public function delete() {
			if(!self::$__deleteStmt || $this->__database != self::$__defaultDatabase) {
				$stmt = $this->__database->prepare("DELETE FROM `" . self::TABLE . "` WHERE `id` = ?");
				$stmt->execute([$this->id]);

				if($this->__database == self::$__defaultDatabase)
					self::$__deleteStmt = $stmt;
			} else
				self::$__deleteStmt->execute([$this->id]);

			if(self::$__defaultDatabase == $this->__database)
				unset(self::$__cache[$this->id]);
			else {
				$cache = self::$__caches[$this->__database];
				unset($cache[$this->id]);
				self::$__caches[$this->__database] = $cache;
			}
		}

		public function lock() {
			try {
				if(!$this->__database->selectForUpdate) {
					$resetSelectForUpdate              = true;
					$this->__database->selectForUpdate = true;
				}

				$this->__construct($this->id, null, $this->__database);
			} finally {
				if(isset($resetSelectForUpdate))
					$this->__database->selectForUpdate = false;
			}
		}

		public function refetch() {
			$this->__construct($this->id, null, $this->__database);
		}

		public function loadForeignKeys() {
			foreach(self::$__foreignKeys as $property => $class)
				$this->__get($property);
		}

		/**
		 * @param           $id
		 * @param \stdClass $dataset
		 * @param Database  $database
		 *
		 * @return $this
		 */
		public static function get($id, \stdClass $dataset = null, Database $database = null) {
			if($database && $database != self::$__defaultDatabase)
				return isset(self::$__caches[$database][$id]) ? self::$__caches[$database][$id] : new static($id, $dataset, $database);
			else if(self::$__transactionalCacheActive && isset(self::$__caches[self::$__defaultDatabase][$id]))
				return self::$__caches[self::$__defaultDatabase][$id];

			return isset(self::$__cache[$id]) ? self::$__cache[$id] : new static($id, $dataset);
		}

		public static function getBulk(array $ids, Database $database = null) {
			static $stmts = [];

			if(!$ids)
				return [];

			if(!$database)
				$database = self::$__defaultDatabase;

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
				$database = self::$__defaultDatabase;

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
		 *
		 * @return Select
		 */
		public static function lookup(Database $database = null) {
			return (new Select())->model(static::class)->database($database ?: self::$__defaultDatabase);
		}

		public static function getForeignKeys() {
			return self::$__foreignKeys;
		}

		public static function getRelations() {
			return self::$__relations;
		}

		public function addRelation($relationTable, $object, $fields = []) {
			$parameters = [$this->id, $object->id];

			if($object->getDatabase() != $this->__database)
				throw new DifferentDatabasesException();

			if($object instanceof $this) {
				$relationField        = self::$__relations[$relationTable][0];
				$foreignRelationField = self::$__relations[$relationTable][1];
			} else {
				$relationField        = self::$__relations[$relationTable];
				$foreignRelationField = $object::getRelations()[$relationTable];
			}

			$query = "INSERT INTO `{$relationTable}` SET `{$relationField}` = ?, `{$foreignRelationField}` = ?";

			foreach($fields as $name => $value) {
				$query .= ", `$name` = ?";
				$parameters[] = $value;
			}

			$stmt = $this->__database->prepare($query);
			$stmt->execute($parameters);
		}

		public function deleteRelation($relationTable, $object) {
			if($object->getDatabase() != $this->__database)
				throw new DifferentDatabasesException();

			if(!isset(self::$__deleteRelationStmts[$relationTable]) || self::$__defaultDatabase != $this->__database) {
				if($object instanceof $this) {
					$relationField        = self::$__relations[$relationTable][0];
					$foreignRelationField = self::$__relations[$relationTable][1];
					$stmt                 = $this->__database->prepare("DELETE FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else
					$stmt = $this->__database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$__relations[$relationTable] . "` = :id AND `" . $object::getRelations()[$relationTable] . "` = :fid");

				if(self::$__defaultDatabase == $this->__database)
					self::$__deleteRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$__deleteRelationStmts[$relationTable];

			$stmt->execute([":id" => $this->id, ":fid" => $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function deleteOneWayRelation($relationTable, self $object) {
			if($object->getDatabase() != $this->__database)
				throw new DifferentDatabasesException();

			if(!isset(self::$__deleteOneWayRelationStmts[$relationTable]) || $this->__database != self::$__defaultDatabase) {
				$stmt = $this->__database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$__relations[$relationTable][0] . "` = ? AND `" . self::$__relations[$relationTable][1] . "` = ?");

				if($this->__database == self::$__defaultDatabase)
					self::$__deleteOneWayRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$__deleteOneWayRelationStmts[$relationTable];

			$stmt->execute([$this->id, $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function hasRelation($relationTable, $object) {
			if($object->getDatabase() != $this->__database)
				throw new DifferentDatabasesException();

			if(!isset(self::$__hasRelationStmts[$relationTable]) || $this->__database != self::$__defaultDatabase) {
				if($object instanceof $this) {
					$relationField        = self::$__relations[$relationTable][0];
					$foreignRelationField = self::$__relations[$relationTable][1];
					$stmt                 = $this->__database->prepare("SELECT 1 FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else
					$stmt = $this->__database->prepare("SELECT 1 FROM `{$relationTable}` WHERE `" . self::$__relations[$relationTable] . "` = :id AND `" . $object::getRelations()[$relationTable] . "` = :fid");

				if($this->__database == self::$__defaultDatabase)
					self::$__hasRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$__hasRelationStmts[$relationTable];

			$stmt->execute([":id" => $this->id, ":fid" => $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function hasOneWayRelation($relationTable, self $object) {
			if($object->getDatabase() != $this->__database)
				throw new DifferentDatabasesException();

			if(!isset(self::$__hasOneWayRelationStmts[$relationTable]) || $this->__database != self::$__defaultDatabase) {
				$stmt = $this->__database->prepare("SELECT 1 FROM `{$relationTable}` WHERE `" . self::$__relations[$relationTable][0] . "` = ? AND `" . self::$__relations[$relationTable][1] . "` = ?");

				if($this->__database == self::$__defaultDatabase)
					self::$__hasOneWayRelationStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$__hasOneWayRelationStmts[$relationTable];

			$stmt->execute([$this->id, $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function deleteAllRelations($relationTable) {
			if(!isset(self::$__deleteAllRelationsStmts[$relationTable]) || $this->__database != self::$__defaultDatabase) {
				$stmt = is_array(self::$__relations[$relationTable])
					? $this->__database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$__relations[$relationTable][0] . "` = :id OR `" . self::$__relations[$relationTable][1] . "` = :id")
					: $this->__database->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$__relations[$relationTable] . "` = :id");

				if($this->__database == self::$__defaultDatabase)
					self::$__deleteAllRelationsStmts[$relationTable] = $stmt;
			} else
				$stmt = self::$__deleteAllRelationsStmts[$relationTable];

			$stmt->execute([":id" => $this->id]);
		}

		/**
		 * @param       $relationTable
		 * @param Model $object
		 * @param       $additionalFields
		 *
		 * @return $this[]
		 * @throws \RuntimeException
		 */
		public static function getByRelation($relationTable, $object, &$additionalFields = []) {
			if($self = get_class($object) == __CLASS__) {
				$relationField        = self::$__relations[$relationTable][0];
				$foreignRelationField = self::$__relations[$relationTable][1];

				$query = "SELECT `" . $relationField . "`, `" . $foreignRelationField . "`";

				foreach($additionalFields as $name) {
					$query .= ", `$name`";
				}

				$stmt = $object->getDatabase()->prepare($query . " FROM `{$relationTable}` WHERE `{$foreignRelationField}` = :fid OR `{$relationField}` = :fid");
			} else {
				$relationField        = self::$__relations[$relationTable];
				$foreignRelationField = $object::getRelations()[$relationTable];

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
				$database = self::$__defaultDatabase;

			$stmt = $database->prepare("SELECT * FROM `{$relationTable}`");
			$stmt->execute();

			$elements = [];

			while($dataset = $stmt->fetchObject()) {
				$element = [];

				foreach($dataset as $name => $value) {
					if($name == self::$__relations[$relationTable][0]) {
						$element[0] = self::get($value, null, $database);
						continue;
					}

					if($name == self::$__relations[$relationTable][1]) {
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
			return $this->__database;
		}

		private static function __initializeSelectQueries() {
			self::$__selectForUpdateStmt = self::$__defaultDatabase->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ? FOR UPDATE");
			self::$__selectStmt          = self::$__defaultDatabase->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ?");
		}

		public static function initialize() {
			self::$__caches          = new \SplObjectStorage();
			self::$__defaultDatabase = Database::getDefault();

			self::__initializeSelectQueries();

			if(defined("self::FOREIGN_KEYS"))
				self::$__foreignKeys = self::FOREIGN_KEYS;

			if(defined("self::RELATIONS"))
				self::$__relations = self::RELATIONS;
		}

		public static function clearCache() {
			self::$__cache = [];
		}

		public static function clearDatabaseCache(Database $database) {
			self::$__caches[$database] = [];

			if($database == self::$__defaultDatabase)
				self::clearCache();
		}

		public static function clearAllCaches() {
			self::clearCache();

			self::$__caches->rewind();

			while(self::$__caches->valid()) {
				self::$__caches->setInfo([]);
				self::$__caches->next();
			}
		}

		public static function activateTransactionalCache() {
			self::$__transactionalCacheActive = true;

			self::$__caches[self::$__defaultDatabase] = self::$__cache;
			self::$__cache                            = [];
		}

		public static function deactivateTransactionalCache() {
			self::$__transactionalCacheActive = false;

			self::$__cache = self::$__caches[self::$__defaultDatabase];
		}

		public static function switchDatabase(Database $newDatabase) {
			self::$__caches[self::$__defaultDatabase] = self::$__cache;
			self::$__defaultDatabase                  = $newDatabase;
			self::$__cache                            = self::$__caches[self::$__defaultDatabase];

			self::__initializeSelectQueries();

			self::$__insertStmt                = null;
			self::$__updateStmt                = null;
			self::$__deleteStmt                = null;
			self::$__hasRelationStmts          = [];
			self::$__hasOneWayRelationStmts    = [];
			self::$__deleteRelationStmts       = [];
			self::$__deleteOneWayRelationStmts = [];
			self::$__deleteAllRelationsStmts   = [];
		}

		public static function registerDatabase(Database $database) {
			self::$__caches[$database] = [];
		}
	}
