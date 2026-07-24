<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Model;

/**
 * Value object for a submission content revision.
 */
class SubmissionRevision {

	public function __construct(
		private int $id,
		private int $submissionId,
		private int $authorUserId,
		private string $content,
		private string $summary,
		private string $sha1,
		private string $createdAt
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function newFromRow( array $row ): self {
		return new self(
			(int)$row['aarr_id'],
			(int)$row['aarr_submission_id'],
			(int)$row['aarr_author_user_id'],
			(string)$row['aarr_content'],
			(string)$row['aarr_summary'],
			(string)$row['aarr_sha1'],
			(string)$row['aarr_created_at']
		);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getSubmissionId(): int {
		return $this->submissionId;
	}

	public function getAuthorUserId(): int {
		return $this->authorUserId;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function getSummary(): string {
		return $this->summary;
	}

	public function getSha1(): string {
		return $this->sha1;
	}

	public function getCreatedAt(): string {
		return $this->createdAt;
	}
}
