<?php

	/**
	 * Copyright (c) 2014 - 2017 Yussuf Khalil
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

	namespace pp3345\ppFramework\Model;

	use function get_class;
	use pp3345\ppFramework\Database;
	use pp3345\ppFramework\Model;
	use pp3345\ppFramework\SQL\Select;
	use pp3345\ppFramework\SQL\Select\Cache;

	trait SingleTableInheritanceModel {
		use Model {
			__construct as protected __mConstruct;
			lookup as protected mLookup;
		}

		public function __construct($id = null, \stdClass $dataset = null, Database $database = null) {
			if($id === null)
				$this->storeObjectClass();

			$this->__mConstruct($id, $dataset, $database);
		}

		protected function storeObjectClass() {
			$this->class = get_class($this);
		}

		protected static function getClassFromObject(\stdClass $object) {
			return isset($object->class) && $object->class ? $object->class : static::class;
		}

		protected static function lookupFilterClass(Select $select) {
			$select->where("class", static::class);
		}

		/**
		 * @param                $id
		 * @param \stdClass|null $dataset
		 * @param Database|null  $database
		 *
		 * @return $this
		 */
		public static function get($id, \stdClass $dataset = null, Database $database = null) {
			if($database && $database != self::$__defaultDatabase) {
				if(isset(self::$__caches[$database][$id]))
					return self::$__caches[$database][$id];
				else {
					$class = static::getClassFromObject($dataset ?: $dataset = static::fetchFromDatabase($id, $database)->fetchObject());
					return new $class($id, $dataset, $database);
				}
			} else if(self::$__transactionalCacheActive && isset(self::$__caches[self::$__defaultDatabase][$id]))
				return self::$__caches[self::$__defaultDatabase][$id];

			if(isset(self::$__cache[$id]))
				return self::$__cache[$id];

			$class = static::getClassFromObject($dataset ?: $dataset = static::fetchFromDatabase($id, self::$__defaultDatabase)->fetchObject());
			return new $class($id, $dataset);
		}

		public static function lookup(Database $database = null, Cache &$cache = null) {
			$select = static::mLookup($database, $cache);
			static::lookupFilterClass($select);
			return $select;
		}
	}
