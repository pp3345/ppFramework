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

    use net\pp3345\ppFramework\Exception\DuplicateMiddlewareException;
    use net\pp3345\ppFramework\Exception\UnknownMiddlewareException;

    abstract class Middleware {
	    private static $middlewares = [];

	    public static function register($name, callable $call) {
		    if(isset(self::$middlewares[$name]))
			    throw new DuplicateMiddlewareException("A middleware called '$name' already exists");

		    self::$middlewares[$name] = $call;
	    }

	    public static function compose(...$calls) {
		    foreach($calls as &$call) {
			    if(is_callable($call))
				    continue;

			    if(isset(self::$middlewares[$call])) {
				    $call = self::$middlewares[$call];
				    continue;
			    }

			    throw new UnknownMiddlewareException("Middleware '{$call}' is unknown");
		    }

		    return function(...$arguments) use($calls) {
			    foreach($calls as $call)
				    $arguments = $call(...$arguments);
		    };
	    }
    }