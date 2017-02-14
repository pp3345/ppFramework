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

	use Error;
	use Exception;
	use pp3345\ppFramework\Exception\CLIComponentActionUnknownException;
	use pp3345\ppFramework\Exception\CLIComponentNotFoundException;
	use pp3345\ppFramework\Exception\HTTPException;
	use pp3345\ppFramework\Exception\NotFoundException;
	use pp3345\ppFramework\Exception\UnknownNamedRouteException;
	use Throwable;

	abstract class Application {
		use StaticSingleton;

		private   $applicationNamespace = "";
		protected $routes               = [];
		protected $namedRoutes          = [];

		protected function __construct() {
			self::$_instance = $this;

			$this->applicationNamespace = static::class;

			set_error_handler(function ($severity, $string, $file, $line) {
				if(error_reporting() & $severity)
					throw new \ErrorException($string, 0, $severity, $file, $line);
			});

			foreach(spl_autoload_functions() as $function) {
				spl_autoload_unregister($function);

				$this->registerAutoload($function);
			}
		}

		public function registerAutoload(callable $function) {
			spl_autoload_register(function ($class) use ($function) {
				$function($class);

				if(class_exists($class, false)) {
					if(ModelRegistry::isModelClass($class))
						ModelRegistry::getInstance()->registerClass($class);

					try {
						$classReflector  = new \ReflectionClass($class);
						$methodReflector = $classReflector->getMethod("__static");

						if($methodReflector->getDeclaringClass()->getName() == $classReflector->getName()) {
							$methodReflector->setAccessible(true);
							$methodReflector->invoke(new \stdClass());
						}
					} catch(\ReflectionException $e) {
					}
				}
			});
		}

		public function onRequest() {
			session_start();

			try {
				if(PHP_SAPI == 'cli')
					$this->routeCLI(array_slice($_SERVER['argv'], 1));
				else
					$this->route($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
			} catch(HTTPException $exception) {
				http_response_code($exception->getCode());

				$this->exception($exception);
			} catch(Exception $exception) {
				http_response_code(500);

				$this->exception($exception);
			} catch(Error $exception) {
				http_response_code(500);

				$this->exception($exception);
			}
		}

		public function routeCLI($argv) {
			if(!isset($argv[0]))
				throw new CLIComponentNotFoundException("Please specify a CLI component to run.");

			if($argv[0] == "--no-colors") {
				CLIColors::disable();
				array_shift($argv);

				if(!isset($argv[0]))
					throw new CLIComponentNotFoundException("Please specify a CLI component to run.");
			}

			if((!class_exists($classPath = __NAMESPACE__ . "\\CLI\\" . $argv[0]) || !is_callable($classPath . "::getInstance"))
			   && (!class_exists($classPath = $this->applicationNamespace . "\\CLI\\" . $argv[0]) || !is_callable($classPath . "::getInstance"))
			)
				throw new CLIComponentNotFoundException("Unknown CLI component '$argv[0]'");

			$component = $classPath::getInstance();

			if(isset($argv[1]) && is_callable([$component, $argv[1]]))
				$component->{$argv[1]}(...array_slice($argv, 2));
			else if(is_callable([$component, $argv[0]]))
				$component->{$argv[0]}(...array_slice($argv, 1));
			else
				throw new CLIComponentActionUnknownException(isset($argv[1]) ? "Unknown action '$argv[1]' specified for CLI component $argv[0]" : "Please specify an action for CLI component $argv[0]");
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

			$classPath = $this->applicationNamespace . "\\Controller\\" . ucfirst($slicedURI[1]);

			$uri = $originalURI;

			if(class_exists($classPath))
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

						return;
					}

					$controller->{$slicedURI[1]}();

					return;
				}

				for($i = count($slicedURI) - 2; $i; $i--) {
					$function = implode(array_slice($slicedURI, 2, $i));

					if(is_callable([$controller, $function])) {
						$controller->$function(...array_map('urldecode', array_slice($slicedURI, $i + 2, count($slicedURI) - $i)));

						return;
					}
				}

				if(is_callable([$controller, $slicedURI[1]])) {
					$controller->{$slicedURI[1]}(...array_map('urldecode', array_slice($slicedURI, 2)));

					return;
				}
			}

			$this->routeDefault();
		}

		public function routeDefault() {
			throw new NotFoundException();
		}

		/**
		 * @param $exception
		 */
		protected function exception($exception) {
			if(!($exception instanceof Exception) && !($exception instanceof Throwable))
				throw new \InvalidArgumentException("Argument must be instance of Exception or Throwable, got " . (is_object($exception) ? get_class($exception) : gettype($exception)));

			if(php_sapi_name() == 'cli') {
				echo CLIColors::$red . ($exception->getMessage() ?: get_class($exception)) . CLIColors::$reset . PHP_EOL;

				if(ini_get('display_errors'))
					echo PHP_EOL . "Stack trace:" . PHP_EOL . $exception->getTraceAsString() . PHP_EOL;
			} else {
				$view = new View();
				$view->setVariable('displayStack', ini_get('display_errors'));
				$view->setVariable('exception', $exception);

				echo $view->render($exception instanceof HTTPException ? "@ppFramework/Exception/HTTP.twig" : "@ppFramework/Exception/Error.twig");
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

		public function getApplicationNamespace() {
			return $this->applicationNamespace;
		}
	}

