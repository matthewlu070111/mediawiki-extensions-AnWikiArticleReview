<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Tests\Unit;

use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus
 */
class SubmissionStatusTest extends MediaWikiUnitTestCase {

	public function testConstants(): void {
		$this->assertSame( 0, SubmissionStatus::PENDING );
		$this->assertSame( 1, SubmissionStatus::APPROVED );
		$this->assertSame( 2, SubmissionStatus::REJECTED );
		$this->assertSame( 3, SubmissionStatus::WITHDRAWN );
		$this->assertSame( 4, SubmissionStatus::CONFLICT );
	}

	public function testIsValid(): void {
		$this->assertTrue( SubmissionStatus::isValid( SubmissionStatus::PENDING ) );
		$this->assertFalse( SubmissionStatus::isValid( 99 ) );
	}

	public function testGetName(): void {
		$this->assertSame( 'pending', SubmissionStatus::getName( SubmissionStatus::PENDING ) );
		$this->assertSame( 'approved', SubmissionStatus::getName( SubmissionStatus::APPROVED ) );
		$this->assertSame( 'unknown', SubmissionStatus::getName( 99 ) );
	}

	public function testGetMessageKey(): void {
		$this->assertSame(
			'anwikiarticlereview-status-pending',
			SubmissionStatus::getMessageKey( SubmissionStatus::PENDING )
		);
	}

	public function testCanResubmit(): void {
		$this->assertTrue( SubmissionStatus::canResubmit( SubmissionStatus::REJECTED ) );
		$this->assertTrue( SubmissionStatus::canResubmit( SubmissionStatus::WITHDRAWN ) );
		$this->assertTrue( SubmissionStatus::canResubmit( SubmissionStatus::CONFLICT ) );
		$this->assertFalse( SubmissionStatus::canResubmit( SubmissionStatus::PENDING ) );
		$this->assertFalse( SubmissionStatus::canResubmit( SubmissionStatus::APPROVED ) );
	}
}
