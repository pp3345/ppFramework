<?php

	/**
	 * Copyright (c) 2014 - 2016 Yussuf Khalil
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

	namespace pp3345\ppFramework\CLI;

	use pp3345\ppFramework\Application;
	use pp3345\ppFramework\Exception\NotFoundException;
	use pp3345\ppFramework\Singleton;

	class WebRoute {
		use Singleton;

		public function WebRoute(Application $application, $path = "/", $method = "GET", $cookies = "") {
			foreach(explode(";", $cookies) as $cookie) {
				$cookie              = explode("=", $cookie, 2);
				$_COOKIE[$cookie[0]] = isset($cookie[1]) ? $cookie[1] : "";
			}

			try {
				$application->route($method, $path);
			} catch(NotFoundException $e) {
				$this->help();
				throw $e;
			}
		}

		public function help() {
			echo 'Usage: WebRoute <path = "/"> <method = "GET"> <cookies>' . PHP_EOL;
		}
	}
