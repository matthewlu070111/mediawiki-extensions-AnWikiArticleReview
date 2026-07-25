<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Service\PreviewRenderer;
use MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionService;
use MediaWiki\Extension\AnWikiArticleReview\Service\TitleValidationService;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
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
		// MW 1.46+: pass only the canonical name; rights via getRestriction().
		parent::__construct( 'SubmitArticle' );
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
		$this->checkReadOnly();

		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.anWikiArticleReview.submit' );
		$this->loadEditorModules();

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

		// Prefer standard edit field name so WikiEditor/CodeMirror find it.
		$content = $request->getText( 'wpTextbox1', $request->getText( 'wpContent', '' ) );
		$summary = $request->getText( 'wpSummary', '' );
		$isPreview = $request->wasPosted() && (
			$request->getCheck( 'wpPreview' ) || $request->getVal( 'wpPreview' ) !== null
		);
		$isSubmit = $request->wasPosted() && (
			$request->getCheck( 'wpSave' ) || $request->getVal( 'wpSave' ) !== null
		);

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

	/**
	 * Load WikiEditor (and related styles) when available.
	 */
	private function loadEditorModules(): void {
		$out = $this->getOutput();
		$out->addModules( 'ext.anWikiArticleReview.editor' );

		// Match core edit-page toolbar when WikiEditor is installed.
		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikiEditor' ) ) {
			$out->addModules( 'ext.wikiEditor' );
			$out->addModuleStyles( 'ext.wikiEditor.styles' );
		}
	}

	private function showForm( Title $title, string $content, string $summary ): void {
		$out = $this->getOutput();
		$token = $this->getUser()->getEditToken();

		$form = Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle( $title->getPrefixedText() )->getLocalURL(),
			'id' => 'anwiki-submit-form',
			'class' => 'mw-editform anwiki-editform',
			'enctype' => 'multipart/form-data',
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

		$form .= Html::rawElement( 'div', [ 'class' => 'anwiki-page-title-field' ],
			Html::element( 'label', [], $this->msg( 'anwikiarticlereview-page-title-label' )->text() )
			. Html::element( 'div', [ 'class' => 'anwiki-readonly-title' ], $title->getPrefixedText() )
		);

		// Standard edit textarea identity so WikiEditor auto-init / mw.addWikiEditor work.
		$form .= Html::rawElement( 'div', [ 'class' => 'editOptions anwiki-content-field' ],
			Html::element( 'label', [ 'for' => 'wpTextbox1' ],
				$this->msg( 'anwikiarticlereview-content-label' )->text()
			)
			. Html::textarea( 'wpTextbox1', $content, [
				'id' => 'wpTextbox1',
				'cols' => 80,
				'rows' => 25,
				'class' => 'mw-editfont-monospace',
				'accesskey' => ',',
				'tabindex' => 1,
			] )
		);

		$form .= Html::rawElement( 'div', [ 'class' => 'anwiki-summary-field' ],
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
				'tabindex' => 2,
			] )
		);

		$form .= Html::rawElement( 'div', [ 'class' => 'editButtons anwiki-edit-buttons' ],
			Html::element( 'input', [
				'type' => 'submit',
				'name' => 'wpPreview',
				'id' => 'wpPreview',
				'value' => $this->msg( 'anwikiarticlereview-preview' )->text(),
				'class' => 'mw-ui-button',
				'tabindex' => 3,
			] )
			. ' '
			. Html::element( 'input', [
				'type' => 'submit',
				'name' => 'wpSave',
				'id' => 'wpSave',
				'value' => $this->msg( 'anwikiarticlereview-submit-for-review' )->text(),
				'class' => 'mw-ui-button mw-ui-progressive',
				'tabindex' => 4,
			] )
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
