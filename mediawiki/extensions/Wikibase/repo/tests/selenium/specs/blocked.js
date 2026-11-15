import { createApiClient } from 'wdio-mediawiki/Api.js';
import { getTestString } from 'wdio-mediawiki/Util.js';
import Page from 'wdio-mediawiki/Page.js';
import LoginPage from 'wdio-mediawiki/LoginPage.js';

describe( 'blocked user cannot use', () => {
	let api;
	let blockedUsername;
	let blockedPassword;

	before( async () => {
		api = await createApiClient();

		// Create a dedicated user for blocking tests to avoid blocking
		// the admin user which can cause parallel test to fail
		blockedUsername = getTestString( 'BlockedUser-' );
		blockedPassword = getTestString( 'BlockedPassword-' );
		await api.createAccount( blockedUsername, blockedPassword );
		await LoginPage.login( blockedUsername, blockedPassword );
	} );

	beforeEach( async () => {
		await api.blockUser( blockedUsername, '1 minute' );
	} );

	afterEach( async () => {
		await api.unblockUser( blockedUsername );
	} );

	async function assertIsUserBlockedError() {
		await expect( $( '#firstHeading' ) ).toHaveText( 'User is blocked' );
	}

	const tests = [
		{ name: 'SetLabel', ids: [ 'wb-modifyentity-id', 'wb-setlabel-submit' ] },
		{ name: 'SetDescription', ids: [ 'wb-modifyentity-id', 'wb-setdescription-submit' ] },
		{ name: 'SetAliases', ids: [ 'wb-modifyentity-id', 'wb-setaliases-submit' ] },
		{ name: 'SetLabelDescriptionAliases', ids: [ 'wb-modifyentity-id', 'wb-setlabeldescriptionaliases-submit' ] },
		{ name: 'SetSiteLink', ids: [ 'wb-modifyentity-id', 'wb-setsitelink-submit' ] },
		{ name: 'NewItem', ids: [ 'wb-newentity-label', 'wb-newentity-submit' ] },
		{ name: 'NewProperty', ids: [ 'wb-newentity-label', 'wb-newentity-submit' ] },
		{ name: 'MergeItems', ids: [ 'wb-mergeitems-fromid', 'wb-mergeitems-submit' ] },
		{ name: 'RedirectEntity', ids: [ 'wb-redirectentity-fromid', 'wb-redirectentity-submit' ] }
	];

	for ( const test of tests ) {
		// eslint-disable-next-line mocha/no-setup-in-describe
		const title = `Special:${ test.name }`;
		it( title, async () => {
			await ( new Page() ).openTitle( title );

			await assertIsUserBlockedError();

			for ( const id of test.ids ) {
				await expect( $( `#${ id }` ) ).not.toExist();
			}
		} );
	}
} );
