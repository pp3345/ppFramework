<?php

	namespace net\pp3345\ppFramework;

	trait StaticSingleton {
		private static $_instance;

		private function __construct() {
		}

		/**
		 * @return $this
		 */
		public static function getInstance() {
			return self::$_instance ?: (self::$_instance = new static);
		}
	}
