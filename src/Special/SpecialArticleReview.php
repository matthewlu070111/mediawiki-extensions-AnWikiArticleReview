<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AnWikiArticleReview\Model\Submission;
use MediaWiki\Extension\AnWikiArticleReview\Model\SubmissionStatus;
use MediaWiki\Extension\AnWikiArticleReview\Service\ApprovalService;
use MediaWiki\Extension\AnWikiArticleReview\Service\NotificationService;
use MediaWiki\Extension\AnWikiArticleReview\Service\PreviewRenderer;
use MediaWiki\Extension\AnWikiArticleReview\Service\ReviewService;
use MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionService;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * Reviewer UI: list, view, approve, reject.
 *
 * Routes:
 *   Special:ArticleReview
 *   Special:ArticleReview/pending|rejected|approved|conflict
 *   Special:ArticleReview/view/{id}
 *   Special:ArticleReview/notifications  (redirect to Special:ReviewNotifications)
 */
class SpecialArticleReview extends SpecialPage {

	public function __construct(
		private readonly SubmissionService $submissionService,
		private readonly ReviewService $reviewService,
		private readonly ApprovalService $approvalService,
		private readonly NotificationService $notificationService,
		private readonly Config $config,
		private readonly Language $contentLanguage
	) {
		parent::__construct( 'ArticleReview', 'article-review-review' );
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->checkPermissions();
		$this->checkReadOnly();

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.anWikiArticleReview.review' );
		$out->addModules( 'ext.anWikiArticleReview.review' );

		$parts = $subPage !== null && $subPage !== ''
			? explode( '/', $subPage )
			: [];

		$section = $parts[0] ?? 'pending';

		if ( $section === 'notifications' ) {
			$out->redirect(
				SpecialPage::getTitleFor( 'ReviewNotifications' )->getLocalURL()
			);
			return;
		}

		if ( $section === 'view' ) {
			$id = (int)( $parts[1] ?? 0 );
			$this->showView( $id );
			return;
		}

		$statusMap = [
			'pending' => SubmissionStatus::PENDING,
			'rejected' => SubmissionStatus::REJECTED,
			'approved' => SubmissionStatus::APPROVED,
			'conflict' => SubmissionStatus::CONFLICT,
			'withdrawn' => SubmissionStatus::WITHDRAWN,
			'all' => null,
		];

		if ( !array_key_exists( $section, $statusMap ) ) {
			$section = 'pending';
		}

		$this->showList( $section, $statusMap[$section] );
	}

	private function showList( string $section, ?int $status ): void {
		$out = $this->getOutput();
		$linkRenderer = $this->getLinkRenderer();

		// Tabs
		$tabs = [ 'pending', 'rejected', 'approved', 'conflict', 'all' ];
		$nav = [];
		foreach ( $tabs as $tab ) {
			$url = $this->getPageTitle( $tab === 'pending' ? '' : $tab )->getLocalURL();
			$class = $tab === $section ? 'selected' : '';
			$nav[] = Html::rawElement( 'li', [ 'class' => $class ],
				Html::element( 'a', [ 'href' => $url ],
					$this->msg( "anwikiarticlereview-tab-$tab" )->text()
				)
			);
		}
		if ( $this->getAuthority()->isAllowed( 'article-review-admin' ) ) {
			$nav[] = Html::rawElement( 'li', [],
				Html::element( 'a', [
					'href' => SpecialPage::getTitleFor( 'ReviewNotifications' )->getLocalURL(),
				], $this->msg( 'anwikiarticlereview-tab-notifications' )->text() )
			);
		}
		$out->addHTML( Html::rawElement( 'ul', [ 'class' => 'anwiki-review-tabs' ],
			implode( '', $nav )
		) );

		$limit = 50;
		$offset = max( 0, $this->getRequest()->getInt( 'offset' ) );
		$submissions = $this->submissionService->listByStatus( $status, $limit, $offset );
		$total = $this->submissionService->countByStatus( $status );

		if ( $submissions === [] ) {
			$out->addWikiMsg( 'anwikiarticlereview-list-empty' );
			return;
		}

		$out->addHTML( Html::openElement( 'table', [
			'class' => 'wikitable sortable anwiki-review-list',
		] ) );
		$headers = [
			'anwikiarticlereview-field-id',
			'anwikiarticlereview-field-title',
			'anwikiarticlereview-field-submitter',
			'anwikiarticlereview-field-status',
			'anwikiarticlereview-field-created',
			'anwikiarticlereview-field-updated',
			'anwikiarticlereview-field-revision',
			'anwikiarticlereview-field-actions',
		];
		$out->addHTML( Html::openElement( 'tr' ) );
		foreach ( $headers as $h ) {
			$out->addHTML( Html::element( 'th', [], $this->msg( $h )->text() ) );
		}
		$out->addHTML( Html::closeElement( 'tr' ) );

		$lang = $this->getLanguage();
		$user = $this->getUser();

		foreach ( $submissions as $sub ) {
			$title = Title::makeTitleSafe( $sub->getNamespace(), $sub->getTitle() );
			$titleText = $title ? $title->getPrefixedText() : $sub->getTitle();
			$submitter = User::newFromId( $sub->getSubmitterUserId() );
			$submitterName = $submitter ? $submitter->getName() : (string)$sub->getSubmitterUserId();

			$viewLink = $linkRenderer->makeKnownLink(
				$this->getPageTitle( 'view/' . $sub->getId() ),
				$this->msg( 'anwikiarticlereview-view' )->text()
			);

			$out->addHTML( Html::rawElement( 'tr', [],
				Html::element( 'td', [], (string)$sub->getId() )
				. Html::element( 'td', [], $titleText )
				. Html::element( 'td', [], $submitterName )
				. Html::element( 'td', [],
					$this->msg( SubmissionStatus::getMessageKey( $sub->getStatus() ) )->text()
				)
				. Html::element( 'td', [],
					$lang->userTimeAndDate( $sub->getCreatedAt(), $user )
				)
				. Html::element( 'td', [],
					$lang->userTimeAndDate( $sub->getUpdatedAt(), $user )
				)
				. Html::element( 'td', [],
					(string)( $sub->getCurrentRevisionId() ?? '' )
				)
				. Html::rawElement( 'td', [], $viewLink )
			) );
		}
		$out->addHTML( Html::closeElement( 'table' ) );

		$out->addWikiMsg( 'anwikiarticlereview-list-count', $total );
	}

	private function showView( int $id ): void {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		if ( $id <= 0 ) {
			$out->addWikiMsg( 'anwikiarticlereview-submission-not-found' );
			return;
		}

		// POST actions: approve / reject / admin-reset
		if ( $request->wasPosted() ) {
			if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$out->addWikiMsg( 'sessionfailure' );
			} else {
				$action = $request->getVal( 'wpAction' );
				$comment = $request->getText( 'wpComment' );
				if ( $action === 'approve' ) {
					$status = $this->approvalService->approve( $user, $id, $comment ?: null );
					if ( $status->isOK() ) {
						$out->addWikiMsg( 'anwikiarticlereview-approve-success' );
					} else {
						$out->addHTML( Html::errorBox( $out->parseAsInterface(
							$status->getWikiText( false, false, $this->getLanguage() )
						) ) );
					}
				} elseif ( $action === 'reject' ) {
					$status = $this->reviewService->reject( $user, $id, $comment );
					if ( $status->isOK() ) {
						$out->addWikiMsg( 'anwikiarticlereview-reject-success' );
					} else {
						$out->addHTML( Html::errorBox( $out->parseAsInterface(
							$status->getWikiText( false, false, $this->getLanguage() )
						) ) );
					}
				} elseif ( $action === 'admin-reset'
					&& $this->getAuthority()->isAllowed( 'article-review-admin' )
				) {
					$status = $this->reviewService->adminReset( $user, $id, $comment ?: null );
					if ( $status->isOK() ) {
						$out->addWikiMsg( 'anwikiarticlereview-reset-success' );
					} else {
						$out->addHTML( Html::errorBox( $out->parseAsInterface(
							$status->getWikiText( false, false, $this->getLanguage() )
						) ) );
					}
				}
			}
		}

		$submission = $this->submissionService->getById( $id );
		if ( !$submission ) {
			$out->addWikiMsg( 'anwikiarticlereview-submission-not-found' );
			return;
		}

		$this->renderDetail( $submission );
	}

	private function renderDetail( Submission $submission ): void {
		$out = $this->getOutput();
		$lang = $this->getLanguage();
		$user = $this->getUser();
		$linkRenderer = $this->getLinkRenderer();

		$title = Title::makeTitleSafe( $submission->getNamespace(), $submission->getTitle() );
		$titleText = $title ? $title->getPrefixedText() : $submission->getTitle();
		$submitter = User::newFromId( $submission->getSubmitterUserId() );
		$submitterName = $submitter ? $submitter->getName() : (string)$submission->getSubmitterUserId();
		$revision = $this->submissionService->getCurrentRevision( $submission );

		// Meta table
		$out->addHTML( Html::openElement( 'table', [ 'class' => 'wikitable' ] ) );
		$meta = [
			[ 'anwikiarticlereview-field-id', (string)$submission->getId() ],
			[ 'anwikiarticlereview-field-title', $titleText ],
			[ 'anwikiarticlereview-field-submitter', $submitterName ],
			[
				'anwikiarticlereview-field-status',
				$this->msg( SubmissionStatus::getMessageKey( $submission->getStatus() ) )->text(),
			],
			[
				'anwikiarticlereview-field-created',
				$lang->userTimeAndDate( $submission->getCreatedAt(), $user ),
			],
			[
				'anwikiarticlereview-field-updated',
				$lang->userTimeAndDate( $submission->getUpdatedAt(), $user ),
			],
		];
		if ( $submission->getReviewComment() ) {
			$meta[] = [
				'anwikiarticlereview-field-review-comment',
				$submission->getReviewComment(),
			];
		}
		if ( $submission->getPageId() ) {
			$meta[] = [
				'anwikiarticlereview-field-page-id',
				(string)$submission->getPageId(),
			];
		}
		foreach ( $meta as [ $msg, $val ] ) {
			$out->addHTML( Html::rawElement( 'tr', [],
				Html::element( 'th', [], $this->msg( $msg )->text() )
				. Html::element( 'td', [], $val )
			) );
		}
		$out->addHTML( Html::closeElement( 'table' ) );

		if ( $title && $title->exists() ) {
			$out->addHTML( Html::rawElement( 'p', [ 'class' => 'anwiki-page-exists-warning' ],
				$this->msg( 'anwikiarticlereview-page-already-exists' )->escaped()
				. ' '
				. $linkRenderer->makeKnownLink(
					$title,
					$this->msg( 'anwikiarticlereview-title-view-existing' )->text()
				)
			) );
		}

		// Preview + raw
		if ( $revision ) {
			$previewTitle = $title ?? Title::newMainPage();
			$out->addHTML( Html::element( 'h2', [],
				$this->msg( 'anwikiarticlereview-preview-header' )->text()
			) );
			$html = PreviewRenderer::renderHtml(
				$revision->getContent(),
				$previewTitle,
				$this->getContext()
			);
			$out->addHTML( Html::rawElement( 'div', [
				'class' => 'anwiki-preview mw-parser-output',
			], $html ) );

			$out->addHTML( Html::element( 'h2', [],
				$this->msg( 'anwikiarticlereview-raw-wikitext' )->text()
			) );
			$out->addHTML( Html::element( 'pre', [
				'class' => 'anwiki-raw-content',
			], $revision->getContent() ) );

			if ( $revision->getSummary() !== '' ) {
				$out->addHTML( Html::rawElement( 'p', [],
					Html::element( 'strong', [],
						$this->msg( 'anwikiarticlereview-summary-label' )->text()
					) . ' ' . htmlspecialchars( $revision->getSummary() )
				) );
			}
		}

		// Diff with previous revision
		$revisions = $this->submissionService->getRevisions( $submission->getId() );
		if ( count( $revisions ) >= 2 ) {
			$current = $revisions[0];
			$previous = $revisions[1];
			$out->addHTML( Html::element( 'h2', [],
				$this->msg( 'anwikiarticlereview-diff-header' )->text()
			) );
			$diff = $this->simpleDiff(
				$previous->getContent(),
				$current->getContent()
			);
			$out->addHTML( Html::rawElement( 'pre', [
				'class' => 'anwiki-diff',
			], $diff ) );
		}

		// Events
		$events = $this->submissionService->getEvents( $submission->getId() );
		$out->addHTML( Html::element( 'h2', [],
			$this->msg( 'anwikiarticlereview-events-header' )->text()
		) );
		if ( $events === [] ) {
			$out->addWikiMsg( 'anwikiarticlereview-events-empty' );
		} else {
			$out->addHTML( Html::openElement( 'ul', [ 'class' => 'anwiki-events' ] ) );
			foreach ( $events as $event ) {
				$actor = User::newFromId( $event->getActorUserId() );
				$actorName = $actor ? $actor->getName() : (string)$event->getActorUserId();
				$line = $lang->userTimeAndDate( $event->getCreatedAt(), $user )
					. ' — ' . $event->getAction()
					. ' — ' . $actorName;
				if ( $event->getComment() ) {
					$line .= ' — ' . $event->getComment();
				}
				$out->addHTML( Html::element( 'li', [], $line ) );
			}
			$out->addHTML( Html::closeElement( 'ul' ) );
		}

		// Action forms (POST + CSRF only)
		$token = $user->getEditToken();
		$status = $submission->getStatus();

		if ( $status === SubmissionStatus::PENDING
			|| $status === SubmissionStatus::CONFLICT
		) {
			$out->addHTML( Html::element( 'h2', [],
				$this->msg( 'anwikiarticlereview-review-actions' )->text()
			) );

			// Approve (pending only)
			if ( $status === SubmissionStatus::PENDING ) {
				$out->addHTML( Html::openElement( 'form', [
					'method' => 'post',
					'action' => $this->getPageTitle( 'view/' . $submission->getId() )->getLocalURL(),
					'class' => 'anwiki-approve-form',
				] ) );
				$out->addHTML( Html::element( 'input', [
					'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $token,
				] ) );
				$out->addHTML( Html::element( 'input', [
					'type' => 'hidden', 'name' => 'wpAction', 'value' => 'approve',
				] ) );
				$out->addHTML( Html::element( 'label', [ 'for' => 'wpCommentApprove' ],
					$this->msg( 'anwikiarticlereview-review-comment-label' )->text()
				) );
				$out->addHTML( Html::element( 'textarea', [
					'name' => 'wpComment',
					'id' => 'wpCommentApprove',
					'rows' => 3,
					'cols' => 60,
				], '' ) );
				$out->addHTML( Html::element( 'br' ) );
				$out->addHTML( Html::element( 'button', [
					'type' => 'submit',
					'class' => 'mw-ui-button mw-ui-progressive anwiki-btn-approve',
					'data-confirm' => $this->msg( 'anwikiarticlereview-confirm-approve' )->text(),
				], $this->msg( 'anwikiarticlereview-approve' )->text() ) );
				$out->addHTML( Html::closeElement( 'form' ) );
			}

			// Reject
			$out->addHTML( Html::openElement( 'form', [
				'method' => 'post',
				'action' => $this->getPageTitle( 'view/' . $submission->getId() )->getLocalURL(),
				'class' => 'anwiki-reject-form',
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $token,
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpAction', 'value' => 'reject',
			] ) );
			$out->addHTML( Html::element( 'label', [ 'for' => 'wpCommentReject' ],
				$this->msg( 'anwikiarticlereview-review-comment-required' )->text()
			) );
			$out->addHTML( Html::element( 'textarea', [
				'name' => 'wpComment',
				'id' => 'wpCommentReject',
				'rows' => 3,
				'cols' => 60,
				'required' => 'required',
			], '' ) );
			$out->addHTML( Html::element( 'br' ) );
			$out->addHTML( Html::element( 'button', [
				'type' => 'submit',
				'class' => 'mw-ui-button mw-ui-destructive anwiki-btn-reject',
				'data-confirm' => $this->msg( 'anwikiarticlereview-confirm-reject' )->text(),
			], $this->msg( 'anwikiarticlereview-reject' )->text() ) );
			$out->addHTML( Html::closeElement( 'form' ) );
		}

		if ( $this->getAuthority()->isAllowed( 'article-review-admin' )
			&& $status !== SubmissionStatus::APPROVED
			&& $status !== SubmissionStatus::PENDING
		) {
			$out->addHTML( Html::openElement( 'form', [
				'method' => 'post',
				'action' => $this->getPageTitle( 'view/' . $submission->getId() )->getLocalURL(),
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $token,
			] ) );
			$out->addHTML( Html::element( 'input', [
				'type' => 'hidden', 'name' => 'wpAction', 'value' => 'admin-reset',
			] ) );
			$out->addHTML( Html::element( 'button', [
				'type' => 'submit',
				'class' => 'mw-ui-button',
			], $this->msg( 'anwikiarticlereview-admin-reset' )->text() ) );
			$out->addHTML( Html::closeElement( 'form' ) );
		}

		$out->addHTML( Html::rawElement( 'p', [],
			$linkRenderer->makeKnownLink(
				$this->getPageTitle(),
				$this->msg( 'anwikiarticlereview-back-to-list' )->text()
			)
		) );
	}

	/**
	 * Simple line-based diff for display (not a full MW DifferenceEngine).
	 */
	private function simpleDiff( string $old, string $new ): string {
		$oldLines = explode( "\n", $old );
		$newLines = explode( "\n", $new );
		$max = max( count( $oldLines ), count( $newLines ) );
		$out = [];
		for ( $i = 0; $i < $max; $i++ ) {
			$a = $oldLines[$i] ?? null;
			$b = $newLines[$i] ?? null;
			if ( $a === $b ) {
				$out[] = '  ' . ( $a ?? '' );
			} else {
				if ( $a !== null ) {
					$out[] = '- ' . $a;
				}
				if ( $b !== null ) {
					$out[] = '+ ' . $b;
				}
			}
		}
		return htmlspecialchars( implode( "\n", $out ) );
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pagetools';
	}
}
