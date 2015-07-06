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
	use net\pp3345\ppFramework\SQL\Select;

	trait Model {
		private static $cache = [];
		public $id = 0;

		public function __construct($id = null, \stdClass $dataset = null) {
			if($id !== null) {
				if($dataset) {
					foreach($dataset as $name => $value)
						$this->$name = $value;

					// Add to cache
					if(!Database::getDefault()->selectForUpdate)
						self::$cache[$this->id] = $this;
				} else {
					$database = Database::getDefault();

					if($database->selectForUpdate) {
						// FOR UPDATE should not have negative effects outside of transactions
						static $forUpdateStmt;

						if(!$forUpdateStmt)
							$forUpdateStmt = $database->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ? FOR UPDATE");

						$stmt = $forUpdateStmt;
					} else {
						static $stmt;

						if(!$stmt)
							$stmt = $database->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = ?");
					}

					// Execute query
					if(!$stmt->execute([$id]) || !$stmt->rowCount())
						throw new DataNotFoundException(__CLASS__, $id);

					foreach($stmt->fetch(Database::FETCH_ASSOC) as $name => $value)
						$this->$name = $value;

					if(!$database->selectForUpdate)
						self::$cache[$this->id] = $this; // We do not cache objects fetched with FOR UPDATE to ensure that restartable transactions work correctly
				}
			}
		}

		public function __get($name) {
			if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name])) {
				if($this->$name instanceof self::$foreignKeys[$name])
					return $this->$name;

				$class = self::$foreignKeys[$name];

				return $this->$name ? $this->$name = $class::get($this->$name) : null;
			}

			return $this->$name;
		}

		public function __set($name, $value) {
			if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name]) && !($value instanceof self::$foreignKeys[$name]) && $value !== null)
				throw new \UnexpectedValueException("Value must be instance of " . self::$foreignKeys[$name] . " or null, " . gettype($value) . " given");

			$this->$name = $value;
		}

		public function __debugInfo() {
			static $recursion = [];

			if(isset($recursion[$this->id]))
				return "[" . __CLASS__ . ":" . $this->id . "] *RECURSION*";

			$recursion[$this->id] = true;

			$retval = [];

			foreach($this as $name => $value) {
				$retval[$name] = $this->__get($name);

				if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name]) && $retval[$name] instanceof self::$foreignKeys[$name])
					$retval[$name] = $retval[$name]->__debugInfo();
			}

			unset($recursion[$this->id]);

			return $retval;
		}

		public function __sleep() {
			return ["id"];
		}

		public function __wakeup() {
			self::__construct($this->id);
		}

		public function __isset($name) {
			return isset($this->$name) || (isset(self::$foreignKeys) && isset(self::$foreignKeys[$name]));
		}

		public function save() {
			/**
			 * @var $stmt \PDOStatement
			 */
			static $stmt = null;

			if(!$stmt) {
				// Build INSERT query
				$query = "INSERT INTO `" . self::TABLE . "` SET ";

				if(isset(self::$databaseFields)) {
					$parameters = [];

					foreach(self::$databaseFields as $field) {
						$query .= "`{$field}` = ?,";
						$parameters[] = is_object($this->$field) ? $this->$field->id : $this->$field;
					}
				} else {
					$parameters = [];

					foreach($this as $field => $value) {
						$query .= "`{$field}` = ?,";
						$parameters[] = is_object($value) ? $value->id : $value; // Assume objects are models
					}
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = "";

				// Prepare query
				$stmt = Database::getDefault()->prepare($query);
				$stmt->execute($parameters);
			} else if(isset(self::$databaseFields)) {
				$parameters = [];

				foreach(self::$databaseFields as $field)
					$parameters[] = is_object($this->$field) ? $this->$field->id : $this->$field;

				$stmt->execute($parameters);
			} else {
				$parameters = [];

				foreach($this as $value)
					$parameters[] = is_object($value) ? $value->id : $value;

				$stmt->execute($parameters);
			}

			// ID might have been set explicitly (no AUTO_INCREMENT)
			if(!$this->id)
				$this->id = Database::getDefault()->lastInsertID();

			// Cache object
			self::$cache[$this->id] = $this;
		}

		public function update() {
			/**
			 * @var $stmt \PDOStatement
			 */
			static $stmt = null;

			if(!$this->id) {
				$this->save();

				return;
			}

			if(!$stmt) {
				// Build UPDATE query
				$query = "UPDATE `" . self::TABLE . "` SET ";

				if(isset(self::$databaseFields)) {
					$parameters = [];

					foreach(self::$databaseFields as $field) {
						$query .= "`{$field}` = ?,";
						$parameters[] = is_object($this->$field) ? $this->$field->id : $this->$field;
					}
				} else {
					$parameters = [];

					foreach($this as $field => $value) {
						$query .= "`{$field}` = ?,";
						$parameters[] = is_object($value) ? $value->id : $value;
					}
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = " ";

				// Set query condition
				$query .= "WHERE `id` = ?";
				$parameters[] = $this->id;

				// Prepare query
				$stmt = Database::getDefault()->prepare($query);
				$stmt->execute($parameters);
			} else if(isset(self::$databaseFields)) {
				$parameters = [];

				foreach(self::$databaseFields as $field)
					$parameters[] = is_object($this->$field) ? $this->$field->id : $this->$field;

				$parameters[] = $this->id;
				$stmt->execute($parameters);
			} else {
				$parameters = [];

				foreach($this as $value)
					$parameters[] = is_object($value) ? $value->id : $value;

				$parameters[] = $this->id;
				$stmt->execute($parameters);
			}
		}

		public function delete() {
			static $stmt = null;

			if(!$stmt)
				$stmt = Database::getDefault()->prepare("DELETE FROM `" . self::TABLE . "` WHERE `id` = ?");

			$stmt->execute([$this->id]);

			// Remove from cache
			unset(self::$cache[$this->id]);
		}

		public function refetch() {
			$this->__construct($this->id);
		}

		/**
		 * @param           $id
		 * @param \stdClass $dataset
		 * @return $this
		 */
		public static function get($id, \stdClass $dataset = null) {
			return isset(self::$cache[$id]) ? self::$cache[$id] : new self($id, $dataset);
		}

		public static function getBulk(array $ids) {
			static $stmts = [];

			if(!$ids)
				return [];

			$stmt = isset($stmts[count($ids)])
				? $stmts[count($ids)]
				: ($stmts[count($ids)] = Database::getDefault()->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` IN(" . implode(",", array_fill(0, count($ids), "?")) . ")"));

			$stmt->execute($ids);

			$retval = [];

			while($dataset = $stmt->fetchObject())
				$retval[$dataset->id] = self::get($dataset->id, $dataset);

			return $retval;
		}

		public static function getBulkOrdered(array $ids) {
			static $stmts = [];

			if(!$ids)
				return [];

			// http://stackoverflow.com/questions/1631723/maintaining-order-in-mysql-in-query
			$stmt = isset($stmts[count($ids)])
				? $stmts[count($ids)]
				: ($stmts[count($ids)] = Database::getDefault()->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` IN(" . ($filled = implode(",", array_fill(0, count($ids), "?"))) . ") ORDER BY FIELD(`id`," . $filled . ")"));

			$stmt->execute(array_merge($ids, $ids));

			$retval = [];

			while($dataset = $stmt->fetchObject())
				$retval[$dataset->id] = self::get($dataset->id, $dataset);

			return $retval;
		}

		/**
		 * @return Select
		 */
		public static function lookup() {
			return (new Select())->model(static::class);
		}

		public static function getForeignKeys() {
			return isset(self::$foreignKeys) ? self::$foreignKeys : [];
		}

		public function addRelation($relationTable, $object, $fields = []) {
			static $stmts = [];

			$withFields = $relationTable . ($fields ? crc32(json_encode(array_keys($fields))) : "");
			$parameters = [$this->id, $object->id];

			if(isset($stmts[$withFields])) {
				foreach($fields as $name => $value)
					$parameters[] = $value;

				$stmts[$withFields]->execute($parameters);
			} else {
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

				$stmt                                   = Database::getDefault()->prepare($query);
				$stmts[$withFields] = $stmt;
				$stmt->execute($parameters);

				return;
			}
		}

		public function deleteRelation($relationTable, $object) {
			static $stmts = [];

			if(isset($stmts[$relationTable])) {
				$stmt = $stmts[$relationTable];
			} else {
				if($object instanceof $this) {
					$relationField        = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];
					$stmt                 = Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else
					$stmt                 = Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable] . "` = :id AND `" . $object::$relations[$relationTable] . "` = :fid");

				$stmts[$relationTable] = $stmt;
			}

			$stmt->execute([":id" => $this->id, ":fid" => $object->id]);
		}

		public function deleteOneWayRelation($relationTable, self $object) {
			static $stmts = [];

			if(isset($stmts[$relationTable]))
				$stmt = $stmts[$relationTable];
			else
				$stmts[$relationTable] = $stmt = Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable][0] . "` = ? AND `" . self::$relations[$relationTable][1] . "` = ?");

			$stmt->execute([$this->id, $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function hasRelation($relationTable, $object) {
			static $stmts = [];

			if(isset($stmts[$relationTable])) {
				$stmt = $stmts[$relationTable];
			} else {
				if($object instanceof $this) {
					$relationField        = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];
					$stmt                 = Database::getDefault()->prepare("SELECT 1 FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else
					$stmt                 = Database::getDefault()->prepare("SELECT 1 FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable] . "` = :id AND `" . $object::$relations[$relationTable] . "` = :fid");

				$stmts[$relationTable] = $stmt;
			}

			$stmt->execute([":id" => $this->id, ":fid" => $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function hasOneWayRelation($relationTable, self $object) {
			static $stmts = [];

			if(isset($stmts[$relationTable]))
				$stmt = $stmts[$relationTable];
			else
				$stmts[$relationTable] = $stmt = Database::getDefault()->prepare("SELECT 1 FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable][0] . "` = ? AND `" . self::$relations[$relationTable][1] . "` = ?");

			$stmt->execute([$this->id, $object->id]);

			return (bool) $stmt->rowCount();
		}

		public function deleteAllRelations($relationTable) {
			static $stmts = [];

			if(isset($stmts[$relationTable])) {
				$stmt = $stmts[$relationTable];
			} else {
				$stmt = is_array(self::$relations[$relationTable])
					? Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable][0] . "` = :id OR `" . self::$relations[$relationTable][1] . "` = :id")
					: Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE `" . self::$relations[$relationTable] . "` = :id");

				$stmts[$relationTable] = $stmt;
			}

			$stmt->execute([":id" => $this->id]);
		}

		/**
		 * @param $relationTable
		 * @param $object
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

				$stmt = Database::getDefault()->prepare($query . " FROM `{$relationTable}` WHERE `{$foreignRelationField}` = :fid OR `{$relationField}` = :fid");
			} else {
				$relationField        = self::$relations[$relationTable];
				$foreignRelationField = $object::$relations[$relationTable];

				$query = "SELECT `" . self::TABLE . "`.*";

				foreach($additionalFields as $name) {
					$query .= ", `" . $relationTable . "`.`$name` AS `__A" . $name . "`";
				}

				$stmt = Database::getDefault()->prepare($query . " FROM `{$relationTable}` JOIN `" . self::TABLE . "` ON `" . self::TABLE . "`.`id` = `{$relationTable}`.`{$relationField}` WHERE `{$relationTable}`.`{$foreignRelationField}` = :fid");
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

					$elements[$dataset->id] = self::get($dataset->id, $dataset);
				}
			}

			$additionalFields = $aFields;

			return $elements;
		}

		public static function getAllRelations($relationTable) {
			static $stmts = [];

			if(isset($stmts[$relationTable])) {
				$stmt = $stmts[$relationTable];
			} else {
				$stmt                  = Database::getDefault()->prepare("SELECT * FROM `{$relationTable}`");
				$stmts[$relationTable] = $stmt;
			}

			$stmt->execute();

			$elements = [];

			while($dataset = $stmt->fetchObject()) {
				$element = [];

				foreach($dataset as $name => $value) {
					if($name == self::$relations[$relationTable][0]) {
						$element[0] = self::get($value);
						continue;
					}

					if($name == self::$relations[$relationTable][1]) {
						$element[1] = self::get($value);
						continue;
					}

					$element[$name] = $value;
				}

				$elements[] = $element;
			}

			return $elements;
		}

		public static function clearCache() {
			self::$cache = [];
		}
	}
