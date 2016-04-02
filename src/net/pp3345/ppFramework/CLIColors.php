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

    final class CLIColors {
	    public static $black = "\e[30m";
	    public static $red = "\e[31m";
	    public static $green = "\e[32m";
	    public static $yellow = "\e[33m";
	    public static $blue = "\e[34m";
	    public static $magenta = "\e[35m";
	    public static $cyan = "\e[36m";
	    public static $white = "\e[37m";

	    public static $reset = "\e[0m";

	    private function __construct() {
	    }

	    public static function enable() {
		    self::$black = "\e[30m";
		    self::$red = "\e[31m";
		    self::$green = "\e[32m";
		    self::$yellow = "\e[33m";
		    self::$blue = "\e[34m";
		    self::$magenta = "\e[35m";
		    self::$cyan = "\e[36m";
		    self::$white = "\e[37m";

		    self::$reset = "\e[0m";
	    }

	    public static function disable() {
		    self::$black =
		    self::$red =
		    self::$green =
		    self::$yellow =
		    self::$blue =
		    self::$magenta =
		    self::$cyan =
		    self::$white =

		    self::$reset = "";
	    }
    }
