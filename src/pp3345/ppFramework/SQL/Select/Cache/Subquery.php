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

	namespace pp3345\ppFramework\SQL\Select\Cache;

	use pp3345\ppFramework\SQL\RawValue;
	use pp3345\ppFramework\SQL\Select;
	use pp3345\ppFramework\SQL\Select\Cache;

	class Subquery extends Cache {
		private $context = 0;
		private $parent;

		/** @noinspection PhpMissingParentConstructorInspection */
		/**
		 * @param int   $context
		 * @param Cache $parent
		 */
		public function __construct($context, Cache $parent = null) {
			$this->context = $context;
			$this->parent  = $parent;
		}

		public function back() {
			if($this->context == Select\Subquery::CONTEXT_CONDITION)
				return new RawValue("", $this->parameters);

			return $this->parent->setSubquery($this);
		}
	}
