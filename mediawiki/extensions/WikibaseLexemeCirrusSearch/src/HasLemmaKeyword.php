<?php

namespace Wikibase\Lexeme\Search\Elastic;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Query\FilterQueryFeature;
use CirrusSearch\Query\SimpleKeywordFeature;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MatchQuery;

class HasLemmaKeyword extends SimpleKeywordFeature implements FilterQueryFeature {
	public const MAX_CONDITIONS = 30;

	/**
	 * @inheritDoc
	 */
	protected function getKeywords() {
		return [ 'haslemma' ];
	}

	/**
	 * @inheritDoc
	 */
	public function parseValue(
		$key,
		$value,
		$quotedValue,
		$valueDelimiter,
		$suffix,
		WarningCollector $warningCollector
	) {
		$variants = explode( ',', $value );
		if ( count( $variants ) > self::MAX_CONDITIONS ) {
			$warningCollector->addWarning(
				'cirrussearch-feature-too-many-conditions',
				$key,
				self::MAX_CONDITIONS
			);
			$variants = array_slice(
				$variants,
				0,
				self::MAX_CONDITIONS
			);
		}
		return [ 'variants' => $variants ];
	}

	/**
	 * @inheritDoc
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ): CrossSearchStrategy {
		return CrossSearchStrategy::hostWikiOnlyStrategy();
	}

	/**
	 * @inheritDoc
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$parsedValue = $this->parseValue( $key, $value, $quotedValue, '', '', $context );
		return [ $this->buildFilterQuery( $parsedValue ), false ];
	}

	/**
	 * @inheritDoc
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		return $this->buildFilterQuery( $node->getParsedValue() );
	}

	private function buildFilterQuery( array $parseValue ): ?AbstractQuery {
		$queries = [];
		foreach ( $parseValue[ 'variants' ] as $variant ) {
			$trimmed = trim( $variant );
			if ( $trimmed !== '' ) {
				$queries[] = ( new MatchQuery() )->setFieldQuery( 'lemma_spelling_variants', $variant );
			}
		}
		return Filters::booleanOr( $queries, false );
	}
}
