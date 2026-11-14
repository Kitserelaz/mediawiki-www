<?php

declare( strict_types = 1 );

namespace Wikibase\Repo\Tests\Api;

use MediaWiki\Api\ApiUsageException;

/**
 * Test case for language attributes API modules.
 *
 * @license GPL-2.0-or-later
 * @author Addshore
 */
abstract class ModifyTermTestCase extends WikibaseApiTestCase {

	/** @var string */
	protected static $testAction;

	public function addDBDataOnce() {
		$this->initTestEntities( [ 'Empty' ] );
	}

	public static function provideData() {
		return [
			// -- Test valid sequence -----------------------------
			[ //0
				'params' => [ 'language' => 'en', 'value' => '' ],
				'expected' => [ 'edit-no-change' => true ] ],
			[ //1
				'params' => [ 'language' => 'en', 'value' => 'Value' ],
				'expected' => [ 'value' => [ 'en' => 'Value' ] ] ],
			[ //2
				'params' => [ 'language' => 'en', 'value' => 'Value' ],
				'expected' => [ 'value' => [ 'en' => 'Value' ], 'edit-no-change'  => true ] ],
			[ //3
				'params' => [ 'language' => 'en', 'value' => 'Another Value', 'summary' => 'Test summary!' ],
				'expected' => [ 'value' => [ 'en' => 'Another Value' ] ] ],
			[ //4
				'params' => [ 'language' => 'en', 'value' => 'Different Value' ],
				'expected' => [ 'value' => [ 'en' => 'Different Value' ] ] ],
			[ //5
				'params' => [ 'language' => 'sgs', 'value' => 'V?sata' ],
				'expected' => [ 'value' => [ 'sgs' => 'V?sata', 'en' => 'Different Value' ] ] ],
			[ //6
				'params' => [ 'language' => 'en', 'value' => '' ],
				'expected' => [ 'value' => [ 'sgs' => 'V?sata' ] ] ],
			[ //7
				'params' => [ 'language' => 'sgs', 'value' => '' ],
				'expected' => [] ],
			[ //8
				'params' => [ 'language' => 'en', 'value' => "  x\nx  " ],
				'expected' => [ 'value' => [ 'en' => 'x x' ] ] ],
		];
	}

	public function doTestSetTerm( $attribute, $params, $expected ) {
		// -- set any defaults ------------------------------------
		$params['action'] = self::$testAction;
		$params['id'] ??= EntityTestHelper::getId( 'Empty' );
		$expected['value'] ??= [];

		// -- do the request --------------------------------------------------
		[ $result ] = $this->doApiRequestWithToken( $params );

		// -- check the result ------------------------------------------------
		$this->assertArrayHasKey( 'success', $result, "Missing 'success' marker in response." );
		$this->assertResultHasEntityType( $result );
		$this->assertArrayHasKey( 'entity', $result, "Missing 'entity' section in response." );

		// -- check the result only has our changed data (if any)  ------------
		$this->assertCount(
			1,
			$result['entity'][$attribute],
			'Entity return contained more than a single language'
		);
		$this->assertArrayHasKey(
			$params['language'],
			$result['entity'][$attribute],
			"Entity doesn't return expected language" );
		$this->assertEquals(
			$params['language'],
			$result['entity'][$attribute][$params['language']]['language'],
			'Returned incorrect language'
		);

		if ( array_key_exists( $params['language'], $expected['value'] ) ) {
			$this->assertEquals(
				$expected['value'][ $params['language'] ],
				$result['entity'][$attribute][$params['language']]['value'], "Returned incorrect attribute {$attribute}"
			);
		} else {
			$this->assertArrayHasKey(
				'removed',
				$result['entity'][$attribute][ $params['language'] ],
				"Entity doesn't return expected 'removed' marker"
			);
		}

		// -- check any warnings ----------------------------------------------
		if ( array_key_exists( 'warning', $expected ) ) {
			$this->assertArrayHasKey( 'warnings', $result, "Missing 'warnings' section in response." );
			$this->assertEquals( $expected['warning'], $result['warnings']['messages']['0']['name'] );
			$this->assertArrayHasKey( 'html', $result['warnings']['messages'] );
		}

		// -- check item in database -------------------------------------------
		$dbEntity = $this->loadEntity( EntityTestHelper::getId( 'Empty' ) );
		$this->assertArrayHasKey( $attribute, $dbEntity );
		$dbLabels = $this->flattenArray( $dbEntity[$attribute], 'language', 'value', true );
		$this->assertSameSize( $expected['value'], $dbLabels, 'Database contains exact number of terms' );
		foreach ( $expected['value'] as $valueLanguage => $value ) {
			$this->assertArrayHasKey( $valueLanguage, $dbLabels );
			$this->assertEquals( $value, $dbLabels[$valueLanguage][0] );
		}

		// -- check the edit summary --------------------------------------------
		if ( empty( $expected['edit-no-change'] ) ) {
			$this->assertRevisionSummary( [ self::$testAction, $params['language'] ], $result['entity']['lastrevid'] );
			if ( array_key_exists( 'summary', $params ) ) {
				$this->assertRevisionSummary( "/{$params['summary']}/", $result['entity']['lastrevid'] );
			}
		}
	}

	public static function provideExceptionData() {
		return [
			// -- Test Exceptions -----------------------------
			[ //0
				'params' => [ 'language' => 'xx', 'value' => 'Foo' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => self::logicalOr(
						self::equalTo( 'unknown_language' ),
						self::equalTo( 'badvalue' )
					),
				] ],
			],
			[ //1
				'params' => [ 'language' => 'nl', 'value' => TermTestHelper::makeOverlyLongString() ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => 'modification-failed',
				] ],
			],
			[ //2
				'params' => [ 'language' => 'pt', 'value' => 'normalValue' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => self::logicalOr(
						self::equalTo( 'notoken' ),
						self::equalTo( 'missingparam' )
					),
					'message' => 'The "token" parameter must be set',
				] ],
				'token' => false,
			],
			[ //3
				'params' => [ 'language' => 'pt', 'value' => 'normalValue', 'token' => '88888888888888888888888888888888+\\' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => 'badtoken',
					'message' => 'Invalid CSRF token.',
				] ],
				'token' => false,
			],
			[ //4
				'params' => [ 'id' => 'noANid', 'language' => 'fr', 'value' => 'normalValue' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => 'invalid-entity-id',
					'message' => 'Invalid entity ID.',
				] ],
			],
			[ //5
				'params' => [ 'site' => 'qwerty', 'language' => 'pl', 'value' => 'normalValue' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => self::logicalOr(
						self::equalTo( 'unknown_site' ),
						self::equalTo( 'badvalue' )
					),
					'message' => 'Unrecognized value for parameter "site"',
				] ],
			],
			[ //6
				'params' => [ 'site' => 'enwiki', 'title' => 'GhskiDYiu2nUd', 'language' => 'en', 'value' => 'normalValue' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => 'no-such-entity-link',
				] ],
			],
			[ //7
				'params' => [ 'title' => 'Blub', 'language' => 'en', 'value' => 'normalValue' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => 'param-missing',
				] ],
			],
			[ //8
				'params' => [ 'site' => 'enwiki', 'language' => 'en', 'value' => 'normalValue' ],
				'expected' => [ 'exception' => [
					'type' => ApiUsageException::class,
					'code' => 'param-missing',
				] ],
			],
		];
	}

	public function doTestSetTermExceptions( $params, $expected, $token = true ) {
		// -- set any defaults ------------------------------------
		$params['action'] = self::$testAction;
		if ( !array_key_exists( 'id', $params )
			&& !array_key_exists( 'site', $params )
			&& !array_key_exists( 'title', $params )
		) {
			$params['id'] = EntityTestHelper::getId( 'Empty' );
		}
		$this->doTestQueryExceptions( $params, $expected['exception'], null, $token );
	}

}
