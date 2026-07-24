<?php
/**
 * Send a test review notification email via MediaWiki core mail.
 *
 * Usage:
 *   php maintenance/run.php AnWikiArticleReview:SendTestReviewEmail --to=admin@example.org
 *
 * Or (legacy):
 *   php extensions/AnWikiArticleReview/maintenance/SendTestReviewEmail.php --to=admin@example.org
 *
 * @file
 * @ingroup MediaWiki
 */

namespace MediaWiki\Extension\AnWikiArticleReview\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Maintenance script: send test email.
 */
class SendTestReviewEmail extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Send a test AnWikiArticleReview notification email via MediaWiki core mail.'
		);
		$this->addOption(
			'to',
			'Recipient email address (defaults to first configured notification recipient)',
			false,
			true
		);
		$this->requireExtension( 'AnWikiArticleReview' );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		$to = $this->getOption( 'to' );
		if ( $to === null || $to === '' ) {
			$recipients = $config->get( 'AnWikiArticleReviewNotificationRecipients' );
			if ( is_array( $recipients ) && $recipients !== [] ) {
				$to = (string)$recipients[0];
			}
		}

		if ( $to === null || $to === '' ) {
			$this->fatalError(
				'No recipient. Pass --to=address@example.org or configure '
				. '$wgAnWikiArticleReviewNotificationRecipients.'
			);
		}

		if ( !filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
			$this->fatalError( "Invalid email address: $to" );
		}

		if ( !$config->get( 'EnableEmail' ) ) {
			$this->output( "WARNING: \$wgEnableEmail is false. Send may fail.\n" );
		}

		/** @var \MediaWiki\Extension\AnWikiArticleReview\Service\NotificationService $service */
		$service = $services->get( 'AnWikiArticleReview.NotificationService' );

		$this->output( "Sending test email to $to ...\n" );
		// Never print SMTP password or full config
		$status = $service->sendTestEmail( $to );

		if ( $status->isOK() ) {
			$this->output( "SUCCESS: Test email accepted by MediaWiki mail layer.\n" );
			$this->output(
				"If the message does not arrive, check SMTP, spam folder, JobQueue, and logs.\n"
			);
			return;
		}

		$errors = $status->getErrors();
		$summary = 'unknown error';
		if ( $errors ) {
			$m = $errors[0]['message'] ?? 'unknown';
			$summary = is_array( $m ) ? (string)( $m[0] ?? 'unknown' ) : (string)$m;
		}
		// Sanitize: never echo credentials
		$summary = preg_replace(
			'/(password|passwd|pwd|secret)\s*[:=]\s*\S+/i',
			'$1=***',
			$summary
		) ?? $summary;

		$this->fatalError( "FAILED: $summary", 1 );
	}
}

// @codeCoverageIgnoreStart
$maintClass = SendTestReviewEmail::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
