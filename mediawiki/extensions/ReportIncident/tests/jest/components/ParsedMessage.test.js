'use strict';

const { mount } = require( '@vue/test-utils' );

const ParsedMessage = require( '../../../resources/ext.reportIncident/components/ParsedMessage.vue' );

describe( 'ParsedMessage', () => {
	it( 'updates the "target" attribute on mount', async () => {
		const wrapper = mount( ParsedMessage, {
			props: { message: { parse: () => 'foo <a href="https://www.example.com">test</a>' } }
		} );

		await wrapper.vm.$nextTick();

		const { target } = wrapper.find( 'a' ).attributes();

		expect( target ).toBe( '_blank' );
	} );

	it( 'updates the "target" attribute after the message changes', async () => {
		const wrapper = mount( ParsedMessage, {
			props: { message: { parse: () => 'foo <a href="https://www.example.com">test</a>' } }
		} );

		await wrapper.vm.$nextTick();

		await wrapper.setProps( {
			message: { parse: () => 'bar <a href="https://www.example.com/updated">test</a>' }
		} );

		await wrapper.vm.$nextTick();

		const { target, href } = wrapper.find( 'a' ).attributes();

		expect( target ).toBe( '_blank' );
		expect( href ).toBe( 'https://www.example.com/updated' );
	} );

	it( 'forwards props', () => {
		const wrapper = mount( ParsedMessage, {
			props: {
				class: 'test',
				message: { parse: () => 'foo <a href="https://www.example.com">test</a>' }
			}
		} );

		expect( wrapper.find( 'p' ).classes() ).toContain( 'test' );
	} );
} );
