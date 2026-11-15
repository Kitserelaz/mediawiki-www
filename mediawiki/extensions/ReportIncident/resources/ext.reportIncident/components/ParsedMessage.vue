<template>
	<!-- eslint-disable-next-line vue/no-v-html -->
	<p ref="content" v-html="message.parse()"></p>
</template>

<script>
const { defineComponent, nextTick, onMounted, ref, toRef, watch } = require( 'vue' );

// A rendered MediaWiki message, with any links within set to open in a new tab.
module.exports = exports = defineComponent( {
	name: 'ParsedMessage',
	props: {
		/**
		 * A MediaWiki message instance as returned by mw.message().
		 */
		message: { type: Object, required: true }
	},
	setup( props ) {
		const content = ref( null );
		const message = toRef( props, 'message' );

		/**
		 * Update the "target" attribute of anchors within the rendered message content
		 * to ensure they all open in a new tab.
		 */
		function setTarget() {
			nextTick( () => {
				if ( content.value ) {
					$( content.value ).find( 'a' ).attr( 'target', '_blank' );
				}
			} );
		}

		watch( message, setTarget );

		onMounted( setTarget );

		return { content };
	}
} );
</script>
