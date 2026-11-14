'use strict';

const { assert } = require( 'api-testing' );
const { RequestBuilder } = require( '../../../../../rest-api/tests/mocha/helpers/RequestBuilder' );
const { assertValidError } = require( '../helpers/responseValidator' );

function newSearchRequest( route ) {
	return new RequestBuilder()
		.withRoute( 'GET', route )
		.withQueryParam( 'language', 'en' )
		.withQueryParam( 'q', 'potato' );
}

describe( 'v0 error tests', () => {

	Object.entries( {
		'item search': { route: '/v0/search/items' },
		'property search': { route: '/v0/search/properties' },
		'item suggest': { route: '/v0/suggest/items' },
		'property suggest': { route: '/v0/suggest/properties' },
	} ).forEach( ( [ title, { route } ] ) => {
		it( title, async () => {

			const response = await newSearchRequest( route )
				.makeRequest();

			assertValidError( response, 404, 'resource-not-found' );
			assert.strictEqual(
				response.body.message,
				'v0 has been removed, please modify your routes to v1 such as \'/rest.php/wikibase/v1\''
			);
		} );
	} );
} );
