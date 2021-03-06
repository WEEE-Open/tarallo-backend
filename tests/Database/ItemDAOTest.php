<?php

namespace WEEEOpen\TaralloTest\Database;

use WEEEOpen\Tarallo\Database\DuplicateItemCodeException;
use WEEEOpen\Tarallo\Feature;
use WEEEOpen\Tarallo\Item;
use WEEEOpen\Tarallo\ItemCode;
use WEEEOpen\Tarallo\ItemTraitCode;
use WEEEOpen\Tarallo\ItemTraitOptionalCode;
use WEEEOpen\Tarallo\NotFoundException;
use WEEEOpen\Tarallo\Product;
use WEEEOpen\Tarallo\ValidationException;

class ItemDAOTest extends DatabaseTest {
	/**
	 * Database tests are really slow and this code is a bit complex to say the least, testing everything
	 * in a sensible manner will be difficult. But some tests are better than no tests at all, right?
	 *
	 * @covers \WEEEOpen\Tarallo\Database\Database
	 * @covers \WEEEOpen\Tarallo\Database\FeatureDAO
	 * @covers \WEEEOpen\Tarallo\Database\TreeDAO
	 */
	public function testAddingAndRetrievingSomeItems() {
		$db = $this->getDb();
		/** @var $case Item */ // PHPStorm suddenly doesn't recognize chained methods. Only the last one of every chain, specifically.
		$case = (new Item('PC42'))
			->addFeature(new Feature('brand', 'TI'))
			->addFeature(new Feature('model', 'GreyPC-\'98'))
			->addFeature(new Feature('type', 'case'))
			->addFeature(new Feature('motherboard-form-factor', 'atx'));
		$discone1 = (new Item('SATAna1'))
			->addFeature(new Feature('capacity-byte', 666))
			->addFeature(new Feature('brand', 'SATAn Storage Corporation Inc.'))
			->addFeature(new Feature('model', 'Discone da 666 byte'))
			->addFeature(new Feature('type', 'hdd'));
		$discone2 = (new Item('SATAna2'))
			->addFeature(new Feature('capacity-byte', 666))
			->addFeature(new Feature('brand', 'SATAn Storage Corporation Inc.'))
			->addFeature(new Feature('model', 'Discone da 666 byte'))
			->addFeature(new Feature('type', 'hdd'));
		$case->addContent($discone1);
		$case->addContent($discone2);
		$db->itemDAO()->addItem($case);

		$newCase = $db->itemDAO()->getItem(new ItemCode('PC42'));
		$this->assertInstanceOf(Item::class, $newCase);
		/** @var Item $newCase */
		$this->assertEquals(2, count($newCase->getContent()), 'Two child Item');
		$this->assertContainsOnly(Item::class, $newCase->getContent(), null, 'Only Items are contained in an Item');
		foreach($newCase->getContent() as $child) {
			/** @var Item $child */
			$this->assertTrue(
				$child->getCode() === 'SATAna1' || $child->getCode() === 'SATAna2',
				'Sub-Item is one of the two expected items, ' . (string) $child
			);
			// this works because the two items are identical except for the code...
			$newFeatures = $child->getFeatures();
			$oldFeatures = $case->getContent()[0]->getFeatures();
			$this->assertEquals(count($oldFeatures), count($newFeatures), 'Feature count should be unchanged');
			foreach($oldFeatures as $name => $feature) {
				$value = $feature->value;
				$this->assertTrue(isset($newFeatures[$name]), "Feature $name should still exist");
				$this->assertEquals(
					$value, $newFeatures[$name]->value,
					"Sub-Item $child should have $name=$value as before"
				);
			}
			$this->assertTrue(empty($child->getContent()), "No children of child Item $child should exist");
		}
	}


	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testDeleteItem() {
		$db = $this->getDb();
		$case = new Item('PC42');
		$db->itemDAO()->addItem($case);

		$deleteMe = new ItemCode('PC42');

		$this->assertTrue($db->itemDAO()->itemExists($deleteMe), 'Item should exist before deletion');
		$this->assertTrue($db->itemDAO()->itemVisible($deleteMe), 'Item shouldn be visible before deletion');
		$this->assertNull($db->itemDAO()->itemDeletedAt($deleteMe), 'Item shouldn\'t have been deleted');
		$beforeTime = new \DateTime();

		$db->itemDAO()->deleteItem($deleteMe);

		$this->assertTrue($db->itemDAO()->itemExists($deleteMe), 'Item should still exist');
		$this->assertFalse($db->itemDAO()->itemVisible($deleteMe), 'Item shouldn\'t be visible');
		$afterTime = new \DateTime($db->itemDAO()->itemDeletedAt($deleteMe), new \DateTimeZone('UTC'));
		$this->assertGreaterThanOrEqual(
			0, $afterTime->getTimestamp() - $beforeTime->getTimestamp(),
			'Item should have a valid deletion date'
		);
		$this->assertInstanceOf(Item::class, $db->itemDAO()->getItem($deleteMe));
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testDeleteItemTwice() {
		$db = $this->getDb();
		$case = new Item('PC42');
		$db->itemDAO()->addItem($case);

		$deleteMe = new ItemCode('PC42');
		$db->itemDAO()->deleteItem($deleteMe);
		$this->assertTrue($db->itemDAO()->itemExists($deleteMe), 'Item should still exist');
		$this->assertFalse($db->itemDAO()->itemVisible($deleteMe), 'Item shouldn\'t be visible');

		$this->expectException(NotFoundException::class);
		$db->itemDAO()->deleteItem($deleteMe);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testDeleteItemWithContents() {
		$db = $this->getDb();
		$case = new Item('PC42');
		$mobo = new Item('MOBO42');
		$case->addContent($mobo);
		$db->itemDAO()->addItem($case);

		$deleteMe = new ItemCode('PC42');

		$this->expectException(ValidationException::class);
		$db->itemDAO()->deleteItem($deleteMe);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testUndelete() {
		$db = $this->getDb();
		$case = new Item('PC42');
		$mobo = new Item('MOBO42');
		$case->addContent($mobo);
		$mobo->addFeature(new Feature('color', 'green'));
		$db->itemDAO()->addItem($case);

		$saveMe = new ItemCode('MOBO42');
		$db->itemDAO()->deleteItem($saveMe);

		$db->itemDAO()->undelete($saveMe);

		$this->assertTrue($db->itemDAO()->itemExists($saveMe), 'Item should still exist');
		$this->assertTrue($db->itemDAO()->itemVisible($saveMe), 'Item should be visible');
		$this->assertNull($db->itemDAO()->itemDeletedAt($saveMe), 'Item shouldn\'t have a deletion date');

		$recovered = $db->itemDAO()->getItem($saveMe);
		$this->assertInstanceOf(Item::class, $recovered, 'Can grab item from database');
		$this->assertEmpty($recovered->getPath(), 'Item isn\'t placed anywhere');
		$this->assertEquals(
			1, count($recovered->getFeatures()),
			'Item contains the same number of features it contained before'
		);

		// Prevent integrity constraint violations due to duplicate audit entries...
		$this->getPdo()
			->query("UPDATE Audit SET `Time` = DATE_SUB(`Time`, INTERVAL 1 SECOND) WHERE `Code` = 'MOBO42' AND `Change` = 'M' ORDER BY `Time` DESC LIMIT 1");
		$db->treeDAO()->moveItem($saveMe, $case);
		$recovered = $db->itemDAO()->getItem($saveMe);
		$this->assertEquals(1, count($recovered->getPath()), 'Item can be moved somewhere');
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testUndeleteExistingItem() {
		$db = $this->getDb();
		$case = new Item('PC42');
		$db->itemDAO()->addItem($case);

		$deleteMe = new ItemCode('PC42');
		$this->expectException(NotFoundException::class);
		$db->itemDAO()->undelete($deleteMe);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testUndeleteNonExistingItem() {
		$db = $this->getDb();
		$case = new Item('PC42');
		$db->itemDAO()->addItem($case);

		$deleteMe = new ItemCode('NOTEXISTING');
		$this->expectException(NotFoundException::class);
		$db->itemDAO()->undelete($deleteMe);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testDuplicateCode() {
		$db = $this->getDb();
		$case = new Item('PC42');
		$db->itemDAO()->addItem($case);

		$case = new Item('PC42');
		$this->expectException(DuplicateItemCodeException::class);
		$db->itemDAO()->addItem($case);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testNonExistingItem() {
		$db = $this->getDb();

		$notHere = new ItemCode('PC9001');
		$this->assertFalse($db->itemDAO()->itemExists($notHere), 'Item shouldn\'t exist');
		$this->assertFalse($db->itemDAO()->itemVisible($notHere), 'Item shouldn\'t be recoverable');
		$this->assertNull($db->itemDAO()->itemDeletedAt($notHere), 'Item shouldn\'t be marked as deleted');
		$this->expectException(NotFoundException::class);
		$db->itemDAO()->getItem($notHere);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testAutogeneratedCodes() {
		$db = $this->getDb();
		$keyboard = (new Item(null))->addFeature(new Feature('type', 'keyboard'));
		$mouse = (new Item(null))->addFeature(new Feature('type', 'mouse'));

		$this->assertFalse($keyboard->hasCode());
		$this->assertFalse($mouse->hasCode());

		$db->itemDAO()->addItem($keyboard);
		$db->itemDAO()->addItem($mouse);

		$this->assertTrue($keyboard->hasCode());
		$this->assertTrue($mouse->hasCode());
		$this->assertEquals('T76', $keyboard->getCode());
		$this->assertEquals('M11', $mouse->getCode());
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testGettingPrefixesSkippingDuplicates() {
		$db = $this->getDb();

		$keyboardz = [];
		for($i = 74; $i < 77; $i++) {
			$keyboardz[] = (new Item('T' . $i))->addFeature(new Feature('type', 'keyboard'));
		}
		$keyboardz[] = $keyboardWithNoCode = (new Item(null))->addFeature(new Feature('type', 'keyboard'));

		$this->assertFalse($keyboardWithNoCode->hasCode());
		foreach($keyboardz as $k) {
			$db->itemDAO()->addItem($k);
		}

		$this->assertTrue($keyboardWithNoCode->hasCode());
		$this->assertEquals('T77', $keyboardWithNoCode->getCode());
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testAddItemToken() {
		$db = $this->getDb();
		$case = (new Item('PC42'))->addFeature(new Feature('motherboard-form-factor', 'atx'));
		$case->setToken('this-is-a-token');
		$db->itemDAO()->addItem($case);

		$newCase = $db->itemDAO()->getItem(new ItemCode('PC42'));
		$this->assertInstanceOf(Item::class, $newCase);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testGetItemToken() {
		$db = $this->getDb();
		$case = (new Item('PC42'))->addFeature(new Feature('motherboard-form-factor', 'atx'));
		$case->setToken('this-is-a-token');
		$db->itemDAO()->addItem($case);

		$getMe = new ItemCode('PC42');
		$newCase = $db->itemDAO()->getItem($getMe, 'this-is-a-token');
		$this->assertInstanceOf(Item::class, $newCase);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testGetItemWrongToken() {
		$db = $this->getDb();
		$case = (new Item('PC42'))->addFeature(new Feature('motherboard-form-factor', 'atx'));
		$case->setToken('this-is-a-token');
		$db->itemDAO()->addItem($case);

		$getMe = new ItemCode('PC42');
		$this->expectException(NotFoundException::class);
		$db->itemDAO()->getItem($getMe, 'WRONGWRONGWRONG');
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 * @covers \WEEEOpen\Tarallo\Item
	 */
	public function testItemSerializable() {
		$db = $this->getDb();

		$where = (new Item('Chernobyl'));
		$where2 = (new Item('Tavolo'));
		$where3 = (new Item('ZonaBlu'));
		$where->addContent($where2)->addContent($where3);

		$case = (new Item('PC42'))
			->addFeature(new Feature('type', 'case'))
			->addFeature(new Feature('motherboard-form-factor', 'atx'));
		$discone1 = (new Item('SATAna1'))
			->addFeature(new Feature('capacity-byte', 666))
			->addFeature(new Feature('brand', 'SATAn Storage Corporation Inc.'))
			->addFeature(new Feature('model', 'Discone da 666 byte'))
			->addFeature(new Feature('type', 'hdd'));
		$discone2 = (new Item('SATAna2'))
			->addFeature(new Feature('capacity-byte', 666))
			->addFeature(new Feature('brand', 'SATAn Storage Corporation Inc.'))
			->addFeature(new Feature('model', 'Discone da 666 byte'))
			->addFeature(new Feature('type', 'hdd'));
		$case->addContent($discone1)->addContent($discone2);
		$where->addContent($case);

		$db->itemDAO()->addItem($where);
		$getMe = new ItemCode('PC42');
		$result = $db->itemDAO()->getItem($getMe);
		json_encode($result);
		$this->assertEquals(JSON_ERROR_NONE, json_last_error());
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 * @covers \WEEEOpen\Tarallo\Item
	 * @covers \WEEEOpen\Tarallo\Product
	 * @noinspection DuplicatedCode
	 */
	public function testItemWithProductSerializable() {
		$db = $this->getDb();

		$where = (new Item('Chernobyl'));

		$product = (new Product('AsStone', 'AS-125B-X', 'default'))
			->addFeature(new Feature('brand', 'AsStone'))
			->addFeature(new Feature('model', 'AS-125B-X'))
			->addFeature(new Feature('variant', 'default'))
			->addFeature(new Feature('type', 'motherboard'))
			->addFeature(new Feature('color', 'brown'));
		$db->productDAO()->addProduct($product);

		$mobo = (new Item('B123'))
			->addFeature(new Feature('brand', 'AsStone'))
			->addFeature(new Feature('model', 'AS-125B-X'))
			->addFeature(new Feature('variant', 'default'))
			->addFeature(new Feature('working', 'yes'));
		$cpu = (new Item('C123'))
			->addFeature(new Feature('brand', "Intel"))
			->addFeature(new Feature('frequency-hertz', (int) (2.67 * 1000 * 1000 * 1000)))
			->addFeature(new Feature('type', 'cpu'));
		$mobo->addContent($cpu);
		$where->addContent($mobo);

		$db->itemDAO()->addItem($where);

		$getMe = new ItemCode('Chernobyl');
		$result = $db->itemDAO()->getItem($getMe);

		$json = json_encode($result);
		$this->assertEquals(JSON_ERROR_NONE, json_last_error());

		$decoded = json_decode($json, true);
		$this->assertArrayHasKey("contents", $decoded);
		$this->assertCount(1, $decoded["contents"]);
		$this->assertArrayHasKey("code", $decoded["contents"][0]);
		$this->assertEquals("B123", $decoded["contents"][0]["code"]);
		$this->assertArrayNotHasKey("product", $decoded["contents"][0]);
		$this->assertArrayHasKey("features", $decoded["contents"][0]);
		$this->assertArrayHasKey("color", $decoded["contents"][0]["features"]);
		$this->assertEquals("brown", $decoded["contents"][0]["features"]["color"]);

		$result->setSeparate();
		$json = json_encode($result);

		$this->assertEquals(JSON_ERROR_NONE, json_last_error());
		$decoded = json_decode($json, true);
		$this->assertArrayHasKey("contents", $decoded);
		$this->assertCount(1, $decoded["contents"]);
		$this->assertArrayHasKey("code", $decoded["contents"][0]);
		$this->assertEquals("B123", $decoded["contents"][0]["code"]);
		$this->assertArrayHasKey("product", $decoded["contents"][0]);
		$this->assertArrayHasKey("features", $decoded["contents"][0]["product"]);
		$this->assertArrayHasKey("color", $decoded["contents"][0]["product"]["features"]);
		$this->assertEquals("brown", $decoded["contents"][0]["product"]["features"]["color"]);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 * @covers \WEEEOpen\Tarallo\Item
	 */
	public function testLostItem() {
		$db = $this->getDb();

		$where = (new Item('Chernobyl'));

		$mobo = (new Item('B123'))
			->addFeature(new Feature('brand', 'AsStone'))
			->addFeature(new Feature('model', 'AS-125B-X'))
			->addFeature(new Feature('variant', 'default'))
			->addFeature(new Feature('type', 'motherboard'))
			->addFeature(new Feature('working', 'yes'));
		$cpu = (new Item('C123'))
			->addFeature(new Feature('brand', "Intel"))
			->addFeature(new Feature('frequency-hertz', (int) (2.67 * 1000 * 1000 * 1000)))
			->addFeature(new Feature('type', 'cpu'));
		$mobo->addContent($cpu);
		$where->addContent($mobo);

		$db->itemDAO()->addItem($where);

		$getMe = new ItemCode('C123');
		$result = $db->itemDAO()->getItem($getMe);
		$this->assertEquals('B123', $result->getParent()->getCode());

		$db->itemDAO()->loseItem($getMe);

		$getMe = new ItemCode('C123');
		$result = $db->itemDAO()->getItem($getMe);
		$this->assertNull($result->getParent());

		$this->assertNotNull($result->getLostAt());
		$this->assertNull($result->getDeletedAt());

		$json = json_encode($result);
		$this->assertEquals(JSON_ERROR_NONE, json_last_error());
		$decoded = json_decode($json, true);

		$this->assertArrayHasKey("lost_at", $decoded);
		$this->assertArrayNotHasKey("deleted_at", $decoded);
		$this->assertIsString($decoded["lost_at"]);
		$this->assertArrayNotHasKey("parent", $decoded);
		$this->assertArrayNotHasKey("contents", $decoded);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 * @covers \WEEEOpen\Tarallo\Item
	 */
	public function testDeletedItem() {
		$db = $this->getDb();

		$where = (new Item('Chernobyl'));

		$mobo = (new Item('B321'))
			->addFeature(new Feature('brand', 'AsStone'))
			->addFeature(new Feature('model', 'AS-125B-X'))
			->addFeature(new Feature('variant', 'default'))
			->addFeature(new Feature('type', 'motherboard'))
			->addFeature(new Feature('working', 'yes'));
		$cpu = (new Item('C321'))
			->addFeature(new Feature('brand', "Intel"))
			->addFeature(new Feature('frequency-hertz', (int) (2.50 * 1000 * 1000 * 1000)))
			->addFeature(new Feature('type', 'cpu'));
		$mobo->addContent($cpu);
		$where->addContent($mobo);
		$db->itemDAO()->addItem($where);

		$getMe = new ItemCode('C321');
		$result = $db->itemDAO()->getItem($getMe);
		$this->assertEquals('B321', $result->getParent()->getCode());

		$db->itemDAO()->deleteItem($getMe);

		$getMe = new ItemCode('C321');
		$result = $db->itemDAO()->getItem($getMe);
		$this->assertNull($result->getParent());

		$this->assertNull($result->getLostAt());
		$this->assertNotNull($result->getDeletedAt());

		$json = json_encode($result);
		$this->assertEquals(JSON_ERROR_NONE, json_last_error());
		$decoded = json_decode($json, true);

		$this->assertArrayHasKey("deleted_at", $decoded);
		$this->assertArrayNotHasKey("lost_at", $decoded);
		$this->assertIsString($decoded["deleted_at"]);
		$this->assertArrayNotHasKey("parent", $decoded);
		$this->assertArrayNotHasKey("contents", $decoded);
	}

	/**
	* @covers \WEEEOpen\Tarallo\Database\ItemDAO
	*/
	public function testCannotLoseIntermediateItem() {
		$db = $this->getDb();

		$where = (new Item('Chernobyl'));

		$mobo = (new Item('B123'))
			->addFeature(new Feature('brand', 'AsStone'))
			->addFeature(new Feature('model', 'AS-125C-X'))
			->addFeature(new Feature('variant', 'default'))
			->addFeature(new Feature('type', 'motherboard'))
			->addFeature(new Feature('working', 'yes'));
		$cpu = (new Item('C123'))
			->addFeature(new Feature('brand', "Intel"))
			->addFeature(new Feature('frequency-hertz', (int) (2.67 * 1000 * 1000 * 1000)))
			->addFeature(new Feature('type', 'cpu'));
		$mobo->addContent($cpu);
		$where->addContent($mobo);
		$db->itemDAO()->addItem($where);

		$this->expectException(ValidationException::class);
		$db->itemDAO()->loseItem(new ItemCode('B123'));
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testCannotDeleteIntermediateItem() {
		$db = $this->getDb();

		$where = (new Item('Chernobyl'));

		$mobo = (new Item('B123'))
			->addFeature(new Feature('brand', 'AsStone'))
			->addFeature(new Feature('model', 'AS-126C-X'))
			->addFeature(new Feature('variant', 'default'))
			->addFeature(new Feature('type', 'motherboard'))
			->addFeature(new Feature('working', 'yes'));
		$cpu = (new Item('C222'))
			->addFeature(new Feature('brand', "Intel"))
			->addFeature(new Feature('frequency-hertz', (int) (2.67 * 1000 * 1000 * 1000)))
			->addFeature(new Feature('type', 'cpu'));
		$mobo->addContent($cpu);
		$where->addContent($mobo);

		$db->itemDAO()->addItem($where);

		$this->expectException(ValidationException::class);
		$db->itemDAO()->deleteItem(new ItemCode('B123'));
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testCannotLoseNotExistingItem() {
		$db = $this->getDb();

		$this->expectException(NotFoundException::class);
		$db->itemDAO()->deleteItem(new ItemCode('DOESNOTEXIST'));
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Database\ItemDAO
	 */
	public function testCannotDeleteNotExistingItem() {
		$db = $this->getDb();

		$this->expectException(NotFoundException::class);
		$db->itemDAO()->deleteItem(new ItemCode('DOESNOTEXIST'));
	}
}