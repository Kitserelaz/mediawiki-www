<?php

/**
 * Hooks for MultiBoilerplate extension
 *
 * * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Extensions
 *
 * @TODO de-duplicate code between this and SpecialBoilerplates
 */

namespace MediaWiki\Extension\MultiBoilerplate;

use MediaWiki\EditPage\EditPage;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use OutputPage;
use ParserOptions;

class Hooks {

	/**
	 * EditPage::showEditForm:initial hook
	 *
	 * Generate a boilerplate selection input on top of the edit page
	 *
	 * @param EditPage $editPage the current EditPage object.
	 * @param OutputPage $out object.
	 * @return true
	 */
	public static function onEditPageShowEditFormInitial( EditPage $editPage, OutputPage $out ) {
		$title = $out->getTitle();
		$request = $out->getRequest();
		$optionsConfig = $out->getConfig()->get( 'MultiBoilerplateOptions' );
		$allowContentOverwrite = $out->getConfig()->get( 'MultiBoilerplateOverwrite' );

		// If $wgMultiBoilerplateOverwrite is true then detect whether
		// the current page exists or not and if it does return true
		// to end execution of this function.
		if ( !$allowContentOverwrite && $title->exists() ) {
			return true;
		}

		// Generate the options list used inside the boilerplate selection box.
		// If $wgMultiBoilerplateOptions is an array then use that, else fall back
		// to the MediaWiki:Multiboilerplate message.
		if ( is_array( $optionsConfig ) && !empty( $optionsConfig ) ) {
			$options = '';
			foreach ( $optionsConfig as $name => $template ) {
				$selected = false;
				if ( $request->getVal( 'boilerplate' ) === $template ) {
					$selected = true;
				}
				$attribs = [ 'value' => $template ];
				if ( $selected === true ) {
					$attribs['selected'] = 'selected';
				}
				$options .= Html::element( 'option', $attribs, $name );
			}
		} else {
			$rows = wfMessage( 'Multiboilerplate' )->inContentLanguage()->text();
			$options = '';
			$headingFound = 0;
			$rows = preg_split( '/\r\n|\r|\n/', $rows );

			foreach ( $rows as $row ) {
				if ( preg_match( '/==\s*(.*)\s*==/', $row, $optGroupText ) ) {
					if ( $headingFound ) {
						$options .= Html::closeElement( 'optgroup' );
					}
					$headingFound = true;
					$options .= Html::openElement( 'optgroup', [ 'label' => $optGroupText[1] ] );
				} elseif ( str_starts_with( ltrim( $row ), '*' ) ) {
					$row = ltrim( $row, '* ' ); // Remove asterisk & spacing from start of line.
					$rowParts = explode( '|', $row );
					if ( !isset( $rowParts[ 1 ] ) ) {
						return true; // Invalid syntax, abort
					}

					$rowParts[1] = trim( $rowParts[1] );  // Clean whitespace that might break wikilinks

					// allow wikilinks in template names
					$rowParts[1] = preg_replace( '/^\[\[/', '', $rowParts[1] );
					$rowParts[1] = preg_replace( '/\]\]$/', '', $rowParts[1] );

					$attribs = [ 'value' => $rowParts[1] ];
					if ( $request->getVal( 'boilerplate' ) === $rowParts[ 1 ] ) {
						$attribs['selected'] = 'selected';
					}
					$options .= Html::element( 'option', $attribs, $rowParts[ 0 ] );
				}

			}

			if ( $headingFound ) {
				$options .= Html::closeElement( 'optgroup' );
			}
		}

		// No options found in either configuration file, abort.
		if ( $options == '' ) {
			return true;
		}

		// Append the selection form to the top of the edit page.
		$label = Html::rawElement(
			'label',
			[],
			wfMessage( 'multiboilerplate-label' )->escaped()
				. Html::rawElement(
					'select',
					[ 'name' => 'boilerplate' ],
					$options
				)
		);
		$editPage->editFormPageTop .= Html::rawElement(
			'form',
			[
				'id' => 'multiboilerplateform',
				'name' => 'multiboilerplateform',
				'method' => 'get',
				'action' => $title->getEditURL()
			],
			Html::rawElement(
				'fieldset',
				[],
				Html::element( 'legend', [], wfMessage( 'multiboilerplate-legend' )->plain() )
					. $label
					. ' '
					. Html::hidden( 'action', 'edit' )
					. Html::hidden( 'title', $request->getText( 'title' ) )
					. Html::submitButton( wfMessage( 'multiboilerplate-submit' )->plain() )
			)
		);

		// If the Load button has been pushed replace the article text with the boilerplate.
		if ( $request->getText( 'boilerplate', false ) ) {
			$boilerplateTitle = Title::newFromText( $request->getVal( 'boilerplate' ) );
			if ( !$boilerplateTitle?->exists() ) {
				$out->addHTML( Html::element(
					'strong',
					[ 'class' => 'error' ],
					$out->msg( 'multiboilerplate-nonexistant-page' )->params(
						$request->getText( 'boilerplate' )
					)->text()
				) );
			} else {
				$boilerplate = MediaWikiServices::getInstance()->getWikiPageFactory()
					->newFromTitle( $boilerplateTitle );

				$parser = MediaWikiServices::getInstance()->getParserFactory()->getInstance();
				$parserOptions = $parser->getOptions() === null
					? new ParserOptions( $out->getUser() )
					: $parser->getOptions();
				$content = $parser->getPreloadText(
					$boilerplate->getContent()->getWikitextForTransclusion(),
					$boilerplateTitle,
					$parserOptions
				);

				$editPage->textbox1 = $content;
			}
		}

		return true;
	}

}
