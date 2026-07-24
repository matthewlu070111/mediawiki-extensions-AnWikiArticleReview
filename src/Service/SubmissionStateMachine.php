<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use RuntimeException;

/**
 * Centralized submission status transitions.
 */
class SubmissionStateMachine {

	/**
	 * Allowed transitions: from status => list of allowed target statuses.
	 * null key means "no existing record" (first insert).
	 *
	 * @var array<int|string, list<int>>
	 */
	private const ALLOWED = [
		'none' => [ SubmissionStatus::PENDING ],
		SubmissionStatus::PENDING => [
			SubmissionStatus::APPROVED,
			SubmissionStatus::REJECTED,
			SubmissionStatus::WITHDRAWN,
			SubmissionStatus::CONFLICT,
		],
		SubmissionStatus::REJECTED => [
			SubmissionStatus::PENDING,
		],
		SubmissionStatus::WITHDRAWN => [
			SubmissionStatus::PENDING,
		],
		SubmissionStatus::CONFLICT => [
			SubmissionStatus::PENDING,
			SubmissionStatus::REJECTED,
		],
		// APPROVED has no allowed transitions via normal APIs
	];

	/**
	 * Whether a transition is allowed.
	 *
	 * @param int|null $from Null means no existing record
	 */
	public function canTransition( ?int $from, int $to ): bool {
		if ( !SubmissionStatus::isValid( $to ) ) {
			return false;
		}
		if ( $from === null ) {
			return in_array( $to, self::ALLOWED['none'], true );
		}
		if ( !isset( self::ALLOWED[$from] ) ) {
			return false;
		}
		return in_array( $to, self::ALLOWED[$from], true );
	}

	/**
	 * Assert that a transition is allowed; throw otherwise.
	 *
	 * @param int|null $from
	 * @throws RuntimeException
	 */
	public function assertTransition( ?int $from, int $to ): void {
		if ( !$this->canTransition( $from, $to ) ) {
			$fromLabel = $from === null
				? 'none'
				: SubmissionStatus::getName( $from );
			$toLabel = SubmissionStatus::getName( $to );
			throw new RuntimeException(
				"Illegal submission status transition: $fromLabel -> $toLabel"
			);
		}
	}
}
