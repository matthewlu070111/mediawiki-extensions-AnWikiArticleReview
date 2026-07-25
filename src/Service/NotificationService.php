<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Job\SendReviewNotificationJob;
use MediaWiki\Extension\AnWikiArticleReview\Model\Notification;
use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWiki\Extension\AnWikiArticleReview\Repository\NotificationRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\ReviewEventRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRevisionRepository;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Status\Status;
use MediaWiki\User\UserFactory;
use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates notification records and queues async send jobs.
 * Does not send mail inside the submission transaction.
 */
class NotificationService implements LoggerAwareInterface {

	private LoggerInterface $logger;

	public function __construct(
		private readonly Config $config,
		private readonly NotificationRepository $notificationRepository,
		private readonly ReviewEventRepository $eventRepository,
		private readonly SubmissionRepository $submissionRepository,
		private readonly SubmissionRevisionRepository $revisionRepository,
		private readonly ReviewNotificationMessageBuilder $messageBuilder,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly UserFactory $userFactory
	) {
		$this->logger = new NullLogger();
	}

	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * After a successful review event, create notification rows and queue jobs.
	 * Safe no-op when notifications are disabled.
	 *
	 * Sends to:
	 * - Configured admin recipients when the event is in NotificationEvents
	 * - The submitter's account email when NotifySubmitter is on and the event
	 *   is in SubmitterNotificationEvents (approve / reject / re-review, etc.)
	 */
	public function queueForEvent(
		int $eventId,
		string $eventType,
		int $submissionId
	): void {
		if ( !$this->config->get( 'AnWikiArticleReviewEmailNotifications' ) ) {
			return;
		}

		/** @var array<string, array{email:string,audience:string}> $targets */
		$targets = [];

		// Admin / staff recipients
		$adminEvents = $this->config->get( 'AnWikiArticleReviewNotificationEvents' );
		if ( is_array( $adminEvents ) && in_array( $eventType, $adminEvents, true ) ) {
			foreach ( $this->normalizeRecipients(
				$this->config->get( 'AnWikiArticleReviewNotificationRecipients' )
			) as $email ) {
				$key = strtolower( $email ) . '|admin';
				$targets[$key] = [
					'email' => $email,
					'audience' => ReviewNotificationMessageBuilder::AUDIENCE_ADMIN,
				];
			}
		}

		// Submitter (outcome of review: approve / reject / conflict / re-open)
		if ( $this->config->get( 'AnWikiArticleReviewNotifySubmitter' ) ) {
			$submitterEvents = $this->config->get(
				'AnWikiArticleReviewSubmitterNotificationEvents'
			);
			if ( is_array( $submitterEvents )
				&& in_array( $eventType, $submitterEvents, true )
			) {
				$submitterEmail = $this->getSubmitterEmail( $submissionId );
				if ( $submitterEmail !== null ) {
					$key = strtolower( $submitterEmail ) . '|submitter';
					$targets[$key] = [
						'email' => $submitterEmail,
						'audience' => ReviewNotificationMessageBuilder::AUDIENCE_SUBMITTER,
					];
				} else {
					$this->logger->info(
						'AnWikiArticleReview: submitter has no usable email; skip user notify',
						[ 'submissionId' => $submissionId, 'eventType' => $eventType ]
					);
				}
			}
		}

		if ( $targets === [] ) {
			$this->logger->info(
				'AnWikiArticleReview: no notification recipients for event',
				[ 'eventType' => $eventType, 'submissionId' => $submissionId ]
			);
			return;
		}

		$now = wfTimestampNow();
		foreach ( $targets as $target ) {
			$email = $target['email'];
			$audience = $target['audience'];
			// Uniqueness includes audience so admin+submitter both get a row
			// even when the addresses happen to match.
			$notificationType = $this->notificationTypeKey( $eventType, $audience );
			$hash = hash( 'sha256', strtolower( $email ) . '|' . $audience );

			$id = $this->notificationRepository->insertIgnore( [
				'aarn_event_id' => $eventId,
				'aarn_recipient' => $email,
				'aarn_recipient_hash' => $hash,
				'aarn_notification_type' => $notificationType,
				'aarn_status' => Notification::STATUS_QUEUED,
				'aarn_attempt_count' => 0,
				'aarn_last_error' => null,
				'aarn_created_at' => $now,
				'aarn_sent_at' => null,
				'aarn_updated_at' => $now,
			] );

			// If insertIgnore skipped (duplicate), look up existing
			if ( $id === null ) {
				$existing = $this->notificationRepository->findByUniquenessKey(
					$eventId,
					$hash,
					$notificationType
				);
				if ( $existing === null || $existing->isSent() ) {
					continue;
				}
				$id = $existing->getId();
			}

			$this->jobQueueGroup->push(
				new SendReviewNotificationJob( [
					'notificationId' => $id,
				] )
			);
		}
	}

	/**
	 * Send a single notification (called from Job).
	 * Idempotent: already-sent notifications are skipped.
	 */
	public function sendNotification( int $notificationId ): Status {
		$notification = $this->notificationRepository->findById( $notificationId, true );
		if ( !$notification ) {
			return Status::newFatal( 'anwikiarticlereview-notification-not-found' );
		}

		if ( $notification->isSent() ) {
			return Status::newGood( [ 'skipped' => true, 'reason' => 'already-sent' ] );
		}

		if ( !$this->config->get( 'AnWikiArticleReviewEmailNotifications' ) ) {
			$this->notificationRepository->update( $notificationId, [
				'aarn_status' => Notification::STATUS_DISABLED,
				'aarn_updated_at' => wfTimestampNow(),
			] );
			return Status::newGood( [ 'skipped' => true, 'reason' => 'disabled' ] );
		}

		if ( !filter_var( $notification->getRecipient(), FILTER_VALIDATE_EMAIL ) ) {
			$this->markFailed( $notificationId, $notification, 'invalid-recipient' );
			return Status::newFatal( 'anwikiarticlereview-email-invalid-recipient' );
		}

		$event = $this->eventRepository->findById( $notification->getEventId() );
		if ( !$event ) {
			$this->markFailed( $notificationId, $notification, 'event-missing' );
			return Status::newFatal( 'anwikiarticlereview-notification-not-found' );
		}

		$submission = $this->submissionRepository->findById( $event->getSubmissionId() );
		if ( !$submission ) {
			$this->markFailed( $notificationId, $notification, 'submission-missing' );
			return Status::newFatal( 'anwikiarticlereview-submission-not-found' );
		}

		$submitter = $this->userFactory->newFromId( $submission->getSubmitterUserId() );
		$submitterName = $submitter ? $submitter->getName() : '(unknown)';

		$excerpt = '';
		$revId = $submission->getCurrentRevisionId();
		if ( $revId !== null ) {
			$rev = $this->revisionRepository->findById( $revId );
			if ( $rev ) {
				$excerpt = $this->messageBuilder->buildExcerpt( $rev->getContent() );
			}
		}

		$pendingCount = $this->submissionRepository->countByStatus( SubmissionStatus::PENDING );
		[ $eventType, $audience ] = $this->parseNotificationType(
			$notification->getNotificationType(),
			$event->getAction()
		);

		$reviewComment = $event->getComment()
			?? $submission->getReviewComment()
			?? '';

		$context = [
			'siteName' => (string)$this->config->get( 'Sitename' ),
			'eventType' => $eventType,
			'eventLabel' => $this->messageBuilder->formatEventLabel( $eventType ),
			'title' => $this->messageBuilder->formatTitle( $submission ),
			'submitterName' => $submitterName,
			'submissionId' => $submission->getId(),
			'submittedAt' => $event->getCreatedAt(),
			'reviewUrl' => $this->messageBuilder->buildReviewUrl( $submission->getId() ),
			'submissionUrl' => $this->messageBuilder->buildMySubmissionUrl(),
			'publishedUrl' => $this->messageBuilder->buildPublishedPageUrl( $submission ),
			'reviewComment' => $reviewComment,
			'pendingCount' => $pendingCount,
			'contentExcerpt' => $excerpt,
			'audience' => $audience,
		];

		// Admin mails keep pending count + excerpt; submitter mails do not need them.
		if ( $audience === ReviewNotificationMessageBuilder::AUDIENCE_SUBMITTER ) {
			unset( $context['pendingCount'], $context['contentExcerpt'] );
		}

		$subject = $this->messageBuilder->buildSubject(
			$eventType,
			$submission,
			$audience
		);
		$body = $this->messageBuilder->buildBody( $context );

		$now = wfTimestampNow();
		$this->notificationRepository->update( $notificationId, [
			'aarn_status' => Notification::STATUS_SENDING,
			'aarn_attempt_count' => $notification->getAttemptCount() + 1,
			'aarn_updated_at' => $now,
		] );

		$to = new MailAddress( $notification->getRecipient() );
		$fromEmail = (string)( $this->config->get( 'PasswordSender' )
			?: $this->config->get( 'EmergencyContact' ) );
		$from = new MailAddress( $fromEmail );

		try {
			$status = UserMailer::send( $to, $from, $subject, $body );
		} catch ( \Throwable $e ) {
			$errorCode = $this->sanitizeError( $e->getMessage() );
			$this->markFailed( $notificationId, $notification, $errorCode );
			$this->logger->warning( 'AnWikiArticleReview email send failed', [
				'notificationId' => $notificationId,
				'error' => $errorCode,
			] );
			return Status::newFatal( 'anwikiarticlereview-email-send-failed' );
		}

		if ( !$status->isOK() ) {
			$errors = $status->getErrors();
			$msg = $errors[0]['message'] ?? 'mail-failed';
			if ( is_array( $msg ) ) {
				$msg = $msg[0] ?? 'mail-failed';
			}
			$errorCode = $this->sanitizeError( (string)$msg );
			$this->markFailed( $notificationId, $notification, $errorCode );
			return Status::newFatal( 'anwikiarticlereview-email-send-failed' );
		}

		$this->notificationRepository->update( $notificationId, [
			'aarn_status' => Notification::STATUS_SENT,
			'aarn_sent_at' => wfTimestampNow(),
			'aarn_updated_at' => wfTimestampNow(),
			'aarn_last_error' => null,
		] );

		return Status::newGood( [ 'sent' => true ] );
	}

	/**
	 * Admin retry of a failed notification.
	 */
	public function retry( int $notificationId ): Status {
		$notification = $this->notificationRepository->findById( $notificationId );
		if ( !$notification ) {
			return Status::newFatal( 'anwikiarticlereview-notification-not-found' );
		}
		if ( $notification->isSent() ) {
			return Status::newGood( [ 'skipped' => true, 'reason' => 'already-sent' ] );
		}

		$this->notificationRepository->update( $notificationId, [
			'aarn_status' => Notification::STATUS_QUEUED,
			'aarn_updated_at' => wfTimestampNow(),
		] );

		$this->jobQueueGroup->push(
			new SendReviewNotificationJob( [
				'notificationId' => $notificationId,
			] )
		);

		return Status::newGood( [ 'queued' => true ] );
	}

	/**
	 * @return list<Notification>
	 */
	public function listNotifications(
		?string $status = null,
		int $limit = 50,
		int $offset = 0
	): array {
		return $this->notificationRepository->listByStatus( $status, $limit, $offset );
	}

	public function getNotification( int $id ): ?Notification {
		return $this->notificationRepository->findById( $id );
	}

	/**
	 * Send a one-off test email via core mailer (maintenance script).
	 */
	public function sendTestEmail( string $toAddress ): Status {
		if ( !filter_var( $toAddress, FILTER_VALIDATE_EMAIL ) ) {
			return Status::newFatal( 'anwikiarticlereview-email-invalid-recipient' );
		}

		$prefix = (string)$this->config->get( 'AnWikiArticleReviewEmailSubjectPrefix' );
		$subject = trim( $prefix . ' ' . wfMessage( 'anwikiarticlereview-email-subject-test' )
			->inContentLanguage()
			->text() );
		$body = wfMessage( 'anwikiarticlereview-email-body-test' )
			->inContentLanguage()
			->params( (string)$this->config->get( 'Sitename' ) )
			->text();

		$to = new MailAddress( $toAddress );
		$fromEmail = $this->config->get( 'PasswordSender' )
			?: $this->config->get( 'EmergencyContact' );
		$from = new MailAddress( (string)$fromEmail );

		try {
			return UserMailer::send( $to, $from, $subject, $body );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'AnWikiArticleReview test email failed', [
				'error' => $this->sanitizeError( $e->getMessage() ),
			] );
			return Status::newFatal( 'anwikiarticlereview-email-send-failed' );
		}
	}

	/**
	 * @return ?string Valid email or null
	 */
	private function getSubmitterEmail( int $submissionId ): ?string {
		$submission = $this->submissionRepository->findById( $submissionId );
		if ( !$submission ) {
			return null;
		}
		$user = $this->userFactory->newFromId( $submission->getSubmitterUserId() );
		if ( !$user || !$user->isRegistered() ) {
			return null;
		}
		$email = trim( $user->getEmail() );
		if ( $email === '' || !filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return null;
		}
		return $email;
	}

	private function notificationTypeKey( string $eventType, string $audience ): string {
		if ( $audience === ReviewNotificationMessageBuilder::AUDIENCE_SUBMITTER ) {
			return $eventType . '-submitter';
		}
		return $eventType;
	}

	/**
	 * @return array{0:string,1:string} [ eventType, audience ]
	 */
	private function parseNotificationType( string $stored, string $fallbackEvent ): array {
		if ( str_ends_with( $stored, '-submitter' ) ) {
			$eventType = substr( $stored, 0, -strlen( '-submitter' ) );
			if ( $eventType === '' ) {
				$eventType = $fallbackEvent;
			}
			return [ $eventType, ReviewNotificationMessageBuilder::AUDIENCE_SUBMITTER ];
		}
		// Legacy rows and admin mails store the raw event type.
		$eventType = $stored !== '' ? $stored : $fallbackEvent;
		return [ $eventType, ReviewNotificationMessageBuilder::AUDIENCE_ADMIN ];
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function normalizeRecipients( $raw ): array {
		if ( !is_array( $raw ) ) {
			return [];
		}
		$out = [];
		$seen = [];
		foreach ( $raw as $email ) {
			if ( !is_string( $email ) ) {
				continue;
			}
			$email = trim( $email );
			if ( $email === '' || !filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}
			$key = strtolower( $email );
			if ( isset( $seen[$key] ) ) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $email;
		}
		return $out;
	}

	private function markFailed(
		int $notificationId,
		Notification $notification,
		string $errorCode
	): void {
		$this->notificationRepository->update( $notificationId, [
			'aarn_status' => Notification::STATUS_FAILED,
			'aarn_attempt_count' => $notification->getAttemptCount() + 1,
			'aarn_last_error' => $errorCode,
			'aarn_updated_at' => wfTimestampNow(),
		] );
	}

	/**
	 * Strip secrets and oversized details from error messages.
	 */
	private function sanitizeError( string $message ): string {
		// Never leak password-like tokens
		$message = preg_replace(
			'/(password|passwd|pwd|auth|credential|secret)\s*[:=]\s*\S+/i',
			'$1=***',
			$message
		) ?? $message;
		$message = preg_replace( '/\b[A-Za-z0-9+\/]{40,}\b/', '***', $message ) ?? $message;
		if ( strlen( $message ) > 500 ) {
			$message = substr( $message, 0, 500 ) . '…';
		}
		return $message;
	}
}
