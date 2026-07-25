<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use MediaWiki\Language\Language;
use MediaWiki\Title\TitleFactory;

/**
 * Builds email subject and plain-text body. Does not send mail or touch SMTP.
 */
class ReviewNotificationMessageBuilder {

	public const AUDIENCE_ADMIN = 'admin';
	public const AUDIENCE_SUBMITTER = 'submitter';

	public function __construct(
		private readonly Config $config,
		private readonly TitleFactory $titleFactory,
		private readonly Language $contentLanguage
	) {
	}

	public function buildSubject(
		string $eventType,
		Submission $submission,
		string $audience = self::AUDIENCE_ADMIN
	): string {
		$prefix = (string)$this->config->get( 'AnWikiArticleReviewEmailSubjectPrefix' );
		$msgKey = $audience === self::AUDIENCE_SUBMITTER
			? "anwikiarticlereview-email-subject-submitter-$eventType"
			: "anwikiarticlereview-email-subject-$eventType";

		// Fallback chain: submitter-specific → admin event key → generic
		if ( !wfMessage( $msgKey )->exists() ) {
			$msgKey = "anwikiarticlereview-email-subject-$eventType";
		}
		if ( !wfMessage( $msgKey )->exists() ) {
			$msgKey = 'anwikiarticlereview-email-subject-generic';
		}
		$subject = wfMessage( $msgKey )
			->inContentLanguage()
			->params( $this->formatTitle( $submission ) )
			->text();
		return trim( $prefix . ' ' . $subject );
	}

	/**
	 * @param array{
	 *   pendingCount?:int,
	 *   contentExcerpt?:string,
	 *   reviewUrl?:string,
	 *   submissionUrl?:string,
	 *   publishedUrl?:string,
	 *   reviewComment?:string,
	 *   siteName:string,
	 *   submitterName:string,
	 *   submissionId:int,
	 *   eventType:string,
	 *   eventLabel:string,
	 *   submittedAt:string,
	 *   audience?:string
	 * } $context
	 */
	public function buildBody( array $context ): string {
		$audience = $context['audience'] ?? self::AUDIENCE_ADMIN;
		if ( $audience === self::AUDIENCE_SUBMITTER ) {
			return $this->buildSubmitterBody( $context );
		}
		return $this->buildAdminBody( $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function buildAdminBody( array $context ): string {
		$lines = [
			wfMessage( 'anwikiarticlereview-email-body-header' )
				->inContentLanguage()
				->params( $context['siteName'] )
				->text(),
			'',
			wfMessage( 'anwikiarticlereview-email-body-event' )
				->inContentLanguage()
				->params( $context['eventLabel'] ?? $context['eventType'] )
				->text(),
			wfMessage( 'anwikiarticlereview-email-body-title' )
				->inContentLanguage()
				->params( $context['title'] ?? '' )
				->text(),
			wfMessage( 'anwikiarticlereview-email-body-submitter' )
				->inContentLanguage()
				->params( $context['submitterName'] )
				->text(),
			wfMessage( 'anwikiarticlereview-email-body-submission-id' )
				->inContentLanguage()
				->params( (string)$context['submissionId'] )
				->text(),
			wfMessage( 'anwikiarticlereview-email-body-time' )
				->inContentLanguage()
				->params( $context['submittedAt'] )
				->text(),
		];

		if ( isset( $context['pendingCount'] ) ) {
			$lines[] = wfMessage( 'anwikiarticlereview-email-body-pending-count' )
				->inContentLanguage()
				->numParams( $context['pendingCount'] )
				->text();
		}

		if ( !empty( $context['reviewComment'] ) ) {
			$lines[] = wfMessage( 'anwikiarticlereview-email-body-review-comment' )
				->inContentLanguage()
				->params( $context['reviewComment'] )
				->text();
		}

		$lines[] = '';
		$lines[] = wfMessage( 'anwikiarticlereview-email-body-review-url' )
			->inContentLanguage()
			->params( $context['reviewUrl'] ?? '' )
			->text();

		if ( !empty( $context['contentExcerpt'] ) ) {
			$lines[] = '';
			$lines[] = wfMessage( 'anwikiarticlereview-email-body-excerpt-header' )
				->inContentLanguage()
				->text();
			$lines[] = $context['contentExcerpt'];
		}

		$lines[] = '';
		$lines[] = wfMessage( 'anwikiarticlereview-email-body-footer' )
			->inContentLanguage()
			->text();

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function buildSubmitterBody( array $context ): string {
		$eventType = (string)$context['eventType'];
		$introKey = "anwikiarticlereview-email-body-submitter-intro-$eventType";
		if ( !wfMessage( $introKey )->exists() ) {
			$introKey = 'anwikiarticlereview-email-body-submitter-intro-generic';
		}

		$lines = [
			wfMessage( 'anwikiarticlereview-email-body-header' )
				->inContentLanguage()
				->params( $context['siteName'] )
				->text(),
			'',
			wfMessage( $introKey )
				->inContentLanguage()
				->params( $context['title'] ?? '' )
				->text(),
			'',
			wfMessage( 'anwikiarticlereview-email-body-title' )
				->inContentLanguage()
				->params( $context['title'] ?? '' )
				->text(),
			wfMessage( 'anwikiarticlereview-email-body-submission-id' )
				->inContentLanguage()
				->params( (string)$context['submissionId'] )
				->text(),
			wfMessage( 'anwikiarticlereview-email-body-time' )
				->inContentLanguage()
				->params( $context['submittedAt'] )
				->text(),
		];

		if ( !empty( $context['reviewComment'] ) ) {
			$lines[] = wfMessage( 'anwikiarticlereview-email-body-review-comment' )
				->inContentLanguage()
				->params( $context['reviewComment'] )
				->text();
		}

		$lines[] = '';

		if ( $eventType === 'approve' && !empty( $context['publishedUrl'] ) ) {
			$lines[] = wfMessage( 'anwikiarticlereview-email-body-published-url' )
				->inContentLanguage()
				->params( $context['publishedUrl'] )
				->text();
		}

		if ( !empty( $context['submissionUrl'] ) ) {
			$lines[] = wfMessage( 'anwikiarticlereview-email-body-submission-url' )
				->inContentLanguage()
				->params( $context['submissionUrl'] )
				->text();
		}

		$lines[] = '';
		$lines[] = wfMessage( 'anwikiarticlereview-email-body-footer-submitter' )
			->inContentLanguage()
			->text();

		return implode( "\n", $lines );
	}

	public function formatTitle( Submission $submission ): string {
		$title = $this->titleFactory->makeTitleSafe(
			$submission->getNamespace(),
			$submission->getTitle()
		);
		if ( $title ) {
			return $title->getPrefixedText();
		}
		return $submission->getTitle();
	}

	/**
	 * Plain-text excerpt of wikitext, length-limited.
	 */
	public function buildExcerpt( string $content ): string {
		if ( !$this->config->get( 'AnWikiArticleReviewEmailIncludeContentExcerpt' ) ) {
			return '';
		}
		$max = (int)$this->config->get( 'AnWikiArticleReviewEmailContentExcerptLength' );
		// Strip some wikitext noise for readability
		$plain = preg_replace( '/\{\{[^}]*\}\}/s', '', $content ) ?? $content;
		$plain = preg_replace( '/\[\[([^|\]]*\|)?([^\]]*)\]\]/', '$2', $plain ) ?? $plain;
		$plain = preg_replace( "/'{2,}/", '', $plain ) ?? $plain;
		$plain = preg_replace( '/\s+/', ' ', $plain ) ?? $plain;
		$plain = trim( $plain );
		if ( strlen( $plain ) > $max ) {
			$plain = substr( $plain, 0, $max ) . '…';
		}
		return $plain;
	}

	public function buildReviewUrl( int $submissionId ): string {
		$title = $this->titleFactory->newFromText(
			'Special:ArticleReview/view/' . $submissionId
		);
		if ( !$title ) {
			return '';
		}
		return $title->getFullURL();
	}

	public function buildMySubmissionUrl(): string {
		$title = $this->titleFactory->newFromText( 'Special:MyArticleSubmission' );
		if ( !$title ) {
			return '';
		}
		return $title->getFullURL();
	}

	public function buildPublishedPageUrl( Submission $submission ): string {
		$title = $this->titleFactory->makeTitleSafe(
			$submission->getNamespace(),
			$submission->getTitle()
		);
		if ( !$title ) {
			return '';
		}
		return $title->getFullURL();
	}

	/**
	 * Human-readable event label for admin emails.
	 */
	public function formatEventLabel( string $eventType ): string {
		$msgKey = "anwikiarticlereview-email-event-label-$eventType";
		if ( wfMessage( $msgKey )->exists() ) {
			return wfMessage( $msgKey )->inContentLanguage()->text();
		}
		return $eventType;
	}
}
