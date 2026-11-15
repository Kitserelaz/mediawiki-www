<?php

declare( strict_types = 1 );

namespace Wikibase\Lexeme\Search\Elastic\Tests\Hooks;

use MediaWikiIntegrationTestCase;
use Wikibase\Lexeme\MediaWiki\Content\LexemeContent;
use Wikibase\Lexeme\Search\Elastic\Hooks\WikibaseRepoEntityTypesHookHandler;
use Wikibase\Lib\EntityTypeDefinitions;

/**
 * @covers \Wikibase\Lexeme\Search\Elastic\Hooks\WikibaseRepoEntityTypesHookHandler
 */
class WikibaseRepoEntityTypesHookHandlerTest extends MediaWikiIntegrationTestCase {

	public function testDoesNothingIfDisabled(): void {
		$this->overrideConfigValue( 'LexemeUseCirrus', null );
		$entityTypeDefinitions = [ 'item' => [], 'property' => [] ];
		$original = $entityTypeDefinitions; // copy
		$handler = new WikibaseRepoEntityTypesHookHandler();

		$handler->onWikibaseRepoEntityTypes( $entityTypeDefinitions );

		$this->assertSame( $original, $entityTypeDefinitions );
	}

	public function testOverridesSearchDefinitions(): void {
		$this->overrideConfigValue( 'LexemeUseCirrus', true );
		$entityTypeDefinitions = [
			'lexeme' => [
				EntityTypeDefinitions::CONTENT_MODEL_ID => LexemeContent::CONTENT_MODEL_ID,
			],
		];
		$handler = new WikibaseRepoEntityTypesHookHandler();

		$handler->onWikibaseRepoEntityTypes( $entityTypeDefinitions );

		$this->assertSame( LexemeContent::CONTENT_MODEL_ID,
			$entityTypeDefinitions['lexeme'][EntityTypeDefinitions::CONTENT_MODEL_ID] );
		$lexemeSearchFieldDefs = $entityTypeDefinitions['lexeme'][EntityTypeDefinitions::SEARCH_FIELD_DEFINITIONS];
		$this->assertIsCallable( $lexemeSearchFieldDefs );
	}

	public function testKeepsEntityTypeOrder(): void {
		$this->overrideConfigValue( 'LexemeUseCirrus', true );
		$entityTypeDefinitions = [
			'item' => [],
			'property' => [],
			'lexeme' => [],
			'form' => [],
			'sense' => [],
		];
		$handler = new WikibaseRepoEntityTypesHookHandler();

		$handler->onWikibaseRepoEntityTypes( $entityTypeDefinitions );

		$this->assertSame( [ 'item', 'property', 'lexeme', 'form', 'sense' ], array_keys( $entityTypeDefinitions ) );
	}

}
