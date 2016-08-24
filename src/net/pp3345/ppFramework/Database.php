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

	use net\pp3345\ppFramework\Exception\MissingDatabaseInstanceException;
	use PDO;
	use Serializable;

	class Database extends PDO implements Serializable {
		private static $default;
		private static $instances = [];

		public  $selectForUpdate = false;
		private $resetSelectForUpdate;
		private $dsn             = "";

		public function __construct($dsn, ...$args) {
			parent::__construct($dsn, ...$args);

			$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->dsn = $dsn;
			self::$instances[$dsn] = $this;

			ModelRegistry::getInstance()->registerDatabase($this);
		}

		/**
		 * @return self
		 */
		public static function getDefault() {
			if(!self::$default)
				throw new \LogicException("Default database not set");

			return self::$default;
		}

		public static function getByDSN($dsn) {
			if(!isset(self::$instances[$dsn]))
				throw new MissingDatabaseInstanceException($dsn);

			return self::$instances[$dsn];
		}

		public function setDefault() {
			self::$default = $this;

			ModelRegistry::getInstance()->switchDatabase($this);

			return $this;
		}

		public function getDSN() {
			return $this->dsn;
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
				ModelRegistry::getInstance()->activateTransactionalCache();

				$this->beginTransaction($selectForUpdate);

				$retval = $call();

				if($this->inTransaction())
					$this->commit();

				ModelRegistry::getInstance()->deactivateTransactionalCache();
			} catch(\PDOException $e) {
				$this->rollBack();

				ModelRegistry::getInstance()->deactivateTransactionalCache();

				if($onError && $onError($e))
					return $this->executeInTransaction($call, $onError, $selectForUpdate);

				throw $e;
			} catch(\Exception $e) {
				$this->rollBack();

				ModelRegistry::getInstance()->deactivateTransactionalCache();

				throw $e;
			} catch(\Error $e) {
				$this->rollBack();

				ModelRegistry::getInstance()->deactivateTransactionalCache();

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

		public function serialize() {
			return serialize($this->dsn);
		}

		public function unserialize($serialized) {
			$this->dsn = unserialize($serialized);
		}
	}
