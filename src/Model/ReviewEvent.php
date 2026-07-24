<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Model;

/**
 * Value object for a review audit event.
 */
class ReviewEvent {

	public const ACTION_SUBMIT = 'submit';
	public const ACTION_RESUBMIT = 'resubmit';
	public const ACTION_REJECT = 'reject';
	public const ACTION_APPROVE = 'approve';
	public const ACTION_WITHDRAW = 'withdraw';
	public const ACTION_CONFLICT = 'conflict';
	public const ACTION_ADMIN_RESET = 'admin-reset';
	public const ACTION_ADMIN_REASSIGN = 'admin-reassign';
	public const ACTION_ADMIN_RETITLE = 'admin-retitle';

	public function __construct(
		private int $id,
		private int $submissionId,
		private int $actorUserId,
		private string $action,
		private ?int $oldStatus,
		private int $newStatus,
		private ?string $comment,
		private string $createdAt
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function newFromRow( array $row ): self {
		return new self(
			(int)$row['aare_id'],
			(int)$row['aare_submission_id'],
			(int)$row['aare_actor_user_id'],
			(string)$row['aare_action'],
			$row['aare_old_status'] !== null
				? (int)$row['aare_old_status'] : null,
			(int)$row['aare_new_status'],
			$row['aare_comment'] !== null
				? (string)$row['aare_comment'] : null,
			(string)$row['aare_created_at']
		);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getSubmissionId(): int {
		return $this->submissionId;
	}

	public function getActorUserId(): int {
		return $this->actorUserId;
	}

	public function getAction(): string {
		return $this->action;
	}

	public function getOldStatus(): ?int {
		return $this->oldStatus;
	}

	public function getNewStatus(): int {
		return $this->newStatus;
	}

	public function getComment(): ?string {
		return $this->comment;
	}

	public function getCreatedAt(): string {
		return $this->createdAt;
	}
}
