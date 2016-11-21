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

	namespace pp3345\ppFramework\SQL\Select;

	use pp3345\ppFramework\Database;
	use pp3345\ppFramework\SQL\RawValue;
	use pp3345\ppFramework\SQL\Select;

	class Cache extends Select {
		private $select = null;

		/** @noinspection PhpMissingParentConstructorInspection */
		/**
		 * @param Select $select
		 */
		public function __construct(Select $select) {
			$this->select = $select;
		}

		public function addAnd($field = "", ...$args) {
			$this->parseCondition($field, $args);

			return $this;
		}

		public function addOr($field = "", ...$args) {
			$this->parseCondition($field, $args);

			return $this;
		}

		public function between($field, $start, $end) {
			$this->parameters[] = $start;
			$this->parameters[] = $end;

			return $this;
		}

		public function clause($field = "", ...$args) {
			$this->parseCondition($field, $args);

			return $this;
		}

		public function countAll($field = "", $alias = "") {
			return $this;
		}

		public function countDistinct($field = "", $alias = "") {
			return $this;
		}

		public function database(Database $database) {
			return $this;
		}

		public function distinct($distinct = true) {
			return $this;
		}

		public function endClause() {
			return $this;
		}

		public function exists() {
			return $this->subquery(Subquery::CONTEXT_EXISTS);
		}

		public function field($field, $alias = "") {
			return $this;
		}

		public function fields(...$fields) {
			return $this;
		}

		public function forceIndex($name, $purpose = self::INDEX_HINT_DEFAULT) {
			return $this;
		}

		public function forUpdate($forUpdate = true) {
			return $this;
		}

		public function from($tableOrModel = "", $alias = "") {
			return $this;
		}

		public function groupBy($column = "", $direction = self::DIRECTION_NONE) {
			return $this;
		}

		public function having($field = "", ...$args) {
			$this->parseCondition($field, $args);

			return $this;
		}

		public function ignoreIndex($name, $purpose = self::INDEX_HINT_DEFAULT) {
			return $this;
		}

		public function in($field, ...$values) {
			if($values) {
				if(count($values) == 1 && is_array($values[0]))
					$values = $values[0];

				array_push($this->parameters, ...$values);
			}

			return $this;
		}

		public function join($tableOrModel = "", $type = self::JOIN_TYPE_DEFAULT, $autoON = true) {
			return $this;
		}

		public function lock($mode = self::LOCK_NONE) {
			return $this;
		}

		public function not() {
			return $this;
		}

		public function on($field = "", ...$args) {
			$this->parseCondition($field, $args);

			return $this;
		}

		public function orderBy($column = "", $direction = self::DIRECTION_NONE) {
			return $this;
		}

		public function raw($raw, $position = self::POSITION_CURRENT) {
			return $this;
		}

		public function rollup($rollup = true) {
			return $this;
		}

		public function setSubquery(Select $subquery) {
			if($subquery->parameters)
				array_push($this->parameters, ...$subquery->parameters);

			return $this;
		}

		public function subquery($context = Subquery::CONTEXT_DEFUALT) {
			return new Select\Cache\Subquery($context, $this);
		}

		public function useIndex($name, $purpose = self::INDEX_HINT_DEFAULT) {
			return $this;
		}

		public function using(...$columns) {
			return $this;
		}

		public function where($field = "", ...$args) {
			$this->parseCondition($field, $args);

			return $this;
		}

		private function parseCondition($field = "", $args) {
			if(count($args) == 1) {
				if($args[0] instanceof RawValue) {
					if($args[0]->parameters)
						array_push($this->parameters, ...$args[0]->parameters);
				} else if($args[0] !== null)
					$this->parameters[] = is_object($args[0]) ? $args[0]->id : $args[0];
			} else if(count($args) == 2) {
				if($args[1] instanceof RawValue) {
					if($args[1]->parameters)
						array_push($this->parameters, ...$args[1]->parameters);
				} else if($args[1] !== null)
					$this->parameters[] = is_object($args[1]) ? $args[1]->id : $args[1];
			}
		}

		public function prepare() {
			if(!$this->select->stmt)
				throw new \BadMethodCallException("Can't prepare cached query");

			return $this->select->stmt;
		}

		public function build() {
			throw new \BadMethodCallException("Can't build cached query");
		}

		public function ready() {
			$this->parameters = [];
			$this->model = $this->select->model;

			return (bool) $this->select->stmt;
		}
	}
