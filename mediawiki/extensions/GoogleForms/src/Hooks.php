<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\GoogleForms;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\Parser;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Utils\UrlUtils;

class Hooks implements ParserFirstCallInitHook {

	/**
	 * Key (string): parameter name
	 * Value (array):
	 * * required (boolean): Whether or not the parameter is required
	 * * validator (string|null): Optional regex to validate the input parameter
	 * * transformer (callable|null): Optional function that is called with the input parameter. Its return value will replace the input parameter.
	 */
	public static array $parameters = [
		'id' => [
			'required' => true,
			// I actually don't know what characters the ID is composed out of, but base64url seems to be the safest choice
			'validator' => '/^[a-zA-Z0-9\\-_]+$/',
			'transformer' => null,
		],
		'width' => [
			'required' => false,
			// For some reason percent works (at least on Firefox), even though the literal standard says that it is in
			// CSS pixels... okay? Not gonna ask why it works
			// Source: https://html.spec.whatwg.org/multipage/embedded-content-other.html#attr-dim-width (last updated 7 August 2024)
			'validator' => '/^[0-9]+%?$/',
			'transformer' => null,
		],
		'height' => [
			'required' => false,
			// See 'width' above for more information on percent
			'validator' => '/^[0-9]+%?$/',
			'transformer' => null,
		],
		'style' => [
			'required' => false,
			'validator' => null,
			'transformer' => [ 'Sanitizer', 'checkCss' ],
		],
		'class' => [
			'required' => false,
			'validator' => null,
			'transformer' => null,
		],
	];

	private UrlUtils $urlUtils;

	/**
	 * @param UrlUtils $urlUtils
	 */
	public function __construct( $urlUtils ) {
		$this->urlUtils = $urlUtils;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserFirstCallInit
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'googleforms', [ $this, 'onGoogleForms' ] );
		$parser->setHook( 'gforms', [ $this, 'onGoogleForms' ] );
	}

	/**
	 * Constructs a <strong class="error"> error message
	 *
	 * @param Parser $parser
	 * @param string $msg
	 * @param mixed ...$args
	 */
	private static function inlineError( $parser, $msg, ...$args ) {
		$attributes = [
			'class' => 'error',
		];
		$content = $parser->msg( $msg, ...$args )->parse();

		return Html::rawElement( 'strong', $attributes, $content );
	}

	/**
	 * @param string|null $text
	 * @param array $params
	 * @param Parser $parser
	 * @param \PPFrame $frame
	 */
	public function onGoogleForms( $text, $params, $parser, $frame ): string {
		$outputParams = [];
		foreach ( Hooks::$parameters as $name => $paramInfo ) {
			if ( !isset( $params[$name] ) ) {
				if ( $paramInfo['required'] ) {
					return $this->inlineError( $parser, 'googleforms-missing-parameter', $name );
				}
				continue;
			}

			$value = $params[$name];
			if ( $paramInfo['validator'] !== null && preg_match( $paramInfo['validator'], $value ) !== 1 ) {
				return $this->inlineError( $parser, 'googleforms-bad-parameter', $name );
			}
			if ( $paramInfo['transformer'] !== null ) {
				$value = call_user_func( $paramInfo['transformer'], $value );
			}

			$outputParams[$name] = $value;
		}

		$outputParams['src'] = $this->urlUtils->assemble( [
			'scheme' => 'https',
			'delimiter' => '://',
			'host' => 'docs.google.com',
			'path' => '/forms/d/e/' . $outputParams['id'] . '/viewform',
		] );
		unset( $outputParams['id'] );

		return Html::element( 'iframe', $outputParams, '' );
	}

}
