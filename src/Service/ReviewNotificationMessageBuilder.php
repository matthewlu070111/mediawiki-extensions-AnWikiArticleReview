<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use MediaWiki\Language\Language;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentity;

/**
 * Builds email subject and plain-text body. Does not send mail or touch SMTP.
 */
class ReviewNotificationMessageBuilder {

	public function __construct(
		private readonly Config $config,
		private readonly TitleFactory $titleFactory,
		private readonly Language $contentLanguage
	) {
	}

	public function buildSubject( string $eventType, Submission $submission ): string {
		$prefix = (string)$this->config->get( 'AnWikiArticleReviewEmailSubjectPrefix' );
		$msgKey = "anwikiarticlereview-email-subject-$eventType";
		// Fallback for unknown event types
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
	 * @param array{pendingCount?:int,contentExcerpt?:string,reviewUrl:string,siteName:string,submitterName:string,submissionId:int,eventType:string,submittedAt:string} $context
	 */
	public function buildBody( array $context ): string {
		$lines = [
			wfMessage( 'anwikiarticlereview-email-body-header' )
				->inContentLanguage()
				->params( $context['siteName'] )
				->text(),
			'',
			wfMessage( 'anwikiarticlereview-email-body-event' )
				->inContentLanguage()
				->params( $context['eventType'] )
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

		$lines[] = '';
		$lines[] = wfMessage( 'anwikiarticlereview-email-body-review-url' )
			->inContentLanguage()
			->params( $context['reviewUrl'] )
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
}
