<?php declare( strict_types=1 );

namespace Wikibase\Repo\Domains\Reuse;

use MediaWiki\MediaWikiServices;
use Psr\Container\ContainerInterface;
use Wikibase\Repo\Domains\Reuse\Domain\Services\FacetedItemSearchEngine;
use Wikibase\Repo\Domains\Reuse\Domain\Services\ItemByExternalIdLookup;
use Wikibase\Repo\Domains\Reuse\Domain\Services\LanguageFallbackLabelSelector;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\GraphQLService;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\GraphQLTracking;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemDescriptionsResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemLabelsResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\ItemLabelsWithLanguageFallbackResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Resolvers\PropertyLabelsWithLanguageFallbackResolver;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema\Schema;
use Wikibase\Repo\Domains\Reuse\Infrastructure\GraphQL\Schema\Types;

/**
 * @license GPL-2.0-or-later
 */
class WbReuse {
	public static function getGraphQLService( ?ContainerInterface $services = null ): GraphQLService {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.GraphQLService' );
	}

	public static function getGraphQLTracking( ?ContainerInterface $services = null ): GraphQLTracking {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.GraphQLTracking' );
	}

	public static function getGraphQLSchema( ?ContainerInterface $services = null ): Schema {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.GraphQLSchema' );
	}

	public static function getGraphQLTypes( ?ContainerInterface $services = null ): Types {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.GraphQLTypes' );
	}

	public static function getItemDescriptionsResolver( ?ContainerInterface $services = null ): ItemDescriptionsResolver {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.ItemDescriptionsResolver' );
	}

	public static function getItemLabelsResolver( ?ContainerInterface $services = null ): ItemLabelsResolver {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.ItemLabelsResolver' );
	}

	public static function getItemLabelsWithLanguageFallbackResolver(
		?ContainerInterface $services = null
	): ItemLabelsWithLanguageFallbackResolver {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.ItemLabelsWithLanguageFallbackResolver' );
	}

	public static function getLanguageFallbackLabelSelector(
		?ContainerInterface $services = null
	): LanguageFallbackLabelSelector {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.LanguageFallbackLabelSelector' );
	}

	public static function getPropertyLabelsResolver( ?ContainerInterface $services = null ): PropertyLabelsResolver {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.PropertyLabelsResolver' );
	}

	public static function getPropertyLabelsWithLanguageFallbackResolver(
		?ContainerInterface $services = null
	): PropertyLabelsWithLanguageFallbackResolver {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.PropertyLabelsWithLanguageFallbackResolver' );
	}

	public static function getFacetedItemSearchEngine( ?ContainerInterface $services = null ): FacetedItemSearchEngine {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.FacetedItemSearchEngine' );
	}

	public static function getItemByExternalIdLookup( ?ContainerInterface $services = null ): ItemByExternalIdLookup {
		return ( $services ?: MediaWikiServices::getInstance() )
			->get( 'WbReuse.ItemByExternalIdLookup' );
	}
}
