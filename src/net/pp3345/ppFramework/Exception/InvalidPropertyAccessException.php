<?php

	namespace net\pp3345\ppFramework\Exception;

	class InvalidPropertyAccessException extends \Exception {
		public function __construct($class, $property) {
			$this->message = "Can not access property \"$property\" of class $class";
		}
	}
