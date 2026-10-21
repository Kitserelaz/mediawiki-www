'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class ReportIncidentPage extends Page {
	get reportLinkInToolsMenu() {
		return $( '.ext-reportincident-link' );
	}

	get reportIncidentDialog() {
		return $( '.ext-reportincident-dialog' );
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

	get stepTwoContent() {
		return $( '.ext-reportincident-dialog-step2', this.reportIncidentDialog );
	}

	get additionalContentFormInput() {
		return $( '.ext-reportincident-dialog-step2__additional-details textarea', this.reportIncidentDialog );
	}

	get violatorFormInput() {
		return $( '.ext-reportincident-dialog-step2__violator-name input', this.reportIncidentDialog );
	}

	get violatorFormInputErrors() {
		return $( '.ext-reportincident-dialog-step2__violator-name .cdx-message--error', this.reportIncidentDialog );
	}

	get harassmentOptionsFormFieldset() {
		return $( '.ext-reportincident-dialog-step2__harassment-options', this.reportIncidentDialog );
	}

	get harassmentOptionsFieldsetFormErrors() {
		return $( '.cdx-message--error', this.harassmentOptionsFormFieldset );
	}

	get hateSpeechOrDiscriminationOption() {
		return $( 'input[value="hate-speech-or-discrimination"]', this.harassmentOptionsFormFieldset );
	}

	get sexualHarassmentOption() {
		return $( 'input[value="sexual-harassment"]', this.harassmentOptionsFormFieldset );
	}

	get threatsOrViolenceOption() {
		return $( 'input[value="threats-or-violence"]', this.harassmentOptionsFormFieldset );
	}

	get intimidationAggressionOption() {
		return $( 'input[value="intimidation-aggression"]', this.harassmentOptionsFormFieldset );
	}

	get somethingElseOption() {
		return $( 'input[value="something-else"]', this.harassmentOptionsFormFieldset );
	}

	get somethingElseTextbox() {
		return $( '.ext-reportincident-dialog-step2__something-else-textarea textarea', this.harassmentOptionsFormFieldset );
	}

	async open( query ) {
		await super.openTitle( 'User talk:Admin', query );
	}
}

module.exports = new ReportIncidentPage();
