<?php

	namespace net\pp3345\ppFramework\Exception;

	class MissingDatabaseInstanceException extends \Exception {
		public function __construct($dsn) {
			$this->message = "No database connection with DSN \"" . $dsn . "\" exists";
		}
	}
