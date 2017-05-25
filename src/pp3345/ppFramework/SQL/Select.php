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

	namespace pp3345\ppFramework\SQL;

	use pp3345\ppFramework\Database;
	use pp3345\ppFramework\Exception\InvalidSQLException;
	use pp3345\ppFramework\Exception\MissingForeignKeyDefinitionException;
	use pp3345\ppFramework\Exception\MissingRelationDefinitionException;
	use pp3345\ppFramework\Model;
	use pp3345\ppFramework\ModelRegistry;
	use pp3345\ppFramework\SQL\Select\Cache;
	use pp3345\ppFramework\SQL\Select\Subquery;

	/**
	 * @package pp3345\ppFramework\SQL
	 * @method Select and ($field = "", ...$args)
	 * @method Select or ($field = "", ...$args)
	 */
	class Select {
		const INDEX_HINT_DEFAULT      = "";
		const INDEX_HINT_FOR_JOIN     = "FOR JOIN";
		const INDEX_HINT_FOR_ORDER_BY = "FOR ORDER BY";
		const INDEX_HINT_FOR_GROUP_BY = "FOR GROUP BY";

		const JOIN_TYPE_DEFAULT             = "JOIN";
		const JOIN_TYPE_INNER               = "INNER JOIN";
		const JOIN_TYPE_CROSS               = "CROSS JOIN";
		const JOIN_TYPE_STRAIGHT            = "STRAIGHT_JOIN";
		const JOIN_TYPE_LEFT                = "LEFT JOIN";
		const JOIN_TYPE_RIGHT               = "RIGHT JOIN";
		const JOIN_TYPE_LEFT_OUTER          = "LEFT OUTER JOIN";
		const JOIN_TYPE_RIGHT_OUTER         = "RIGHT OUTER JOIN";
		const JOIN_TYPE_NATURAL             = "NATURAL JOIN";
		const JOIN_TYPE_NATURAL_LEFT        = "NATURAL LEFT JOIN";
		const JOIN_TYPE_NATURAL_RIGHT       = "NATURAL RIGHT JOIN";
		const JOIN_TYPE_NATURAL_LEFT_OUTER  = "NATURAL LEFT OUTER JOIN";
		const JOIN_TYPE_NATURAL_RIGHT_OUTER = "NATURAL RIGHT OUTER JOIN";

		const DIRECTION_NONE       = "";
		const DIRECTION_ASCENDING  = "ASC";
		const DIRECTION_DESCENDING = "DESC";

		const LOCK_NONE          = "";
		const LOCK_FOR_UPDATE    = "FOR UPDATE";
		const LOCK_IN_SHARE_MODE = "LOCK IN SHARE MODE";

		const POSITION_CURRENT  = 0b0;
		const POSITION_FIELDS   = 0b1;
		const POSITION_TABLES   = 0b10;
		const POSITION_JOINS    = 0b100;
		const POSITION_WHERE    = 0b1000;
		const POSITION_GROUP_BY = 0b10000;
		const POSITION_HAVING   = 0b100000;
		const POSITION_ORDER_BY = 0b1000000;

		private $distinct = false;
		private $fields   = "";
		private $from     = "";
		private $joins    = "";
		private $where    = "";
		private $groupBy  = "";
		private $rollup   = false;
		private $having   = "";
		private $orderBy  = "";
		private $limit    = null;
		private $offset   = null;
		private $lockMode = "";

		/**
		 * @var Database
		 */
		private   $database        = null;
		protected $model           = null;
		private   $modelAlias      = "";
		protected $stmt            = null;
		private   $currentPosition = 0;
		protected $parameters      = [];

		public static function cached(Cache &$cache = null) {
			if($cache instanceof Cache && $cache->ready())
				return $cache;
			$cache = new Cache($select = new static);

			return $select;
		}

		public function __construct(Database $database = null) {
			$this->database($database ?: Database::getDefault());
		}

		public function database(Database $database) {
			$this->database = $database;
			if($this->database->selectForUpdate)
				$this->lockMode = self::LOCK_FOR_UPDATE;

			return $this;
		}

		public function distinct($distinct = true) {
			$this->distinct = $distinct;

			return $this;
		}

		public function fields(...$fields) {
			$this->currentPosition = self::POSITION_FIELDS;

			if(count($fields) == 1 && is_array($fields[0]))
				$fields = $fields[0];

			foreach($fields as $field) {
				if($this->fields)
					$this->fields .= ", ";

				$this->fields .= self::parseField($field);
			}

			return $this;
		}

		public function field($field, $alias = "") {
			$this->currentPosition = self::POSITION_FIELDS;

			if($this->fields)
				$this->fields .= ", ";

			$this->fields .= self::parseField($field);

			if($alias)
				$this->fields .= " AS `" . $alias . "`";

			return $this;
		}

		public function countAll($field = "", $alias = "") {
			$this->currentPosition = self::POSITION_FIELDS;
			if($this->fields)
				$this->fields .= ", ";

			$this->fields .= $field ? ("COUNT(" . self::parseField($field) . ")") : "COUNT(*)";

			if($alias)
				$this->fields .= " AS `" . $alias . "`";

			return $this;
		}

		public function countDistinct($field = "", $alias = "") {
			$this->currentPosition = self::POSITION_FIELDS;
			if($this->fields)
				$this->fields .= ", ";

			$this->fields .= $field ? ("COUNT(DISTINCT " . self::parseField($field) . ")") : "COUNT(DISTINCT *)";

			if($alias)
				$this->fields .= " AS `" . $alias . "`";

			return $this;
		}

		public function from($tableOrModel = "", $alias = "") {
			$this->currentPosition = self::POSITION_TABLES;

			if($this->from)
				$this->from .= ", ";

			if($tableOrModel) {
				if(class_exists($tableOrModel) && ModelRegistry::isModelClass($tableOrModel)) {
					$this->model      = $tableOrModel;
					$this->modelAlias = $alias ?: $tableOrModel::TABLE;

					$this->from .= "`" . $tableOrModel::TABLE . "`";
				} else {
					$this->from .= "`{$tableOrModel}`";
				}

				if($alias)
					$this->from .= " AS `{$alias}`";
			}

			return $this;
		}

		public function useIndex($name, $purpose = self::INDEX_HINT_DEFAULT) {
			$this->currentPosition = self::POSITION_TABLES;
			$this->from            .= " USE INDEX ";
			if($purpose)
				$this->from .= $purpose . " ";
			$this->from .= "(`" . $name . "`)";

			return $this;
		}

		public function ignoreIndex($name, $purpose = self::INDEX_HINT_DEFAULT) {
			$this->currentPosition = self::POSITION_TABLES;
			$this->from            .= " IGNORE INDEX ";
			if($purpose)
				$this->from .= $purpose . " ";
			$this->from .= "(`" . $name . "`)";

			return $this;
		}

		public function forceIndex($name, $purpose = self::INDEX_HINT_DEFAULT) {
			$this->currentPosition = self::POSITION_TABLES;
			$this->from            .= " FORCE INDEX ";
			if($purpose)
				$this->from .= $purpose . " ";
			$this->from .= "(`" . $name . "`)";

			return $this;
		}

		public function join($tableOrModel = "", $type = self::JOIN_TYPE_DEFAULT, $autoON = true) {
			$this->currentPosition = self::POSITION_JOINS;

			if($this->joins)
				$this->joins .= " ";

			$this->joins .= $type . " ";

			if($tableOrModel) {
				if(class_exists($tableOrModel) && ModelRegistry::isModelClass($tableOrModel)) {
					$this->joins .= "`" . $tableOrModel::TABLE . "`";

					if($autoON && $this->model && strpos($type, "NATURAL") === false) {
						$model  = $this->model;
						$column = array_search($tableOrModel, $model::getForeignKeys());

						if(!$column)
							throw new MissingForeignKeyDefinitionException($tableOrModel, $model);

						$this->joins .= " ON `" . $tableOrModel::TABLE . "`.`id` = `" . $model::TABLE . "`.`$column`";
					}
				} else
					$this->joins .= "`{$tableOrModel}`";
			}

			return $this;
		}

		public function on($field = "", ...$args) {
			$this->currentPosition = self::POSITION_JOINS;
			$this->joins           .= " ON " . $this->parseCondition($field, $args);

			return $this;
		}

		public function using(...$columns) {
			$this->currentPosition = self::POSITION_JOINS;
			$this->joins           .= " USING (";

			foreach($columns as $column) {
				if(isset($first))
					$this->joins .= ", ";
				else
					$first = true;

				$this->joins .= "`" . $column . "`";
			}

			$this->joins .= ")";

			return $this;
		}

		public function where($field = "", ...$args) {
			$this->currentPosition = self::POSITION_WHERE;

			if($this->where)
				return $this->addAnd($field, ...$args);

			$this->where = " WHERE " . $this->parseCondition($field, $args);

			return $this;
		}

		public function clause($field = "", ...$args) {
			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= "(" . $this->parseCondition($field, $args);
					break;
				case self::POSITION_WHERE:
				default:
					if(!$this->where)
						$this->where();

					$this->where .= "(" . $this->parseCondition($field, $args);
					break;
				case self::POSITION_HAVING:
					$this->having .= "(" . $this->parseCondition($field, $args);
					break;
			}

			return $this;
		}

		public function endClause() {
			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= ")";
					break;
				case self::POSITION_WHERE:
					$this->where .= ")";
					break;
				case self::POSITION_HAVING:
					$this->having .= ")";
					break;
				default:
					throw new InvalidSQLException("endClause() not allowed here");
			}

			return $this;
		}

		public function addAnd($field = "", ...$args) {
			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= " AND " . $this->parseCondition($field, $args);
					break;
				case self::POSITION_WHERE:
				default:
					if(!$this->where)
						throw new InvalidSQLException("AND not allowed here");

					$this->where .= " AND " . $this->parseCondition($field, $args);
					break;
				case self::POSITION_HAVING:
					$this->having .= " AND " . $this->parseCondition($field, $args);
					break;
			}

			return $this;
		}

		public function addOr($field = "", ...$args) {
			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= " OR " . $this->parseCondition($field, $args);
					break;
				case self::POSITION_WHERE:
				default:
					if(!$this->where)
						throw new InvalidSQLException("OR not allowed here");

					$this->where .= " OR " . $this->parseCondition($field, $args);
					break;
				case self::POSITION_HAVING:
					$this->having .= " OR " . $this->parseCondition($field, $args);
					break;
			}

			return $this;
		}

		public function not() {
			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= "NOT ";
					break;
				case self::POSITION_WHERE:
				default:
					if(!$this->where)
						$this->where();

					$this->where .= "NOT ";
					break;
				case self::POSITION_HAVING:
					$this->having .= "NOT ";
					break;
			}

			return $this;
		}

		public function in($field, ...$values) {
			if(!$values) {
				switch($this->currentPosition) {
					case self::POSITION_JOINS:
						$this->joins .= "0";
						break;
					case self::POSITION_WHERE:
					default:
						if(!$this->where)
							$this->where();

						$this->where .= "0";
						break;
					case self::POSITION_HAVING:
						$this->having .= "0";
						break;
				}

				return $this;
			}

			if(count($values) == 1 && is_array($values[0]))
				$values = $values[0];

			$expression = "(" . self::parseField($field) . " IN(" . implode(",", array_fill(0, count($values), "?")) . "))";
			array_push($this->parameters, ...$values);

			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= $expression;
					break;
				case self::POSITION_WHERE:
				default:
					if(!$this->where)
						$this->where();

					$this->where .= $expression;
					break;
				case self::POSITION_HAVING:
					$this->having .= $expression;
					break;
			}

			return $this;
		}

		public function between($field, $start, $end) {
			$expression         = "(" . self::parseField($field) . " BETWEEN ? AND ?)";
			$this->parameters[] = $start;
			$this->parameters[] = $end;

			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= $expression;
					break;
				case self::POSITION_WHERE:
				default:
					if(!$this->where)
						$this->where();

					$this->where .= $expression;
					break;
				case self::POSITION_HAVING:
					$this->having .= $expression;
					break;
			}

			return $this;
		}

		public function hasRelation($relationTable, $object = null) {
			$model = $this->model;

			$modelRelations = $model::getRelations();
			if(!isset($modelRelations[$relationTable]))
				throw new MissingRelationDefinitionException($relationTable, $model);

			if(!$object) {
				if(is_array($modelRelations[$relationTable]))
					$this->exists()->from($relationTable)->where($modelRelations[$relationTable][0], self::conditionField($this->modelAlias . ".id"))->addOr($modelRelations[$relationTable][1], self::conditionField($this->modelAlias . ".id"))->back();
				else
					$this->exists()->from($relationTable)->where($modelRelations[$relationTable], self::conditionField($this->modelAlias . ".id"))->back();
			} else {
				if(is_array($modelRelations[$relationTable]))
					$this->exists()->from($relationTable)->where()
					     ->clause($modelRelations[$relationTable][0], self::conditionField($this->modelAlias . ".id"))->addAnd($modelRelations[$relationTable][1], $object)->endClause()
					     ->addOr()
					     ->clause($modelRelations[$relationTable][1], self::conditionField($this->modelAlias . ".id"))->addAnd($modelRelations[$relationTable][0], $object)->endClause()
					     ->back();
				else {
					$objectRelations = $object::getRelations();

					if(!isset($objectRelations[$relationTable]))
						throw new MissingRelationDefinitionException($relationTable, get_class($object));

					$this->exists()->from($relationTable)->where($modelRelations[$relationTable], self::conditionField($this->modelAlias . ".id"))->addAnd($objectRelations[$relationTable], $object)->back();
				}
			}

			return $this;
		}

		public function exists() {
			switch($this->currentPosition) {
				case self::POSITION_JOINS:
					$this->joins .= "EXISTS ";
					break;
				case self::POSITION_WHERE:
				default:
					if(!$this->where)
						$this->where();

					$this->where .= "EXISTS ";
					break;
				case self::POSITION_HAVING:
					$this->having .= "EXISTS ";
					break;
			}

			return $this->subquery(Subquery::CONTEXT_EXISTS);
		}

		public function groupBy($column = "", $direction = self::DIRECTION_NONE) {
			$this->currentPosition = self::POSITION_GROUP_BY;

			if(!$this->groupBy)
				$this->groupBy = " GROUP BY ";
			else
				$this->groupBy .= ", ";

			if($column) {
				$this->groupBy .= self::parseField($column);
				if($direction)
					$this->groupBy .= " " . $direction;
			}

			return $this;
		}

		public function rollup($rollup = true) {
			$this->rollup = true;

			return $this;
		}

		public function having($field = "", ...$args) {
			$this->currentPosition = self::POSITION_HAVING;

			if($this->having)
				return $this->addAnd($field, ...$args);

			$this->having = " HAVING " . $this->parseCondition($field, $args);

			return $this;
		}

		public function orderBy($column = "", $direction = self::DIRECTION_NONE) {
			$this->currentPosition = self::POSITION_ORDER_BY;

			if(!$this->orderBy)
				$this->orderBy = " ORDER BY ";
			else
				$this->orderBy .= ", ";

			if($column) {
				$this->orderBy .= self::parseField($column);

				if($direction)
					$this->orderBy .= " " . $direction;
			}

			return $this;
		}

		public function limit($amount) {
			$this->limit = $amount;

			return $this;
		}

		public function offset($offset) {
			$this->offset = $offset;

			return $this;
		}

		public function lock($mode = self::LOCK_NONE) {
			$this->lockMode = $mode;

			return $this;
		}

		public function forUpdate($forUpdate = true) {
			$this->lockMode = $forUpdate ? self::LOCK_FOR_UPDATE : self::LOCK_NONE;

			return $this;
		}

		public function raw($raw, $position = self::POSITION_CURRENT) {
			switch($position == self::POSITION_CURRENT ? $this->currentPosition : $position) {
				case self::POSITION_FIELDS:
				default:
					$this->fields .= $raw;
					break;
				case self::POSITION_TABLES:
					$this->from .= $raw;
					break;
				case self::POSITION_JOINS:
					$this->joins .= $raw;
					break;
				case self::POSITION_WHERE:
					$this->where .= $raw;
					break;
				case self::POSITION_GROUP_BY:
					$this->groupBy .= $raw;
					break;
				case self::POSITION_HAVING:
					$this->having .= $raw;
					break;
				case self::POSITION_ORDER_BY:
					$this->orderBy .= $raw;
					break;
			}

			return $this;
		}

		public function subquery($context = Subquery::CONTEXT_DEFUALT) {
			return new Subquery($context, $this);
		}

		public function setSubquery(Select $subquery) {
			if($subquery->parameters)
				array_push($this->parameters, ...$subquery->parameters);
			$this->raw("(" . $subquery->build() . ")");

			return $this;
		}

		public static function conditionField($field) {
			return new RawValue(self::parseField($field));
		}

		public static function conditionSubquery() {
			return new Subquery(Subquery::CONTEXT_CONDITION);
		}

		private static function parseField($field) {
			if(strpos($field, ".")) {
				$field = explode(".", $field, 2);

				return $field[1] == "*" ? "`" . $field[0] . "`.*" : "`" . $field[0] . "`.`" . $field[1] . "`";
			} else
				return "`" . $field . "`";
		}

		private function parseCondition($field = "", $args) {
			if(!$field)
				return "";

			if($field instanceof RawValue) {
				$condition = $field . " ";

				if($field->parameters)
					array_push($this->parameters, ...$field->parameters);
			} else
				$condition = self::parseField($field) . " ";

			switch(count($args)) {
				case 0:
					$condition .= "= ?";

					break;
				case 1:
					if($args[0] instanceof RawValue) {
						$condition .= "= " . $args[0];

						if($args[0]->parameters)
							array_push($this->parameters, ...$args[0]->parameters);
					} else if($args[0] === null)
						$condition .= "IS NULL";
					else {
						$condition          .= "= ?";
						$this->parameters[] = is_object($args[0]) ? $args[0]->id : $args[0];
					}

					break;
				case 2:
					$condition .= $args[0] . " ";

					if($args[1] instanceof RawValue) {
						$condition .= $args[1];

						if($args[1]->parameters)
							array_push($this->parameters, ...$args[1]->parameters);
					} else if($args[1] === null)
						$condition .= "NULL";
					else {
						$condition          .= "?";
						$this->parameters[] = is_object($args[1]) ? $args[1]->id : $args[1];
					}
			}

			return $condition;
		}

		public function __call($name, $arguments) {
			switch($name) {
				case "and":
					return $this->addAnd(...$arguments);
				case "or":
					return $this->addOr(...$arguments);
				default:
					if(strlen($name) > 1) {
						$name = strtolower($name);

						switch(substr($name, 0, 2)) {
							case "an":
								if($name[2] == "d") {
									$method = "and";
									$offset = 3;
								}
								break;
							case "or":
								$method = "or";
								$offset = 2;
								break;
							case "ha":
								if(substr($name, 2, 4) == "ving") {
									$method = "having";
									$offset = 6;
								}
								break;
							case "on":
								$method = "on";
								$offset = 2;
								break;
							case "wh":
								if(substr($name, 2, 3) == "ere") {
									$method = "where";
									$offset = 5;
								}
						}

						if(!isset($method)) {
							$method = "where";
							$offset = 0;
						}

						switch(substr($name, -2)) {
							case "is":
								$this->$method(substr($name, $offset, -2), ...$arguments);

								return $this;
							case "ot":
								if(substr($name, -5, 3) == "isn") {
									$this->$method(substr($name, $offset, -5), count($arguments) && $arguments[0] === null ? "IS NOT" : "!=", ...$arguments);

									return $this;
								}

								break;
							case "in":
								$this->$method();

								if(substr($name, -5, 3) == "not") {
									$this->not();
									$this->in(substr($name, $offset, -5), ...$arguments);
								} else
									$this->in(substr($name, $offset, -2), ...$arguments);

								return $this;
							case "en":
								if(substr($name, -7, 5) == "betwe") {
									$this->$method();

									if(substr($name, -10, 3) == "not") {
										$this->not();
										$this->between(substr($name, $offset, -10), ...$arguments);
									} else
										$this->between(substr($name, $offset, -7), ...$arguments);

									return $this;
								}
						}
					}

					throw new \BadMethodCallException("Unknown method " . __CLASS__ . "::" . $name);
			}
		}

		public function build() {
			$query = $this->buildFields();

			if($this->from)
				$query .= " FROM " . $this->from;

			if($this->joins)
				$query .= " " . $this->joins;

			$query .= $this->where;
			$query .= $this->groupBy;

			if($this->rollup)
				$query .= " WITH ROLLUP";

			$query .= $this->having;
			$query .= $this->orderBy;

			if($this->limit !== null) {
				$query .= " LIMIT ?";

				if($this->offset !== null)
					$query .= " OFFSET ?";
			} else if($this->offset !== null)
				$query .= " LIMIT 18446744073709551615 OFFSET ?";

			if($this->lockMode)
				$query .= " " . $this->lockMode;

			return $query;
		}

		protected function buildFields() {
			$query = "SELECT ";

			if($this->distinct)
				$query .= "DISTINCT ";

			if($this->fields)
				$query .= $this->fields;
			else if($this->model)
				$query .= "`" . $this->modelAlias . "`.*";
			else
				$query .= "*";

			return $query;
		}

		public function prepare() {
			return $this->stmt ?: $this->stmt = $this->database->prepare($this->build());
		}

		public function run($parameters = []) {
			$stmt = $this->prepare();

			if($parameters) {
				foreach($parameters as &$parameter) {
					if(is_object($parameter) && isset($parameter->id))
						$parameter = $parameter->id;
				}
			} else
				$parameters = $this->parameters;

			if($this->limit !== null)
				$parameters[] = $this->limit;

			if($this->offset !== null)
				$parameters[] = $this->offset;

			$stmt->execute($parameters);

			return $stmt;
		}

		public function execute($parameters = []) {
			$stmt = $this->run($parameters);

			if($model = $this->model) {
				$retval = [];

				while($result = $stmt->fetchObject()) {
					if(!isset($result->id))
						throw new InvalidSQLException("Model queries must include id field");

					$retval[$result->id] = $model::get($result->id, $result, $this->database);
				}

				return $retval;
			} else
				return $stmt->fetchAll();
		}

		public function generate($parameters = [], $batchSize = 100) {
			$originalLimit  = $this->limit;
			$originalOffset = $this->offset;
			$offset         = $this->offset ?: 0;
			$processed      = 0;

			$this->limit($batchSize);

			try {
                do {
                    if ($originalLimit && $originalLimit - $processed < $batchSize)
                        $this->limit($batchSize = $originalLimit - $processed);

                    $this->offset($offset);
                    $stmt = $this->run($parameters);

                    $rows = 0;

                    if ($model = $this->model) {
                        while ($result = $stmt->fetchObject()) {
                            if (!isset($result->id))
                                throw new InvalidSQLException("Model queries must include id field");

                            yield $model::get($result->id, $result, $this->database);
                            $rows++;
                        }
                    } else {
                        while ($result = $stmt->fetch()) {
                            yield $result;
                            $rows++;
                        }
                    }

                    $offset += $batchSize;
                    $processed += $batchSize;
                } while ($rows >= $batchSize && (!$originalLimit || $originalLimit - $processed > 0));
            } finally {
			    $this->limit($originalLimit);
			    $this->offset($originalOffset);
            }
		}

		public function unique($parameters = []) {
			$this->limit(1);
			$stmt = $this->run($parameters);

			if($model = $this->model) {
				if(!($result = $stmt->fetchObject()))
					return null;

				if(!isset($result->id))
					throw new InvalidSQLException("Model queries must include id field");

				return $model::get($result->id, $result, $this->database);
			} else
				return $stmt->fetch();
		}
	}
