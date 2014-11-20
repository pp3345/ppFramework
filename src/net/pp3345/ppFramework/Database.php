<?php

	/*
	 * 	This file is part of ppFramework.
	 *
	 *  ppFramework is free software: you can redistribute it and/or modify
	 *  it under the terms of the GNU General Public License as published by
	 *  the Free Software Foundation, either version 3 of the License, or
	 *  (at your option) any later version.
	 *
	 *  ppFramework is distributed in the hope that it will be useful,
	 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
	 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 *  GNU General Public License for more details.
	 *
	 *  You should have received a copy of the GNU General Public License
	 *  along with ppFramework.  If not, see <http://www.gnu.org/licenses/>.
	 */

	namespace net\pp3345\ppFramework;

	use PDO;

	class Database extends PDO {
		private static $default;

		public function __construct() {
			parent::__construct(...func_get_args());

			$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}

		/**
		 * @return self
		 */
		public static function getDefault() {
			if(!self::$default)
				throw new \LogicException("Default database not set");

			return self::$default;
		}

		public function setDefault() {
			return self::$default = $this;
		}
	}
