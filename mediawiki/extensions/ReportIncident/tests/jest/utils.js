'use strict';

/**
 * Mocks mw.Api().get() and returns a jest.fn()
 * that is used as the get() method. This can
 * be used to expect that the get() method is
 * called with the correct arguments.
 *
 * @param {*} returnValue
 * @return {jest.fn}
 */
function mockApiGet( returnValue ) {
	const apiGet = jest.fn();
	apiGet.mockImplementation( () => returnValue );
	jest.spyOn( mw, 'Api' ).mockImplementation( () => ( {
		get: apiGet
	} ) );
	return apiGet;
}

/**
 * Mocks mediawiki.String so that require calls work.
 * Returns a jest.fn() for the codePointLength function.
 *
 * @return {jest.fn}
 */
function mockCodePointLength() {
	const codePointLength = jest.fn();
	jest.mock( 'mediawiki.String', () => ( {
		codePointLength: codePointLength
	} ), { virtual: true } );
	return codePointLength;
}

module.exports = {
	mockApiGet: mockApiGet,
	mockCodePointLength: mockCodePointLength
};
