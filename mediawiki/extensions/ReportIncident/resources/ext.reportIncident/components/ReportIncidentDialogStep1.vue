<template>
	<div class="ext-reportincident-dialog-step1">
		<!-- Harassment explainer -->
		<div>
			<span>
				{{ $i18n( 'reportincident-dialog-harassment-intro-text' ).text() }}
			</span>
			<span v-i18n-html:reportincident-dialog-harassment-lead-in-to-harassment-examples>
			</span>
			<ul>
				<li>
					{{ $i18n( 'reportincident-dialog-harassment-example-insults' ).text() }}
				</li>
				<li>
					{{
						$i18n( 'reportincident-dialog-harassment-example-sexual-harassment' ).text()
					}}
				</li>
				<li>
					{{ $i18n( 'reportincident-dialog-harassment-example-threats' ).text() }}
				</li>
				<li>
					{{ $i18n(
						'reportincident-dialog-harassment-example-encouraging-harm'
					).text() }}
				</li>
				<li>
					{{ $i18n( 'reportincident-dialog-harassment-example-doxing' ).text() }}
				</li>
				<li>
					{{ $i18n( 'reportincident-dialog-harassment-example-hounding' ).text() }}
				</li>
				<li>
					{{ $i18n( 'reportincident-dialog-harassment-example-trolling' ).text() }}
				</li>
			</ul>
		</div>

		<!-- How to engage in dialog section -->
		<div class="ext-reportincident-dialog-step1__text-block">
			<div class="ext-reportincident-dialog__text-header">
				{{ $i18n( 'reportincident-dialog-engage-header' ).text() }}
			</div>
			<span> {{ $i18n( 'reportincident-dialog-engage-text' ).text() }}</span>
		</div>
	</div>
</template>

<script>
// @vue/component
module.exports = exports = {
	name: 'ReportIncidentDialogStep1'
};
</script>

<style lang="less">
@import ( reference ) 'mediawiki.skin.variables.less';

.ext-reportincident-dialog-step1 {
	font-size: @font-size-large;
	line-height: @line-height-medium;

	&__text-block {
		margin-top: @spacing-125;
	}
}
</style>
