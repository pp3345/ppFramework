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

	use pp3345\ppFramework\Exception\DuplicateHookException;
	use pp3345\ppFramework\Hook\View\AfterRenderHook;
	use pp3345\ppFramework\Hook\View\BeforeRenderHook;
	use Twig_Environment;

	class View {
		/**
		 * @var Twig_Environment
		 */
		private static $environment;
		private        $context = [];
		/**
		 * @var BeforeRenderHook[]
		 */
		private static $beforeRender = [];
		/**
		 * @var AfterRenderHook[]
		 */
		private static $afterRender = [];

		public static function getEnvironment() {
			if(!self::$environment) {
				$applicationReflector = new \ReflectionClass(Application::getInstance());
				$loader               = new \Twig_Loader_Filesystem();

				self::$environment = new Twig_Environment($loader);

				// ppFramework built-in views
				$loader->addPath(__DIR__ . DIRECTORY_SEPARATOR . "View", "ppFramework");

				// Application view namespace
				$path = dirname($applicationReflector->getFileName()) . DIRECTORY_SEPARATOR . $applicationReflector->getShortName() . DIRECTORY_SEPARATOR . "View";

				if(is_dir($path))
					$loader->addPath($path, $applicationReflector->getShortName());
			}

			return self::$environment;
		}

		public static function beforeRender(BeforeRenderHook $hook) {
			if(in_array($hook, self::$beforeRender))
				throw new DuplicateHookException(BeforeRenderHook::class);

			self::$beforeRender[] = $hook;
		}

		public static function afterRender(AfterRenderHook $hook) {
			if(in_array($hook, self::$afterRender))
				throw new DuplicateHookException(AfterRenderHook::class);

			self::$afterRender[] = $hook;
		}

		public function render($template) {
			foreach(self::$beforeRender as $hook)
				$hook->beforeRenderView($this, $template);

			$content = self::getEnvironment()->render($template, $this->context);

			foreach(self::$afterRender as $hook)
				$content = $hook->afterRenderView($this, $template, $content);

			return $content;
		}

		public function getVariable($name) {
			return $this->context[$name];
		}

		public function setVariable($name, $value) {
			$this->context[$name] = $value;

			return $this;
		}

		public function getVariables() {
			return $this->context;
		}

		public function setVariables(array $variables) {
			$this->context = array_merge($this->context, $variables);

			return $this;
		}
	}
