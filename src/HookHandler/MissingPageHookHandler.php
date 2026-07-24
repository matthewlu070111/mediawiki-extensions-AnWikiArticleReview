<?php

namespace MediaWiki\Extension\AnWikiArticleReview\HookHandler;

use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Page\Hook\BeforeDisplayNoArticleTextHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserGroupManager;

/**
 * On missing allowed pages, show a submit-for-review link to unapproved users.
 */
class MissingPageHookHandler implements BeforeDisplayNoArticleTextHook {

	public function __construct(
		private readonly Config $config,
		private readonly UserGroupManager $userGroupManager,
		private readonly PermissionManager $permissionManager
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforeDisplayNoArticleText( $article ): bool {
		if ( !$this->config->get( 'AnWikiArticleReviewShowLinkOnMissingPages' ) ) {
			return true;
		}

		$title = $article->getTitle();
		$user = $article->getContext()->getUser();
		$output = $article->getContext()->getOutput();

		if ( !$user->isRegistered() ) {
			return true;
		}

		if ( !$this->permissionManager->userHasRight( $user, 'article-review-submit' ) ) {
			return true;
		}

		// Approved users can create normally
		$group = $this->config->get( 'AnWikiArticleReviewApprovedGroup' );
		if ( is_string( $group ) && $group !== '' ) {
			$groups = $this->userGroupManager->getUserGroups( $user );
			if ( in_array( $group, $groups, true ) ) {
				return true;
			}
		}

		$allowed = $this->config->get( 'AnWikiArticleReviewAllowedNamespaces' );
		if ( !is_array( $allowed )
			|| !in_array( $title->getNamespace(), $allowed, true )
		) {
			return true;
		}

		$chooseUrl = SpecialPage::getTitleFor( 'ChooseArticleTitle' )->getLocalURL( [
			'title' => $title->getPrefixedText(),
		] );

		$html = Html::rawElement( 'div', [ 'class' => 'anwiki-missing-page-submit' ],
			Html::element( 'p', [],
				$article->getContext()->msg( 'anwikiarticlereview-missing-page-text' )->text()
			)
			. Html::rawElement( 'p', [],
				Html::element( 'a', [
					'href' => $chooseUrl,
					'class' => 'mw-ui-button mw-ui-progressive',
				], $article->getContext()->msg( 'anwikiarticlereview-missing-page-button' )->text() )
			)
		);

		// Append after default no-article text by printing then continuing
		// Returning true keeps default message; we add our prompt below it.
		$output->addHTML( $html );

		return true;
	}
}
