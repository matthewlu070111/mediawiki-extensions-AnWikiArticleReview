<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Model\ReviewEvent;
use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionRevision;
use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWiki\Extension\AnWikiArticleReview\Repository\ReviewEventRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRevisionRepository;
use MediaWiki\Status\Status;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

/**
 * First submit, resubmit, withdraw, and read helpers.
 */
class SubmissionService {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly SubmissionRepository $submissionRepository,
		private readonly SubmissionRevisionRepository $revisionRepository,
		private readonly ReviewEventRepository $eventRepository,
		private readonly SubmissionStateMachine $stateMachine,
		private readonly TitleValidationService $titleValidation,
		private readonly NotificationService $notificationService,
		private readonly Config $config,
		private readonly UserGroupManager $userGroupManager
	) {
	}

	public function getById( int $id ): ?Submission {
		return $this->submissionRepository->findById( $id );
	}

	public function getBySubmitter( int $userId ): ?Submission {
		return $this->submissionRepository->findBySubmitter( $userId );
	}

	/**
	 * @return list<Submission>
	 */
	public function listByStatus( ?int $status, int $limit = 50, int $offset = 0 ): array {
		return $this->submissionRepository->listByStatus( $status, $limit, $offset );
	}

	public function countByStatus( ?int $status = null ): int {
		return $this->submissionRepository->countByStatus( $status );
	}

	public function getCurrentRevision( Submission $submission ): ?SubmissionRevision {
		$id = $submission->getCurrentRevisionId();
		if ( $id === null ) {
			return null;
		}
		return $this->revisionRepository->findById( $id );
	}

	/**
	 * @return list<SubmissionRevision>
	 */
	public function getRevisions( int $submissionId ): array {
		return $this->revisionRepository->findBySubmission( $submissionId );
	}

	/**
	 * @return list<\MediaWiki\Extension\AnWikiArticleReview\Model\ReviewEvent>
	 */
	public function getEvents( int $submissionId ): array {
		return $this->eventRepository->findBySubmission( $submissionId );
	}

	/**
	 * Whether the user is already in the approved editor group.
	 */
	public function isUserApproved( UserIdentity $user ): bool {
		$group = $this->config->get( 'AnWikiArticleReviewApprovedGroup' );
		if ( !is_string( $group ) || $group === '' ) {
			return false;
		}
		return in_array(
			$group,
			$this->userGroupManager->getUserGroups( $user ),
			true
		);
	}

	/**
	 * Validate content size limits.
	 */
	public function validateContent( string $content, string $summary ): Status {
		$min = (int)$this->config->get( 'AnWikiArticleReviewMinContentBytes' );
		$max = (int)$this->config->get( 'AnWikiArticleReviewMaxContentBytes' );
		$maxSummary = (int)$this->config->get( 'AnWikiArticleReviewMaxSummaryBytes' );

		$len = strlen( $content );
		if ( $len === 0 ) {
			return Status::newFatal( 'anwikiarticlereview-content-empty' );
		}
		if ( $len < $min ) {
			return Status::newFatal( 'anwikiarticlereview-content-too-short', $min );
		}
		if ( $len > $max ) {
			return Status::newFatal( 'anwikiarticlereview-content-too-long', $max );
		}
		if ( strlen( $summary ) > $maxSummary ) {
			return Status::newFatal( 'anwikiarticlereview-summary-too-long', $maxSummary );
		}
		return Status::newGood();
	}

	/**
	 * First submission.
	 *
	 * @return Status<Submission>
	 */
	public function submit(
		UserIdentity $user,
		string $rawTitle,
		string $content,
		string $summary
	): Status {
		if ( !$user->isRegistered() ) {
			return Status::newFatal( 'anwikiarticlereview-loginrequired' );
		}
		if ( $this->isUserApproved( $user ) ) {
			return Status::newFatal( 'anwikiarticlereview-already-approved' );
		}

		$contentStatus = $this->validateContent( $content, $summary );
		if ( !$contentStatus->isOK() ) {
			return $contentStatus;
		}

		$titleStatus = $this->titleValidation->validateForFinalSubmit( $rawTitle );
		if ( !$titleStatus->isOK() ) {
			return $titleStatus;
		}
		/** @var \MediaWiki\Title\Title $title */
		$title = $titleStatus->getValue();

		$existing = $this->submissionRepository->findBySubmitter( $user->getId() );
		if ( $existing !== null ) {
			return Status::newFatal( 'anwikiarticlereview-user-has-submission' );
		}

		$now = wfTimestampNow();
		$db = $this->connectionProvider->getPrimaryDatabase();

		try {
			$db->startAtomic( __METHOD__ );

			$this->stateMachine->assertTransition( null, SubmissionStatus::PENDING );

			$submissionId = $this->submissionRepository->insert( [
				'aars_submitter_user_id' => $user->getId(),
				'aars_namespace' => $title->getNamespace(),
				'aars_title' => $title->getDBkey(),
				'aars_status' => SubmissionStatus::PENDING,
				'aars_current_revision_id' => null,
				'aars_created_at' => $now,
				'aars_updated_at' => $now,
				'aars_reviewer_user_id' => null,
				'aars_reviewed_at' => null,
				'aars_review_comment' => null,
				'aars_page_id' => null,
				'aars_published_revision_id' => null,
				'aars_row_version' => 1,
			], $db );

			$revisionId = $this->revisionRepository->insert( [
				'aarr_submission_id' => $submissionId,
				'aarr_author_user_id' => $user->getId(),
				'aarr_content' => $content,
				'aarr_summary' => $summary,
				'aarr_sha1' => sha1( $content ),
				'aarr_created_at' => $now,
			], $db );

			$this->submissionRepository->update( $submissionId, [
				'aars_current_revision_id' => $revisionId,
			], null, $db );

			$eventId = $this->eventRepository->insert( [
				'aare_submission_id' => $submissionId,
				'aare_actor_user_id' => $user->getId(),
				'aare_action' => ReviewEvent::ACTION_SUBMIT,
				'aare_old_status' => null,
				'aare_new_status' => SubmissionStatus::PENDING,
				'aare_comment' => null,
				'aare_created_at' => $now,
			], $db );

			$db->endAtomic( __METHOD__ );
		} catch ( \Wikimedia\Rdbms\DBQueryError $e ) {
			if ( $db->explicitTrxActive() ) {
				$db->cancelAtomic( __METHOD__ );
			}
			return $this->mapUniqueConstraintError( $e, $user->getId(), $title );
		} catch ( RuntimeException $e ) {
			if ( $db->explicitTrxActive() ) {
				$db->cancelAtomic( __METHOD__ );
			}
			return Status::newFatal( 'anwikiarticlereview-submit-failed' );
		}

		// Queue notifications after successful commit
		$this->notificationService->queueForEvent(
			$eventId,
			ReviewEvent::ACTION_SUBMIT,
			$submissionId
		);

		$submission = $this->submissionRepository->findById( $submissionId );
		return Status::newGood( $submission );
	}

	/**
	 * Resubmit after reject / withdraw / conflict.
	 *
	 * @return Status<Submission>
	 */
	public function resubmit(
		UserIdentity $user,
		int $submissionId,
		string $content,
		string $summary
	): Status {
		if ( !$this->config->get( 'AnWikiArticleReviewAllowResubmit' ) ) {
			return Status::newFatal( 'anwikiarticlereview-resubmit-disabled' );
		}
		if ( !$user->isRegistered() ) {
			return Status::newFatal( 'anwikiarticlereview-loginrequired' );
		}
		if ( $this->isUserApproved( $user ) ) {
			return Status::newFatal( 'anwikiarticlereview-already-approved' );
		}

		$contentStatus = $this->validateContent( $content, $summary );
		if ( !$contentStatus->isOK() ) {
			return $contentStatus;
		}

		$db = $this->connectionProvider->getPrimaryDatabase();

		try {
			$db->startAtomic( __METHOD__ );

			$submission = $this->submissionRepository->findById( $submissionId, true );
			if ( !$submission ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-submission-not-found' );
			}
			if ( $submission->getSubmitterUserId() !== $user->getId() ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-submission-not-yours' );
			}
			if ( !SubmissionStatus::canResubmit( $submission->getStatus() ) ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-cannot-resubmit' );
			}

			$title = $this->titleValidation->titleFromStored(
				$submission->getNamespace(),
				$submission->getTitle()
			);
			if ( !$title ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-title-invalid' );
			}

			$titleStatus = $this->titleValidation->validateForFinalSubmit(
				$title->getPrefixedText(),
				$submission->getId()
			);
			if ( !$titleStatus->isOK() ) {
				$db->endAtomic( __METHOD__ );
				return $titleStatus;
			}

			$oldStatus = $submission->getStatus();
			$this->stateMachine->assertTransition( $oldStatus, SubmissionStatus::PENDING );

			$now = wfTimestampNow();
			$revisionId = $this->revisionRepository->insert( [
				'aarr_submission_id' => $submission->getId(),
				'aarr_author_user_id' => $user->getId(),
				'aarr_content' => $content,
				'aarr_summary' => $summary,
				'aarr_sha1' => sha1( $content ),
				'aarr_created_at' => $now,
			], $db );

			$updated = $this->submissionRepository->update(
				$submission->getId(),
				[
					'aars_status' => SubmissionStatus::PENDING,
					'aars_current_revision_id' => $revisionId,
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
				'aare_actor_user_id' => $user->getId(),
				'aare_action' => ReviewEvent::ACTION_RESUBMIT,
				'aare_old_status' => $oldStatus,
				'aare_new_status' => SubmissionStatus::PENDING,
				'aare_comment' => null,
				'aare_created_at' => $now,
			], $db );

			$db->endAtomic( __METHOD__ );
		} catch ( RuntimeException $e ) {
			if ( $db->explicitTrxActive() ) {
				$db->cancelAtomic( __METHOD__ );
			}
			return Status::newFatal( 'anwikiarticlereview-submit-failed' );
		}

		$this->notificationService->queueForEvent(
			$eventId,
			ReviewEvent::ACTION_RESUBMIT,
			$submissionId
		);

		return Status::newGood( $this->submissionRepository->findById( $submissionId ) );
	}

	/**
	 * Withdraw a pending submission.
	 *
	 * @return Status<Submission>
	 */
	public function withdraw( UserIdentity $user, int $submissionId ): Status {
		if ( !$this->config->get( 'AnWikiArticleReviewAllowWithdraw' ) ) {
			return Status::newFatal( 'anwikiarticlereview-withdraw-disabled' );
		}
		if ( !$user->isRegistered() ) {
			return Status::newFatal( 'anwikiarticlereview-loginrequired' );
		}

		$db = $this->connectionProvider->getPrimaryDatabase();

		try {
			$db->startAtomic( __METHOD__ );

			$submission = $this->submissionRepository->findById( $submissionId, true );
			if ( !$submission ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-submission-not-found' );
			}
			if ( $submission->getSubmitterUserId() !== $user->getId() ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-submission-not-yours' );
			}
			if ( $submission->getStatus() !== SubmissionStatus::PENDING ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-cannot-withdraw' );
			}

			$oldStatus = $submission->getStatus();
			$this->stateMachine->assertTransition( $oldStatus, SubmissionStatus::WITHDRAWN );

			$now = wfTimestampNow();
			$updated = $this->submissionRepository->update(
				$submission->getId(),
				[
					'aars_status' => SubmissionStatus::WITHDRAWN,
					'aars_updated_at' => $now,
				],
				$submission->getRowVersion(),
				$db
			);
			if ( !$updated ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-concurrent-modification' );
			}

			$this->eventRepository->insert( [
				'aare_submission_id' => $submission->getId(),
				'aare_actor_user_id' => $user->getId(),
				'aare_action' => ReviewEvent::ACTION_WITHDRAW,
				'aare_old_status' => $oldStatus,
				'aare_new_status' => SubmissionStatus::WITHDRAWN,
				'aare_comment' => null,
				'aare_created_at' => $now,
			], $db );

			$db->endAtomic( __METHOD__ );
		} catch ( RuntimeException $e ) {
			if ( $db->explicitTrxActive() ) {
				$db->cancelAtomic( __METHOD__ );
			}
			return Status::newFatal( 'anwikiarticlereview-submit-failed' );
		}

		return Status::newGood( $this->submissionRepository->findById( $submissionId ) );
	}

	/**
	 * Map unique constraint failures to user-facing status messages.
	 */
	private function mapUniqueConstraintError(
		\Wikimedia\Rdbms\DBQueryError $e,
		int $userId,
		\MediaWiki\Title\Title $title
	): Status {
		// Re-check which unique key was hit without exposing SQL details
		$byUser = $this->submissionRepository->findBySubmitter( $userId );
		if ( $byUser !== null ) {
			return Status::newFatal( 'anwikiarticlereview-user-has-submission' );
		}
		$byTitle = $this->submissionRepository->findByTitle(
			$title->getNamespace(),
			$title->getDBkey()
		);
		if ( $byTitle !== null ) {
			return Status::newFatal( 'anwikiarticlereview-title-under-review' );
		}
		return Status::newFatal( 'anwikiarticlereview-submit-failed' );
	}
}
