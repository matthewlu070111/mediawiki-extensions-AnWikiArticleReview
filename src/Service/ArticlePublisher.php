<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Service;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Config\Config;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

/**
 * Creates formal wiki pages from approved submissions using core services only.
 */
class ArticlePublisher {

	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly IContentHandlerFactory $contentHandlerFactory,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManager $userGroupManager,
		private readonly Config $config
	) {
	}

	/**
	 * Create the formal page with the submitter as first-revision author.
	 *
	 * @return Status array{pageId:int,revisionId:int}|null
	 */
	public function publish(
		Title $title,
		string $wikitext,
		UserIdentity $submitter,
		?string $summary = null
	): Status {
		if ( $title->exists() ) {
			return Status::newFatal( 'anwikiarticlereview-publish-page-exists' );
		}

		$author = $this->userFactory->newFromUserIdentity( $submitter );
		if ( !$author->isRegistered() ) {
			return Status::newFatal( 'anwikiarticlereview-publish-user-invalid' );
		}

		$handler = $this->contentHandlerFactory->getContentHandler( CONTENT_MODEL_WIKITEXT );
		$content = $handler->unserializeContent( $wikitext );

		$summaryText = $summary ?? wfMessage( 'anwikiarticlereview-publish-summary' )
			->inContentLanguage()
			->text();
		$comment = CommentStoreComment::newUnsavedComment( $summaryText );

		$wikiPage = $this->wikiPageFactory->newFromTitle( $title );
		$updater = $wikiPage->newPageUpdater( $author );
		$updater->setContent( SlotRecord::MAIN, $content );

		// EDIT_NEW creates the page; do not suppress recentchanges.
		$revision = $updater->saveRevision( $comment, EDIT_NEW );

		if ( !$updater->wasSuccessful() || $revision === null ) {
			$status = $updater->getStatus();
			if ( !$status->isOK() ) {
				return $status;
			}
			return Status::newFatal( 'anwikiarticlereview-publish-failed' );
		}

		$pageId = $wikiPage->getId();
		$revisionId = $revision->getId();

		if ( !$pageId || !$revisionId ) {
			return Status::newFatal( 'anwikiarticlereview-publish-failed' );
		}

		return Status::newGood( [
			'pageId' => $pageId,
			'revisionId' => $revisionId,
		] );
	}

	/**
	 * Promote submitter into the configured approved group.
	 */
	public function promoteUser( UserIdentity $user ): void {
		if ( !$this->config->get( 'AnWikiArticleReviewPromoteOnApprove' ) ) {
			return;
		}
		$group = $this->config->get( 'AnWikiArticleReviewApprovedGroup' );
		if ( !is_string( $group ) || $group === '' ) {
			return;
		}
		$groups = $this->userGroupManager->getUserGroups( $user );
		if ( !in_array( $group, $groups, true ) ) {
			$this->userGroupManager->addUserToGroup( $user, $group );
		}
	}

	/**
	 * Whether the user is already in the approved group.
	 */
	public function isUserApproved( UserIdentity $user ): bool {
		$group = $this->config->get( 'AnWikiArticleReviewApprovedGroup' );
		if ( !is_string( $group ) || $group === '' ) {
			return false;
		}
		return in_array(
			$group,
			$this->userGroupManager->getUserGroups( $user ),
			true
		);
	}
}
