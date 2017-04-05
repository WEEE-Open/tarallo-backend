<?php

namespace WEEEOpen\Tarallo\Test\Query;

use PHPUnit\Framework\TestCase;
use WEEEOpen\Tarallo\Query\GetQuery;
use WEEEOpen\Tarallo;
use WEEEOpen\Tarallo\InvalidParameterException;

/*
 * Why are there tests covering classes other than GetQuery?
 * Well, that functionality could have been implemented in GetQuery, even if
 * it's elsewhere, it should be marked as tested and covered.
 * The important thing is that those query strings should give the expected
 * results (exceptions, etc...), writing a million tests for every method
 * in every abstract class that shouldn't even be accessed durectly by the
 * application seems a bit pointless. Maybe these tests aren't of impeccable
 * quality, but they're better than no tests at all and I can change how
 * GetQuery implements its functionality and see if I've broken anything
 * without rewriting half of the tests, which is the whole point of this
 * effort...
 */
class GetQueryTest extends TestCase {

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @covers \WEEEOpen\Tarallo\Query\AbstractQuery
	 */
	public function testBuildEmptyQuery() {
		$this->expectException(InvalidParameterException::class);
		new GetQuery('');
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @covers \WEEEOpen\Tarallo\Query\AbstractQuery
	 */
	public function testBuildNullQuery() {
		$this->expectException(InvalidParameterException::class);
		new GetQuery(null);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @covers \WEEEOpen\Tarallo\Query\AbstractQuery
	 * @uses   \WEEEOpen\Tarallo\User
	 */
	public function testRunEmptyQuery() {
		$this->expectException(InvalidParameterException::class);
		$db = $this->createMock(Tarallo\Database\Database::class);
		$query = new GetQuery('');
		$query->run(new Tarallo\User('example', 'example'), $db);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @covers \WEEEOpen\Tarallo\Query\AbstractQuery
	 * @uses   \WEEEOpen\Tarallo\User
	 */
	public function testRunNullQuery() {
		$this->expectException(InvalidParameterException::class);
		$db = $this->createMock(Tarallo\Database\Database::class);
		$query = new GetQuery(null);
		$query->run(new Tarallo\User('example', 'example'), $db);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @covers \WEEEOpen\Tarallo\Query\AbstractQuery
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Location
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Multifield
	 * @uses   \WEEEOpen\Tarallo\Query\PostJSONQuery
	 * @uses   \WEEEOpen\Tarallo\User
	 */
	public function testNullDatabaseError() {
		$this->expectException(\TypeError::class);
		(new GetQuery('/Location/foo'))->run(new Tarallo\User('example', 'example'), null);
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Location
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Multifield
	 * @uses   \WEEEOpen\Tarallo\Query\AbstractQuery
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Depth
	 * @covers \WEEEOpen\Tarallo\Query\Field\AbstractQueryField
	 */
	public function testDuplicateField() {
		$this->expectException(InvalidParameterException::class);
		new GetQuery('/Depth/2/Depth/6');
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Location
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Multifield
	 * @uses   \WEEEOpen\Tarallo\Query\AbstractQuery
	 */
	public function testMissingParameter() {
		$this->expectException(InvalidParameterException::class);
		new GetQuery('/Location');
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Location
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Multifield
	 * @uses   \WEEEOpen\Tarallo\Query\AbstractQuery
	 * @uses   \WEEEOpen\Tarallo\Query\Field\Depth
	 */
	public function testMissingParameterMismatch() {
		$this->expectException(InvalidParameterException::class);
		new GetQuery('/Location/Depth/3');
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @uses   \WEEEOpen\Tarallo\Query\AbstractQuery
	 */
	public function testUnrecognizedField() {
		$this->expectException(InvalidParameterException::class);
		new GetQuery('/NotAField/test');
	}

	/**
	 * @covers \WEEEOpen\Tarallo\Query\GetQuery
	 * @uses   \WEEEOpen\Tarallo\Query\AbstractQuery
	 */
	public function testEmptyQueryString() {
		$this->expectException(InvalidParameterException::class);
		new GetQuery('');
	}

	/**
	 * @dataProvider   providerTestUnchangedValidNonDefaultStrings
	 *
	 * @covers         \WEEEOpen\Tarallo\Query\GetQuery
	 * @covers         \WEEEOpen\Tarallo\Query\Field\Location
	 * @covers         \WEEEOpen\Tarallo\Query\Field\Depth
	 * @covers         \WEEEOpen\Tarallo\Query\Field\Language
	 * @covers         \WEEEOpen\Tarallo\Query\Field\ParentField
	 * @covers         \WEEEOpen\Tarallo\Query\Field\Search
	 * @covers         \WEEEOpen\Tarallo\Query\Field\Sort
	 * @covers         \WEEEOpen\Tarallo\Query\Field\Token
	 * @covers         \WEEEOpen\Tarallo\Query\Field\AbstractQueryField
	 * @uses           \WEEEOpen\Tarallo\Query\Field\Multifield
	 * @uses           \WEEEOpen\Tarallo\Query\AbstractQuery
	 *
	 * @param $string GetQuery string
	 */
	public function testUnchangedValidNonDefaultStrings($string) {
		$this->assertEquals($string, (string) new GetQuery($string));
	}


	public function providerTestUnchangedValidNonDefaultStrings() {
		return [
			['/Location/test'],
			['/Location/test/Depth/3'],
			['/Location/test/Language/fr-FR'],
			['/Location/test/Parent/2'],
			['/Location/test/Search/key=foo'],
			['/Location/test/Sort/+key'],
			['/Location/test/Token/foo'],
		];
	}

	/**
	 * @covers         \WEEEOpen\Tarallo\Query\GetQuery
	 * @covers         \WEEEOpen\Tarallo\Query\Field\Multifield
	 * @uses           \WEEEOpen\Tarallo\Query\Field\Location
	 * @uses           \WEEEOpen\Tarallo\Query\Field\AbstractQueryField
	 * @uses           \WEEEOpen\Tarallo\Query\AbstractQuery
	 */
	public function testMultipleFields() {
		$this->assertEquals('/Location/foo/Location/bar', (string) new GetQuery('/Location/foo/Location/bar'));
	}
}