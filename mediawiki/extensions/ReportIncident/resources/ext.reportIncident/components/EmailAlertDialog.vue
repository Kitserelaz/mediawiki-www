<template>
	<cdx-dialog
		v-model:open="wrappedOpen"
		class="ext-reportincident-emaildialog"
		:title="$i18n( 'reportincident-emaildialog-title' ).text()"
		:close-button-label="$i18n( 'reportincident-emaildialog-close-button' ).text()"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="onPrimaryAction"
		@default="onDefaultAction">
		<p>{{ $i18n( 'reportincident-emaildialog-content' ).text() }}</p>
	</cdx-dialog>
</template>

<script>

const { toRef } = require( 'vue' );
const { CdxDialog, useModelWrapper } = require( '@wikimedia/codex' );

// @vue/component
module.exports = exports = {
	name: 'EmailAlertDialog',
	components: { CdxDialog },
	emits: [ 'update:open' ],
	setup( props, { emit } ) {
		const wrappedOpen = useModelWrapper( toRef( props, 'open' ), emit, 'update:open' );

		const primaryAction = {
			label: mw.msg( 'reportincident-emaildialog-primary' ),
			actionType: 'progressive'
		};

		const defaultAction = {
			label: mw.msg( 'reportincident-emaildialog-close-button' )
		};

		function onPrimaryAction() {
			wrappedOpen.value = false;
			window.location.assign( mw.Title.newFromText( 'Special:ChangeEmail' ).getUrl() );
		}

		function onDefaultAction() {
			wrappedOpen.value = false;
		}

		return {
			wrappedOpen,
			primaryAction,
			defaultAction,
			onPrimaryAction,
			onDefaultAction
		};
	}
};

</script>

<style lang="less">
@import ( reference ) 'mediawiki.skin.variables.less';

.ext-reportincident-emaildialog {
	font-size: @font-size-large;
	line-height: @line-height-medium;
}
</style>
