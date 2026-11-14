/**
 * See also: http://webdriver.io/guide/testrunner/configurationfile.html
 */

import { dirname } from 'wdio-mediawiki/Util.js';
import { config as mwConfig } from 'wdio-mediawiki/wdio-defaults.conf.js';

const testDir = dirname( import.meta.url );

export const config = {
	...mwConfig,

	// To enable video recording, enable video and disable browser headless
	// recordVideo: true,
	// useBrowserHeadless: false,
	//
	// To enable screenshots on all tests, disable screenshotsOnFailureOnly
	// screenshotsOnFailureOnly: false,

	// Hardcode tests in parallel for now
	maxInstances: 2,
	// ==================
	// Test Files
	// ==================
	specs: [
		testDir + '/specs/*.js'
	],

	// ===================
	// Test Configurations
	// ===================

	capabilities: [ {
		...mwConfig.capabilities[ 0 ],

		// Setting this enables automatic screenshots for when a browser command fails
		// It is also used by afterTest for capturig failed assertions.
		'mw:screenshotPath': process.env.LOG_DIR || testDir + '/log'
	} ],

	// Default timeout for each waitFor* command.
	waitforTimeout: 10 * 1000,

	// See also: http://webdriver.io/guide/testrunner/reporters.html
	reporters: [ 'spec' ]
};
