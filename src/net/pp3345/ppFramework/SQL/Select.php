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

    namespace net\pp3345\ppFramework\SQL;

    use net\pp3345\ppFramework\Database;
    use net\pp3345\ppFramework\Model;

    class Select {
	    /**
	     * @var Database
	     */
	    private $database = null;
	    private $table = "";

	    /**
	     * @var Model
	     */
	    private $model = null;

	    private $distinct = false;

	    private $fields = [];
	    private $rawFields = [];

	    private $joins = "";
	    private $where = "";
	    private $orderBy = "";

	    private $limit = 0;
	    private $offset = 0;

	    private $parameters = [];

	    /**
	     * @var Select
	     */
	    private $previous = null;

	    private $lastClause = 0;

	    const CLAUSE_JOIN = 0b1;
	    const CLAUSE_WHERE = 0b10;

	    public function __construct(Database $database = null, Select $previous = null) {
		    $this->database = $database ?: Database::getDefault();
		    $this->previous = $previous;
	    }

	    public function database(Database $database) {
		    $this->database = $database;

		    return $this;
	    }

	    public function model($class) {
			if(!array_search(Model::class, class_uses($class)))
				throw new \UnexpectedValueException("Argument class must use " . Model::class);

		    $this->model = $class;

		    return $this;
	    }

	    public function distinct() {
		    $this->distinct = true;

		    return $this;
	    }

	    public function fields(...$fields) {
		    array_push($this->fields, ...$fields);

		    return $this;
	    }

	    public function rawFields(...$fields) {
		    array_push($this->rawFields, ...$fields);

		    return $this;
	    }

	    public function all() {
		    $this->fields = [];

		    return $this;
	    }

	    public function from($table) {
		    if($this->model) {
			    $model = $this->model;

			    if($model::TABLE == $table)
				    return $this;

			    throw new \UnexpectedValueException("Can't set table when model is set");
		    }

		    $this->table = $table;

		    return $this;
	    }

	    public function join($table, $autoON = true) {
		    if(class_exists($table) && array_search(Model::class, class_uses($table))) {
			    $this->joins .= "JOIN `" . $table::TABLE . "` ";

			    if($autoON && $this->model) {
				    $model = $this->model;

				    $property = array_search($table, $model::getForeignKeys());;

				    if(!$property)
					    throw new \LogicException("Missing foreign key definition for class {$table} in class {$model}");

				    $this->joins .= "ON `" . $table::TABLE . "`.`id` = `" . $model::TABLE . "`.`{$property}` ";
			    }
		    } else
		        $this->joins .= "JOIN `{$table}` ";

		    $this->lastClause = self::CLAUSE_JOIN;

		    return $this;
	    }

	    public function on($field, ...$value) {
		    $this->joins .= "ON " . $this->condition($field, $value);

		    return $this;
	    }

	    public function where($field = null, ...$value) {
		    $this->where .= "WHERE " . ($field ? $this->condition($field, $value) : "");
		    $this->lastClause = self::CLAUSE_WHERE;

		    return $this;
	    }

	    public function orderBy($field, $sort = "ASC") {
		    $sort = strtoupper($sort);
		    if($sort != "ASC" && $sort != "DESC")
			    throw new \UnexpectedValueException("Order must be either ASC or DESC");

			if($this->orderBy)
				$this->orderBy .= ", ";

		    $this->orderBy .= "ORDER BY `{$field}` " . $sort;

		    return $this;
	    }

	    public function limit($limit) {
		    $this->limit = $limit;

		    return $this;
	    }

	    public function offset($offset) {
		    $this->offset = $offset;

		    return $this;
	    }

	    public function addAnd($field, ...$value) {
		    switch($this->lastClause) {
			    case self::CLAUSE_JOIN:
				    $this->joins .= "AND " . $this->condition($field, $value);
				    break;
			    case self::CLAUSE_WHERE:
				    $this->where .= "AND " . $this->condition($field, $value);
				    break;
			    default:
				    throw new \LogicException("AND not allowed in current clause");
		    }

		    return $this;
	    }

	    public function addOr($field, ...$value) {
		    switch($this->lastClause) {
			    case self::CLAUSE_JOIN:
				    $this->joins .= "OR " . $this->condition($field, $value);
				    break;
			    case self::CLAUSE_WHERE:
				    $this->where .= "OR " . $this->condition($field, $value);
				    break;
			    default:
				    throw new \LogicException("OR not allowed in current clause");
		    }

		    return $this;
	    }

	    public function exists() {
		    switch($this->lastClause) {
			    case self::CLAUSE_JOIN:
				    $this->joins .= "EXISTS ";
				    break;
			    case self::CLAUSE_WHERE:
				    $this->where .= "EXISTS ";
				    break;
			    default:
				    throw new \LogicException("EXISTS not allowed in current clause");
		    }

		    return $this;
	    }

	    public function raw($raw) {
		    switch($this->lastClause) {
			    case self::CLAUSE_JOIN:
				    $this->joins .= $raw . " ";
				    break;
			    case self::CLAUSE_WHERE:
				    $this->where .= $raw . " ";
				    break;
			    default:
					if(!strncasecmp("ORDER BY", $raw, 8))
						$this->orderBy .= $raw;
					else
						throw new \LogicException("Raw statements outside of WHERE and JOIN clauses are not supported");
		    }

		    return $this;
	    }

	    public function openBraces() {
		    switch($this->lastClause) {
			    case self::CLAUSE_JOIN:
				    $this->joins .= "(";
				    break;
			    case self::CLAUSE_WHERE:
				    $this->where .= "(";
				    break;
			    default:
				    throw new \LogicException("Braces not allowed in current clause");
		    }

		    return $this;
	    }

	    public function closeBraces() {
		    switch($this->lastClause) {
			    case self::CLAUSE_JOIN:
				    $this->joins .= ")";
				    break;
			    case self::CLAUSE_WHERE:
				    $this->where .= ")";
				    break;
			    default:
				    throw new \LogicException("Braces not allowed in current clause");
		    }

		    return $this;
	    }

	    private function condition($field, $value) {
		    if(count($value) == 1) {
			    if($value === null)
				    return "`{$field}` IS NULL";

			    $this->parameters[] = $value[0];
			    return "`{$field}` = ? ";
		    } else {
			    $this->parameters[] = $value[1];
			    return "`{$field}` {$value[0]} ? ";
		    }
	    }

	    public function parameter($value) {
		    $this->parameters[] = $value;

		    return $this;
	    }

	    /**
	     * @return Select
	     */
	    public function subquery() {
		    return new Select($this->database, $this);
	    }

	    private function setSubquery($query) {
		    switch($this->lastClause) {
			    case self::CLAUSE_JOIN:
				    $this->joins .= "({$query})";
				    break;
			    case self::CLAUSE_WHERE:
				    $this->where .= "({$query})";
				    break;
			    default:
				    throw new \LogicException("Subqueries not allowed in current clause");
		    }

		    return $this;
	    }

	    public function back() {
		    if(!$this->previous)
			    throw new \LogicException("No parent query set");

		    return $this->previous->setSubquery($this->build());
	    }

	    public function build() {
		    $query = "SELECT ";

		    if($this->distinct)
			    $query .= "DISTINCT ";

		    if($this->fields) {
				foreach($this->fields as $field) {
					if(is_array($field)) {
						$query .= "`{$field[0]}` AS `{$field[1]}`,";
					} else {
						$query .= "`{$field}`,";
					}
				}

			    if(!$this->rawFields)
				    // Remove trailing comma
			        $query[strlen($query) - 1] = " ";
		    }

		    if($this->rawFields) {
			    foreach($this->rawFields as $field) {
				    if(is_array($field)) {
					    $query .= "{$field[0]} AS `{$field[1]}`,";
				    } else {
					    $query .= "{$field},";
				    }
			    }

			    // Remove trailing comma
			    $query[strlen($query) - 1] = " ";
		    }

		    if(!$this->fields && !$this->rawFields)
			    $query .= "* ";

		    if($this->model) {
			    $model = $this->model;
			    $query .= "FROM `" . $model::TABLE . "` ";
		    } else if($this->table)
			    $query .= "FROM `{$this->table}` ";

		    $query .= $this->joins;

		    $query .= $this->where;

		    $query .= $this->orderBy;

		    if($this->limit)
			    $query .= "LIMIT {$this->limit} ";

		    if($this->offset)
			    $query .= "OFFSET {$this->offset} ";

		    return $query;
	    }

	    public function prepare() {
		    return $this->database->prepare($this->build());
	    }

	    public function run() {
		    $stmt = $this->prepare();
		    $stmt->execute($this->parameters);

		    return $stmt;
	    }

	    public function execute() {
		    $stmt = $this->run();

		    if($this->model) {
			    $retval = [];

			    $model = $this->model;

			    while($result = $stmt->fetchObject()) {
				    if(!isset($result->id))
					    throw new \LogicException("Model queries must include id field");

				    $retval[] = $model::get($result->id, $result);
			    }

			    return $retval;
		    } else {
			    return $stmt->fetchAll();
		    }
	    }

	    public function generate() {
		    $stmt = $this->run();

		    if($this->model) {
			    $model = $this->model;

			    while($result = $stmt->fetchObject()) {
				    if(!isset($result->id))
					    throw new \LogicException("Model queries must include id field");

				    yield $model::get($result->id, $result);
			    }
		    } else {
			    while($result = $stmt->fetch()) {
				    yield $result;
			    }
		    }
	    }
    }
