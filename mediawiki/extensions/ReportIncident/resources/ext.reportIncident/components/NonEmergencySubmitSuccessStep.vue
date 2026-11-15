<template>
	<div v-if="page" ref="pageContent">
		<cdx-message v-if="isTestReport" type="warning">
			{{ $i18n( 'reportincident-e2e-tester-notice' ).text() }}
		</cdx-message>
		<cdx-message :icon="icons.cdxIconUserGroup">
			<p v-if="page.description.header">
				<strong>{{ page.description.header }}</strong>
			</p>
			<p>{{ page.description.text }}</p>
		</cdx-message>
		<cdx-message v-if="page.notice && page.notice.shouldDisplay" type="warning">
			<p>{{ page.notice.text }}</p>
		</cdx-message>
		<h3 class="ext-reportincident-dialog__submit-success-section-header">
			{{ $i18n( 'reportincident-nonemergency-nextsteps-header' ).text() }}
		</h3>
		<!-- eslint-disable vue/no-v-html-->
		<p
			v-for="( paragraph, key ) in nextStepMsg"
			:key="key"
			v-html="paragraph"
		></p>
		<!-- eslint-enable vue/no-v-html-->
		<h3 class="ext-reportincident-dialog__submit-success-section-header">
			{{ $i18n( 'reportincident-nonemergency-requesthelp-header' ).text() }}
		</h3>
		<ul>
			<!-- eslint-disable vue/no-v-html-->
			<li
				v-for="( helpMethod, key ) in helpMethods"
				:key="key"
				v-html="helpMethod"
			></li>
			<!-- eslint-enable vue/no-v-html-->
		</ul>
		<h3 class="ext-reportincident-dialog__submit-success-section-header">
			{{ $i18n( 'reportincident-nonemergency-other-header' ).text() }}
		</h3>
		<p v-i18n-html:reportincident-nonemergency-generic-nextstep-otheraction>
		</p>
	</div>
</template>

<script>

const { onMounted, ref } = require( 'vue' );
const useFormStore = require( '../stores/Form.js' );
const Constants = require( '../Constants.js' );
const { CdxMessage } = require( '@wikimedia/codex' );
const useInstrument = require( '../composables/useInstrument.js' );
const icons = require( '../components/icons.json' );

// @vue/component
module.exports = exports = {
	name: 'ReportIncidentDialogNonEmergencySupport',
	components: { CdxMessage },
	setup() {
		const logEvent = useInstrument();
		const pageContent = ref( null );
		onMounted( () => {
			logEvent( 'view', { source: 'get_support' } );

			$( pageContent.value ).find( 'a' ).on( 'click', function () {
				logEvent( 'click', {
					context: $( this ).attr( 'href' ),
					source: 'get_support'
				} );
			} );
		} );

		const store = useFormStore();
		const behavior = store.inputBehavior;

		const pages = {};

		// Pages that describe next steps for the non-emergency incident. The type
		// should match up with what's defined in Constants.harassmentTypes.
		// A page is described by an object with the following keys:
		// description: an object that summarizes the page; pass through the header (parsed
		// message) and text (parsed message)
		// notice (optional): an object that describes a notice displayed in a warning notice box;
		// pass through the text (parsed message) and a boolean determining if the box should be
		// displayed at all
		// nextSteps: an array that describes messages to display in descending priority. When
		// processed, it'll return the first valid message.
		// helpMethods: an array that describes the possible contact methods. When processed, it'll
		// return all valid messages.
		// helpMethodDefault: If no helpMethods are valid, this message will be returned
		pages[ Constants.harassmentTypes.INTIMIDATION ] = {
			description: {
				header: mw.msg( 'reportincident-nonemergency-intimidation-header' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-intimidation-nextstep-configured',
					requiredParams: [ 'wgReportIncidentNonEmergencyIntimidationDisputeResolutionURL' ]
				},
				{
					msgKey: 'reportincident-nonemergency-intimidation-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencyIntimidationHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyIntimidationHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencyIntimidationHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.DOXING ] = {
			description: {
				header: mw.msg( 'reportincident-nonemergency-doxing-header' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			notice: {
				text: mw.msg( 'reportincident-nonemergency-doxing-notice' ),
				shouldDisplay: mw.config.get( 'wgReportIncidentNonEmergencyDoxingShowWarning' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-doxing-nextsteps-configured',
					requiredParams: [ 'wgReportIncidentNonEmergencyDoxingHideEditURL' ]
				},
				{
					msgKey: 'reportincident-nonemergency-doxing-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-wikiemailurl',
					requiredParams: [ 'wgReportIncidentNonEmergencyDoxingHelpMethodWikiEmailURL' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyDoxingHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-otherurl',
					requiredParams: [ 'wgReportIncidentNonEmergencyDoxingHelpMethodOtherURL' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-emailstewards',
					requiredParams: [ 'wgReportIncidentNonEmergencyDoxingHelpMethodEmailStewards' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.SEXUAL_HARASSMENT ] = {
			description: {
				header: mw.msg( 'reportincident-nonemergency-sexualharassment-header' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-sexualharassment-nextstep'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencySexualHarassmentHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencySexualHarassmentHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.TROLLING ] = {
			description: {
				header: mw.msg( 'reportincident-nonemergency-trolling-header' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-trolling-nextstep'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencyTrollingHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyTrollingHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencyTrollingHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.HATE_SPEECH ] = {
			description: {
				header: mw.msg( 'reportincident-nonemergency-hatespeech-header' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-hatespeech-nextstep'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencyHateSpeechHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyHateSpeechHelpMethodEmail' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.SPAM ] = {
			description: {
				header: mw.msg( 'reportincident-nonemergency-spam-header' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-spam-nextsteps-configured',
					requiredParams: [ 'wgReportIncidentNonEmergencySpamSpamContentURL' ]
				},
				{
					msgKey: 'reportincident-nonemergency-spam-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencySpamHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencySpamHelpMethodEmail' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.SOMETHING_ELSE ] = {
			description: {
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-somethingelse-nextstep-configured',
					requiredParams: [ 'wgReportIncidentNonEmergencyOtherDisputeResolutionURL' ]
				},
				{
					msgKey: 'reportincident-nonemergency-somethingelse-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencySomethingElseHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencySomethingElseHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencySomethingElseHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.SOCKPUPPETRY ] = {
			description: {
				header: mw.msg( 'reportincident-dialog-harassment-type-sockpuppetry' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-sockpuppetry-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencySockpuppetryHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencySockpuppetryHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.VANDALISM ] = {
			description: {
				header: mw.msg( 'reportincident-dialog-harassment-type-vandalism' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-vandalism-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencyVandalismHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyVandalismHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencyVandalismHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.USER_DISPUTE ] = {
			description: {
				header: mw.msg( 'reportincident-dialog-harassment-type-userdispute' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-userdispute-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyUserDisputeHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencyUserDisputeHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.DISRUPTIVE_EDITING ] = {
			description: {
				header: mw.msg( 'reportincident-dialog-harassment-type-disruptiveediting' ),
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-disruptiveediting-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencyDisruptiveEditingHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		pages[ Constants.harassmentTypes.OTHER ] = {
			description: {
				text: mw.msg( 'reportincident-nonemergency-generic-description' )
			},
			notice: {
				text: mw.msg( 'reportincident-nonemergency-other-notice' ),
				shouldDisplay: true
			},
			nextSteps: [
				{
					msgKey: 'reportincident-nonemergency-other-nextstep-default'
				}
			],
			helpMethods: [
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactadmin',
					requiredParams: [ 'wgReportIncidentNonEmergencyOtherHelpMethodContactAdmin' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-email',
					requiredParams: [ 'wgReportIncidentNonEmergencyOtherHelpMethodEmail' ]
				},
				{
					msgKey: 'reportincident-nonemergency-helpmethod-contactcommunity',
					requiredParams: [ 'wgReportIncidentNonEmergencyOtherHelpMethodContactCommunity' ]
				}
			],
			helpMethodDefault: {
				msgKey: 'reportincident-nonemergency-helpmethod-default'
			}
		};

		const page = pages[ behavior ];

		// A message is considered valid if the requiredParams are found via mw.config.get()
		// If no requiredParams are found, the message is considered valid by default.
		function getValidMsgParams( msg ) {
			// No required params, message is automatically considered valid
			if ( !msg.requiredParams ) {
				return [];
			}

			// Check if all required parameters exist. No other validation is done here.
			// If all required parameters exist, message is considered valid.
			const msgParams = [];
			for ( const param of msg.requiredParams ) {
				const paramValue = mw.config.get( param );
				if ( !paramValue ) {
					// Found an invalid value, break as the message is considered invalid now
					break;
				}
				msgParams.push( paramValue );
			}

			// If all required parameters weren't found, message is invalid
			if ( msgParams.length !== msg.requiredParams.length ) {
				return false;
			}
			return msgParams;
		}

		// Cycle through all the next step messages, returning the first valid one
		let nextStepMsg = '';
		for ( const msg of page.nextSteps ) {
			const msgParams = getValidMsgParams( msg );
			if ( msgParams !== false ) {
				// * reportincident-nonemergency-intimidation-nextstep-configured
				// * reportincident-nonemergency-intimidation-nextstep-default
				nextStepMsg = mw.message( msg.msgKey, msgParams ).parse();
				break;
			}
		}
		nextStepMsg = nextStepMsg.split( '\n\n' );

		// Cycle through all help method messages and return all valid ones
		const helpMethods = [];
		for ( const msg of page.helpMethods ) {
			const msgParams = getValidMsgParams( msg );
			if ( msgParams !== false ) {
				// * reportincident-nonemergency-helpmethod-contactadmin
				// * reportincident-nonemergency-helpmethod-email
				// * reportincident-nonemergency-helpmethod-contactcommunity
				helpMethods.push( mw.message( msg.msgKey, msgParams ).parse() );
			}
		}

		// If no valid (configured) help methods were found, return the default fallback
		if ( !helpMethods.length ) {
			// * reportincident-nonemergency-helpmethod-default
			// * HACK: linter wants at least 2 messages, remove when second one added
			helpMethods.push( mw.message( page.helpMethodDefault.msgKey ).parse() );
		}

		const testers = mw.config.get( 'wgReportIncidentE2ETesterUsers' ) || [];
		const isTestReport = testers.includes( mw.user.getName() );

		return {
			page,
			pageContent,
			nextStepMsg,
			helpMethods,
			icons,
			isTestReport
		};
	}
};
</script>

<style lang="less">
.ext-reportincident-dialog__submit-success-section-header {
	font-size: 100%;
	padding: 0;
	margin: 1em 0 0 0;
}
</style>
