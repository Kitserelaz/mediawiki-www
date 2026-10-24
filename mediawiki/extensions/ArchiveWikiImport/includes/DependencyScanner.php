<?php
/** @license GPL-2.0-or-later */
namespace MediaWiki\Extension\ArchiveWikiImport;

use RuntimeException;
use XMLReader;

/** Reads dump data only; archived markup and extension code are never executed. */
class DependencyScanner {
	public function __construct( private $pulse = null ) {
	}

	private const TAGS = [
		'templatestyles' => 'TemplateStyles',
		'syntaxhighlight' => 'SyntaxHighlight',
		'source' => 'SyntaxHighlight',
		'ref' => 'Cite',
		'references' => 'Cite',
		'math' => 'Math',
		'poem' => 'Poem',
		'score' => 'Score',
		'inputbox' => 'InputBox',
	];

	/** Discover content-handler providers from trusted, locally installed manifests. */
	public static function providers( string $extensionsDirectory ): array {
		$providers = [ 'sanitized-css' => [ 'TemplateStyles' ], 'Scribunto' => [ 'Scribunto' ] ];
		foreach ( glob( rtrim( $extensionsDirectory, '/' ) . '/*/extension.json' ) ?: [] as $path ) {
			$manifest = json_decode( file_get_contents( $path ), true );
			$name = $manifest['name'] ?? null;
			if ( !is_string( $name ) ) {
				continue;
			}
			foreach ( array_keys( $manifest['ContentHandlers'] ?? [] ) as $model ) {
				$providers[$model][] = $name;
			}
		}
		foreach ( $providers as &$names ) {
			$names = array_values( array_unique( $names ) );
			sort( $names );
		}
		return $providers;
	}

	public function inspect( string $path, array $availableModels, array $enabledExtensions, array $providers ): array {
		$report = [ 'pages' => 0, 'revisions' => 0, 'models' => [], 'missingModels' => [], 'extensions' => [] ];
		$models = [];
		$evidence = [];
		$previousErrors = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$reader = new XMLReader();
		try {
			if ( !$reader->open( $path, null, LIBXML_NONET | LIBXML_PARSEHUGE ) ) {
				throw new RuntimeException( 'Cannot open XML dump for dependency checks.' );
			}
			$root = false;
			while ( $reader->read() ) {
				if ( $this->pulse ) { ( $this->pulse )(); }
				if ( $reader->nodeType === XMLReader::DOC_TYPE ) {
					throw new RuntimeException( 'Dependency checks do not accept XML document type declarations.' );
				}
				if ( $reader->nodeType !== XMLReader::ELEMENT ) {
					continue;
				}
				if ( $reader->depth === 0 ) {
					if ( $reader->localName !== 'mediawiki' ) {
						throw new RuntimeException( 'Expected a MediaWiki XML dump.' );
					}
					$root = true;
				} elseif ( $reader->depth === 1 && $reader->localName === 'page' ) {
					$report['pages']++;
				} elseif ( $reader->depth === 2 && $reader->localName === 'revision' ) {
					$report['revisions']++;
				} elseif ( $reader->localName === 'model' && $reader->depth >= 3 ) {
					$model = trim( $reader->readString() );
					if ( $model !== '' ) {
						$models[$model] = true;
					}
				} elseif ( $reader->localName === 'text' && $reader->depth >= 3 ) {
					$text = $reader->readString();
					// Markup is only a hint: it can appear in examples, comments or old revisions.
					foreach ( self::TAGS as $tag => $extension ) {
						if ( preg_match( '/<\s*' . $tag . '\b/i', $text ) ) {
							$evidence[$extension]['<' . $tag . '>'] = true;
						}
					}
					if ( preg_match( '/\{\{\s*#invoke\s*:/i', $text ) ) {
						$evidence['Scribunto']['#invoke'] = true;
					}
					if ( preg_match( '/\{\{\s*#(?:if|ifeq|ifexpr|ifexist|switch|expr)\s*:/i', $text ) ) {
						$evidence['ParserFunctions']['parser functions'] = true;
					}
				}
			}
			if ( !$root || libxml_get_last_error() ) {
				throw new RuntimeException( 'Invalid or incomplete XML dump during dependency checks.' );
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $previousErrors );
		}
		$report['models'] = array_keys( $models );
		sort( $report['models'] );
		foreach ( $report['models'] as $model ) {
			$names = $providers[$model] ?? [];
			$missing = !in_array( $model, $availableModels, true );
			if ( $missing ) {
				$report['missingModels'][$model] = $names;
			}
			foreach ( $names as $name ) {
				$evidence[$name]['content model: ' . $model] = true;
			}
		}
		foreach ( $evidence as $name => $signals ) {
			$report['extensions'][$name] = [ 'enabled' => in_array( $name, $enabledExtensions, true ),
				'evidence' => array_keys( $signals ) ];
		}
		ksort( $report['missingModels'] );
		ksort( $report['extensions'] );
		return $report;
	}
}
