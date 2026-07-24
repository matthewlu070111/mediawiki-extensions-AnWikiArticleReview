<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Service\TitleValidationService;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;

/**
 * Step 1: choose and pre-check a new article title (no DB reservation).
 */
class SpecialChooseArticleTitle extends FormSpecialPage {

	public function __construct(
		private readonly TitleValidationService $titleValidation,
		private readonly Config $config
	) {
		// MW 1.46+: pass only the canonical name; rights via getRestriction().
		parent::__construct( 'ChooseArticleTitle' );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'article-review-submit';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		// Form POST only performs validation + redirect; no DB writes.
		return false;
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	public function execute( $par ): void {
		$this->requireNamedUser( 'anwikiarticlereview-loginrequired' );
		$this->checkPermissions();
		$this->checkReadOnly();

		// Approved users should not use the qualification flow
		$user = $this->getUser();
		$group = $this->config->get( 'AnWikiArticleReviewApprovedGroup' );
		if ( is_string( $group ) && $group !== '' ) {
			$groups = $this->getMediaWikiServices()
				->getUserGroupManager()
				->getUserGroups( $user );
			if ( in_array( $group, $groups, true ) ) {
				$this->getOutput()->showErrorPage(
					'anwikiarticlereview-already-approved-title',
					'anwikiarticlereview-already-approved'
				);
				return;
			}
		}

		$this->getOutput()->addModuleStyles( 'ext.anWikiArticleReview.chooseTitle' );

		// Prefill from ?title= (must still re-validate on submit).
		// Do not name this helper setParameter(): FormSpecialPage already has a
		// protected setParameter() used by parent::execute(); a private method
		// with the same name is an illegal visibility reduction in PHP and
		// fatals when the class is loaded (breaking Special:SpecialPages).
		$request = $this->getRequest();
		if ( $request->getCheck( 'title' ) && !$request->wasPosted() ) {
			$this->setPrefillTitle( $request->getText( 'title' ) );
		}

		parent::execute( $par );
	}

	/**
	 * Hold optional prefilled title from URL.
	 */
	private string $prefillTitle = '';

	private function setPrefillTitle( string $title ): void {
		$this->prefillTitle = $title;
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		$hintConfig = (string)$this->config->get( 'AnWikiArticleReviewTitleHint' );
		if ( $hintConfig !== '' ) {
			// Plain text only — HTMLForm help will escape via message or we escape ourselves
			$hint = htmlspecialchars( $hintConfig, ENT_QUOTES | ENT_HTML5 );
		} else {
			$hint = $this->msg( 'anwikiarticlereview-title-hint' )->escaped();
		}

		$placeholderConfig = (string)$this->config->get( 'AnWikiArticleReviewTitlePlaceholder' );
		$placeholder = $placeholderConfig !== ''
			? $placeholderConfig
			: $this->msg( 'anwikiarticlereview-title-placeholder' )->text();

		$default = $this->prefillTitle;
		if ( $default === '' ) {
			$default = $this->getRequest()->getText( 'wpTitle', '' );
		}

		return [
			'Title' => [
				'type' => 'text',
				'name' => 'wpTitle',
				'label-message' => 'anwikiarticlereview-title-label',
				'default' => $default,
				'required' => true,
				'placeholder' => $placeholder,
				'help' => $hint,
				// help is raw HTML from us (already escaped config / escaped message)
				'help-raw' => true,
			],
		];
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ): void {
		$form->setSubmitTextMsg( 'anwikiarticlereview-title-continue' );
		$form->setWrapperLegendMsg( 'anwikiarticlereview-title-legend' );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return Status|true
	 */
	public function onSubmit( array $data ) {
		// Rate limit
		if ( $this->getUser()->pingLimiter( 'edit' ) ) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$raw = (string)( $data['Title'] ?? '' );
		$status = $this->titleValidation->validateForSubmission( $raw );

		if ( !$status->isOK() ) {
			// Special handling when page exists: include link value
			$errors = $status->getErrors();
			$first = $errors[0]['message'] ?? '';
			if ( $first === 'anwikiarticlereview-title-exists'
				&& $status->getValue() instanceof Title
			) {
				/** @var Title $existing */
				$existing = $status->getValue();
				$this->existingTitle = $existing;
			}
			return $status;
		}

		/** @var Title $title */
		$title = $status->getValue();
		$this->validatedTitle = $title;
		return Status::newGood();
	}

	private ?Title $validatedTitle = null;
	private ?Title $existingTitle = null;

	/** @inheritDoc */
	public function onSuccess(): void {
		if ( $this->validatedTitle === null ) {
			return;
		}
		// Redirect to SubmitArticle/<normalized title>
		$submit = \MediaWiki\SpecialPage\SpecialPage::getTitleFor(
			'SubmitArticle',
			$this->validatedTitle->getPrefixedText()
		);
		$this->getOutput()->redirect( $submit->getLocalURL() );
	}

	/** @inheritDoc */
	protected function postHtml(): string {
		if ( $this->existingTitle === null ) {
			return '';
		}
		$link = $this->getLinkRenderer()->makeKnownLink(
			$this->existingTitle,
			$this->msg( 'anwikiarticlereview-title-view-existing' )->text()
		);
		return Html::rawElement( 'div', [ 'class' => 'anwiki-title-exists' ],
			Html::rawElement( 'p', [],
				$this->msg( 'anwikiarticlereview-title-exists' )->escaped()
			)
			. Html::rawElement( 'p', [], $link )
		);
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pagetools';
	}

	private function getMediaWikiServices(): \MediaWiki\MediaWikiServices {
		return \MediaWiki\MediaWikiServices::getInstance();
	}
}
