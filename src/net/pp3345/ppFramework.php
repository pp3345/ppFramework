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

	namespace net\pp3345;

	use Exception;
	use net\pp3345\ppFramework\Exception\HTTPException;
	use net\pp3345\ppFramework\Exception\NotFoundException;
	use net\pp3345\ppFramework\Exception\UnknownNamedRouteException;
	use net\pp3345\ppFramework\Singleton;
	use net\pp3345\ppFramework\View;

	class ppFramework {
		use Singleton;

		public $application = __NAMESPACE__;
		protected $routes = [];
		protected $namedRoutes = [];

		protected function __construct() {
			set_error_handler(function ($severity, $string, $file, $line) {
				if(error_reporting() & $severity)
					throw new \ErrorException($string, 0, $severity, $file, $line);
			});
		}

		public function onRequest() {
			session_start();

			try {
				$this->route($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
			} catch(Exception $exception) {
				if($exception instanceof HTTPException)
					http_response_code($exception->getCode());

				$this->exception($exception);
			}
		}

		public function route($method, $originalURI) {
			if($originalURI[0] != '/') {
				$namedRoute = ($argumentPos = strpos($originalURI, "/")) ? $namedRoute = substr($originalURI, 0, $argumentPos) : $originalURI;

				if(isset($this->namedRoutes[$method][$namedRoute]))
					$originalURI = $this->namedRoutes[$method][$namedRoute] . ($argumentPos ? substr($originalURI, $argumentPos) : "");
				elseif(isset($this->namedRoutes["*"][$namedRoute]))
					$originalURI = $this->namedRoutes["*"][$namedRoute] . ($argumentPos ? substr($originalURI, $argumentPos) : "");
				else
					$originalURI = "/" . $originalURI;
			}

			if($query = strpos($originalURI, '?'))
				$originalURI = substr($originalURI, 0, $query);

			$slicedURI = explode('/', urldecode($originalURI));

			$classPath = $this->application . "\\Controller\\" . ucfirst($slicedURI[1]);

			$uri = $originalURI;

			if(class_exists($classPath) && is_callable($classPath . "::getInstance"))
				$controller = $classPath::getInstance();

			do {
				if(isset($this->routes[$method][$uri])) {
					$this->routes[$method][$uri](...array_map('urldecode', explode('/', substr($originalURI, strlen($uri) + 1))));

					return;
				}

				if(isset($this->routes['*'][$uri])) {
					$this->routes['*'][$uri](...array_map('urldecode', explode('/', substr($originalURI, strlen($uri) + 1))));

					return;
				}
			} while($uri = substr($uri, 0, strrpos($uri, '/')));

			if(isset($controller)) {
				if(!isset($slicedURI[2]) || (count($slicedURI) == 3 && !$slicedURI[2])) {
					if(!is_callable([$controller, $slicedURI[1]])) {
						$this->routeDefault();
					}

					$controller->$slicedURI[1]();

					return;
				}

				for($i = count($slicedURI) - 2; $i; $i--) {
					$function = implode(array_slice($slicedURI, 2, $i));

					if(is_callable([$controller, $function])) {
						$controller->$function(...array_map('urldecode', array_slice($slicedURI, $i + 2, count($slicedURI) - $i)));

						return;
					}
				}
			}

			$this->routeDefault();
		}

		public function routeDefault() {
			throw new NotFoundException();
		}

		protected function exception(Exception $exception) {
			$view = new View();
			$view->setVariable('displayStack', ini_get('display_errors'));
			$view->setVariable('exception', $exception);

			if($exception instanceof HTTPException) {
				echo $view->render("@ppFramework/Exception/HTTP.twig");
			} else {
				echo $view->render("@ppFramework/Exception/Error.twig");
			}
		}

		public function addRoute($path, $callback, $methods = ['*']) {
			foreach((array) $methods as $method) {
				if(is_callable($callback))
					$this->routes[$method][$path] = $callback;
				else
					$this->namedRoutes[$method][$callback] = $path;
			}
		}

		public function getNamedRouteLocation($name, $method = "GET") {
			if(isset($this->namedRoutes[$name][$method]))
				return $this->namedRoutes[$name][$method];

			if(isset($this->namedRoutes[$name]['*']))
				return $this->namedRoutes[$name]['*'];

			throw new UnknownNamedRouteException("The named route '{$method} {$name}' is unknown");
		}
	}

