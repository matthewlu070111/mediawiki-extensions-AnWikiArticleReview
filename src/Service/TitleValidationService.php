<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRepository;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

/**
 * Title normalization and availability checks for submissions.
 */
class TitleValidationService {

	public function __construct(
		private readonly Config $config,
		private readonly TitleFactory $titleFactory,
		private readonly SubmissionRepository $submissionRepository
	) {
	}

	/**
	 * Parse and fully validate a title for submission.
	 *
	 * Does not write any reservation rows (pre-check only).
	 *
	 * @return Status<Title> OK with Title value, or fatal with message key
	 */
	public function validateForSubmission( string $rawTitle ): Status {
		$rawTitle = trim( $rawTitle );
		if ( $rawTitle === '' ) {
			return Status::newFatal( 'anwikiarticlereview-title-empty' );
		}

		$title = $this->titleFactory->newFromText( $rawTitle );
		if ( !$title || !$title->isValid() ) {
			return Status::newFatal( 'anwikiarticlereview-title-invalid' );
		}

		if ( $title->isExternal() || $title->getInterwiki() !== '' ) {
			return Status::newFatal( 'anwikiarticlereview-title-invalid' );
		}

		if ( $title->getText() === '' ) {
			return Status::newFatal( 'anwikiarticlereview-title-invalid' );
		}

		$allowed = $this->config->get( 'AnWikiArticleReviewAllowedNamespaces' );
		if ( !is_array( $allowed ) || !in_array( $title->getNamespace(), $allowed, true ) ) {
			return Status::newFatal( 'anwikiarticlereview-title-namespace-not-allowed' );
		}

		// Special / Media namespaces should never be allowed
		if ( $title->getNamespace() < 0 ) {
			return Status::newFatal( 'anwikiarticlereview-title-namespace-not-allowed' );
		}

		if ( $this->pageExists( $title ) ) {
			$status = Status::newFatal( 'anwikiarticlereview-title-exists' );
			$status->value = $title;
			return $status;
		}

		$existing = $this->submissionRepository->findByTitle(
			$title->getNamespace(),
			$title->getDBkey()
		);
		if ( $existing !== null ) {
			return Status::newFatal( 'anwikiarticlereview-title-under-review' );
		}

		return Status::newGood( $title );
	}

	/**
	 * Re-check title at final submit / approve time.
	 * Optionally ignore a specific submission ID (for resubmit of same record).
	 *
	 * @return Status<Title>
	 */
	public function validateForFinalSubmit(
		string $rawTitle,
		?int $ignoreSubmissionId = null
	): Status {
		$rawTitle = trim( $rawTitle );
		if ( $rawTitle === '' ) {
			return Status::newFatal( 'anwikiarticlereview-title-empty' );
		}

		$title = $this->titleFactory->newFromText( $rawTitle );
		if ( !$title || !$title->isValid() ) {
			return Status::newFatal( 'anwikiarticlereview-title-invalid' );
		}

		if ( $title->isExternal() || $title->getInterwiki() !== '' ) {
			return Status::newFatal( 'anwikiarticlereview-title-invalid' );
		}

		$allowed = $this->config->get( 'AnWikiArticleReviewAllowedNamespaces' );
		if ( !is_array( $allowed ) || !in_array( $title->getNamespace(), $allowed, true ) ) {
			return Status::newFatal( 'anwikiarticlereview-title-namespace-not-allowed' );
		}

		if ( $title->getNamespace() < 0 ) {
			return Status::newFatal( 'anwikiarticlereview-title-namespace-not-allowed' );
		}

		if ( $this->pageExists( $title ) ) {
			$status = Status::newFatal( 'anwikiarticlereview-title-exists' );
			$status->value = $title;
			return $status;
		}

		$existing = $this->submissionRepository->findByTitle(
			$title->getNamespace(),
			$title->getDBkey()
		);
		if ( $existing !== null
			&& ( $ignoreSubmissionId === null || $existing->getId() !== $ignoreSubmissionId )
		) {
			return Status::newFatal( 'anwikiarticlereview-title-under-review' );
		}

		return Status::newGood( $title );
	}

	/**
	 * Build Title from stored namespace + dbkey.
	 */
	public function titleFromStored( int $namespace, string $dbkey ): ?Title {
		return $this->titleFactory->makeTitleSafe( $namespace, $dbkey );
	}

	/**
	 * Whether a formal wiki page already exists (including redirects / blank pages).
	 */
	public function pageExists( Title $title ): bool {
		// Any page table row counts as existing
		return $title->exists() || $title->isAlwaysKnown();
	}

	/**
	 * Check page existence using primary DB for approve-time safety.
	 */
	public function pageExistsOnPrimary( Title $title ): bool {
		// Force fresh ID lookup
		$title->resetArticleID( false );
		return $title->exists();
	}
}
