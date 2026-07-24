<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Extension\AnWikiArticleReview\Model\ReviewEvent;
use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWiki\Extension\AnWikiArticleReview\Repository\ReviewEventRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRevisionRepository;
use MediaWiki\Status\Status;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Idempotent approval: create page, promote user, record audit.
 */
class ApprovalService {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly SubmissionRepository $submissionRepository,
		private readonly SubmissionRevisionRepository $revisionRepository,
		private readonly ReviewEventRepository $eventRepository,
		private readonly SubmissionStateMachine $stateMachine,
		private readonly TitleValidationService $titleValidation,
		private readonly ArticlePublisher $articlePublisher,
		private readonly NotificationService $notificationService,
		private readonly UserFactory $userFactory
	) {
	}

	/**
	 * Approve a pending submission.
	 *
	 * Idempotent: concurrent approvers — only one succeeds; the other sees "already processed".
	 *
	 * @return Status<Submission>
	 */
	public function approve(
		UserIdentity $reviewer,
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

			// Already approved — treat as success (idempotent)
			if ( $submission->getStatus() === SubmissionStatus::APPROVED ) {
				$db->endAtomic( __METHOD__ );
				return Status::newGood( $submission );
			}

			if ( $submission->getStatus() !== SubmissionStatus::PENDING ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-not-pending' );
			}

			$revisionId = $submission->getCurrentRevisionId();
			if ( $revisionId === null ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-no-content' );
			}

			$revision = $this->revisionRepository->findById( $revisionId );
			if ( !$revision ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-no-content' );
			}

			$title = $this->titleValidation->titleFromStored(
				$submission->getNamespace(),
				$submission->getTitle()
			);
			if ( !$title ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-title-invalid' );
			}

			// Re-check formal page does not exist
			if ( $this->titleValidation->pageExistsOnPrimary( $title ) ) {
				// Transition to CONFLICT inside same transaction path
				$oldStatus = $submission->getStatus();
				$this->stateMachine->assertTransition( $oldStatus, SubmissionStatus::CONFLICT );
				$now = wfTimestampNow();
				$this->submissionRepository->update(
					$submission->getId(),
					[
						'aars_status' => SubmissionStatus::CONFLICT,
						'aars_updated_at' => $now,
						'aars_reviewer_user_id' => $reviewer->getId(),
						'aars_reviewed_at' => $now,
						'aars_review_comment' => $comment,
					],
					$submission->getRowVersion(),
					$db
				);
				$eventId = $this->eventRepository->insert( [
					'aare_submission_id' => $submission->getId(),
					'aare_actor_user_id' => $reviewer->getId(),
					'aare_action' => ReviewEvent::ACTION_CONFLICT,
					'aare_old_status' => $oldStatus,
					'aare_new_status' => SubmissionStatus::CONFLICT,
					'aare_comment' => $comment,
					'aare_created_at' => $now,
				], $db );
				$db->endAtomic( __METHOD__ );

				$this->notificationService->queueForEvent(
					$eventId,
					ReviewEvent::ACTION_CONFLICT,
					$submissionId
				);

				return Status::newFatal( 'anwikiarticlereview-publish-page-exists' );
			}

			$submitter = $this->userFactory->newFromId( $submission->getSubmitterUserId() );
			if ( !$submitter || !$submitter->isRegistered() ) {
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-publish-user-invalid' );
			}

			$oldStatus = $submission->getStatus();
			$this->stateMachine->assertTransition( $oldStatus, SubmissionStatus::APPROVED );

			// Create formal page (uses core PageUpdater — may open nested ops)
			$publishStatus = $this->articlePublisher->publish(
				$title,
				$revision->getContent(),
				$submitter
			);
			if ( !$publishStatus->isOK() ) {
				// Do not mark APPROVED if page creation failed
				$db->endAtomic( __METHOD__ );
				return $publishStatus;
			}

			/** @var array{pageId:int,revisionId:int} $publishResult */
			$publishResult = $publishStatus->getValue();

			// Promote user after successful page creation
			$this->articlePublisher->promoteUser( $submitter );

			$now = wfTimestampNow();
			$updated = $this->submissionRepository->update(
				$submission->getId(),
				[
					'aars_status' => SubmissionStatus::APPROVED,
					'aars_updated_at' => $now,
					'aars_reviewer_user_id' => $reviewer->getId(),
					'aars_reviewed_at' => $now,
					'aars_review_comment' => $comment,
					'aars_page_id' => $publishResult['pageId'],
					'aars_published_revision_id' => $publishResult['revisionId'],
				],
				$submission->getRowVersion(),
				$db
			);
			if ( !$updated ) {
				// Page was created but row update lost the race — rare.
				// Status remains not APPROVED in DB; admin can reconcile.
				// We do not delete the page (safer than silent dual pages).
				$db->endAtomic( __METHOD__ );
				return Status::newFatal( 'anwikiarticlereview-concurrent-modification' );
			}

			$eventId = $this->eventRepository->insert( [
				'aare_submission_id' => $submission->getId(),
				'aare_actor_user_id' => $reviewer->getId(),
				'aare_action' => ReviewEvent::ACTION_APPROVE,
				'aare_old_status' => $oldStatus,
				'aare_new_status' => SubmissionStatus::APPROVED,
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
			ReviewEvent::ACTION_APPROVE,
			$submissionId
		);

		return Status::newGood( $this->submissionRepository->findById( $submissionId ) );
	}
}
