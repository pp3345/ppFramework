<?php

	namespace net\pp3345\ppFramework;

	use net\pp3345\ppFramework\Exception\InvalidPropertyAccessException;

	trait TransparentPropertyAccessors {
		public function __get($name) {
			if(is_callable($call = [$this, "get" . $name]))
				return $call();

			throw new InvalidPropertyAccessException(__CLASS__, $name);
		}

		public function __set($name, $value) {
			if(is_callable($call = [$this, "set" . $name]))
				$call($value);
			else throw new InvalidPropertyAccessException(__CLASS__, $name);
		}
	}
