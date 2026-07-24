<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Tests\Unit;

use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionStateMachine;
use MediaWikiUnitTestCase;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionStateMachine
 */
class SubmissionStateMachineTest extends MediaWikiUnitTestCase {

	private SubmissionStateMachine $sm;

	protected function setUp(): void {
		parent::setUp();
		$this->sm = new SubmissionStateMachine();
	}

	public function testFirstSubmitAllowed(): void {
		$this->assertTrue(
			$this->sm->canTransition( null, SubmissionStatus::PENDING )
		);
	}

	public function testFirstSubmitCannotApprove(): void {
		$this->assertFalse(
			$this->sm->canTransition( null, SubmissionStatus::APPROVED )
		);
	}

	public function testPendingToApproved(): void {
		$this->assertTrue(
			$this->sm->canTransition(
				SubmissionStatus::PENDING,
				SubmissionStatus::APPROVED
			)
		);
	}

	public function testPendingToRejected(): void {
		$this->assertTrue(
			$this->sm->canTransition(
				SubmissionStatus::PENDING,
				SubmissionStatus::REJECTED
			)
		);
	}

	public function testPendingToWithdrawn(): void {
		$this->assertTrue(
			$this->sm->canTransition(
				SubmissionStatus::PENDING,
				SubmissionStatus::WITHDRAWN
			)
		);
	}

	public function testPendingToConflict(): void {
		$this->assertTrue(
			$this->sm->canTransition(
				SubmissionStatus::PENDING,
				SubmissionStatus::CONFLICT
			)
		);
	}

	public function testRejectedToPending(): void {
		$this->assertTrue(
			$this->sm->canTransition(
				SubmissionStatus::REJECTED,
				SubmissionStatus::PENDING
			)
		);
	}

	public function testWithdrawnToPending(): void {
		$this->assertTrue(
			$this->sm->canTransition(
				SubmissionStatus::WITHDRAWN,
				SubmissionStatus::PENDING
			)
		);
	}

	public function testConflictToPending(): void {
		$this->assertTrue(
			$this->sm->canTransition(
				SubmissionStatus::CONFLICT,
				SubmissionStatus::PENDING
			)
		);
	}

	public function testApprovedHasNoTransitions(): void {
		$this->assertFalse(
			$this->sm->canTransition(
				SubmissionStatus::APPROVED,
				SubmissionStatus::PENDING
			)
		);
		$this->assertFalse(
			$this->sm->canTransition(
				SubmissionStatus::APPROVED,
				SubmissionStatus::REJECTED
			)
		);
	}

	public function testAssertTransitionThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->sm->assertTransition(
			SubmissionStatus::APPROVED,
			SubmissionStatus::PENDING
		);
	}

	public function testAssertTransitionOk(): void {
		$this->sm->assertTransition(
			SubmissionStatus::PENDING,
			SubmissionStatus::APPROVED
		);
		$this->addToAssertionCount( 1 );
	}
}
