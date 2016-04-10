<?php

	namespace net\pp3345\ppFramework\Exception;
	
	class DifferentDatabasesException extends \Exception {
		protected $message = "Can't create relationship between data from two different databases";
	}
