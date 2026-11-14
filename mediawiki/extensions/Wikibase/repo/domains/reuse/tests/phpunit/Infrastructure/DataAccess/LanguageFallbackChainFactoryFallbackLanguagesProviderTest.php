<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\Domains\Reuse\Infrastructure\DataAccess;

use PHPUnit\Framework\TestCase;
use Wikibase\Lib\LanguageFallbackChainFactory;
use Wikibase\Lib\TermLanguageFallbackChain;
use Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess\LanguageFallbackChainFactoryFallbackLanguagesProvider;

/**
 * @covers \Wikibase\Repo\Domains\Reuse\Infrastructure\DataAccess\LanguageFallbackChainFactoryFallbackLanguagesProvider
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class LanguageFallbackChainFactoryFallbackLanguagesProviderTest extends TestCase {

	public function testGetFallbackLanguages(): void {
		$languageCode = 'de';
		$fallbackCodes = [ 'de', 'mul', 'en' ];

		$chain = $this->createStub( TermLanguageFallbackChain::class );
		$chain->method( 'getFetchLanguageCodes' )->willReturn( $fallbackCodes );

		$factory = $this->createMock( LanguageFallbackChainFactory::class );
		$factory->expects( $this->once() )
			->method( 'newFromLanguageCode' )
			->with( $languageCode )
			->willReturn( $chain );

		$provider = new LanguageFallbackChainFactoryFallbackLanguagesProvider( $factory );

		$this->assertSame( $fallbackCodes, $provider->getFallbackLanguages( $languageCode ) );
	}

}
