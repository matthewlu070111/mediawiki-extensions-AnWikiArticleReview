<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Model;

/**
 * Value object for an email notification record.
 */
class Notification {

	public const STATUS_QUEUED = 'queued';
	public const STATUS_SENDING = 'sending';
	public const STATUS_SENT = 'sent';
	public const STATUS_FAILED = 'failed';
	public const STATUS_DISABLED = 'disabled';

	public function __construct(
		private int $id,
		private int $eventId,
		private string $recipient,
		private string $recipientHash,
		private string $notificationType,
		private string $status,
		private int $attemptCount,
		private ?string $lastError,
		private string $createdAt,
		private ?string $sentAt,
		private string $updatedAt
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function newFromRow( array $row ): self {
		return new self(
			(int)$row['aarn_id'],
			(int)$row['aarn_event_id'],
			(string)$row['aarn_recipient'],
			(string)$row['aarn_recipient_hash'],
			(string)$row['aarn_notification_type'],
			(string)$row['aarn_status'],
			(int)$row['aarn_attempt_count'],
			$row['aarn_last_error'] !== null
				? (string)$row['aarn_last_error'] : null,
			(string)$row['aarn_created_at'],
			$row['aarn_sent_at'] !== null
				? (string)$row['aarn_sent_at'] : null,
			(string)$row['aarn_updated_at']
		);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getEventId(): int {
		return $this->eventId;
	}

	public function getRecipient(): string {
		return $this->recipient;
	}

	public function getRecipientHash(): string {
		return $this->recipientHash;
	}

	public function getNotificationType(): string {
		return $this->notificationType;
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function getAttemptCount(): int {
		return $this->attemptCount;
	}

	public function getLastError(): ?string {
		return $this->lastError;
	}

	public function getCreatedAt(): string {
		return $this->createdAt;
	}

	public function getSentAt(): ?string {
		return $this->sentAt;
	}

	public function getUpdatedAt(): string {
		return $this->updatedAt;
	}

	public function isSent(): bool {
		return $this->status === self::STATUS_SENT;
	}
}
