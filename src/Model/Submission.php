<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Model;

/**
 * Value object for a main submission row.
 */
class Submission {

	public function __construct(
		private int $id,
		private int $submitterUserId,
		private int $namespace,
		private string $title,
		private int $status,
		private ?int $currentRevisionId,
		private string $createdAt,
		private string $updatedAt,
		private ?int $reviewerUserId,
		private ?string $reviewedAt,
		private ?string $reviewComment,
		private ?int $pageId,
		private ?int $publishedRevisionId,
		private int $rowVersion
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function newFromRow( array $row ): self {
		return new self(
			(int)$row['aars_id'],
			(int)$row['aars_submitter_user_id'],
			(int)$row['aars_namespace'],
			(string)$row['aars_title'],
			(int)$row['aars_status'],
			$row['aars_current_revision_id'] !== null
				? (int)$row['aars_current_revision_id'] : null,
			(string)$row['aars_created_at'],
			(string)$row['aars_updated_at'],
			$row['aars_reviewer_user_id'] !== null
				? (int)$row['aars_reviewer_user_id'] : null,
			$row['aars_reviewed_at'] !== null
				? (string)$row['aars_reviewed_at'] : null,
			$row['aars_review_comment'] !== null
				? (string)$row['aars_review_comment'] : null,
			$row['aars_page_id'] !== null
				? (int)$row['aars_page_id'] : null,
			$row['aars_published_revision_id'] !== null
				? (int)$row['aars_published_revision_id'] : null,
			(int)$row['aars_row_version']
		);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getSubmitterUserId(): int {
		return $this->submitterUserId;
	}

	public function getNamespace(): int {
		return $this->namespace;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function getStatus(): int {
		return $this->status;
	}

	public function getCurrentRevisionId(): ?int {
		return $this->currentRevisionId;
	}

	public function getCreatedAt(): string {
		return $this->createdAt;
	}

	public function getUpdatedAt(): string {
		return $this->updatedAt;
	}

	public function getReviewerUserId(): ?int {
		return $this->reviewerUserId;
	}

	public function getReviewedAt(): ?string {
		return $this->reviewedAt;
	}

	public function getReviewComment(): ?string {
		return $this->reviewComment;
	}

	public function getPageId(): ?int {
		return $this->pageId;
	}

	public function getPublishedRevisionId(): ?int {
		return $this->publishedRevisionId;
	}

	public function getRowVersion(): int {
		return $this->rowVersion;
	}

	public function isPending(): bool {
		return $this->status === SubmissionStatus::PENDING;
	}

	public function isApproved(): bool {
		return $this->status === SubmissionStatus::APPROVED;
	}
}
