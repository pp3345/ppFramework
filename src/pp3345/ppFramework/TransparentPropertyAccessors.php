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

	use pp3345\ppFramework\Exception\InvalidPropertyAccessException;

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
