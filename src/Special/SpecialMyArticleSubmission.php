<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWiki\Extension\AnWikiArticleReview\Service\PreviewRenderer;
use MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionService;
use MediaWiki\Extension\AnWikiArticleReview\Service\TitleValidationService;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Current user's qualification submission: view, resubmit, withdraw.
 */
class SpecialMyArticleSubmission extends SpecialPage {

	public function __construct(
		private readonly SubmissionService $submissionService,
		private readonly TitleValidationService $titleValidation,
		private readonly Config $config,
		private readonly Language $contentLanguage
	) {
		// MW 1.46+: pass only the canonical name; rights via getRestriction().
		parent::__construct( 'MyArticleSubmission' );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'article-review-submit';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->requireNamedUser( 'anwikiarticlereview-loginrequired' );
		$this->checkPermissions();

		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.anWikiArticleReview.submit' );

		$submission = $this->submissionService->getBySubmitter( $user->getId() );

		if ( $submission === null ) {
			$out->addWikiMsg( 'anwikiarticlereview-no-submission' );
			if ( !$this->submissionService->isUserApproved( $user ) ) {
				$out->addHTML( Html::rawElement( 'p', [],
					$this->getLinkRenderer()->makeKnownLink(
						SpecialPage::getTitleFor( 'ChooseArticleTitle' ),
						$this->msg( 'anwikiarticlereview-nav-create' )->text()
					)
				) );
			}
			return;
		}

		// Actions
		if ( $request->wasPosted() ) {
			if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$out->addWikiMsg( 'sessionfailure' );
			} else {
				$action = $request->getVal( 'wpAction' );
				if ( $action === 'withdraw' ) {
					$status = $this->submissionService->withdraw( $user, $submission->getId() );
					if ( $status->isOK() ) {
						$out->addWikiMsg( 'anwikiarticlereview-withdraw-success' );
						$submission = $status->getValue();
					} else {
						$out->addHTML( Html::errorBox( $out->parseAsInterface(
							$status->getWikiText( false, false, $this->getLanguage() )
						) ) );
					}
				} elseif ( $action === 'resubmit' ) {
					$content = $request->getText( 'wpContent' );
					$summary = $request->getText( 'wpSummary' );
					$status = $this->submissionService->resubmit(
						$user,
						$submission->getId(),
						$content,
						$summary
					);
					if ( $status->isOK() ) {
						$out->addWikiMsg( 'anwikiarticlereview-resubmit-success' );
						$submission = $status->getValue();
					} else {
						$out->addHTML( Html::errorBox( $out->parseAsInterface(
							$status->getWikiText( false, false, $this->getLanguage() )
						) ) );
					}
				}
			}
		}

		$this->renderSubmission( $submission );
	}

	private function renderSubmission( Submission $submission ): void {
		$out = $this->getOutput();
		$title = $this->titleValidation->titleFromStored(
			$submission->getNamespace(),
			$submission->getTitle()
		);
		$titleText = $title ? $title->getPrefixedText() : $submission->getTitle();
		$revision = $this->submissionService->getCurrentRevision( $submission );
		$lang = $this->getLanguage();

		$out->addHTML( Html::openElement( 'table', [
			'class' => 'wikitable anwiki-my-submission',
		] ) );
		$rows = [
			[ 'anwikiarticlereview-field-id', (string)$submission->getId() ],
			[ 'anwikiarticlereview-field-title', $titleText ],
			[
				'anwikiarticlereview-field-status',
				$this->msg( SubmissionStatus::getMessageKey( $submission->getStatus() ) )->text(),
			],
			[
				'anwikiarticlereview-field-created',
				$lang->userTimeAndDate( $submission->getCreatedAt(), $this->getUser() ),
			],
			[
				'anwikiarticlereview-field-updated',
				$lang->userTimeAndDate( $submission->getUpdatedAt(), $this->getUser() ),
			],
		];
		if ( $submission->getReviewComment() ) {
			$rows[] = [
				'anwikiarticlereview-field-review-comment',
				$submission->getReviewComment(),
			];
		}
		foreach ( $rows as [ $msg, $value ] ) {
			$out->addHTML( Html::rawElement( 'tr', [],
				Html::element( 'th', [], $this->msg( $msg )->text() )
				. Html::element( 'td', [], $value )
			) );
		}
		$out->addHTML( Html::closeElement( 'table' ) );

		// Current content
		if ( $revision ) {
			$out->addHTML( Html::element( 'h2', [],
				$this->msg( 'anwikiarticlereview-current-content' )->text()
			) );
			$previewTitle = $title ?? Title::newMainPage();
			$html = PreviewRenderer::renderHtml(
				$revision->getContent(),
				$previewTitle,
				$this->getContext()
			);
			$out->addHTML( Html::rawElement( 'div', [
				'class' => 'anwiki-preview mw-parser-output',
			], $html ) );

			$out->addHTML( Html::element( 'h3', [],
				$this->msg( 'anwikiarticlereview-raw-wikitext' )->text()
			) );
			$out->addHTML( Html::element( 'pre', [
				'class' => 'anwiki-raw-content',
			], $revision->getContent() ) );
		}

		// Revision history
		$revisions = $this->submissionService->getRevisions( $submission->getId() );
		if ( count( $revisions ) > 1 ) {
			$out->addHTML( Html::element( 'h2', [],
				$this->msg( 'anwikiarticlereview-revision-history' )->text()
			) );
			$out->addHTML( Html::openElement( 'ul' ) );
			foreach ( $revisions as $rev ) {
				$out->addHTML( Html::element( 'li', [],
					'#' . $rev->getId() . ' — '
					. $lang->userTimeAndDate( $rev->getCreatedAt(), $this->getUser() )
					. ( $rev->getSummary() !== '' ? ' — ' . $rev->getSummary() : '' )
				) );
			}
			$out->addHTML( Html::closeElement( 'ul' ) );
		}

		// Actions
		$token = $this->getUser()->getEditToken();
		$status = $submission->getStatus();

		if ( $status === SubmissionStatus::PENDING
			&& $this->config->get( 'AnWikiArticleReviewAllowWithdraw' )
		) {
			$out->addHTML( Html::openElement( 'form', [
				'method' => 'post',
				'action' => $this->getPageTitle()->getLocalURL(),
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $token,
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpAction', 'value' => 'withdraw',
			] ) );
			$out->addHTML( Html::element( 'button', [
				'type' => 'submit',
				'class' => 'mw-ui-button',
			], $this->msg( 'anwikiarticlereview-withdraw' )->text() ) );
			$out->addHTML( Html::closeElement( 'form' ) );
		}

		if ( SubmissionStatus::canResubmit( $status )
			&& $this->config->get( 'AnWikiArticleReviewAllowResubmit' )
		) {
			$out->addHTML( Html::element( 'h2', [],
				$this->msg( 'anwikiarticlereview-resubmit-header' )->text()
			) );
			$content = $revision ? $revision->getContent() : '';
			$out->addHTML( Html::openElement( 'form', [
				'method' => 'post',
				'action' => $this->getPageTitle()->getLocalURL(),
				'id' => 'anwiki-resubmit-form',
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $token,
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpAction', 'value' => 'resubmit',
			] ) );
			$out->addHTML( Html::element( 'textarea', [
				'name' => 'wpContent',
				'rows' => 25,
				'cols' => 80,
				'class' => 'mw-editfont-monospace',
			], $content ) );
			$out->addHTML( Html::element( 'br' ) );
			$out->addHTML( Html::element( 'label', [ 'for' => 'wpSummary' ],
				$this->msg( 'anwikiarticlereview-summary-label' )->text()
			) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'text',
				'name' => 'wpSummary',
				'id' => 'wpSummary',
				'size' => 60,
			] ) );
			$out->addHTML( Html::element( 'br' ) );
			$out->addHTML( Html::element( 'button', [
				'type' => 'submit',
				'class' => 'mw-ui-button mw-ui-progressive',
			], $this->msg( 'anwikiarticlereview-resubmit' )->text() ) );
			$out->addHTML( Html::closeElement( 'form' ) );
		}

		if ( $status === SubmissionStatus::APPROVED && $title && $title->exists() ) {
			$out->addHTML( Html::rawElement( 'p', [],
				$this->getLinkRenderer()->makeKnownLink(
					$title,
					$this->msg( 'anwikiarticlereview-view-published' )->text()
				)
			) );
		}
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pagetools';
	}
}
