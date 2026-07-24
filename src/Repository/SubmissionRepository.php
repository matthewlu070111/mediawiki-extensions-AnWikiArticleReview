<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Repository;

use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Persistence for main submission rows.
 */
class SubmissionRepository {

	public const TABLE = 'anwiki_article_review_submission';

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

	public function findById( int $id, bool $forUpdate = false ): ?Submission {
		$db = $forUpdate ? $this->getPrimary() : $this->getReplica();
		$query = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'aars_id' => $id ] )
			->caller( __METHOD__ );
		if ( $forUpdate ) {
			$query->forUpdate();
		}
		$row = $query->fetchRow();
		return $row ? Submission::newFromRow( (array)$row ) : null;
	}

	public function findBySubmitter( int $userId, bool $forUpdate = false ): ?Submission {
		$db = $forUpdate ? $this->getPrimary() : $this->getReplica();
		$query = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'aars_submitter_user_id' => $userId ] )
			->caller( __METHOD__ );
		if ( $forUpdate ) {
			$query->forUpdate();
		}
		$row = $query->fetchRow();
		return $row ? Submission::newFromRow( (array)$row ) : null;
	}

	public function findByTitle(
		int $namespace,
		string $titleDbKey,
		bool $forUpdate = false
	): ?Submission {
		$db = $forUpdate ? $this->getPrimary() : $this->getReplica();
		$query = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [
				'aars_namespace' => $namespace,
				'aars_title' => $titleDbKey,
			] )
			->caller( __METHOD__ );
		if ( $forUpdate ) {
			$query->forUpdate();
		}
		$row = $query->fetchRow();
		return $row ? Submission::newFromRow( (array)$row ) : null;
	}

	/**
	 * List submissions filtered by status (null = all).
	 * Always reads from the main table only (one row per submission).
	 *
	 * @return list<Submission>
	 */
	public function listByStatus(
		?int $status = null,
		int $limit = 50,
		int $offset = 0
	): array {
		$query = $this->getReplica()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->orderBy( 'aars_updated_at', 'DESC' )
			->limit( $limit )
			->offset( $offset )
			->caller( __METHOD__ );
		if ( $status !== null ) {
			$query->where( [ 'aars_status' => $status ] );
		}
		$rows = $query->fetchResultSet();
		$result = [];
		foreach ( $rows as $row ) {
			$result[] = Submission::newFromRow( (array)$row );
		}
		return $result;
	}

	public function countByStatus( ?int $status = null ): int {
		$query = $this->getReplica()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->caller( __METHOD__ );
		if ( $status !== null ) {
			$query->where( [ 'aars_status' => $status ] );
		}
		return (int)$query->fetchField();
	}

	/**
	 * Insert a new submission. Returns the new ID.
	 * Throws on unique constraint violation.
	 *
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

	/**
	 * Update submission fields with optimistic row version check when provided.
	 *
	 * @param array<string, mixed> $fields
	 * @return bool True if a row was updated
	 */
	public function update(
		int $id,
		array $fields,
		?int $expectedRowVersion = null,
		?IDatabase $db = null
	): bool {
		$db ??= $this->getPrimary();
		$conds = [ 'aars_id' => $id ];
		if ( $expectedRowVersion !== null ) {
			$conds['aars_row_version'] = $expectedRowVersion;
			if ( !isset( $fields['aars_row_version'] ) ) {
				$fields['aars_row_version'] = $expectedRowVersion + 1;
			}
		}
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( $fields )
			->where( $conds )
			->caller( __METHOD__ )
			->execute();
		return $db->affectedRows() > 0;
	}

	/**
	 * @return list<string>
	 */
	public function getPrimaryKeyFields(): array {
		return [ 'aars_id' ];
	}
}
