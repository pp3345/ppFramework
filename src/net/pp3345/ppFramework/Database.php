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

	use PDO;

	class Database extends PDO {
		private static $default;

		public  $selectForUpdate = false;
		private $resetSelectForUpdate;

		public function __construct(...$args) {
			parent::__construct(...$args);

			$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}

		/**
		 * @return self
		 */
		public static function getDefault() {
			if(!self::$default)
				throw new \LogicException("Default database not set");

			return self::$default;
		}

		public function setDefault() {
			return self::$default = $this;
		}

		public function beginTransaction($selectForUpdate = null) {
			if($selectForUpdate !== null) {
				$this->resetSelectForUpdate = $this->selectForUpdate;
				$this->selectForUpdate      = $selectForUpdate;
			}

			return parent::beginTransaction();
		}

		public function commit() {
			if($this->resetSelectForUpdate !== null) {
				$this->selectForUpdate      = $this->resetSelectForUpdate;
				$this->resetSelectForUpdate = null;
			}

			return parent::commit();
		}

		public function rollBack() {
			if($this->resetSelectForUpdate !== null) {
				$this->selectForUpdate      = $this->resetSelectForUpdate;
				$this->resetSelectForUpdate = null;
			}

			return parent::rollBack();
		}

		public function executeInTransaction(callable $call, callable $onError = null, $selectForUpdate = null) {
			try {
				$this->beginTransaction($selectForUpdate);

				$retval = $call();

				if($this->inTransaction())
					$this->commit();
			} catch(\PDOException $e) {
				$this->rollBack();

				if($onError && $onError($e))
					return $this->executeInTransaction($call, $onError, $selectForUpdate);

				throw $e;
			}

			return $retval;
		}

		public static function onErrorRestartTransaction($n = 3) {
			return function (\PDOException $e) use ($n) {
				static $restarts = 0;

				return ($e->getCode() == "40001" || $e->getCode() == "HY000") && $restarts++ < $n;
			};
		}
	}
