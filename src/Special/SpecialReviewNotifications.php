<?php

namespace MediaWiki\Extension\AnWikiArticleReview\Special;

use MediaWiki\Extension\AnWikiArticleReview\Service\NotificationService;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Admin view of email notification records and manual retry.
 */
class SpecialReviewNotifications extends SpecialPage {

	public function __construct(
		private readonly NotificationService $notificationService
	) {
		parent::__construct( 'ReviewNotifications', 'article-review-admin' );
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
		$user = $this->getUser();
		$request = $this->getRequest();

		if ( $request->wasPosted() ) {
			if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$out->addWikiMsg( 'sessionfailure' );
			} else {
				$action = $request->getVal( 'wpAction' );
				$notificationId = $request->getInt( 'wpNotificationId' );
				if ( $action === 'retry' && $notificationId > 0 ) {
					$status = $this->notificationService->retry( $notificationId );
					if ( $status->isOK() ) {
						$out->addWikiMsg( 'anwikiarticlereview-notification-retry-queued' );
					} else {
						$out->addHTML( Html::errorBox( $out->parseAsInterface(
							$status->getWikiText( false, false, $this->getLanguage() )
						) ) );
					}
				}
			}
		}

		$statusFilter = $request->getVal( 'status' );
		if ( $statusFilter === '' || $statusFilter === null ) {
			$statusFilter = null;
		}

		// Filter links
		$filters = [ null, 'queued', 'sending', 'sent', 'failed', 'disabled' ];
		$nav = [];
		foreach ( $filters as $f ) {
			$label = $f === null
				? $this->msg( 'anwikiarticlereview-tab-all' )->text()
				: $f;
			$url = $this->getPageTitle()->getLocalURL(
				$f === null ? [] : [ 'status' => $f ]
			);
			$nav[] = Html::element( 'a', [ 'href' => $url ], $label );
		}
		$out->addHTML( Html::rawElement( 'p', [ 'class' => 'anwiki-notification-filters' ],
			implode( ' | ', $nav )
		) );

		$notifications = $this->notificationService->listNotifications( $statusFilter, 100, 0 );

		if ( $notifications === [] ) {
			$out->addWikiMsg( 'anwikiarticlereview-notifications-empty' );
			return;
		}

		$lang = $this->getLanguage();
		$token = $user->getEditToken();

		$out->addHTML( Html::openElement( 'table', [
			'class' => 'wikitable anwiki-notifications-list',
		] ) );
		$out->addHTML( Html::rawElement( 'tr', [],
			Html::element( 'th', [], 'ID' )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-event' )->text() )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-recipient' )->text() )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-type' )->text() )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-status' )->text() )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-attempts' )->text() )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-error' )->text() )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-updated' )->text() )
			. Html::element( 'th', [], $this->msg( 'anwikiarticlereview-field-actions' )->text() )
		) );

		foreach ( $notifications as $n ) {
			$retry = '';
			if ( $n->getStatus() === 'failed' || $n->getStatus() === 'queued' ) {
				$retry = Html::openElement( 'form', [
					'method' => 'post',
					'action' => $this->getPageTitle()->getLocalURL(),
					'style' => 'display:inline',
				] )
					. Html::element( 'input', [
						'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $token,
					] )
					. Html::element( 'input', [
						'type' => 'hidden', 'name' => 'wpAction', 'value' => 'retry',
					] )
					. Html::element( 'input', [
						'type' => 'hidden',
						'name' => 'wpNotificationId',
						'value' => (string)$n->getId(),
					] )
					. Html::element( 'button', [
						'type' => 'submit',
						'class' => 'mw-ui-button mw-ui-progressive',
					], $this->msg( 'anwikiarticlereview-notification-retry' )->text() )
					. Html::closeElement( 'form' );
			}

			// Mask email partially for display
			$recipient = $this->maskEmail( $n->getRecipient() );

			$out->addHTML( Html::rawElement( 'tr', [],
				Html::element( 'td', [], (string)$n->getId() )
				. Html::element( 'td', [], (string)$n->getEventId() )
				. Html::element( 'td', [], $recipient )
				. Html::element( 'td', [], $n->getNotificationType() )
				. Html::element( 'td', [], $n->getStatus() )
				. Html::element( 'td', [], (string)$n->getAttemptCount() )
				. Html::element( 'td', [], $n->getLastError() ?? '' )
				. Html::element( 'td', [],
					$lang->userTimeAndDate( $n->getUpdatedAt(), $user )
				)
				. Html::rawElement( 'td', [], $retry )
			) );
		}
		$out->addHTML( Html::closeElement( 'table' ) );

		$out->addHTML( Html::rawElement( 'p', [],
			$this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'ArticleReview' ),
				$this->msg( 'anwikiarticlereview-back-to-list' )->text()
			)
		) );
	}

	private function maskEmail( string $email ): string {
		$parts = explode( '@', $email, 2 );
		if ( count( $parts ) !== 2 ) {
			return '***';
		}
		$local = $parts[0];
		$domain = $parts[1];
		if ( strlen( $local ) <= 2 ) {
			$masked = str_repeat( '*', strlen( $local ) );
		} else {
			$masked = $local[0] . str_repeat( '*', max( 1, strlen( $local ) - 2 ) )
				. substr( $local, -1 );
		}
		return $masked . '@' . $domain;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pagetools';
	}
}
