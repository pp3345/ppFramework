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

	namespace pp3345\ppFramework\Model;

	use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
	use Mockery\MockInterface;
	use PHPUnit\Framework\TestCase;
	use pp3345\ppFramework\Database;

	class SingleTableInheritanceModelTest extends TestCase {
		use MockeryPHPUnitIntegration;

		/**
		 * @var MockInterface
		 */
		private $mockDatabase;
		private $stubAMockStmt;
		private $stubBMockStmt;

		protected function setUp() {
			\Mockery::resetContainer();

			$this->stubAMockStmt = \Mockery::mock("PDOStatement");
			$this->stubBMockStmt = \Mockery::mock("PDOStatement");

			$this->mockDatabase                  = \Mockery::mock("alias:" . Database::class);
			$this->mockDatabase->selectForUpdate = false;
			$this->mockDatabase->shouldReceive("getDefault")->andReturn($this->mockDatabase);
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubATable` WHERE `id` = ? FOR UPDATE")->globally()->ordered();
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubATable` WHERE `id` = ?")->andReturn($this->stubAMockStmt)->globally()->ordered();
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubBTable` WHERE `id` = ? FOR UPDATE")->globally()->ordered();
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubBTable` WHERE `id` = ?")->andReturn($this->stubBMockStmt)->globally()->ordered();

			ModelStubA::initialize();
			ModelStubB::initialize();
		}

		public function testDefaultMapping() {
			$a1        = new \stdClass();
			$a1->id    = 1;
			$a1->class = ModelStubAChild::class;
			$a1->x     = 1000;

			$a2        = new \stdClass();
			$a2->id    = 2;
			$a2->class = ModelStubA::class;
			$a2->x     = 2000;

			$a3        = new \stdClass();
			$a3->id    = 3;
			$a3->x     = 3000;

			$this->stubAMockStmt->shouldReceive("execute")->once()->with([1])->andReturn(true)->globally()->ordered();
			$this->stubAMockStmt->shouldReceive("rowCount")->once()->with()->andReturn(1)->globally()->ordered();
			$this->stubAMockStmt->shouldReceive("fetchObject")->once()->with()->andReturn($a1)->globally()->ordered();
			$aChild1 = ModelStubA::get(1);
			$this->assertInstanceOf(ModelStubAChild::class, $aChild1);
			$this->assertSame($aChild1, ModelStubA::get(1));
			$this->assertSame($aChild1, ModelStubAChild::get(1));
			$this->assertSame($aChild1, ModelStubA::get(1, $a1));
			$this->assertEquals(1000, $aChild1->x);

			$this->stubAMockStmt->shouldReceive("execute")->once()->with([2])->andReturn(true)->globally()->ordered();
			$this->stubAMockStmt->shouldReceive("rowCount")->once()->with()->andReturn(1)->globally()->ordered();
			$this->stubAMockStmt->shouldReceive("fetchObject")->once()->with()->andReturn($a2)->globally()->ordered();
			$aChild2 = ModelStubA::get(2);
			$this->assertInstanceOf(ModelStubA::class, $aChild2);
			$this->assertSame($aChild2, ModelStubA::get(2));
			$this->assertSame($aChild2, ModelStubA::get(2, $a2));
			$this->assertEquals(2000, $aChild2->x);

			$aChild3 = ModelStubAChild::get(3, $a3);
			$this->assertInstanceOf(ModelStubAChild::class, $aChild3);
			$this->assertSame($aChild3, ModelStubA::get(3));
			$this->assertSame($aChild3, ModelStubA::get(3, $a3));
			$this->assertEquals(3000, $aChild3->x);
		}

		public function testCustomMapping() {
			$b1 = new \stdClass();
			$b1->id = 50;
			$b1->type = 1;
			$b1->y = 21;

			$b2 = new \stdClass();
			$b2->id = 60;
			$b2->type = 2;
			$b2->y = 42;

			$this->stubBMockStmt->shouldReceive("execute")->once()->with([50])->andReturn(true)->globally()->ordered();
			$this->stubBMockStmt->shouldReceive("rowCount")->once()->with()->andReturn(1)->globally()->ordered();
			$this->stubBMockStmt->shouldReceive("fetchObject")->once()->with()->andReturn($b1)->globally()->ordered();
			$bChild1 = ModelStubB::get(50);
			$this->assertInstanceOf(ModelStubBChildA::class, $bChild1);
			$this->assertSame($bChild1, ModelStubB::get(50));
			$this->assertSame($bChild1, ModelStubBChildA::get(50));
			$this->assertSame($bChild1, ModelStubBChildB::get(50));
			$this->assertSame($bChild1, ModelStubB::get(50, $b1));
			$this->assertEquals(21, $bChild1->y);

			$this->stubBMockStmt->shouldReceive("execute")->once()->with([60])->andReturn(true)->globally()->ordered();
			$this->stubBMockStmt->shouldReceive("rowCount")->once()->with()->andReturn(1)->globally()->ordered();
			$this->stubBMockStmt->shouldReceive("fetchObject")->once()->with()->andReturn($b2)->globally()->ordered();
			$bChild2 = ModelStubBChildA::get(60);
			$this->assertInstanceOf(ModelStubBChildB::class, $bChild2);
			$this->assertSame($bChild2, ModelStubB::get(60));
			$this->assertSame($bChild2, ModelStubBChildB::get(60));
			$this->assertSame($bChild2, ModelStubB::get(60, $b2));
			$this->assertEquals(42, $bChild2->y);
		}
	}

	class ModelStubA {
		use SingleTableInheritanceModel;

		public $class;
		public $x;

		const TABLE = "ModelStubATable";
	}

	class ModelStubAChild extends ModelStubA {
	}

	abstract class ModelStubB {
		use SingleTableInheritanceModel;

		public $type;
		public $y;

		protected static function getClassFromObject(\stdClass $object) {
			return self::CLASS_MAP[$object->type];
		}

		const TABLE     = "ModelStubBTable";
		const CLASS_MAP = [
			1 => ModelStubBChildA::class,
			2 => ModelStubBChildB::class
		];
	}

	class ModelStubBChildA extends ModelStubB {
	}

	class ModelStubBChildB extends ModelStubB {
	}
