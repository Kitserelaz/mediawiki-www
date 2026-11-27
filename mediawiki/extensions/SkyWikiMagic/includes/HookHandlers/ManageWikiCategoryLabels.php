<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Context\IContextSource;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\ManageWiki\Hooks\ManageWikiCoreAddFormFieldsHook;
use WikiOasis\WikiOasisMagic\Helpers\WikiCategoryLabels;

class ManageWikiCategoryLabels implements ManageWikiCoreAddFormFieldsHook {

	/** @inheritDoc */
	public function onManageWikiCoreAddFormFields(
		IContextSource $context,
		ModuleFactory $moduleFactory,
		string $dbname,
		bool $ceMW,
		array &$formDescriptor
	): void {
		if ( isset( $formDescriptor['category']['options'] ) ) {
			$formDescriptor['category']['options'] = WikiCategoryLabels::getOptions(
				$context, $formDescriptor['category']['options']
			);
		}
	}
}
