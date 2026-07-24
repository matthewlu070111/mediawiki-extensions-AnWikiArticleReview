<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Job;

use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;

/**
 * Async job to send a single review notification email via core UserMailer.
 */
class SendReviewNotificationJob extends Job {

	/**
	 * @param array<string, mixed> $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'SendReviewNotificationJob', $params );
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$notificationId = (int)( $this->params['notificationId'] ?? 0 );
		if ( $notificationId <= 0 ) {
			$this->setLastError( 'Missing notificationId' );
			return false;
		}

		$services = MediaWikiServices::getInstance();
		/** @var \MediaWiki\Extension\AnWikiArticleReview\Service\NotificationService $service */
		$service = $services->get( 'AnWikiArticleReview.NotificationService' );

		$status = $service->sendNotification( $notificationId );
		if ( !$status->isOK() ) {
			// Return true so the job is not endlessly retried on permanent failures;
			// failed state is recorded in the notification table for admin retry.
			// Transient failures can still be retried by the admin UI.
			$errors = $status->getErrors();
			$msg = 'send-failed';
			if ( $errors ) {
				$m = $errors[0]['message'] ?? 'send-failed';
				$msg = is_array( $m ) ? (string)( $m[0] ?? 'send-failed' ) : (string)$m;
			}
			$this->setLastError( $msg );
			// Do not fail the job hard — status already persisted as failed
			return true;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function allowRetries(): bool {
		// Idempotent job; MediaWiki may re-queue. sendNotification skips already-sent.
		return true;
	}
}
