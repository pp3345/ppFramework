<?php

	namespace net\pp3345\ppFramework;

	trait SingletonContainer {
		private static $_instances = [];

		private function __construct() {
		}

		/**
		 * @return $this
		 */
		public static function getInstance() {
			return self::$_instances[static::class] ?: (self::$_instances[static::class] = new static);
		}
	}
