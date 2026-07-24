<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Content\ContentHandler;
use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

/**
 * Renders wikitext for preview without creating a page.
 */
class PreviewRenderer {

	/**
	 * Return safe HTML for a wikitext body in the context of $title.
	 */
	public static function renderHtml(
		string $wikitext,
		Title $title,
		IContextSource $context
	): string {
		$popts = ParserOptions::newFromContext( $context );
		$content = ContentHandler::makeContent(
			$wikitext,
			$title,
			CONTENT_MODEL_WIKITEXT
		);

		$services = MediaWikiServices::getInstance();
		if ( $services->hasService( 'ContentRenderer' ) ) {
			$parserOutput = $services->getContentRenderer()->getParserOutput(
				$content,
				$title,
				null,
				$popts
			);
		} else {
			// Fallback for environments that still expose Content::getParserOutput
			// @phan-suppress-next-line PhanUndeclaredMethod
			$parserOutput = $content->getParserOutput( $title, null, $popts );
		}

		if ( method_exists( $parserOutput, 'getContentHolderText' ) ) {
			return $parserOutput->getContentHolderText();
		}

		return $parserOutput->getText();
	}
}
