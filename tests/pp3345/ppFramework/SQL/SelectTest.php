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

	namespace pp3345\ppFramework\SQL;

	use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
	use Mockery\MockInterface;
	use PHPUnit\Framework\TestCase;
	use pp3345\ppFramework\Database;
	use pp3345\ppFramework\Exception\InvalidSQLException;
	use pp3345\ppFramework\Model;

	class SelectTest extends TestCase {
		use MockeryPHPUnitIntegration;

		/**
		 * @var MockInterface
		 */
		private $mockDatabase;

		protected function setUp() {
			\Mockery::resetContainer();

			$this->mockDatabase = \Mockery::mock("alias:" . Database::class);
			$this->mockDatabase->selectForUpdate = false;
			$this->mockDatabase->shouldReceive("getDefault")->andReturn($this->mockDatabase);
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubATable` WHERE `id` = ? FOR UPDATE")->globally()->ordered();
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubATable` WHERE `id` = ?")->globally()->ordered();
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubBTable` WHERE `id` = ? FOR UPDATE")->globally()->ordered();
			$this->mockDatabase->shouldReceive("prepare")->with("SELECT * FROM `ModelStubBTable` WHERE `id` = ?")->globally()->ordered();

			ModelStubA::initialize();
			ModelStubB::initialize();
		}

		public function testFrom() {
			$select = (new Select())->from("foo");
			$this->assertEquals("SELECT * FROM `foo`", $select->build());

			$select = (new Select())->from("foo", "bar");
			$this->assertEquals("SELECT * FROM `foo` AS `bar`", $select->build());

			$select = (new Select())->from(ModelStubA::class);
			$this->assertEquals("SELECT `ModelStubATable`.* FROM `ModelStubATable`", $select->build());

			$select = (new Select())->from(ModelStubA::class, "foo");
			$this->assertEquals("SELECT `foo`.* FROM `ModelStubATable` AS `foo`", $select->build());

			$select = (new Select())->from("foo")->from("bar", "foobar");
			$this->assertEquals("SELECT * FROM `foo`, `bar` AS `foobar`", $select->build());

			$select = (new Select())->from(ModelStubA::class, "foobar")->from("foo")->from("bar", "foofoo");
			$this->assertEquals("SELECT `foobar`.* FROM `ModelStubATable` AS `foobar`, `foo`, `bar` AS `foofoo`", $select->build());
		}

		public function testDistinct() {
			$select = (new Select())->distinct()->from("foo");
			$this->assertEquals("SELECT DISTINCT * FROM `foo`", $select->build());
		}

		public function testCountAll() {
			$select = (new Select())->from("foo")->countAll();
			$this->assertEquals("SELECT COUNT(*) FROM `foo`", $select->build());

			$select = (new Select())->from("foo")->countAll("", "c");
			$this->assertEquals("SELECT COUNT(*) AS `c` FROM `foo`", $select->build());

			$select = (new Select())->from("foo")->countAll("x");
			$this->assertEquals("SELECT COUNT(`x`) FROM `foo`", $select->build());
		}

		public function testCountDistinct() {
			$select = (new Select())->from("foo")->countDistinct();
			$this->assertEquals("SELECT COUNT(DISTINCT *) FROM `foo`", $select->build());

			$select = (new Select())->from("foo")->countDistinct("", "c");
			$this->assertEquals("SELECT COUNT(DISTINCT *) AS `c` FROM `foo`", $select->build());

			$select = (new Select())->from("foo")->countDistinct("foo.bar");
			$this->assertEquals("SELECT COUNT(DISTINCT `foo`.`bar`) FROM `foo`", $select->build());
		}

		public function testFields() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->fields("abc", "xyz");
			$this->assertEquals("SELECT `abc`, `xyz` FROM `foo`", $select->build());

			$select = (new Select())->from("foo")->fields("abc")->field("xyz", "bar")->fields("foofoo", "barbar");
			$this->assertEquals("SELECT `abc`, `xyz` AS `bar`, `foofoo`, `barbar` FROM `foo`", $select->build());

			$select = (new Select())->from("foo")->from("bar")->fields("foo.abc", "bar.xyz")->field("bar.aaa", "bbb");
			$this->assertEquals("SELECT `foo`.`abc`, `bar`.`xyz`, `bar`.`aaa` AS `bbb` FROM `foo`, `bar`", $select->build());

			$select = (new Select())->from("foo")->from("bar")->field("foo.*");
			$this->assertEquals("SELECT `foo`.* FROM `foo`, `bar`", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT `foo`.`abc`, `bar`.`xyz`, `bar`.`aaa` AS `bbb` FROM `foo`, `bar`")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->from("foo")->from("bar")->fields("foo.abc", "bar.xyz")->field("bar.aaa", "bbb")->run();
			Select::cached($cache)->from("foo")->from("bar")->fields("foo.abc", "bar.xyz")->field("bar.aaa", "bbb")->run();
		}

		public function testUseIndex() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->useIndex("idx_foo_test");
			$this->assertEquals("SELECT * FROM `foo` USE INDEX (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->useIndex("idx_foo_test")->from("bar");
			$this->assertEquals("SELECT * FROM `foo` USE INDEX (`idx_foo_test`), `bar`", $select->build());

			$select = (new Select())->from("foo")->useIndex("idx_foo_test", Select::INDEX_HINT_FOR_JOIN);
			$this->assertEquals("SELECT * FROM `foo` USE INDEX FOR JOIN (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->useIndex("idx_foo_test", Select::INDEX_HINT_FOR_GROUP_BY);
			$this->assertEquals("SELECT * FROM `foo` USE INDEX FOR GROUP BY (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->useIndex("idx_foo_test", Select::INDEX_HINT_FOR_ORDER_BY);
			$this->assertEquals("SELECT * FROM `foo` USE INDEX FOR ORDER BY (`idx_foo_test`)", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` USE INDEX (`idx_foo_test`)")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->from("foo")->useIndex("idx_foo_test")->run();
			Select::cached($cache)->from("foo")->useIndex("idx_foo_test")->run();
		}

		public function testForceIndex() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->forceIndex("idx_foo_test");
			$this->assertEquals("SELECT * FROM `foo` FORCE INDEX (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->forceIndex("idx_foo_test")->from("bar");
			$this->assertEquals("SELECT * FROM `foo` FORCE INDEX (`idx_foo_test`), `bar`", $select->build());

			$select = (new Select())->from("foo")->forceIndex("idx_foo_test", Select::INDEX_HINT_FOR_JOIN);
			$this->assertEquals("SELECT * FROM `foo` FORCE INDEX FOR JOIN (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->forceIndex("idx_foo_test", Select::INDEX_HINT_FOR_GROUP_BY);
			$this->assertEquals("SELECT * FROM `foo` FORCE INDEX FOR GROUP BY (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->forceIndex("idx_foo_test", Select::INDEX_HINT_FOR_ORDER_BY);
			$this->assertEquals("SELECT * FROM `foo` FORCE INDEX FOR ORDER BY (`idx_foo_test`)", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` FORCE INDEX (`idx_foo_test`)")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->from("foo")->forceIndex("idx_foo_test")->run();
			Select::cached($cache)->from("foo")->forceIndex("idx_foo_test")->run();
		}

		public function testIgnoreIndex() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->ignoreIndex("idx_foo_test");
			$this->assertEquals("SELECT * FROM `foo` IGNORE INDEX (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->ignoreIndex("idx_foo_test")->from("bar");
			$this->assertEquals("SELECT * FROM `foo` IGNORE INDEX (`idx_foo_test`), `bar`", $select->build());

			$select = (new Select())->from("foo")->ignoreIndex("idx_foo_test", Select::INDEX_HINT_FOR_JOIN);
			$this->assertEquals("SELECT * FROM `foo` IGNORE INDEX FOR JOIN (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->ignoreIndex("idx_foo_test", Select::INDEX_HINT_FOR_GROUP_BY);
			$this->assertEquals("SELECT * FROM `foo` IGNORE INDEX FOR GROUP BY (`idx_foo_test`)", $select->build());

			$select = (new Select())->from("foo")->ignoreIndex("idx_foo_test", Select::INDEX_HINT_FOR_ORDER_BY);
			$this->assertEquals("SELECT * FROM `foo` IGNORE INDEX FOR ORDER BY (`idx_foo_test`)", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` IGNORE INDEX (`idx_foo_test`)")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->from("foo")->ignoreIndex("idx_foo_test")->run();
			Select::cached($cache)->from("foo")->ignoreIndex("idx_foo_test")->run();
		}

		public function testJoin() {
			$select = (new Select())->from("foo")->join("bar");
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_NATURAL);
			$this->assertEquals("SELECT * FROM `foo` NATURAL JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_NATURAL_LEFT);
			$this->assertEquals("SELECT * FROM `foo` NATURAL LEFT JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_NATURAL_LEFT_OUTER);
			$this->assertEquals("SELECT * FROM `foo` NATURAL LEFT OUTER JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_NATURAL_RIGHT);
			$this->assertEquals("SELECT * FROM `foo` NATURAL RIGHT JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_NATURAL_RIGHT_OUTER);
			$this->assertEquals("SELECT * FROM `foo` NATURAL RIGHT OUTER JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_CROSS);
			$this->assertEquals("SELECT * FROM `foo` CROSS JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_INNER);
			$this->assertEquals("SELECT * FROM `foo` INNER JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_LEFT);
			$this->assertEquals("SELECT * FROM `foo` LEFT JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_LEFT_OUTER);
			$this->assertEquals("SELECT * FROM `foo` LEFT OUTER JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_RIGHT);
			$this->assertEquals("SELECT * FROM `foo` RIGHT JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_RIGHT_OUTER);
			$this->assertEquals("SELECT * FROM `foo` RIGHT OUTER JOIN `bar`", $select->build());

			$select = (new Select())->from("foo")->join("bar", Select::JOIN_TYPE_STRAIGHT);
			$this->assertEquals("SELECT * FROM `foo` STRAIGHT_JOIN `bar`", $select->build());

			$select = (new Select())->from(ModelStubA::class)->join(ModelStubB::class);
			$this->assertEquals("SELECT `ModelStubATable`.* FROM `ModelStubATable` JOIN `ModelStubBTable` ON `ModelStubBTable`.`id` = `ModelStubATable`.`b`", $select->build());

			$select = (new Select())->from(ModelStubB::class)->join(ModelStubA::class);
			$this->assertEquals("SELECT `ModelStubBTable`.* FROM `ModelStubBTable` JOIN `ModelStubATable` ON `ModelStubATable`.`id` = `ModelStubBTable`.`a`", $select->build());

			$select = (new Select())->from("foo")->join("bar")->join("foobar");
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` JOIN `foobar`", $select->build());

			$select = (new Select())->from(ModelStubB::class)->join(ModelStubA::class)->join("foo");
			$this->assertEquals("SELECT `ModelStubBTable`.* FROM `ModelStubBTable` JOIN `ModelStubATable` ON `ModelStubATable`.`id` = `ModelStubBTable`.`a` JOIN `foo`", $select->build());
		}

		public function testOn() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->join("bar")->on("bar.foo", Select::conditionField("foo.id"));
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON `bar`.`foo` = `foo`.`id`", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` JOIN `bar` ON `bar`.`foo` = `foo`.`id`")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->join("bar")->on("bar.foo", Select::conditionField("foo.id"))->run();
			Select::cached($cache)->from("foo")->join("bar")->on("bar.foo", Select::conditionField("foo.id"))->run();
		}

		public function testUsing() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->join("bar")->using("foofoo", "barbar");
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` USING (`foofoo`, `barbar`)", $select->build());

			$select = (new Select())->from("foo")->join("bar")->using("foofoo.barbar");
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` USING (`foofoo.barbar`)", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` JOIN `bar` USING (`foofoo.barbar`)")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->join("bar")->using("foofoo.barbar")->run();
			Select::cached($cache)->from("foo")->join("bar")->using("foofoo.barbar")->run();
		}

		public function testWhere() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->where("x", "abc");
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ?", $select->build());

			$select = (new Select())->from("foo")->where("x", "abc")->where("y", 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? AND `y` = ?", $select->build());

			$select = (new Select())->from("foo")->where("x", "abc")->where("y", null);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? AND `y` IS NULL", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with(["abc"])->andReturn(true)->globally()->ordered();
			$select->run();

			$select = (new Select())->from("foo")->where("x", "abc")->where("y", "IS NOT", null);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? AND `y` IS NOT NULL", $select->build());

			$select = (new Select())->from("foo")->where("x", "abc")->where("y", ">", 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? AND `y` > ?", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with(["abc", 42])->andReturn(true)->globally()->ordered();
			$select->run();

			$select = (new Select())->from("foo")->where("x")->where("y");
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? AND `y` = ?", $select->build());
		}

		public function testAddAnd() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->where("x", "abc")->addAnd("y", 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? AND `y` = ?", $select->build());

			$select = (new Select())->from("foo")->where("x", "abc")->and("y", 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? AND `y` = ?", $select->build());

			$select = (new Select())->from("foo")->join("bar")->on("bar.foo", Select::conditionField("foo.id"))->addAnd("bar.y", 42);
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON `bar`.`foo` = `foo`.`id` AND `bar`.`y` = ?", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ? AND `y` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with(["abc", 42])->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with(["abc", 43])->globally()->ordered();
			Select::cached($cache)->from("foo")->where("x", "abc")->and("y", 42)->run();
			Select::cached($cache)->from("foo")->where("x", "abc")->and("y", 43)->run();

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ? AND `y` != `x`")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42])->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([43])->globally()->ordered();
			Select::cached($cache)->from("foo")->where("x", 42)->and("y", "!=", Select::conditionField("x"))->run();
			Select::cached($cache)->from("foo")->where("x", 43)->and("y", "!=", Select::conditionField("x"))->run();}

		public function testAddAndInvalidPosition() {
			$this->expectException(InvalidSQLException::class);
			$select = (new Select())->from("foo")->addAnd();
		}

		public function testAddOr() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->where("x", "abc")->addOr("y", 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? OR `y` = ?", $select->build());

			$select = (new Select())->from("foo")->where("x", "abc")->or("y", 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `x` = ? OR `y` = ?", $select->build());

			$select = (new Select())->from("foo")->join("bar")->on("bar.foo", Select::conditionField("foo.id"))->addOr("bar.y", 42);
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON `bar`.`foo` = `foo`.`id` OR `bar`.`y` = ?", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ? OR `y` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with(["abc", 42])->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with(["abc", 43])->globally()->ordered();
			Select::cached($cache)->from("foo")->where("x", "abc")->or("y", 42)->run();
			Select::cached($cache)->from("foo")->where("x", "abc")->or("y", 43)->run();
		}

		public function testAddOrInvalidPosition() {
			$this->expectException(InvalidSQLException::class);
			$select = (new Select())->from("foo")->addOr();
		}

		public function testClause() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->where()->clause("y", 42)->addAnd("x", "!=", "abc")->endClause()->addOr("z", "xyz");
			$this->assertEquals("SELECT * FROM `foo` WHERE (`y` = ? AND `x` != ?) OR `z` = ?", $select->build());

			$select = (new Select())->from("foo")->join("bar")->on()->clause("y", 42)->addAnd("x", "!=", "abc")->endClause()->addOr("z", "xyz");
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON (`y` = ? AND `x` != ?) OR `z` = ?", $select->build());

			$select = (new Select())->from("foo")->groupBy("z")->having()->clause("y", 42)->addAnd("x", "!=", "abc")->endClause()->addOr("z", "xyz");
			$this->assertEquals("SELECT * FROM `foo` GROUP BY `z` HAVING (`y` = ? AND `x` != ?) OR `z` = ?", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE (`y` = ? AND `x` != ?) OR `z` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1, 2, 3])->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([4, 5, 6])->globally()->ordered();
			Select::cached($cache)->from("foo")->where()->clause("y", 1)->addAnd("x", "!=", 2)->endClause()->addOr("z", 3)->run();
			Select::cached($cache)->from("foo")->where()->clause("y", 4)->addAnd("x", "!=", 5)->endClause()->addOr("z", 6)->run();
		}

		public function testEndClauseInvalidPosition() {
			$this->expectException(InvalidSQLException::class);
			$select = (new Select())->from("foo")->endClause();
		}

		public function testIn() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->where()->in("x", 1, 2, 3);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`x` IN(?,?,?))", $select->build());

			$select = (new Select())->from("foo")->where()->in("x");
			$this->assertEquals("SELECT * FROM `foo` WHERE 0", $select->build());

			$select = (new Select())->from("foo")->where()->in("x", 1);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`x` IN(?))", $select->build());

			$select = (new Select())->from("foo")->where()->in("x", [1, 2, 3, 4]);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`x` IN(?,?,?,?))", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1, 2, 3, 4])->andReturn(true)->globally()->ordered();
			$select->run();

			$select = (new Select())->from("foo")->join("bar")->on()->in("x");
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON 0", $select->build());

			$select = (new Select())->from("foo")->groupBy("x")->having()->in("x");
			$this->assertEquals("SELECT * FROM `foo` GROUP BY `x` HAVING 0", $select->build());
		}

		public function testBetween() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->where()->between("x", 1, 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`x` BETWEEN ? AND ?)", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1, 42])->andReturn(true)->globally()->ordered();
			$select->run();

			$select = (new Select())->from("foo")->join("bar")->on()->between("x", 1, 42);
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON (`x` BETWEEN ? AND ?)", $select->build());

			$select = (new Select())->from("foo")->groupBy("x")->having()->between("x", 1, 42);
			$this->assertEquals("SELECT * FROM `foo` GROUP BY `x` HAVING (`x` BETWEEN ? AND ?)", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE (`x` BETWEEN ? AND ?)")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1, 42])->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1, 43])->globally()->ordered();
			Select::cached($cache)->from("foo")->where()->between("x", 1, 42)->run();
			Select::cached($cache)->from("foo")->where()->between("x", 1, 43)->run();
		}

		public function testExists() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->exists()->from("bar")->where("x", ">", 42)->back();
			$this->assertEquals("SELECT * FROM `foo` WHERE EXISTS (SELECT 1 FROM `bar` WHERE `x` > ?)", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42])->andReturn(true)->globally()->ordered();
			$select->run();

			$select = (new Select())->from("foo")->join("bar")->on()->exists()->back();
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON EXISTS (SELECT 1)", $select->build());

			$select = (new Select())->from("foo")->groupBy("x")->having()->exists()->back();
			$this->assertEquals("SELECT * FROM `foo` GROUP BY `x` HAVING EXISTS (SELECT 1)", $select->build());
		}

		public function testNot() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->where()->not()->in("x", [1, 2, 3, 4]);
			$this->assertEquals("SELECT * FROM `foo` WHERE NOT (`x` IN(?,?,?,?))", $select->build());

			$select = (new Select())->from("foo")->where()->not()->between("x", 1, 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE NOT (`x` BETWEEN ? AND ?)", $select->build());

			$select = (new Select())->from("foo")->not()->exists()->from("bar")->where("x", ">", 42)->back();
			$this->assertEquals("SELECT * FROM `foo` WHERE NOT EXISTS (SELECT 1 FROM `bar` WHERE `x` > ?)", $select->build());

			$select = (new Select())->from("foo")->join("bar")->on()->not()->in("x", [1, 2, 3, 4]);
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON NOT (`x` IN(?,?,?,?))", $select->build());

			$select = (new Select())->from("foo")->groupBy("x")->having()->not()->in("x", [1, 2, 3, 4]);
			$this->assertEquals("SELECT * FROM `foo` GROUP BY `x` HAVING NOT (`x` IN(?,?,?,?))", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` GROUP BY `x` WITH ROLLUP HAVING NOT (`x` IN(?,?,?,?))")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1, 2, 3, 4])->andReturn(true)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([5, 6, 7, 8])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->groupBy("x")->rollup()->having()->not()->in("x", [1, 2, 3, 4])->run();
			Select::cached($cache)->from("foo")->groupBy("x")->rollup()->having()->not()->in("x", [5, 6, 7, 8])->run();
		}

		public function testGroupBy() {
			$select = (new Select())->from("foo")->fields("x")->countAll()->groupBy("x");
			$this->assertEquals("SELECT `x`, COUNT(*) FROM `foo` GROUP BY `x`", $select->build());

			$select = (new Select())->from("foo")->fields("x")->countAll()->groupBy("x", Select::DIRECTION_ASCENDING)->groupBy("y");
			$this->assertEquals("SELECT `x`, COUNT(*) FROM `foo` GROUP BY `x` ASC, `y`", $select->build());

			$select = (new Select())->from("foo")->fields("x")->countAll()->groupBy("x")->groupBy("y", Select::DIRECTION_DESCENDING);
			$this->assertEquals("SELECT `x`, COUNT(*) FROM `foo` GROUP BY `x`, `y` DESC", $select->build());
		}

		public function testRollup() {
			$select = (new Select())->from("foo")->fields("x")->countAll()->groupBy("x")->rollup();
			$this->assertEquals("SELECT `x`, COUNT(*) FROM `foo` GROUP BY `x` WITH ROLLUP", $select->build());
		}

		public function testHaving() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->countAll("x")->groupBy("y")->having("y", 2);
			$this->assertEquals("SELECT COUNT(`x`) FROM `foo` GROUP BY `y` HAVING `y` = ?", $select->build());

			$select = (new Select())->from("foo")->countAll("x")->groupBy("y")->having("y", 2)->having("z", 3);
			$this->assertEquals("SELECT COUNT(`x`) FROM `foo` GROUP BY `y` HAVING `y` = ? AND `z` = ?", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT COUNT(`x`) FROM `foo` GROUP BY `y` HAVING `y` = ? AND `z` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([2, 3])->andReturn(true)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 1337])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->countAll("x")->groupBy("y")->having("y", 2)->having("z", 3)->run();
			Select::cached($cache)->from("foo")->countAll("x")->groupBy("y")->having("y", 42)->having("z", 1337)->run();
		}

		public function testOrderBy() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->orderBy("x");
			$this->assertEquals("SELECT * FROM `foo` ORDER BY `x`", $select->build());

			$select = (new Select())->from("foo")->orderBy("x", Select::DIRECTION_DESCENDING);
			$this->assertEquals("SELECT * FROM `foo` ORDER BY `x` DESC", $select->build());

			$select = (new Select())->from("foo")->orderBy("x")->orderBy("y", Select::DIRECTION_ASCENDING);
			$this->assertEquals("SELECT * FROM `foo` ORDER BY `x`, `y` ASC", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` ORDER BY `x`, `y` ASC")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->orderBy("x")->orderBy("y", Select::DIRECTION_ASCENDING)->run();
			Select::cached($cache)->from("foo")->orderBy("x")->orderBy("y", Select::DIRECTION_ASCENDING)->run();
		}

		public function testLimit() {
			$select = (new Select())->from("foo")->limit(100);
			$this->assertEquals("SELECT * FROM `foo` LIMIT ?", $select->build());
		}

		public function testOffset() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->limit(100)->offset(42);
			$this->assertEquals("SELECT * FROM `foo` LIMIT ? OFFSET ?", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([100, 42])->andReturn(true)->globally()->ordered();
			$select->run();

			$select = (new Select())->from("foo")->offset(42);
			$this->assertEquals("SELECT * FROM `foo` LIMIT 18446744073709551615 OFFSET ?", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42])->andReturn(true)->globally()->ordered();
			$select->run();
		}

		public function testLock() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->lock(Select::LOCK_FOR_UPDATE);
			$this->assertEquals("SELECT * FROM `foo` FOR UPDATE", $select->build());

			$select = (new Select())->from("foo")->lock(Select::LOCK_IN_SHARE_MODE);
			$this->assertEquals("SELECT * FROM `foo` LOCK IN SHARE MODE", $select->build());

			Database::getDefault()->selectForUpdate = true;
			$select                                 = (new Select())->from("foo");
			$this->assertEquals("SELECT * FROM `foo` FOR UPDATE", $select->build());

			Database::getDefault()->selectForUpdate = false;
			$select                                 = (new Select())->from("foo");
			$this->assertEquals("SELECT * FROM `foo`", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` FOR UPDATE")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->lock(Select::LOCK_FOR_UPDATE)->run();
			Select::cached($cache)->from("foo")->lock(Select::LOCK_FOR_UPDATE)->run();
		}

		public function testForUpdate() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from("foo")->forUpdate();
			$this->assertEquals("SELECT * FROM `foo` FOR UPDATE", $select->build());

			Database::getDefault()->selectForUpdate = true;
			$select                                 = (new Select())->from("foo")->forUpdate(false);
			$this->assertEquals("SELECT * FROM `foo`", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once("SELECT * FROM `foo` FOR UPDATE")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->forUpdate()->run();
			Select::cached($cache)->from("foo")->forUpdate()->run();
		}

		public function testHasRelation() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->from(ModelStubA::class)->hasRelation("ModelStubRelations");
			$this->assertEquals("SELECT `ModelStubATable`.* FROM `ModelStubATable` WHERE EXISTS (SELECT 1 FROM `ModelStubRelations` WHERE `A` = `ModelStubATable`.`id`)", $select->build());

			$select = (new Select())->from(ModelStubA::class, "foobar")->hasRelation("ModelStubRelations");
			$this->assertEquals("SELECT `foobar`.* FROM `ModelStubATable` AS `foobar` WHERE EXISTS (SELECT 1 FROM `ModelStubRelations` WHERE `A` = `foobar`.`id`)", $select->build());

			$select = (new Select())->from(ModelStubA::class, "foobar")->hasRelation("ModelStubRelationsA");
			$this->assertEquals("SELECT `foobar`.* FROM `ModelStubATable` AS `foobar` WHERE EXISTS (SELECT 1 FROM `ModelStubRelationsA` WHERE `X` = `foobar`.`id` OR `Y` = `foobar`.`id`)", $select->build());

			$stubA = new ModelStubA();
			$stubA->id = 42;
			$stubA2 = new ModelStubA();
			$stubA2->id = 43;
			$stubB = new ModelStubB();
			$stubB->id = 1337;

			$select = (new Select())->from(ModelStubA::class, "foobar")->hasRelation("ModelStubRelations", $stubB);
			$this->assertEquals("SELECT `foobar`.* FROM `ModelStubATable` AS `foobar` WHERE EXISTS (SELECT 1 FROM `ModelStubRelations` WHERE `A` = `foobar`.`id` AND `B` = ?)", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1337])->andReturn(true)->globally()->ordered();
			$select->run();

			$select = (new Select())->from(ModelStubA::class, "foobar")->hasRelation("ModelStubRelationsA", $stubA);
			$this->assertEquals("SELECT `foobar`.* FROM `ModelStubATable` AS `foobar` WHERE EXISTS (SELECT 1 FROM `ModelStubRelationsA` WHERE (`X` = `foobar`.`id` AND `Y` = ?) OR (`Y` = `foobar`.`id` AND `X` = ?))", $select->build());
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 42])->andReturn(true)->globally()->ordered();
			$select->run();

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 42])->andReturn(true)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([43, 43])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from(ModelStubA::class, "foobar")->hasRelation("ModelStubRelationsA", $stubA)->run();
			Select::cached($cache)->from(ModelStubA::class, "foobar")->hasRelation("ModelStubRelationsA", $stubA2)->run();
		}

		public function testRaw() {
			$stmt = \Mockery::mock("PDOStatement");

			$select = (new Select())->raw("123");
			$this->assertEquals("SELECT 123", $select->build());

			$select = (new Select())->fields("a")->raw(", 123");
			$this->assertEquals("SELECT `a`, 123", $select->build());

			$select = (new Select())->from()->raw("bar");
			$this->assertEquals("SELECT * FROM bar", $select->build());

			$select = (new Select())->from("foo")->join()->raw("bar");
			$this->assertEquals("SELECT * FROM `foo` JOIN bar", $select->build());

			$select = (new Select())->from("foo")->join("bar")->on()->raw("bar.x = foo.id");
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON bar.x = foo.id", $select->build());

			$select = (new Select())->from("foo")->groupBy()->raw("x");
			$this->assertEquals("SELECT * FROM `foo` GROUP BY x", $select->build());

			$select = (new Select())->from("foo")->groupBy("x")->having()->raw("x >= 42");
			$this->assertEquals("SELECT * FROM `foo` GROUP BY `x` HAVING x >= 42", $select->build());

			$select = (new Select())->from("foo")->orderBy()->raw("x");
			$this->assertEquals("SELECT * FROM `foo` ORDER BY x", $select->build());

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` GROUP BY `x` HAVING x >= 42")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->andReturn(true)->globally()->ordered();
			Select::cached($cache)->from("foo")->groupBy("x")->having()->raw("x >= 42")->run();
			Select::cached($cache)->from("foo")->groupBy("x")->having()->raw("x >= 42")->run();
		}

		public function testSubquery() {
			$select = (new Select())->from("foo")->where(Select::conditionSubquery()->countAll()->from("bar")->where("bar.x", Select::conditionField("foo.id"))->back(), ">=", 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE (SELECT COUNT(*) FROM `bar` WHERE `bar`.`x` = `foo`.`id`) >= ?", $select->build());

			$select = (new Select())->from("foo")->where(Select::conditionSubquery()->countAll()->from("bar")->where("bar.x", Select::conditionField("foo.id"))->back(), ">=", Select::conditionSubquery()->countAll()->from("foobar")->where("foobar.z", Select::conditionField("foo.id"))->back());
			$this->assertEquals("SELECT * FROM `foo` WHERE (SELECT COUNT(*) FROM `bar` WHERE `bar`.`x` = `foo`.`id`) >= (SELECT COUNT(*) FROM `foobar` WHERE `foobar`.`z` = `foo`.`id`)", $select->build());
		}

		public function testVirtualCalls() {
			$select = (new Select())->from("foo")->xyzIs(42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `xyz` = ?", $select->build());

			$select = (new Select())->from("foo")->whereXyzIs(42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `xyz` = ?", $select->build());

			$select = (new Select())->from("foo")->xyzIsNot(42);
			$this->assertEquals("SELECT * FROM `foo` WHERE `xyz` != ?", $select->build());

			$select = (new Select())->from("foo")->xyzIsNot(null);
			$this->assertEquals("SELECT * FROM `foo` WHERE `xyz` IS NOT NULL", $select->build());

			$select = (new Select())->from("foo")->xyzIs(null);
			$this->assertEquals("SELECT * FROM `foo` WHERE `xyz` IS NULL", $select->build());

			$select = (new Select())->from("foo")->abcIs(1)->orXyzIs(null);
			$this->assertEquals("SELECT * FROM `foo` WHERE `abc` = ? OR `xyz` IS NULL", $select->build());

			$select = (new Select())->from("foo")->abcIn(1, 2, 3);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`abc` IN(?,?,?))", $select->build());

			$select = (new Select())->from("foo")->abcIn(1, 2, 3)->andXyzNotIn([42, 1337]);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`abc` IN(?,?,?)) AND NOT (`xyz` IN(?,?))", $select->build());

			$select = (new Select())->from("foo")->abcBetween(1, 42);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`abc` BETWEEN ? AND ?)", $select->build());

			$select = (new Select())->from("foo")->abcIn(1, 2, 3)->andXyzNotBetween(42, 1337);
			$this->assertEquals("SELECT * FROM `foo` WHERE (`abc` IN(?,?,?)) AND NOT (`xyz` BETWEEN ? AND ?)", $select->build());

			$select = (new Select())->from("foo")->groupBy("x")->havingXNotBetween(1, 42);
			$this->assertEquals("SELECT * FROM `foo` GROUP BY `x` HAVING NOT (`x` BETWEEN ? AND ?)", $select->build());

			$select = (new Select())->from("foo")->join("bar")->onXIs(5);
			$this->assertEquals("SELECT * FROM `foo` JOIN `bar` ON `x` = ?", $select->build());
		}

		public function testUnknownVirtualCall() {
			$this->expectException(\BadMethodCallException::class);
			$select = (new Select())->garbage();
		}

		public function testMalformedVirtualCall() {
			$this->expectException(\BadMethodCallException::class);
			$select = (new Select())->from("foo")->whereXyIsOt(1);
		}

		public function testUnique() {
			$stmt = \Mockery::mock("PDOStatement");
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ? LIMIT ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 1])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "abc" => 1])->globally()->ordered();
			$this->assertEquals(["x" => 42, "abc" => 1], (new Select())->from("foo")->where("x", 42)->unique());

			$object     = new \stdClass();
			$object->id = 1337;
			$object->x  = 42;
			$object->b  = 1;

			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT `ModelStubATable`.* FROM `ModelStubATable` WHERE `x` = ? LIMIT ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 1])->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object)->globally()->ordered();
			$this->assertInstanceOf(ModelStubA::class, $result = (new Select())->from(ModelStubA::class)->where("x", 42)->unique());
			$this->assertEquals(42, $result->x);
			$this->assertEquals(1337, $result->id);
		}

		public function testUniqueModelWithoutID() {
			$stmt      = \Mockery::mock("PDOStatement");
			$object    = new \stdClass();
			$object->x = 42;
			$object->b = 1;

			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT `ModelStubATable`.* FROM `ModelStubATable` WHERE `x` = ? LIMIT ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 1])->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object)->globally()->ordered();

			$this->expectException(InvalidSQLException::class);
			(new Select())->from(ModelStubA::class)->where("x", 42)->unique();
		}

		public function testRun() {
			$stmt = \Mockery::mock("PDOStatement");
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1337])->globally()->ordered();
			(new Select())->from("foo")->where("x", 42)->run([1337]);
		}

		public function testExecute() {
			$stmt = \Mockery::mock("PDOStatement");
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42])->globally()->ordered();
			$stmt->shouldReceive("fetchAll")->once()->andReturn([["x" => 42, "abc" => 1], ["x" => 42, "abc" => 2]])->globally()->ordered();
			$this->assertEquals([["x" => 42, "abc" => 1], ["x" => 42, "abc" => 2]], (new Select())->from("foo")->where("x", 42)->execute());

			$object1     = new \stdClass();
			$object1->id = 1;
			$object1->x  = 42;

			$object2     = new \stdClass();
			$object2->id = 21;
			$object2->x  = 42;

			$stmt = \Mockery::mock("PDOStatement");
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT `ModelStubATable`.* FROM `ModelStubATable` WHERE `x` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42])->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object1)->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object2)->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn(null)->globally()->ordered();

			$result = (new Select())->from(ModelStubA::class)->where("x", 42)->execute();
			$this->assertTrue(isset($result[1]));
			$this->assertTrue(isset($result[21]));
			$this->assertInstanceOf(ModelStubA::class, $result[1]);
			$this->assertInstanceOf(ModelStubA::class, $result[21]);
			$this->assertEquals(1, $result[1]->id);
			$this->assertEquals(42, $result[1]->x);
			$this->assertEquals(21, $result[21]->id);
			$this->assertEquals(42, $result[21]->x);
		}

		public function testExecuteModelWithoutID() {
			$object1     = new \stdClass();
			$object1->x  = 42;

			$stmt = \Mockery::mock("PDOStatement");
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT `ModelStubATable`.* FROM `ModelStubATable` WHERE `x` = ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42])->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object1)->globally()->ordered();

			$this->expectException(InvalidSQLException::class);
			(new Select())->from(ModelStubA::class)->where("x", 42)->execute();
		}

		public function testGenerate() {
			$stmt = \Mockery::mock("PDOStatement");
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ? LIMIT ? OFFSET ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 0])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 1])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 2])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(null)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 2])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 3])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 4])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(null)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 4])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 5])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(null)->globally()->ordered();

			$i = 0;
			foreach((new Select())->from("foo")->where("x", 42)->generate([], 2) as $result) {
				$this->assertEquals(++$i, $result["id"]);
				$this->assertEquals(42, $result["x"]);
			}

			$object1 = new \stdClass();
			$object1->id = 1;
			$object1->x = 42;
			$object2 = clone $object1;
			$object2->id++;
			$object3 = clone $object2;
			$object3->id++;
			$object4 = clone $object3;
			$object4->id++;
			$object5 = clone $object4;
			$object5->id++;

			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT `ModelStubATable`.* FROM `ModelStubATable` WHERE `x` = ? LIMIT ? OFFSET ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 0])->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object1)->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object2)->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn(null)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 2])->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object3)->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object4)->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn(null)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 4])->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn($object5)->globally()->ordered();
			$stmt->shouldReceive("fetchObject")->once()->andReturn(null)->globally()->ordered();

			$i = 0;
			foreach((new Select())->from(ModelStubA::class)->where("x", 42)->generate([], 2) as $result) {
				$this->assertInstanceOf(ModelStubA::class, $result);
				$this->assertEquals(++$i, $result->id);
				$this->assertEquals(42, $result->x);
			}

			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ? LIMIT ? OFFSET ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 0])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 1])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 2])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(null)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 1, 2])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 3])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(null)->globally()->ordered();

			$i = 0;
			foreach((new Select())->from("foo")->where("x", 42)->limit(3)->generate([], 2) as $result) {
				$this->assertEquals(++$i, $result["id"]);
				$this->assertEquals(42, $result["x"]);
			}

			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` = ? LIMIT ? OFFSET ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 0])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 1])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 2])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(null)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([42, 2, 2])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 3])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(["x" => 42, "id" => 4])->globally()->ordered();
			$stmt->shouldReceive("fetch")->once()->andReturn(null)->globally()->ordered();

			$i = 0;
			foreach((new Select())->from("foo")->where("x", 42)->limit(4)->generate([], 2) as $result) {
				$this->assertEquals(++$i, $result["id"]);
				$this->assertEquals(42, $result["x"]);
			}
		}

		public function testCache() {
			$cache = null;

			$stmt = \Mockery::mock("PDOStatement");
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo` WHERE `x` >= ?")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([0])->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([1])->globally()->ordered();
			$stmt->shouldReceive("execute")->once()->with([2])->globally()->ordered();

			for($i = 0; $i < 3; $i++)
				Select::cached($cache)->from("foo")->where("x", ">=", $i)->run();

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT COUNT(*) FROM `foo`")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->countAll()->from("foo")->run();
			Select::cached($cache)->countAll()->from("foo")->run();

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT COUNT(DISTINCT `bar`) FROM `foo`")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->countDistinct("bar")->from("foo")->run();
			Select::cached($cache)->countDistinct("bar")->from("foo")->run();

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT DISTINCT * FROM `foo`")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->distinct()->from("foo")->run();
			Select::cached($cache)->distinct()->from("foo")->run();

			$cache = null;
			$this->mockDatabase->shouldReceive("prepare")->once()->with("SELECT * FROM `foo`")->andReturn($stmt)->globally()->ordered();
			$stmt->shouldReceive("execute")->twice()->with([])->globally()->ordered();
			Select::cached($cache)->database($this->mockDatabase)->from("foo")->run();
			Select::cached($cache)->database($this->mockDatabase)->from("foo")->run();
		}

		public function testCacheInvalidPrepare() {
			$this->expectException(\BadMethodCallException::class);
			$cache = null;

			Select::cached($cache);
			$cache->prepare();
		}

		public function testCacheInvalidBuild() {
			$this->expectException(\BadMethodCallException::class);
			$cache = null;

			Select::cached($cache);
			$cache->build();
		}
	}

	class ModelStubA {
		use Model;

		public $x;

		const TABLE        = "ModelStubATable";
		const FOREIGN_KEYS = ["b" => ModelStubB::class];
		const RELATIONS    = ["ModelStubRelations" => "A", "ModelStubRelationsA" => ["X", "Y"]];
	}

	class ModelStubB {
		use Model;

		const TABLE        = "ModelStubBTable";
		const FOREIGN_KEYS = ["a" => ModelStubA::class];
		const RELATIONS    = ["ModelStubRelations" => "B"];
	}

