<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Service\PreviewRenderer;
use MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionService;
use MediaWiki\Extension\AnWikiArticleReview\Service\TitleValidationService;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;

/**
 * Step 2: write body, preview, submit for review.
 */
class SpecialSubmitArticle extends SpecialPage {

	public function __construct(
		private readonly TitleValidationService $titleValidation,
		private readonly SubmissionService $submissionService,
		private readonly Config $config,
		private readonly Language $contentLanguage,
		private readonly PermissionManager $permissionManager,
		private readonly UserGroupManager $userGroupManager
	) {
		parent::__construct( 'SubmitArticle', 'article-review-submit' );
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
		$this->checkReadOnly();

		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.anWikiArticleReview.submit' );

		if ( $this->submissionService->isUserApproved( $user ) ) {
			$out->showErrorPage(
				'anwikiarticlereview-already-approved-title',
				'anwikiarticlereview-already-approved'
			);
			return;
		}

		// Existing submission: send to MyArticleSubmission
		$existing = $this->submissionService->getBySubmitter( $user->getId() );
		if ( $existing !== null ) {
			$out->redirect(
				SpecialPage::getTitleFor( 'MyArticleSubmission' )->getLocalURL()
			);
			return;
		}

		// Title from subpage — never trust blindly
		$rawTitle = $subPage !== null && $subPage !== ''
			? str_replace( '_', ' ', $subPage )
			: $request->getText( 'wpPageTitle', '' );

		if ( $rawTitle === '' ) {
			$out->redirect( SpecialPage::getTitleFor( 'ChooseArticleTitle' )->getLocalURL() );
			return;
		}

		$titleStatus = $this->titleValidation->validateForFinalSubmit( $rawTitle );
		if ( !$titleStatus->isOK() ) {
			$this->showTitleError( $titleStatus );
			return;
		}
		/** @var Title $title */
		$title = $titleStatus->getValue();

		// Change title link
		$changeLink = $this->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleFor( 'ChooseArticleTitle' ),
			$this->msg( 'anwikiarticlereview-change-title' )->text()
		);
		$out->addHTML( Html::rawElement( 'p', [ 'class' => 'anwiki-change-title' ], $changeLink ) );

		$content = $request->getText( 'wpContent', '' );
		$summary = $request->getText( 'wpSummary', '' );
		$isPreview = $request->wasPosted() && $request->getCheck( 'wpPreview' );
		$isSubmit = $request->wasPosted() && $request->getCheck( 'wpSave' );

		if ( $request->wasPosted() ) {
			if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$out->addWikiMsg( 'sessionfailure' );
				$this->showForm( $title, $content, $summary );
				return;
			}
		}

		if ( $isPreview ) {
			$this->showPreview( $title, $content, $summary );
			$this->showForm( $title, $content, $summary );
			return;
		}

		if ( $isSubmit ) {
			if ( $user->pingLimiter( 'edit' ) ) {
				$out->addWikiMsg( 'actionthrottledtext' );
				$this->showForm( $title, $content, $summary );
				return;
			}

			// Full re-validation of title at submit time
			$recheck = $this->titleValidation->validateForFinalSubmit( $title->getPrefixedText() );
			if ( !$recheck->isOK() ) {
				$this->showTitleError( $recheck );
				return;
			}

			$status = $this->submissionService->submit(
				$user,
				$title->getPrefixedText(),
				$content,
				$summary
			);

			if ( !$status->isOK() ) {
				$out->addHTML( Html::errorBox( $out->parseAsInterface(
					$status->getWikiText( false, false, $this->getLanguage() )
				) ) );
				$this->showForm( $title, $content, $summary );
				return;
			}

			$out->addWikiMsg( 'anwikiarticlereview-submit-success' );
			$out->addHTML( Html::rawElement( 'p', [],
				$this->getLinkRenderer()->makeKnownLink(
					SpecialPage::getTitleFor( 'MyArticleSubmission' ),
					$this->msg( 'anwikiarticlereview-nav-my-submission' )->text()
				)
			) );
			return;
		}

		$this->showForm( $title, $content, $summary );
	}

	private function showForm( Title $title, string $content, string $summary ): void {
		$out = $this->getOutput();
		$token = $this->getUser()->getEditToken();

		$form = Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle( $title->getPrefixedText() )->getLocalURL(),
			'id' => 'anwiki-submit-form',
			'class' => 'mw-htmlform mw-htmlform-ooui',
		] );

		$form .= Html::element( 'input', [
			'type' => 'hidden',
			'name' => 'wpEditToken',
			'value' => $token,
		] );
		$form .= Html::element( 'input', [
			'type' => 'hidden',
			'name' => 'wpPageTitle',
			'value' => $title->getPrefixedText(),
		] );

		$form .= Html::rawElement( 'div', [ 'class' => 'mw-htmlform-field-HTMLInfoField' ],
			Html::element( 'label', [], $this->msg( 'anwikiarticlereview-page-title-label' )->text() )
			. Html::element( 'div', [ 'class' => 'anwiki-readonly-title' ], $title->getPrefixedText() )
		);

		$form .= Html::rawElement( 'div', [ 'class' => 'mw-htmlform-field-HTMLTextAreaField' ],
			Html::element( 'label', [ 'for' => 'wpContent' ],
				$this->msg( 'anwikiarticlereview-content-label' )->text()
			)
			. Html::element( 'textarea', [
				'name' => 'wpContent',
				'id' => 'wpContent',
				'rows' => 25,
				'cols' => 80,
				'class' => 'mw-editfont-monospace',
			], $content )
		);

		$form .= Html::rawElement( 'div', [ 'class' => 'mw-htmlform-field-HTMLTextField' ],
			Html::element( 'label', [ 'for' => 'wpSummary' ],
				$this->msg( 'anwikiarticlereview-summary-label' )->text()
			)
			. Html::element( 'input', [
				'type' => 'text',
				'name' => 'wpSummary',
				'id' => 'wpSummary',
				'value' => $summary,
				'size' => 60,
				'maxlength' => (int)$this->config->get( 'AnWikiArticleReviewMaxSummaryBytes' ),
			] )
		);

		$form .= Html::rawElement( 'div', [ 'class' => 'mw-htmlform-submit-buttons' ],
			Html::element( 'button', [
				'type' => 'submit',
				'name' => 'wpPreview',
				'value' => '1',
				'class' => 'mw-htmlform-submit mw-ui-button',
			], $this->msg( 'anwikiarticlereview-preview' )->text() )
			. ' '
			. Html::element( 'button', [
				'type' => 'submit',
				'name' => 'wpSave',
				'value' => '1',
				'class' => 'mw-htmlform-submit mw-ui-button mw-ui-progressive',
			], $this->msg( 'anwikiarticlereview-submit-for-review' )->text() )
		);

		$form .= Html::closeElement( 'form' );
		$out->addHTML( $form );
	}

	private function showPreview( Title $title, string $content, string $summary ): void {
		$out = $this->getOutput();
		$out->addHTML( Html::element( 'h2', [],
			$this->msg( 'anwikiarticlereview-preview-header' )->text()
		) );

		if ( $content === '' ) {
			$out->addWikiMsg( 'anwikiarticlereview-content-empty' );
			return;
		}

		// Parse with target title as context; do not create a page
		$html = PreviewRenderer::renderHtml( $content, $title, $this->getContext() );
		$out->addHTML( Html::rawElement( 'div', [
			'class' => 'anwiki-preview mw-parser-output',
		], $html ) );

		if ( $summary !== '' ) {
			$out->addHTML( Html::rawElement( 'p', [ 'class' => 'anwiki-preview-summary' ],
				Html::element( 'strong', [],
					$this->msg( 'anwikiarticlereview-summary-label' )->text()
				) . ' ' . htmlspecialchars( $summary )
			) );
		}
	}

	private function showTitleError( Status $status ): void {
		$out = $this->getOutput();
		$errors = $status->getErrors();
		$first = $errors[0]['message'] ?? 'anwikiarticlereview-title-invalid';

		if ( $first === 'anwikiarticlereview-title-exists'
			&& $status->getValue() instanceof Title
		) {
			/** @var Title $existing */
			$existing = $status->getValue();
			$out->addHTML( Html::errorBox(
				$this->msg( 'anwikiarticlereview-title-exists' )->escaped()
			) );
			$out->addHTML( Html::rawElement( 'p', [],
				$this->getLinkRenderer()->makeKnownLink(
					$existing,
					$this->msg( 'anwikiarticlereview-title-view-existing' )->text()
				)
			) );
		} else {
			$out->addHTML( Html::errorBox( $out->parseAsInterface(
				$status->getWikiText( false, false, $this->getLanguage() )
			) ) );
		}

		$out->addHTML( Html::rawElement( 'p', [],
			$this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'ChooseArticleTitle' ),
				$this->msg( 'anwikiarticlereview-change-title' )->text()
			)
		) );
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pagetools';
	}
}
