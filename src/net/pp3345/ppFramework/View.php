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

	use Twig_Environment;

	class View {
		/**
		 * @var Twig_Environment
		 */
		private static $environment;
		private $vars = [];
		private $context = [];

		public static function getEnvironment() {
			if(!self::$environment) {
				self::$environment = new Twig_Environment(new \Twig_Loader_Filesystem());
				self::$environment->getLoader()->addPath(__DIR__ . "/View", "ppFramework");
			}

			return self::$environment;
		}

		public function render($template) {
			return self::getEnvironment()->render($template, $this->context);
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
