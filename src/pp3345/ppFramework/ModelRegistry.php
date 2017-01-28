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

	namespace pp3345\ppFramework;

	class ModelRegistry {
		use Singleton;

		private $classes                  = [];
		private $databases                = [];
		private $debugInfoRecursionHelper = null;

		/**
		 * @param Model $class
		 */
		public function registerClass($class) {
			$class::initialize();

			foreach($this->databases as $database)
				$class::registerDatabase($database);

			$this->classes[] = $class;
		}

		public function registerDatabase(Database $database) {
			foreach($this->classes as $class)
				$class::registerDatabase($database);

			$this->databases[] = $database;
		}

		public function switchDatabase(Database $database) {
			foreach($this->classes as $class) {
				$class::switchDatabase($database);
			}
		}

		public function clearCaches() {
			foreach($this->classes as $class)
				$class::clearCache();
		}

		public function clearAllCaches() {
			foreach($this->classes as $class)
				$class::clearAllCaches();
		}

		public function clearDatabaseCaches(Database $database) {
			foreach($this->classes as $class)
				$class::clearDatabaseCache($database);
		}

		public function activateTransactionalCache() {
			foreach($this->classes as $class)
				$class::activateTransactionalCache();
		}

		public function deactivateTransactionalCache() {
			foreach($this->classes as $class)
				$class::deactivateTransactionalCache();
		}

		public function getDebugInfoRecursionHelper() {
			return $this->debugInfoRecursionHelper ?: ($this->debugInfoRecursionHelper = new \SplObjectStorage());
		}
	}
