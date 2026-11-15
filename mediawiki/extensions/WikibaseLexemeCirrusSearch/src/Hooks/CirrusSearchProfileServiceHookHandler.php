<?php

declare( strict_types = 1 );

namespace Wikibase\Lexeme\Search\Elastic\Hooks;

use CirrusSearch\Hooks\CirrusSearchProfileServiceHook;
use CirrusSearch\Profile\ConfigProfileRepository;
use CirrusSearch\Profile\SearchProfileService;
use MediaWiki\Config\Config;
use Wikibase\Lexeme\Search\Elastic\LexemeFullTextQueryBuilder;
use Wikibase\Lexeme\Search\Elastic\LexemeSearchEntity;
use Wikibase\Lib\WikibaseSettings;
use Wikibase\Search\Elastic\EntitySearchElastic;

/**
 * Handler for the CirrusSearchProfileServiceHook.
 *
 * @license GPL-2.0-or-later
 */
class CirrusSearchProfileServiceHookHandler implements CirrusSearchProfileServiceHook {

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Register our cirrus profiles.
	 *
	 * @param SearchProfileService $service
	 */
	public function onCirrusSearchProfileService( SearchProfileService $service ): void {
		// Do not add Lexeme specific search stuff if we are not a repo
		if ( !WikibaseSettings::isRepoEnabled() || !$this->config->get( 'LexemeEnableRepo' ) ) {
			return;
		}

		// register base profiles available on all wikibase installs
		$service->registerFileRepository( EntitySearchElastic::WIKIBASE_PREFIX_QUERY_BUILDER,
			'lexeme_base', __DIR__ . '/../../config/LexemePrefixSearchProfiles.php' );
		$service->registerFileRepository( SearchProfileService::RESCORE_FUNCTION_CHAINS,
			'lexeme_base', __DIR__ . '/../../config/LexemeRescoreFunctions.php' );
		$service->registerFileRepository( SearchProfileService::RESCORE,
			'lexeme_base', __DIR__ . '/../../config/LexemeRescoreProfiles.php' );
		$service->registerFileRepository( SearchProfileService::FT_QUERY_BUILDER,
			'lexeme_base', __DIR__ . '/../../config/LexemeSearchProfiles.php' );

		// register custom profiles provided in the WikibaseLexeme config settings
		$service->registerRepository(
			new ConfigProfileRepository( EntitySearchElastic::WIKIBASE_PREFIX_QUERY_BUILDER,
				'lexeme_config', 'LexemePrefixSearchProfiles', $this->config )
		);
		// Rescore functions for lexemes
		$service->registerRepository(
			new ConfigProfileRepository( SearchProfileService::RESCORE_FUNCTION_CHAINS,
				'lexeme_config', 'LexemeRescoreFunctions', $this->config )
		);

		// Determine the default rescore profile to use for entity autocomplete search
		$service->registerDefaultProfile( SearchProfileService::RESCORE,
			LexemeSearchEntity::CONTEXT_LEXEME_PREFIX,
			EntitySearchElastic::DEFAULT_RESCORE_PROFILE );
		$service->registerConfigOverride( SearchProfileService::RESCORE,
			LexemeSearchEntity::CONTEXT_LEXEME_PREFIX, $this->config, 'LexemePrefixRescoreProfile' );
		// add the possibility to override the profile by setting the URI param cirrusRescoreProfile
		$service->registerUriParamOverride( SearchProfileService::RESCORE,
			LexemeSearchEntity::CONTEXT_LEXEME_PREFIX, 'cirrusRescoreProfile' );

		// Determine the default query builder profile to use for entity autocomplete search
		$service->registerDefaultProfile( EntitySearchElastic::WIKIBASE_PREFIX_QUERY_BUILDER,
			LexemeSearchEntity::CONTEXT_LEXEME_PREFIX,
			EntitySearchElastic::DEFAULT_QUERY_BUILDER_PROFILE );
		$service->registerConfigOverride( EntitySearchElastic::WIKIBASE_PREFIX_QUERY_BUILDER,
			LexemeSearchEntity::CONTEXT_LEXEME_PREFIX, $this->config, 'LexemePrefixSearchProfile' );
		$service->registerUriParamOverride( EntitySearchElastic::WIKIBASE_PREFIX_QUERY_BUILDER,
			LexemeSearchEntity::CONTEXT_LEXEME_PREFIX, 'cirrusWBProfile' );

		// Determine query builder profile for fulltext search
		$service->registerDefaultProfile( SearchProfileService::FT_QUERY_BUILDER,
			LexemeFullTextQueryBuilder::CONTEXT_LEXEME_FULLTEXT,
			LexemeFullTextQueryBuilder::LEXEME_DEFAULT_PROFILE );
		$service->registerUriParamOverride( SearchProfileService::FT_QUERY_BUILDER,
			LexemeFullTextQueryBuilder::CONTEXT_LEXEME_FULLTEXT, 'cirrusWBProfile' );

		// Determine the default rescore profile to use for fulltext search
		$service->registerDefaultProfile( SearchProfileService::RESCORE,
			LexemeFullTextQueryBuilder::CONTEXT_LEXEME_FULLTEXT,
			LexemeFullTextQueryBuilder::LEXEME_DEFAULT_PROFILE );
		$service->registerConfigOverride( SearchProfileService::RESCORE,
			LexemeFullTextQueryBuilder::CONTEXT_LEXEME_FULLTEXT, $this->config,
			'LexemeFulltextRescoreProfile' );
		// add the possibility to override the profile by setting the URI param cirrusRescoreProfile
		$service->registerUriParamOverride( SearchProfileService::RESCORE,
			LexemeFullTextQueryBuilder::CONTEXT_LEXEME_FULLTEXT, 'cirrusRescoreProfile' );
	}

}
