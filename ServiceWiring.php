<?php

use MediaWiki\Extension\AnWikiArticleReview\Repository\NotificationRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\ReviewEventRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRepository;
use MediaWiki\Extension\AnWikiArticleReview\Repository\SubmissionRevisionRepository;
use MediaWiki\Extension\AnWikiArticleReview\Service\ApprovalService;
use MediaWiki\Extension\AnWikiArticleReview\Service\ArticlePublisher;
use MediaWiki\Extension\AnWikiArticleReview\Service\NotificationService;
use MediaWiki\Extension\AnWikiArticleReview\Service\ReviewNotificationMessageBuilder;
use MediaWiki\Extension\AnWikiArticleReview\Service\ReviewService;
use MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionService;
use MediaWiki\Extension\AnWikiArticleReview\Service\SubmissionStateMachine;
use MediaWiki\Extension\AnWikiArticleReview\Service\TitleValidationService;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'AnWikiArticleReview.SubmissionRepository' => static function (
		MediaWikiServices $services
	): SubmissionRepository {
		return new SubmissionRepository(
			$services->getConnectionProvider()
		);
	},

	'AnWikiArticleReview.SubmissionRevisionRepository' => static function (
		MediaWikiServices $services
	): SubmissionRevisionRepository {
		return new SubmissionRevisionRepository(
			$services->getConnectionProvider()
		);
	},

	'AnWikiArticleReview.ReviewEventRepository' => static function (
		MediaWikiServices $services
	): ReviewEventRepository {
		return new ReviewEventRepository(
			$services->getConnectionProvider()
		);
	},

	'AnWikiArticleReview.NotificationRepository' => static function (
		MediaWikiServices $services
	): NotificationRepository {
		return new NotificationRepository(
			$services->getConnectionProvider()
		);
	},

	'AnWikiArticleReview.SubmissionStateMachine' => static function (
		MediaWikiServices $services
	): SubmissionStateMachine {
		return new SubmissionStateMachine();
	},

	'AnWikiArticleReview.TitleValidationService' => static function (
		MediaWikiServices $services
	): TitleValidationService {
		return new TitleValidationService(
			$services->getMainConfig(),
			$services->getTitleFactory(),
			$services->get( 'AnWikiArticleReview.SubmissionRepository' )
		);
	},

	'AnWikiArticleReview.ArticlePublisher' => static function (
		MediaWikiServices $services
	): ArticlePublisher {
		return new ArticlePublisher(
			$services->getTitleFactory(),
			$services->getWikiPageFactory(),
			$services->getContentHandlerFactory(),
			$services->getUserFactory(),
			$services->getUserGroupManager(),
			$services->getMainConfig()
		);
	},

	'AnWikiArticleReview.ReviewNotificationMessageBuilder' => static function (
		MediaWikiServices $services
	): ReviewNotificationMessageBuilder {
		return new ReviewNotificationMessageBuilder(
			$services->getMainConfig(),
			$services->getTitleFactory(),
			$services->getContentLanguage()
		);
	},

	'AnWikiArticleReview.NotificationService' => static function (
		MediaWikiServices $services
	): NotificationService {
		return new NotificationService(
			$services->getMainConfig(),
			$services->get( 'AnWikiArticleReview.NotificationRepository' ),
			$services->get( 'AnWikiArticleReview.ReviewEventRepository' ),
			$services->get( 'AnWikiArticleReview.SubmissionRepository' ),
			$services->get( 'AnWikiArticleReview.SubmissionRevisionRepository' ),
			$services->get( 'AnWikiArticleReview.ReviewNotificationMessageBuilder' ),
			$services->getJobQueueGroup(),
			$services->getUserFactory()
		);
	},

	'AnWikiArticleReview.SubmissionService' => static function (
		MediaWikiServices $services
	): SubmissionService {
		return new SubmissionService(
			$services->getConnectionProvider(),
			$services->get( 'AnWikiArticleReview.SubmissionRepository' ),
			$services->get( 'AnWikiArticleReview.SubmissionRevisionRepository' ),
			$services->get( 'AnWikiArticleReview.ReviewEventRepository' ),
			$services->get( 'AnWikiArticleReview.SubmissionStateMachine' ),
			$services->get( 'AnWikiArticleReview.TitleValidationService' ),
			$services->get( 'AnWikiArticleReview.NotificationService' ),
			$services->getMainConfig(),
			$services->getUserGroupManager()
		);
	},

	'AnWikiArticleReview.ReviewService' => static function (
		MediaWikiServices $services
	): ReviewService {
		return new ReviewService(
			$services->getConnectionProvider(),
			$services->get( 'AnWikiArticleReview.SubmissionRepository' ),
			$services->get( 'AnWikiArticleReview.ReviewEventRepository' ),
			$services->get( 'AnWikiArticleReview.SubmissionStateMachine' ),
			$services->get( 'AnWikiArticleReview.NotificationService' )
		);
	},

	'AnWikiArticleReview.ApprovalService' => static function (
		MediaWikiServices $services
	): ApprovalService {
		return new ApprovalService(
			$services->getConnectionProvider(),
			$services->get( 'AnWikiArticleReview.SubmissionRepository' ),
			$services->get( 'AnWikiArticleReview.SubmissionRevisionRepository' ),
			$services->get( 'AnWikiArticleReview.ReviewEventRepository' ),
			$services->get( 'AnWikiArticleReview.SubmissionStateMachine' ),
			$services->get( 'AnWikiArticleReview.TitleValidationService' ),
			$services->get( 'AnWikiArticleReview.ArticlePublisher' ),
			$services->get( 'AnWikiArticleReview.NotificationService' ),
			$services->getUserFactory()
		);
	},
];
