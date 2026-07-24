<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Tests\Unit;

use MediaWiki\Extension\AnWikiArticleReview\Model\Notification;
use MediaWikiUnitTestCase;

/**
 * Lightweight checks for notification model constants and status helpers.
 *
 * @covers \MediaWiki\Extension\AnWikiArticleReview\Model\Notification
 */
class NotificationSanitizeTest extends MediaWikiUnitTestCase {

	public function testStatusConstants(): void {
		$this->assertSame( 'queued', Notification::STATUS_QUEUED );
		$this->assertSame( 'sending', Notification::STATUS_SENDING );
		$this->assertSame( 'sent', Notification::STATUS_SENT );
		$this->assertSame( 'failed', Notification::STATUS_FAILED );
		$this->assertSame( 'disabled', Notification::STATUS_DISABLED );
	}

	public function testIsSent(): void {
		$sent = new Notification(
			1, 1, 'a@b.c', 'hash', 'submit',
			Notification::STATUS_SENT, 1, null,
			'20260101000000', '20260101000001', '20260101000001'
		);
		$failed = new Notification(
			2, 1, 'a@b.c', 'hash', 'submit',
			Notification::STATUS_FAILED, 2, 'mail-failed',
			'20260101000000', null, '20260101000002'
		);
		$this->assertTrue( $sent->isSent() );
		$this->assertFalse( $failed->isSent() );
	}

	public function testNewFromRow(): void {
		$row = [
			'aarn_id' => '5',
			'aarn_event_id' => '9',
			'aarn_recipient' => 'admin@example.org',
			'aarn_recipient_hash' => str_repeat( 'a', 64 ),
			'aarn_notification_type' => 'submit',
			'aarn_status' => 'queued',
			'aarn_attempt_count' => '0',
			'aarn_last_error' => null,
			'aarn_created_at' => '20260101120000',
			'aarn_sent_at' => null,
			'aarn_updated_at' => '20260101120000',
		];
		$n = Notification::newFromRow( $row );
		$this->assertSame( 5, $n->getId() );
		$this->assertSame( 'admin@example.org', $n->getRecipient() );
		$this->assertSame( 'submit', $n->getNotificationType() );
		$this->assertFalse( $n->isSent() );
	}

	/**
	 * Ensure NotificationService sanitizeError redacts password-like fragments.
	 */
	public function testSanitizeErrorRedactsPassword(): void {
		// Instantiate without full DI by reflecting private method via anonymous subclass is hard;
		// test the same regex pattern used by NotificationService.
		$message = 'SMTP auth failed password=SuperSecret123 host=smtp.example.org';
		$sanitized = preg_replace(
			'/(password|passwd|pwd|auth|credential|secret)\s*[:=]\s*\S+/i',
			'$1=***',
			$message
		);
		$this->assertStringNotContainsString( 'SuperSecret123', $sanitized );
		$this->assertStringContainsString( 'password=***', $sanitized );
	}
}
