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

namespace MediaWiki\Extension\ReportIncident\Hooks\Handlers;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\DiscussionTools\Hooks\DiscussionToolsAddOverflowMenuItemsHook;
use MediaWiki\Extension\DiscussionTools\OverflowMenuItem;
use MediaWiki\Extension\ReportIncident\Services\ReportIncidentController;

class DiscussionToolsHandler implements DiscussionToolsAddOverflowMenuItemsHook {

	public function __construct(
		private readonly ReportIncidentController $controller,
	) {
	}

	/** @inheritDoc */
	public function onDiscussionToolsAddOverflowMenuItems(
		array &$overflowMenuItems,
		array &$resourceLoaderModules,
		array $threadItemData,
		IContextSource $contextSource
	) {
		// Only add overflow menu link if the:
		// * page is in a supported namespace,
		// * link is to be shown to the current user, and
		// * feature flag is enabled.
		if ( $this->controller->shouldAddMenuItem( $contextSource ) ) {
			$overflowMenuItems[] = new OverflowMenuItem(
				'reportincident',
				'flag',
				'reportincident-report-btn-label',
				0,
				[ 'thread-id' => $threadItemData['id'] ]
			);
			$resourceLoaderModules[] = 'ext.reportIncident';
		}
	}
}
