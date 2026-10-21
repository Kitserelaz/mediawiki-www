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

use MediaWiki\Extension\ReportIncident\Services\ReportIncidentController;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;

class MainHooksHandler implements
	BeforePageDisplayHook,
	SidebarBeforeOutputHook,
	SkinTemplateNavigation__UniversalHook
{

	private ReportIncidentController $controller;

	public function __construct( ReportIncidentController $controller ) {
		$this->controller = $controller;
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		// Only add HTML if the:
		// * page is in a supported namespace,
		// * skin is minerva
		// * link is to be shown to the current user, and
		// * feature flag is enabled.
		if ( $this->controller->shouldAddMenuItem( $out->getContext() ) ) {
			$out->addHtml( '<div id="ext-reportincident-app"></div>' );
		}
	}

	/** @inheritDoc */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		// Show the link to report in minerva overflow menu if the:
		// * page is in a supported namespace,
		// * skin is minerva
		// * link is to be shown to the current user, and
		// * feature flag is enabled.
		if (
			$skin->getSkinName() === 'minerva' &&
			$this->controller->shouldAddMenuItem( $skin->getContext() )
		) {
			$this->controller->addModulesAndConfigVars( $skin->getOutput() );
			$sidebar['TOOLBOX']['reportincident'] = [
				'class' => 'ext-reportincident-link',
				'text' => $skin->msg( 'reportincident-report-btn-label' )->text(),
				'href' => '#',
				'icon' => 'flag',
			];
		}
	}

	/**
	 * @inheritDoc
	 * @phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		// Show the reporting link in the "Tools" menu if the:
		// * page is in a supported namespace,
		// * link is to be shown to the current user, and
		// * feature flag is enabled.
		if ( $this->controller->shouldAddMenuItem( $sktemplate->getContext() ) ) {
			$this->controller->addModulesAndConfigVars( $sktemplate->getOutput() );
			$links['actions']['reportincident'] = [
				'class' => 'ext-reportincident-link',
				'text' => $sktemplate->msg( 'reportincident-report-btn-label' )->text(),
				'href' => '#',
				'icon' => 'flag',
			];
		}
	}
}
