<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * Verifies extension SQL schema files exist and describe expected tables.
 *
 * @group Database
 * @coversNothing
 */
class SchemaTablesTest extends MediaWikiIntegrationTestCase {

	public function testTablesJsonExistsAndListsFourTables(): void {
		$path = dirname( __DIR__, 3 ) . '/sql/tables.json';
		$this->assertFileExists( $path );
		$data = json_decode( file_get_contents( $path ), true );
		$this->assertIsArray( $data );
		$names = array_column( $data, 'name' );
		$this->assertContains( 'anwiki_article_review_submission', $names );
		$this->assertContains( 'anwiki_article_review_revision', $names );
		$this->assertContains( 'anwiki_article_review_event', $names );
		$this->assertContains( 'anwiki_article_review_notification', $names );
	}

	public function testMysqlSchemaFileExists(): void {
		$path = dirname( __DIR__, 3 ) . '/sql/mysql/tables.sql';
		$this->assertFileExists( $path );
		$sql = file_get_contents( $path );
		$this->assertStringContainsString( 'anwiki_article_review_submission', $sql );
		$this->assertStringContainsString( 'UNIQUE INDEX', $sql );
		$this->assertStringContainsString( 'aars_submitter_user_id', $sql );
		$this->assertStringContainsString( 'aars_namespace_title', $sql );
	}

	public function testSubmissionTableHasUniqueIndexesInJson(): void {
		$path = dirname( __DIR__, 3 ) . '/sql/tables.json';
		$data = json_decode( file_get_contents( $path ), true );
		$submission = null;
		foreach ( $data as $table ) {
			if ( $table['name'] === 'anwiki_article_review_submission' ) {
				$submission = $table;
				break;
			}
		}
		$this->assertNotNull( $submission );
		$unique = [];
		foreach ( $submission['indexes'] as $idx ) {
			if ( !empty( $idx['unique'] ) ) {
				$unique[] = $idx['name'];
			}
		}
		$this->assertContains( 'aars_submitter_user_id', $unique );
		$this->assertContains( 'aars_namespace_title', $unique );
	}
}
