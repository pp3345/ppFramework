<?php

	namespace net\pp3345\ppFramework;

	class ModelRegistry {
		use Singleton;

		private $classes = [];
		private $databases = [];

		/**
		 * @param Model $class
		 */
		public function registerClass($class) {
			$class::initialize();

			foreach($this->databases as $database)
				$class::registerDatabase($database);

			$classes[] = $class;
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
	}
