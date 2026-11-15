import Page from 'wdio-mediawiki/Page';

class ReportIncidentPage extends Page {
	get actionsMenu() {
		return $( '.mw-portlet-cactions' );
	}

	get reportLinkInToolsMenu() {
		return $( '.ext-reportincident-link' );
	}

	get reportIncidentDialog() {
		return $( '.ext-reportincident-dialog' );
	}

	get dialogUnacceptableBehaviorsButton() {
		return $( 'input[value="unacceptable-user-behavior"]', this.reportIncidentDialog );
	}

	get dialogFooterNextButton() {
		return $( '.ext-reportincident-dialog-footer__next-btn', this.reportIncidentDialog );
	}

	get dialogFooterBackButton() {
		return $( '.ext-reportincident-dialog-footer__back-btn', this.reportIncidentDialog );
	}

	get stepOneContent() {
		return $( '.ext-reportincident-dialog-step1', this.reportIncidentDialog );
	}

	get typesOfBehviorScreenContent() {
		return $( '.ext-reportincident-dialog-types-of-behavior', this.reportIncidentDialog );
	}

	get violatorFormInput() {
		return $( '.ext-reportincident-dialog-step2__violator-name input', this.reportIncidentDialog );
	}

	get violatorFormInputErrors() {
		return $( '.ext-reportincident-dialog-step2__violator-name .cdx-message--error', this.reportIncidentDialog );
	}

	get harassmentOptionsFormFieldset() {
		return $( '.ext-reportincident-dialog-types-of-behavior__harassment-options', this.reportIncidentDialog );
	}

	get harassmentOptionsFieldsetFormErrors() {
		return $( '.cdx-message--error', this.harassmentOptionsFormFieldset );
	}

	get hateSpeechOrDiscriminationOption() {
		return $( 'input[value="hate-or-discrimination"]', this.harassmentOptionsFormFieldset );
	}

	get sexualHarassmentOption() {
		return $( 'input[value="sexual-harassment"]', this.harassmentOptionsFormFieldset );
	}

	get trollingOption() {
		return $( 'input[value="trolling"]', this.harassmentOptionsFormFieldset );
	}

	get intimidationOption() {
		return $( 'input[value="intimidation"]', this.harassmentOptionsFormFieldset );
	}

	get somethingElseOption() {
		return $( 'input[value="something-else"]', this.harassmentOptionsFormFieldset );
	}

	get somethingElseTextbox() {
		return $( '.ext-reportincident-dialog-types-of-behavior__something-else-textarea textarea', this.harassmentOptionsFormFieldset );
	}

	get submitButton() {
		return $( 'input[name="wpSave"]' );
	}

	get messageDialog() {
		return $( '.oo-ui-messageDialog-content' );
	}

	get threadOptions() {
		return $( '.ext-discussiontools-init-section-overflowMenuButton > a' );
	}

	get reportLink() {
		return $( '#reportincident .oo-ui-labelElement-label', this.threadOptions );
	}

	get successfulSubmissionSectionHeader() {
		return $( '.ext-reportincident-dialog__submit-success-section-header', this.messageDialog );
	}

	async open( userName, query, fragment ) {
		await super.openTitle(
			`User talk:${ userName }`,
			query,
			fragment
		);
	}
}

export default new ReportIncidentPage();
