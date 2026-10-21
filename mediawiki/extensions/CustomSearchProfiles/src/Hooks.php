<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\CustomSearchProfiles;

use MediaWiki\MediaWikiServices;

class Hooks implements \MediaWiki\Hook\SpecialSearchProfilesHook {

	/**
	 * Retrieves custom search profiles from the config and reformats them for
	 * insertion into MediaWiki's search profile array
	 *
	 * @return array Custom search profiles
	 */
	private static function getCustomSearchProfiles(): array {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CustomSearchProfiles' );
		$rawCustomProfiles = $config->get( 'CustomSearchProfilesProfiles' );

		$customProfiles = [];
		foreach ( $rawCustomProfiles as $key => $value ) {
			$customProfiles[$key] = [
				'message' => $value['message'] ?? "searchprofile-{$key}",
				'tooltip' => $value['tooltip'] ?? "searchprofile-{$key}-tooltip",
				'namespaces' => $value['namespaces'],
			];
		}
		return $customProfiles;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SpecialSearchProfiles
	 * @param array &$profiles A list of search profiles to be shown
	 * @return bool Whether or not to abort
	 */
	public function onSpecialSearchProfiles( &$profiles ): bool {
		$allOffset = array_search( 'all', array_keys( $profiles ), true );
		if ( $allOffset === false ) {
			trigger_error(
				'CustomSearchProfiles: failed to find "all" search profile; not adding custom search profiles',
				E_USER_WARNING,
			);
			return true;
		}

		$profilesBeforeAll = array_slice( $profiles, 0, $allOffset, true );
		$profilesAfterAll = array_slice( $profiles, $allOffset, null, true );

		$profiles = $profilesBeforeAll + $this->getCustomSearchProfiles() + $profilesAfterAll;
		return true;
	}

}
