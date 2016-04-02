<?php

	namespace net\pp3345\ppFramework\Exception;

	class DuplicateHookException extends \Exception {
		public function __construct($hook) {
			$this->message = "This " . $hook . " hook is already registered";
		}
	}
