'use strict';

jest.mock( '../../../resources/ext.reportIncident/composables/useInstrument.js' );
jest.mock( '../../../resources/ext.reportIncident/components/icons.json', () => ( {
	cdxIconUserGroup: ''
} ), { virtual: true } );

const NonEmergencySubmitSuccessStep = require( '../../../resources/ext.reportIncident/components/NonEmergencySubmitSuccessStep.vue' ),
	{ createTestingPinia } = require( '@pinia/testing' ),
	utils = require( '@vue/test-utils' ),
	Constants = require( '../../../resources/ext.reportIncident/Constants.js' ),
	useInstrument = require( '../../../resources/ext.reportIncident/composables/useInstrument.js' );

describe( 'NonEmergencySubmitSuccessStep', () => {
	function mount( formStoreState ) {
		return utils.mount( NonEmergencySubmitSuccessStep, {
			global: {
				plugins: [
					createTestingPinia( {
						stubActions: false,
						initialState: {
							form: formStoreState
						}
					} )
				]
			}
		} );
	}

	let logEvent;

	beforeEach( () => {
		jest.spyOn( mw, 'message' ).mockImplementation( ( key ) => ( {
			text() {
				return key;
			},
			parse() {
				return key;
			}
		} ) );

		logEvent = jest.fn();
		useInstrument.mockImplementation( () => logEvent );
		mw.user.getName = () => 'Foo';
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete mw.user.getName;
	} );

	const defaultNonEmergencyPageCases = [
		{
			title: 'intimidation resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.INTIMIDATION,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-intimidation-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-intimidation-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'doxing resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.DOXING,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-doxing-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-doxing-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'sexual harassment resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.SEXUAL_HARASSMENT,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-sexualharassment-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-sexualharassment-nextstep',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'trolling resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.TROLLING,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-trolling-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-trolling-nextstep',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'hate speech resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.HATE_SPEECH,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-hatespeech-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-hatespeech-nextstep',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'spam resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.SPAM,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-spam-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-spam-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'other resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.SOMETHING_ELSE,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-somethingelse-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'sockpuppetry resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.SOCKPUPPETRY,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-dialog-harassment-type-sockpuppetry',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-sockpuppetry-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'vandalism resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.VANDALISM,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-dialog-harassment-type-vandalism',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-vandalism-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'user dispute resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.USER_DISPUTE,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-dialog-harassment-type-userdispute',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-userdispute-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'disruptive editing resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.DISRUPTIVE_EDITING,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-dialog-harassment-type-disruptiveediting',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-disruptiveediting-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'other resolution',
			config: {
				inputBehavior: Constants.harassmentTypes.OTHER,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				headers: [
					'reportincident-nonemergency-nextsteps-header',
					'reportincident-nonemergency-requesthelp-header',
					'reportincident-nonemergency-other-header'
				],
				copy: [
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-other-notice',
					'reportincident-nonemergency-other-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		}
	];

	it.each( defaultNonEmergencyPageCases )(
		'renders the default page', ( { config, expected } ) => {
			const wrapper = mount( {
				incidentType: Constants.typeOfIncident.unacceptableUserBehavior,
				inputBehavior: config.inputBehavior,
				get: {
					wgReportIncidentE2ETesterUsers: []
				}
			} );

			const headers = wrapper.findAll( 'h3' ).map( ( h ) => h.text() );
			expect( headers ).toEqual( expected.headers );

			const copy = wrapper.findAll( 'p, li' ).map( ( c ) => c.text() );
			expect( copy ).toEqual( expected.copy );

			expect( logEvent ).toHaveBeenCalledTimes( 1 );
			expect( logEvent ).toHaveBeenCalledWith( 'view', { source: 'get_support' } );
		}
	);

	const configuredNonEmergencyPageCases = [
		{
			title: 'intimidation resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.INTIMIDATION,
				get: {
					wgReportIncidentNonEmergencyIntimidationDisputeResolutionURL: 'url',
					wgReportIncidentNonEmergencyIntimidationHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencyIntimidationHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencyIntimidationHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-intimidation-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-intimidation-nextstep-configured',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'doxing resolution, show notice',
			config: {
				inputBehavior: Constants.harassmentTypes.DOXING,
				get: {
					wgReportIncidentNonEmergencyDoxingShowWarning: true,
					wgReportIncidentE2ETesterUsers: []
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-doxing-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-doxing-notice',
					'reportincident-nonemergency-doxing-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'doxing resolution, hide notice',
			config: {
				inputBehavior: Constants.harassmentTypes.DOXING,
				get: {
					wgReportIncidentNonEmergencyDoxingShowWarning: false
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-doxing-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-doxing-nextstep-default',
					'reportincident-nonemergency-helpmethod-default',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'doxing resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.DOXING,
				get: {
					wgReportIncidentNonEmergencyDoxingShowWarning: false,
					wgReportIncidentNonEmergencyDoxingHideEditURL: 'url',
					wgReportIncidentNonEmergencyDoxingHelpMethodWikiEmailURL: 'foo',
					wgReportIncidentNonEmergencyDoxingHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencyDoxingHelpMethodOtherURL: 'baz',
					wgReportIncidentNonEmergencyDoxingHelpMethodEmailStewards: true
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-doxing-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-doxing-nextsteps-configured',
					'reportincident-nonemergency-helpmethod-wikiemailurl',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-otherurl',
					'reportincident-nonemergency-helpmethod-emailstewards',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'sexual harassment resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.SEXUAL_HARASSMENT,
				get: {
					wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencySexualHarassmentHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-sexualharassment-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-sexualharassment-nextstep',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'trolling resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.TROLLING,
				get: {
					wgReportIncidentNonEmergencyTrollingHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencyTrollingHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencyTrollingHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-trolling-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-trolling-nextstep',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'hate speech resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.HATE_SPEECH,
				get: {
					wgReportIncidentNonEmergencyHateSpeechHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencyHateSpeechHelpMethodEmail: 'bar'
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-hatespeech-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-hatespeech-nextstep',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'spam resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.SPAM,
				get: {
					wgReportIncidentNonEmergencySpamSpamContentURL: 'url',
					wgReportIncidentNonEmergencySpamHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencySpamHelpMethodEmail: 'bar'
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-spam-header',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-spam-nextsteps-configured',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'other resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.SOMETHING_ELSE,
				get: {
					wgReportIncidentNonEmergencyOtherDisputeResolutionURL: 'url',
					wgReportIncidentNonEmergencySomethingElseHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencySomethingElseHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencySomethingElseHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-somethingelse-nextstep-configured',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'sockpuppetry resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.SOCKPUPPETRY,
				get: {
					wgReportIncidentNonEmergencySockpuppetryHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencySockpuppetryHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencySockpuppetryHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-dialog-harassment-type-sockpuppetry',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-sockpuppetry-nextstep-default',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'vandalism resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.VANDALISM,
				get: {
					wgReportIncidentNonEmergencyVandalismHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencyVandalismHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencyVandalismHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-dialog-harassment-type-vandalism',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-vandalism-nextstep-default',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'user dispute resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.USER_DISPUTE,
				get: {
					wgReportIncidentNonEmergencyUserDisputeHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencyUserDisputeHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencyUserDisputeHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-dialog-harassment-type-userdispute',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-userdispute-nextstep-default',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'disruptive editing resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.DISRUPTIVE_EDITING,
				get: {
					wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-dialog-harassment-type-disruptiveediting',
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-disruptiveediting-nextstep-default',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		},
		{
			title: 'other resolution, configured',
			config: {
				inputBehavior: Constants.harassmentTypes.OTHER,
				get: {
					wgReportIncidentNonEmergencyOtherHelpMethodContactAdmin: 'foo',
					wgReportIncidentNonEmergencyOtherHelpMethodEmail: 'bar',
					wgReportIncidentNonEmergencyOtherHelpMethodContactCommunity: 'baz'
				}
			},
			expected: {
				copy: [
					'reportincident-nonemergency-generic-description',
					'reportincident-nonemergency-other-notice',
					'reportincident-nonemergency-other-nextstep-default',
					'reportincident-nonemergency-helpmethod-contactadmin',
					'reportincident-nonemergency-helpmethod-email',
					'reportincident-nonemergency-helpmethod-contactcommunity',
					'reportincident-nonemergency-generic-nextstep-otheraction'
				]
			}
		}
	];

	it.each( configuredNonEmergencyPageCases )(
		'renders the configured page', ( { config, expected } ) => {
			jest.spyOn( mw.config, 'get' ).mockImplementation( ( key ) => {
				if ( key in config.get ) {
					return config.get[ key ];
				} else {
					return null;
				}
			} );

			const wrapper = mount( {
				incidentType: Constants.typeOfIncident.unacceptableUserBehavior,
				inputBehavior: config.inputBehavior
			} );
			const copy = wrapper.findAll( 'p, li' ).map( ( c ) => c.text() );
			expect( copy ).toEqual( expected.copy );
		}
	);

	it( 'records interaction event for links in non-emergency flow', async () => {
		// Manually mount instead of using mount() in order to overwrite global.$i18n
		const wrapper = utils.mount( NonEmergencySubmitSuccessStep, {
			global: {
				directives: {
					'i18n-html': ( el, binding ) => {
						if ( binding.arg === 'reportincident-nonemergency-generic-nextstep-otheraction' ) {
							el.innerHTML = 'Test message <a href="https://example.com">link</a>';
							return;
						}
						el.innerHTML = binding.value ? `${ binding.arg } (${ binding.value })` : binding.arg;
					}
				},
				plugins: [
					createTestingPinia( {
						stubActions: false,
						initialState: {
							form: {
								incidentType: Constants.typeOfIncident.unacceptableUserBehavior,
								inputBehavior: Constants.harassmentTypes.INTIMIDATION
							}
						}
					} )
				]
			}
		} );

		await wrapper.find( 'a' ).trigger( 'click' );

		expect( logEvent ).toHaveBeenCalledTimes( 2 );
		expect( logEvent ).toHaveBeenNthCalledWith( 1, 'view', { source: 'get_support' } );
		expect( logEvent ).toHaveBeenNthCalledWith( 2, 'click', {
			context: 'https://example.com',
			source: 'get_support'
		} );
	} );
} );
