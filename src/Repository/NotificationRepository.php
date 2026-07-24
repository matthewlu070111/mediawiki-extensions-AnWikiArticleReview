<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Repository;

use MediaWiki\Extension\AnWikiArticleReview\Model\Notification;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Persistence for email notification records.
 */
class NotificationRepository {

	public const TABLE = 'anwiki_article_review_notification';

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

	public function findById( int $id, bool $forUpdate = false ): ?Notification {
		$db = $forUpdate ? $this->getPrimary() : $this->getReplica();
		$query = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'aarn_id' => $id ] )
			->caller( __METHOD__ );
		if ( $forUpdate ) {
			$query->forUpdate();
		}
		$row = $query->fetchRow();
		return $row ? Notification::newFromRow( (array)$row ) : null;
	}

	/**
	 * @return list<Notification>
	 */
	public function findByEvent( int $eventId ): array {
		$rows = $this->getReplica()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [ 'aarn_event_id' => $eventId ] )
			->orderBy( 'aarn_id', 'ASC' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$result = [];
		foreach ( $rows as $row ) {
			$result[] = Notification::newFromRow( (array)$row );
		}
		return $result;
	}

	/**
	 * @return list<Notification>
	 */
	public function listByStatus(
		?string $status = null,
		int $limit = 50,
		int $offset = 0
	): array {
		$query = $this->getReplica()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->orderBy( 'aarn_updated_at', 'DESC' )
			->limit( $limit )
			->offset( $offset )
			->caller( __METHOD__ );
		if ( $status !== null ) {
			$query->where( [ 'aarn_status' => $status ] );
		}
		$rows = $query->fetchResultSet();
		$result = [];
		foreach ( $rows as $row ) {
			$result[] = Notification::newFromRow( (array)$row );
		}
		return $result;
	}

	/**
	 * Insert notification. Returns ID, or null if unique key conflict.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function insertIgnore( array $fields, ?IDatabase $db = null ): ?int {
		$db ??= $this->getPrimary();
		$db->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->ignore()
			->row( $fields )
			->caller( __METHOD__ )
			->execute();
		if ( $db->affectedRows() === 0 ) {
			return null;
		}
		return $db->insertId();
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public function update( int $id, array $fields, ?IDatabase $db = null ): bool {
		$db ??= $this->getPrimary();
		$db->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( $fields )
			->where( [ 'aarn_id' => $id ] )
			->caller( __METHOD__ )
			->execute();
		return $db->affectedRows() > 0;
	}

	/**
	 * Find existing notification by uniqueness key.
	 */
	public function findByUniquenessKey(
		int $eventId,
		string $recipientHash,
		string $type
	): ?Notification {
		$row = $this->getReplica()->newSelectQueryBuilder()
			->select( '*' )
			->from( self::TABLE )
			->where( [
				'aarn_event_id' => $eventId,
				'aarn_recipient_hash' => $recipientHash,
				'aarn_notification_type' => $type,
			] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? Notification::newFromRow( (array)$row ) : null;
	}
}
