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

	namespace pp3345\ppFramework\SQL\Select;

	use pp3345\ppFramework\Exception\InvalidSQLException;
	use pp3345\ppFramework\SQL\RawValue;
	use pp3345\ppFramework\SQL\Select;

	class Subquery extends Select {
		const CONTEXT_DEFUALT   = 0b0;
		const CONTEXT_EXISTS    = 0b1;
		const CONTEXT_CONDITION = 0b10;

		private $context = 0;
		private $parent  = null;

		/**
		 * @param int         $context
		 * @param Select|null $parent
		 */
		public function __construct($context, Select $parent = null) {
			parent::__construct();

			$this->context = $context;
			$this->parent  = $parent;

			if($context != self::CONTEXT_CONDITION && !$parent)
				throw new InvalidSQLException("Must have parent for context");
		}

		public function back() {
			if($this->context == self::CONTEXT_CONDITION)
				return new RawValue("(" . $this->build() . ")", $this->parameters);

			return $this->parent->setSubquery($this);
		}

		public function buildFields() {
			return $this->context == self::CONTEXT_EXISTS ? "SELECT 1" : parent::buildFields();
		}
	}
