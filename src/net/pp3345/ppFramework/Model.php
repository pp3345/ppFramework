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
				// Fetch dataset and copy properties to object
				foreach($dataset ?: $this->fetchFromDatabase($id) as $name => $value)
					$this->$name = $value;

				// Add to cache
				self::$cache[$this->id] = $this;
			}
		}

		public function __get($name) {
			if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name]))
				return $this->fetchForeignKey($name);

			return $this->$name;
		}

		public function __set($name, $value) {
			if(isset(self::$foreignKeys) && isset(self::$foreignKeys[$name]) && !($value instanceof self::$foreignKeys[$name]) && $value !== null)
				throw new \UnexpectedValueException("Value must be instance of " . self::$foreignKeys[$name] . " or null, " . gettype($value) . " given");

			$this->$name = $value;
		}

		/*public function __debugInfo() {
			$retval = [];

			foreach($this as $name => $value)
				$retval[$name] = $this->__get($name);

			return $retval;
		}*/

		public function __sleep() {
			return ["id"];
		}

		public function __wakeup() {
			self::__construct($this->id);
		}

		public function __isset($name) {
			return isset($this->$name) || (isset(self::$foreignKeys) && isset(self::$foreignKeys[$name]));
		}

		/**
		 * @param $key
		 *
		 * @throws \Exception
		 * @return Model|null
		 */
		private function fetchForeignKey($key) {
			if($this->$key instanceof self::$foreignKeys[$key])
				return $this->$key;

			$class = self::$foreignKeys[$key];

			return $this->$key ? $this->$key = $class::get($this->$key) : null;
		}

		private function fetchFromDatabase($id) {
			static $stmt = null;

			// Prepare query
			if(!$stmt)
				$stmt = Database::getDefault()->prepare("SELECT * FROM `" . self::TABLE . "` WHERE `id` = :id");

			$stmt->bindValue(':id', $id);

			// Execute query
			if(!$stmt->execute() || !$stmt->rowCount()) {
				throw new DataNotFoundException(get_class($this), $id);
			}

			// Return dataset as stdClass object
			return $stmt->fetchObject();
		}

		public function save() {
			static $stmt = null;

			if(!$stmt) {
				// Build INSERT query
				$query = "INSERT INTO `" . self::TABLE . "` SET ";

				if(isset(self::$databaseFields)) {
					foreach(self::$databaseFields as $name => $value) {
						$query .= "`{$name}` = :{$name},";
					}
				} else foreach($this as $name => $value) {
					$query .= "`{$name}` = :{$name},";
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = "";

				// Prepare query
				$stmt = Database::getDefault()->prepare($query);
			}

			// Bind parameters
			foreach($this as $name => $value) {
				if(isset(self::$databaseFields) && !isset(self::$databaseFields[$name]))
					continue;

				$stmt->bindValue(":" . $name, is_object($value) ? $value->id : $value);
			}

			// Execute query
			$stmt->execute();

			if(!$this->id)
				$this->id = Database::getDefault()->lastInsertID();

			// Add to cache
			self::$cache[$this->id] = $this;
		}

		public function update() {
			static $stmt = null;

			if(!$this->id) {
				$this->save();

				return;
			}

			if(!$stmt) {
				// Build UPDATE query
				$query = "UPDATE `" . self::TABLE . "` SET ";

				if(isset(self::$databaseFields)) {
					foreach(self::$databaseFields as $name => $value) {
						$query .= "`{$name}` = :{$name},";
					}
				} else foreach($this as $name => $value) {
					$query .= "`{$name}` = :{$name},";
				}

				// Remove trailing comma
				$query[strlen($query) - 1] = " ";

				// Set query condition
				$query .= "WHERE `id` = :id";

				// Prepare query
				$stmt = Database::getDefault()->prepare($query);
			}

			// Bind parameters
			foreach($this as $name => $value) {
				if(isset(self::$databaseFields) && !isset(self::$databaseFields[$name]))
					continue;

				$stmt->bindValue(":" . $name, is_object($value) ? $value->id : $value);
			}

			$stmt->execute();
		}

		public function delete() {
			static $stmt = null;

			if(!$stmt)
				$stmt = Database::getDefault()->prepare("DELETE FROM `" . self::TABLE . "` WHERE `id` = :id");

			$stmt->bindValue(':id', $this->id);
			$stmt->execute();

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

		/**
		 * @return Select
		 */
		public static function lookup() {
			return (new Select())->model(get_called_class());
		}

		public static function getForeignKeys() {
			return isset(self::$foreignKeys) ? self::$foreignKeys : [];
		}

		public function addRelation($relationTable, $object, $fields = []) {
			static $stmts = [];

			$fieldChecksum = $fields ? crc32(json_encode(array_keys($fields))) : "";

			if(isset($stmts[$relationTable . $fieldChecksum])) {
				$stmt = $stmts[$relationTable . $fieldChecksum];
			} else {
				if(get_class($object) == __CLASS__) {
					$relationField        = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];
				} else {
					$relationField        = self::$relations[$relationTable];
					$foreignRelationField = $object::$relations[$relationTable];
				}

				$query = "INSERT INTO `{$relationTable}` SET `{$relationField}` = :id, `{$foreignRelationField}` = :fid";

				foreach($fields as $name => $value) {
					$query .= ", `$name` = :$name";
				}

				$stmt                                   = Database::getDefault()->prepare($query);
				$stmts[$relationTable . $fieldChecksum] = $stmt;
			}

			$stmt->bindValue(':id', $this->id);
			$stmt->bindValue(':fid', $object->id);

			foreach($fields as $name => $value) {
				$stmt->bindValue(':' . $name, $value);
			}

			$stmt->execute();
		}

		public function deleteRelation($relationTable, $object) {
			static $stmts = [];

			if(isset($stmts[$relationTable])) {
				$stmt = $stmts[$relationTable];
			} else {
				if(get_class($object) == __CLASS__) {
					$relationField        = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];
					$stmt                 = Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else {
					$relationField        = self::$relations[$relationTable];
					$foreignRelationField = $object::$relations[$relationTable];
					$stmt                 = Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE `{$relationField}` = :id AND `{$foreignRelationField}` = :fid");
				}

				$stmts[$relationTable] = $stmt;
			}

			$stmt->bindValue(':id', $this->id);
			$stmt->bindValue(':fid', $object->id);

			$stmt->execute();
		}

		public function hasRelation($relationTable, $object) {
			static $stmts = [];

			if(isset($stmts[$relationTable])) {
				$stmt = $stmts[$relationTable];
			} else {
				if(get_class($object) == __CLASS__) {
					$relationField        = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];
					$stmt                 = Database::getDefault()->prepare("SELECT 1 FROM `{$relationTable}` WHERE (`{$relationField}` = :id AND `{$foreignRelationField}` = :fid) OR (`{$relationField}` = :fid AND `{$foreignRelationField}` = :id)");
				} else {
					$relationField        = self::$relations[$relationTable];
					$foreignRelationField = $object::$relations[$relationTable];
					$stmt                 = Database::getDefault()->prepare("SELECT 1 FROM `{$relationTable}` WHERE `{$relationField}` = :id AND `{$foreignRelationField}` = :fid");
				}

				$stmts[$relationTable] = $stmt;
			}

			$stmt->bindValue(':id', $this->id);
			$stmt->bindValue(':fid', $object->id);

			$stmt->execute();

			return (bool) $stmt->rowCount();
		}

		public function deleteAllRelations($relationTable) {
			static $stmts = [];

			if(isset($stmts[$relationTable])) {
				$stmt = $stmts[$relationTable];
			} else {
				if(is_array(self::$relations[$relationTable])) {
					$relationField        = self::$relations[$relationTable][0];
					$foreignRelationField = self::$relations[$relationTable][1];

					$stmt = Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE `{$relationField}` = :id OR `{$foreignRelationField}` = :id");
				} else {
					$relationField = self::$relations[$relationTable];

					$stmt = Database::getDefault()->prepare("DELETE FROM `{$relationTable}` WHERE `{$relationField}` = :id");
				}

				$stmts[$relationTable] = $stmt;
			}

			$stmt->bindValue(':id', $this->id);

			$stmt->execute();
		}

		/**
		 * @param $relationTable
		 * @param $object
		 * @param $additionalFields
		 * @return $this[]
		 * @throws \RuntimeException
		 */
		public static function getByRelation($relationTable, $object, &$additionalFields = []) {
			if(get_class($object) == __CLASS__) {
				$relationField        = self::$relations[$relationTable][0];
				$foreignRelationField = self::$relations[$relationTable][1];

				$query = "SELECT `" . $relationField . "`, `" . $foreignRelationField . "`";

				foreach($additionalFields as $name) {
					$query .= ", `$name`";
				}

				$stmt = Database::getDefault()->prepare($query . " FROM " . $relationTable . " WHERE `" . $foreignRelationField . "` = :fid OR `" . $relationField . "` = :fid");
			} else {
				$relationField        = self::$relations[$relationTable];
				$foreignRelationField = $object::$relations[$relationTable];

				$query = "SELECT `" . self::TABLE . "`.*";

				foreach($additionalFields as $name) {
					$query .= ", `" . $relationTable . "`.`$name` AS `__A" . $name . "`";
				}

				$stmt = Database::getDefault()->prepare($query . " FROM `" . $relationTable . "` JOIN `" . self::TABLE . "` ON `" . self::TABLE . "`.`id` = `" . $relationTable . "`.`" . $relationField ."` WHERE `" . $foreignRelationField . "` = :fid");
			}

			$stmt->bindValue(':fid', $object->id);

			$stmt->execute();

			$elements = $aFields = [];

			while($dataset = $stmt->fetchObject()) {
				if(get_class($object) == __CLASS__) {
					$elements[$dataset->$relationField == $object->id ? $dataset->$foreignRelationField : $dataset->$relationField] = self::get($dataset->$relationField == $object->id ? $dataset->$foreignRelationField : $dataset->$relationField);

					foreach($additionalFields as $name) {
						$aFields[$dataset->$relationField == $object->id ? $dataset->$foreignRelationField : $dataset->$relationField][$name] = $dataset->$name;
					}
				} else {
					foreach($additionalFields as $name) {
						$aFields[$dataset->id][$name] = $dataset->{"__A" . $name};
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
				$stmt                  = Database::getDefault()->prepare("SELECT * FROM " . $relationTable);
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
