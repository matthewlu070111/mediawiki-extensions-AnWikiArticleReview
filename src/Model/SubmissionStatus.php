<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Model;

/**
 * Submission status constants and helpers.
 */
final class SubmissionStatus {

	public const PENDING = 0;
	public const APPROVED = 1;
	public const REJECTED = 2;
	public const WITHDRAWN = 3;
	public const CONFLICT = 4;

	/** @var array<int, string> */
	private const NAMES = [
		self::PENDING => 'pending',
		self::APPROVED => 'approved',
		self::REJECTED => 'rejected',
		self::WITHDRAWN => 'withdrawn',
		self::CONFLICT => 'conflict',
	];

	/**
	 * @return list<int>
	 */
	public static function all(): array {
		return [
			self::PENDING,
			self::APPROVED,
			self::REJECTED,
			self::WITHDRAWN,
			self::CONFLICT,
		];
	}

	public static function isValid( int $status ): bool {
		return isset( self::NAMES[$status] );
	}

	public static function getName( int $status ): string {
		return self::NAMES[$status] ?? 'unknown';
	}

	/**
	 * Message key for UI display of a status.
	 */
	public static function getMessageKey( int $status ): string {
		$name = self::getName( $status );
		return "anwikiarticlereview-status-$name";
	}

	/**
	 * Statuses from which a user may resubmit.
	 *
	 * @return list<int>
	 */
	public static function resubmittable(): array {
		return [
			self::REJECTED,
			self::WITHDRAWN,
			self::CONFLICT,
		];
	}

	public static function canResubmit( int $status ): bool {
		return in_array( $status, self::resubmittable(), true );
	}
}
