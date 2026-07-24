<?php

namespace MediaWiki\Extension\AnWikiArticleReview\HookHandler;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Registers database schema updates for AnWikiArticleReview.
 */
class SchemaHookHandler implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		$dir = dirname( __DIR__, 2 ) . '/sql';
		$dbType = $updater->getDB()->getType();
		$schemaDir = "$dir/$dbType";
		if ( !is_dir( $schemaDir ) ) {
			$schemaDir = "$dir/mysql";
		}

		// Creates all four tables when the primary table is missing.
		// Running update.php twice is safe (idempotent).
		$updater->addExtensionTable(
			'anwiki_article_review_submission',
			"$schemaDir/tables.sql"
		);
	}
}
