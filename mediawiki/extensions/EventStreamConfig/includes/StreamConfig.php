<?php

namespace MediaWiki\Extension\EventStreamConfig;

use Wikimedia\Assert\Assert;

/**
 * Configuration of single stream's settings.
 */
class StreamConfig {

	/**
	 * Key of stream setting. Used to DRY references here.
	 * @var string
	 */
	public const STREAM_SETTING = 'stream';

	/**
	 * Streams can be made up of multiple topics.  If
	 * topic_prefixes are set, the topics will default to be
	 * the stream name with these prefixes.
	 * @var array
	 */
	public const TOPIC_PREFIXES_SETTING = 'topic_prefixes';

	/**
	 * Streams can be made up of multiple topics.
	 * @var array
	 */
	public const TOPICS_SETTING = 'topics';

	/**
	 * Wrapped array with stream config settings.  See $settings
	 * param doc for __construct().
	 * @var array
	 */
	private $settings;

	/**
	 * True if the stream setting is a regex, false otherwise.
	 * Used to avoid attempting to regex match with a non regex.
	 * @var bool
	 */
	private $streamIsRegex;

	/**
	 * @param string $stream stream name, e.g., "my.event.stream-name"
	 * @param array $settings
	 *        An array with stream config settings.
	 *        Must include a 'stream' setting with either the explicit stream
	 *        name, or a regex pattern (starting with '/') that will match
	 *        against stream names that this config should be used for.
	 *        Example:
	 *        [
	 *          "schema_title" => "my/event/schema",
	 *          "sample" => [
	 *            "rate" => 0.8,
	 *            "unit" => "session",
	 *          ],
	 *          "destination_event_service" => "eventgate-analytics-public",
	 *           ...
	 *        ]
	 * @param array $defaultSettings
	 *        An array with default stream config settings.
	 */
	public function __construct( string $stream, array $settings, array $defaultSettings = [] ) {
		$this->settings = array_replace_recursive( $defaultSettings, $settings );

		// To preserve backward compatibility, add the stream name or regex pattern to the config body
		// as the 'stream' setting. This can likely be removed if we're sure nothing is relying on it.
		// Background: T277193
		$this->settings[self::STREAM_SETTING] = $stream;

		self::validate( $this->settings );

		$this->streamIsRegex = self::isValidRegex( $this->stream() );
	}

	/**
	 * Gets the stream setting
	 * @return string
	 */
	public function stream() {
		return $this->settings[self::STREAM_SETTING];
	}

	/**
	 * Returns a list of topics that compose the $stream.
	 * If a target $stream is not provided, $stream will default to the value of
	 * $this->stream(), which is this StreamConfig's stream setting.
	 *
	 * - If self::TOPICS_SETTING is set, this will be returned as is.
	 * - Else if self::TOPIC_PREFIXES_SETTING is not set, then [$stream] will be returned.
	 * - Else if $stream is a regex, it will be modified to include the prefixes in the regex.
	 * - Else a list of topics with the prefixes will be returned.
	 *
	 * @param string|null $stream
	 * @return array
	 */
	public function topics( $stream = null ): array {
		// if $stream was provided, it could be an explicit target stream name
		// OR a regex stream setting.  $this->stream() could also be either, but in the
		// case where it is a regex, a user might want to provide an explicit stream name
		// to get topics rather than this StreamConfig's regex stream setting.
		// Default to using the StreamConfig stream setting otherwise.
		if ( !$stream ) {
			$stream = $this->stream();
		}

		if ( isset( $this->settings[self::TOPICS_SETTING] ) ) {
			// If this stream was configured with specific topics, just return those.
			return $this->settings[self::TOPICS_SETTING];
		} elseif ( !isset( $this->settings[self::TOPIC_PREFIXES_SETTING] ) ) {
			// Else if this stream does not has topic prefixes, just return the
			// stream name as the topic.
			return [ $stream ];
		} else {
			$topicPrefixes = $this->settings[self::TOPIC_PREFIXES_SETTING];

			if ( self::isValidRegex( $stream ) ) {
				// This is a regex string stream name, return a regex with the prefixes.

				// Remove the regex boundry chars.
				$streamPattern = trim( $stream, '/' );

				// If the regex starts with ^, save it for later.
				$beginAnchor = '';
				if ( substr( $streamPattern, 0, 1 ) === '^' ) {
					$beginAnchor = '^';
					$streamPattern = substr( $streamPattern, 1 );
				}

				// Escape any regex looking chars in the prefixes
				$topicPrefixes = array_map( "preg_quote", $topicPrefixes );

				// Reconstruct the regex with prefixes, e.g.
				// /^(eqiad.|codfw.)
				return [
					'/' . $beginAnchor .
					'(' . implode( '|', $topicPrefixes ) . ')' . $streamPattern .
					'/'
				];
			} else {
				// Else prefix the stream with each topic prefix.
				// If $stream is a regex string, then we need to alter the
				// regex to prefix safely inside the regex string.
				return array_map(
					static function ( $topicPrefix ) use ( $stream ) {
						return $topicPrefix . $stream;
					},
					$topicPrefixes
				);
			}
		}
	}

	/**
	 * Returns this StreamConfig as an array of settings.
	 * self::TOPICS_SETTING is set to the value returned by $this->topics($targetStream)
	 * if self::TOPICS_SETTING is not explicitly set.
	 *
	 * @param string|null $targetStream
	 *        If given, this will be used to get topics, otherwise $this->stream()
	 *        will be used (which could be a regex stream pattern).
	 *
	 * @return array
	 */
	public function toArray( $targetStream = null ): array {
		$settings = $this->settings;

		// If TOPICS_SETTING is already set explicitly in the settings,
		// $this->topics() will just return it.
		$settings[self::TOPICS_SETTING] = $this->topics( $targetStream );

		return $settings;
	}

	/**
	 * True if this StreamConfig applies for $stream, false otherwise.
	 *
	 * @param string $stream name of stream to match
	 * @return bool
	 */
	public function matches( $stream ) {
		return $this->streamIsRegex ?
			preg_match( $this->stream(), $stream ) :
			( $this->stream() === $stream );
	}

	/**
	 * True if this StreamConfig has all of the given $settingsConstraints.
	 * If 'stream' is given as a constraint, it be matched against this
	 * StreamConfig's stream name via $this->matches.
	 *
	 * @param array $settingsConstraints
	 * @return bool
	 */
	public function matchesSettings( $settingsConstraints ) {
		if ( isset( $settingsConstraints[self::STREAM_SETTING] ) ) {
			if ( !$this->matches( $settingsConstraints[self::STREAM_SETTING] ) ) {
				return false;
			}
			// stream matching is special and can't use ::isPartialMatch(). Since we've already
			// matched stream, remove it from the constraints now.
			unset( $settingsConstraints[self::STREAM_SETTING] );
		}

		return $this->isPartialMatch( $this->settings, $settingsConstraints );
	}

	/**
	 * Partially matches two arrays, LHS and RHS, with one another up to a maximum depth. LHS and
	 * RHS are considered a partial match if LHS contains all of RHS.
	 *
	 * To maintain backwards compatibility, all non-array values are cast to strings before they
	 * are compared.
	 *
	 * @param array $lhs
	 * @param array $rhs
	 * @param int $maxDepth
	 * @return bool
	 */
	private function isPartialMatch( $lhs, $rhs, $maxDepth = 10 ) {
		if ( $maxDepth === 0 ) {
			return true;
		}

		foreach ( $rhs as $key => $expected ) {
			if ( !isset( $lhs[$key] ) ) {
				return false;
			}

			$actual = $lhs[$key];

			if ( is_array( $expected ) && is_array( $actual ) ) {
				if ( $this->arrayIsList( $expected ) && $this->arrayIsList( $actual ) ) {
					return array_intersect( $expected, $actual ) === $expected;
				}

				if ( !$this->isPartialMatch( $actual, $expected, $maxDepth - 1 ) ) {
					return false;
				}
			} elseif (

				// Avoid casting $expected or $actual to a string if either one of them is an
				// array.
				is_array( $expected ) ||
				is_array( $actual ) ||

				(string)$expected !== (string)$actual
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Gets whether the array is a list, i.e. an integer-indexed array with indices starting at 0.
	 *
	 * As written, this method trades performance for elegance. This method should not be called on
	 * large arrays.
	 *
	 * TODO: Replace this with array_is_list when MediaWiki supports PHP >= 8.1
	 *
	 * @param array $array
	 * @return bool
	 */
	private function arrayIsList( $array ) {
		$array = array_keys( $array );

		return $array === array_keys( $array );
	}

	/**
	 * Returns true if $string is a valid regex.
	 * It must start with '/' and preg_match must not return false.
	 *
	 * @param string $string
	 * @return bool
	 */
	private static function isValidRegex( $string ) {
		// FIXME: This is very ugly, and not very safe.
		// Temporarily disable errors/warnings when checking if valid regex.
		$errorLevel = error_reporting( E_ERROR );
		// @phan-suppress-next-line PhanParamSuspiciousOrder false positive
		$isValid = mb_substr( $string, 0, 1 ) === '/' && preg_match( $string, '' ) !== false;
		error_reporting( $errorLevel );
		return $isValid;
	}

	/**
	 * Validates that the stream config settings are valid.
	 *
	 * @param array $settings
	 * @throws \InvalidArgumentException if stream config settings are invalid
	 */
	private static function validate( array $settings ) {
		Assert::parameter(
			isset( $settings[self::STREAM_SETTING] ),
			self::STREAM_SETTING,
			self::STREAM_SETTING . ' not set in stream config entry ' .
				var_export( $settings, true )
		);
		$stream = $settings[self::STREAM_SETTING];

		Assert::parameterType( 'string', $stream, self::STREAM_SETTING );
		// If stream looks like a regex, make sure it is valid.
		// (Yes, isValidRegex also checks that string starts with '/', but here we want
		// to fail if the stream is not a valid regex.)
		if ( substr( $stream, 0, 1 ) === '/' ) {
			Assert::parameter(
				self::isValidRegex( $stream ), self::STREAM_SETTING, "Invalid regex '$stream'"
			);
		}

		if ( isset( $settings[self::TOPIC_PREFIXES_SETTING] ) ) {
			Assert::parameterType(
				'array',
				$settings[self::TOPIC_PREFIXES_SETTING],
				self::TOPIC_PREFIXES_SETTING
			);
		}

		if ( isset( $settings[self::TOPICS_SETTING] ) ) {
			Assert::parameterType(
				'array',
				$settings[self::TOPICS_SETTING],
				self::TOPICS_SETTING
			);
		}
	}
}
