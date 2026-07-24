<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Repository;

use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionRevision;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Persistence for submission content revisions (append-only).
 */
class SubmissionRevisionRepository {

	public const TABLE = 'anwiki_article_review_revision';

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	private function getReplica(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase();
	}

	private function getPrimary(): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase();
	}

	public function findById( int $id ): ?SubmissionRevision {
		$row = $this->getReplica()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'aarr_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? SubmissionRevision::newFromRow( (array)$row ) : null;
	}

	/**
	 * @return list<SubmissionRevision>
	 */
	public function findBySubmission( int $submissionId ): array {
		$rows = $this->getReplica()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'aarr_submission_id' => $submissionId ] )
			->orderBy( 'aarr_created_at', 'DESC' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$result = [];
		foreach ( $rows as $row ) {
			$result[] = SubmissionRevision::newFromRow( (array)$row );
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public function insert( array $fields, ?IDatabase $db = null ): int {
		$db ??= $this->getPrimary();
		$db->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( $fields )
			->caller( __METHOD__ )
			->execute();
		return $db->insertId();
	}
}
