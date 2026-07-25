<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Extension\AnWikiArticleReview\Model\ReviewEvent;
use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWiki\Extension\AnWikiArticleReview\Repository\ReviewEventRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRepository;
use MediaWiki\Status\Status;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Reject and conflict handling for reviewers.
 */
class ReviewService {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly SubmissionRepository $submissionRepository,
		private readonly ReviewEventRepository $eventRepository,
		private readonly SubmissionStateMachine $stateMachine,
		private readonly NotificationService $notificationService
	) {
	}

	/**
	 * Reject a pending submission. Comment is required.
	 *
	 * @return Status<Submission>
	 */
	public function reject(
		UserIdentity $reviewer,
		int $submissionId,
		string $comment
	): Status {
		$comment = trim( $comment );
		if ( $comment === '' ) {
			return Status::newFatal( 'anwikiarticlereview-reject-comment-required' );
		}

		$db = $this->connectionProvider->getPrimaryDatabase();

		try {
			$db->startAtomic( __METHOD__ );

			$submission = $this->submissionRepository->findById( $submissionId, true );
			if ( !$submission ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-submission-not-found' );
			}
			if ( $submission->getStatus() !== SubmissionStatus::PENDING
				&& $submission->getStatus() !== SubmissionStatus::CONFLICT
			) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-not-pending' );
			}

			$oldStatus = $submission->getStatus();
			$this->stateMachine->assertTransition( $oldStatus, SubmissionStatus::REJECTED );

			$now = wfTimestampNow();
			$updated = $this->submissionRepository->update(
				$submission->getId(),
				[
					'aars_status' => SubmissionStatus::REJECTED,
					'aars_updated_at' => $now,
					'aars_reviewer_user_id' => $reviewer->getId(),
					'aars_reviewed_at' => $now,
					'aars_review_comment' => $comment,
				],
				$submission->getRowVersion(),
				$db
			);
			if ( !$updated ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-concurrent-modification' );
			}

			$eventId = $this->eventRepository->insert( [
				'aare_submission_id' => $submission->getId(),
				'aare_actor_user_id' => $reviewer->getId(),
				'aare_action' => ReviewEvent::ACTION_REJECT,
				'aare_old_status' => $oldStatus,
				'aare_new_status' => SubmissionStatus::REJECTED,
				'aare_comment' => $comment,
				'aare_created_at' => $now,
			], $db );

			$db->endAtomic( __METHOD__ );
		} catch ( RuntimeException $e ) {
			if ( $db->explicitTrxActive() ) {
				$db->cancelAtomic( __METHOD__ );
			}
			return Status::newFatal( 'anwikiarticlereview-review-failed' );
		}

		$this->notificationService->queueForEvent(
			$eventId,
			ReviewEvent::ACTION_REJECT,
			$submissionId
		);

		return Status::newGood( $this->submissionRepository->findById( $submissionId ) );
	}

	/**
	 * Mark submission as CONFLICT when formal page already exists.
	 *
	 * @return Status<Submission>
	 */
	public function markConflict(
		UserIdentity $actor,
		int $submissionId,
		?string $comment = null
	): Status {
		$db = $this->connectionProvider->getPrimaryDatabase();

		try {
			$db->startAtomic( __METHOD__ );

			$submission = $this->submissionRepository->findById( $submissionId, true );
			if ( !$submission ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-submission-not-found' );
			}
			if ( $submission->getStatus() !== SubmissionStatus::PENDING ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-not-pending' );
			}

			$oldStatus = $submission->getStatus();
			$this->stateMachine->assertTransition( $oldStatus, SubmissionStatus::CONFLICT );

			$now = wfTimestampNow();
			$updated = $this->submissionRepository->update(
				$submission->getId(),
				[
					'aars_status' => SubmissionStatus::CONFLICT,
					'aars_updated_at' => $now,
					'aars_reviewer_user_id' => $actor->getId(),
					'aars_reviewed_at' => $now,
					'aars_review_comment' => $comment,
				],
				$submission->getRowVersion(),
				$db
			);
			if ( !$updated ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-concurrent-modification' );
			}

			$eventId = $this->eventRepository->insert( [
				'aare_submission_id' => $submission->getId(),
				'aare_actor_user_id' => $actor->getId(),
				'aare_action' => ReviewEvent::ACTION_CONFLICT,
				'aare_old_status' => $oldStatus,
				'aare_new_status' => SubmissionStatus::CONFLICT,
				'aare_comment' => $comment,
				'aare_created_at' => $now,
			], $db );

			$db->endAtomic( __METHOD__ );
		} catch ( RuntimeException $e ) {
			if ( $db->explicitTrxActive() ) {
				$db->cancelAtomic( __METHOD__ );
			}
			return Status::newFatal( 'anwikiarticlereview-review-failed' );
		}

		$this->notificationService->queueForEvent(
			$eventId,
			ReviewEvent::ACTION_CONFLICT,
			$submissionId
		);

		return Status::newGood( $this->submissionRepository->findById( $submissionId ) );
	}

	/**
	 * Admin: reset submission to PENDING.
	 *
	 * @return Status<Submission>
	 */
	public function adminReset(
		UserIdentity $admin,
		int $submissionId,
		?string $comment = null
	): Status {
		$db = $this->connectionProvider->getPrimaryDatabase();

		try {
			$db->startAtomic( __METHOD__ );

			$submission = $this->submissionRepository->findById( $submissionId, true );
			if ( !$submission ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-submission-not-found' );
			}
			if ( $submission->getStatus() === SubmissionStatus::APPROVED ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-cannot-reset-approved' );
			}

			$oldStatus = $submission->getStatus();
			// Allow reset from conflict/rejected/withdrawn via explicit admin path
			if ( !$this->stateMachine->canTransition( $oldStatus, SubmissionStatus::PENDING )
				&& $oldStatus !== SubmissionStatus::PENDING
			) {
				// Force only for non-approved states not covered by SM
				if ( $oldStatus === SubmissionStatus::APPROVED ) {
					$db->endAtomic( __METHOD__ );
					return Status::newFatal( 'anwikiarticlereview-cannot-reset-approved' );
				}
			}

			$now = wfTimestampNow();
			$updated = $this->submissionRepository->update(
				$submission->getId(),
				[
					'aars_status' => SubmissionStatus::PENDING,
					'aars_updated_at' => $now,
					'aars_reviewer_user_id' => null,
					'aars_reviewed_at' => null,
					'aars_review_comment' => null,
				],
				$submission->getRowVersion(),
				$db
			);
			if ( !$updated ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-concurrent-modification' );
			}

			$eventId = $this->eventRepository->insert( [
				'aare_submission_id' => $submission->getId(),
				'aare_actor_user_id' => $admin->getId(),
				'aare_action' => ReviewEvent::ACTION_ADMIN_RESET,
				'aare_old_status' => $oldStatus,
				'aare_new_status' => SubmissionStatus::PENDING,
				'aare_comment' => $comment,
				'aare_created_at' => $now,
			], $db );

			$db->endAtomic( __METHOD__ );
		} catch ( RuntimeException $e ) {
			if ( $db->explicitTrxActive() ) {
				$db->cancelAtomic( __METHOD__ );
			}
			return Status::newFatal( 'anwikiarticlereview-review-failed' );
		}

		$this->notificationService->queueForEvent(
			$eventId,
			ReviewEvent::ACTION_ADMIN_RESET,
			$submissionId
		);

		return Status::newGood( $this->submissionRepository->findById( $submissionId ) );
	}
}
