<?php

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;

define('SB_MODULE_KEY', 'ext.snapblocks');

class SnapBlocksHook implements ParserFirstCallInitHook, ResourceLoaderGetConfigVarsHook {
	// Ouput HTML for <scratchblocks> tag

	public function onParserFirstCallInit($parser) {
		// Register extension tags
		$parser->setHook('scratchblocks', [__CLASS__, 'sb4RenderTag']);
		$parser->setHook('snapblocks', [__CLASS__, 'snapblocksRenderTag']);
		$parser->setHook('sb', [__CLASS__, 'sb4RenderInlineTag']);
		return true;
	}

	public function onResourceLoaderGetConfigVars(array &$vars, $skin, Config $config): void {
		$vars['wgSnapBlocksLangs'] = $config->get('SnapBlocksLangs');
		$vars['wgSnapBlocksBlockVersion'] = $config->get('SnapBlocksBlockVersion');
		$vars['wgSnapBlocksWrap'] = $config->get('SnapBlocksWrap');
		$vars['wgSnapBlocksWrapSize'] = $config->get('SnapBlocksWrapSize');
		$vars['wgSnapBlocksZebraColoring'] = $config->get('SnapBlocksZebraColoring');
		$vars['wgSnapBlocksShowSpaces'] = $config->get('SnapBlocksShowSpaces');
		$vars['wgSnapSantaHats'] = $config->get('SnapBlocksSantaHats');
	}

	public static function sb4Setup(Parser $parser) {
		$out = $parser->getOutput();
		if (!in_array(SB_MODULE_KEY, $out->getModules())) {
			$out->addModules([ SB_MODULE_KEY ]);
		}
	}

	public static function sb4RenderTagGeneric($input, array $args, $parser, $tag) {
		self::sb4Setup($parser);
		return Html::element($tag, [
			'class' => 'blocks' . (isset($args['version']) ? '-' . $args['version'] : '')
		], $input);
	}

	// Output HTML for <scratchblocks> tag
	public static function sb4RenderTag($input, array $args, Parser $parser, PPFrame $frame) {
		return self::sb4RenderTagGeneric($input, $args, $parser, 'pre');
	}

	// Output HTML for <snapblocks> tag
	public static function snapblocksRenderTag($input, array $args, Parser $parser, PPFrame $frame) {
		return self::sb4RenderTagGeneric($input, $args, $parser, 'pre');
	}

	// Output HTML for inline <sb> tag
	public static function sb4RenderInlineTag($input, array $args, Parser $parser, PPFrame $frame) {
		return self::sb4RenderTagGeneric($input, $args, $parser, 'code');
	}
}
