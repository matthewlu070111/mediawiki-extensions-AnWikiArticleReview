<?php

namespace MediaWiki\Extension\AnWikiArticleReview\HookHandler;

use MediaWiki\Config\Config;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserGroupManager;
use SkinTemplate;

/**
 * Adds personal navigation links for submission entry points.
 */
class NavigationHookHandler implements SkinTemplateNavigation__UniversalHook {

	public function __construct(
		private readonly Config $config,
		private readonly UserGroupManager $userGroupManager,
		private readonly PermissionManager $permissionManager
	) {
	}

	/**
	 * @inheritDoc
	 * @param SkinTemplate $sktemplate
	 * @param array<string, array<string, mixed>>& $links
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$user = $sktemplate->getUser();
		if ( !$user->isRegistered() ) {
			return;
		}

		if ( !$this->permissionManager->userHasRight( $user, 'article-review-submit' ) ) {
			return;
		}

		// Approved users use normal editing; hide qualification entry points
		$group = $this->config->get( 'AnWikiArticleReviewApprovedGroup' );
		if ( is_string( $group ) && $group !== '' ) {
			$groups = $this->userGroupManager->getUserGroups( $user );
			if ( in_array( $group, $groups, true ) ) {
				return;
			}
		}

		$links['user-menu']['anwiki-create-article'] = [
			'text' => $sktemplate->msg( 'anwikiarticlereview-nav-create' )->text(),
			'href' => SpecialPage::getTitleFor( 'ChooseArticleTitle' )->getLocalURL(),
			'active' => $sktemplate->getTitle()
				&& $sktemplate->getTitle()->isSpecial( 'ChooseArticleTitle' ),
		];

		$links['user-menu']['anwiki-my-submission'] = [
			'text' => $sktemplate->msg( 'anwikiarticlereview-nav-my-submission' )->text(),
			'href' => SpecialPage::getTitleFor( 'MyArticleSubmission' )->getLocalURL(),
			'active' => $sktemplate->getTitle()
				&& $sktemplate->getTitle()->isSpecial( 'MyArticleSubmission' ),
		];

		if ( $this->permissionManager->userHasRight( $user, 'article-review-review' ) ) {
			$links['user-menu']['anwiki-review'] = [
				'text' => $sktemplate->msg( 'anwikiarticlereview-nav-review' )->text(),
				'href' => SpecialPage::getTitleFor( 'ArticleReview' )->getLocalURL(),
				'active' => $sktemplate->getTitle()
					&& $sktemplate->getTitle()->isSpecial( 'ArticleReview' ),
			];
		}
	}
}
