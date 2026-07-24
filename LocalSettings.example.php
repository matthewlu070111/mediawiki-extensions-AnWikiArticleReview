<?php
/**
 * Example LocalSettings.php fragment for AnWikiArticleReview.
 *
 * Copy the relevant sections into your wiki's LocalSettings.php.
 * Replace example domains, emails, and credentials before production use.
 *
 * @file
 */

// ---------------------------------------------------------------------------
// Permission model: only approved users may edit / create pages
// ---------------------------------------------------------------------------

// Anonymous users cannot edit
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['createpage'] = false;
$wgGroupPermissions['*']['createtalk'] = false;

// Ordinary registered users cannot edit directly
$wgGroupPermissions['user']['edit'] = false;
$wgGroupPermissions['user']['createpage'] = false;
$wgGroupPermissions['user']['createtalk'] = false;

// Approved editors (granted automatically on submission approval)
$wgGroupPermissions['approved']['edit'] = true;
$wgGroupPermissions['approved']['createpage'] = true;
$wgGroupPermissions['approved']['createtalk'] = true;
$wgGroupPermissions['approved']['writeapi'] = true;

// Let sysops manage article-reviewer membership
$wgAddGroups['sysop'][] = 'article-reviewer';
$wgRemoveGroups['sysop'][] = 'article-reviewer';

// Let bureaucrats manage approved membership
$wgAddGroups['bureaucrat'][] = 'approved';
$wgRemoveGroups['bureaucrat'][] = 'approved';

// ---------------------------------------------------------------------------
// Load extension
// ---------------------------------------------------------------------------

wfLoadExtension( 'AnWikiArticleReview' );

// ---------------------------------------------------------------------------
// AnWikiArticleReview configuration
// ---------------------------------------------------------------------------

// Only allow main-namespace submissions
$wgAnWikiArticleReviewAllowedNamespaces = [ NS_MAIN ];

// Group granted on approval
$wgAnWikiArticleReviewApprovedGroup = 'approved';
$wgAnWikiArticleReviewPromoteOnApprove = true;

// Title selection page help text (plain text; HTML is escaped)
$wgAnWikiArticleReviewTitleHint =
	'请输入支付卡的正式名称，例如“中国农业银行金穗借记卡”。';

// Optional placeholder for the title input
$wgAnWikiArticleReviewTitlePlaceholder =
	'输入准备创建的页面名称';

// Content size limits (bytes)
$wgAnWikiArticleReviewMinContentBytes = 100;
$wgAnWikiArticleReviewMaxContentBytes = 2097152;
$wgAnWikiArticleReviewMaxSummaryBytes = 500;

// Feature toggles
$wgAnWikiArticleReviewAllowResubmit = true;
$wgAnWikiArticleReviewAllowWithdraw = true;
$wgAnWikiArticleReviewRequireConfirmedEmail = false;
$wgAnWikiArticleReviewShowLinkOnMissingPages = true;

// ---------------------------------------------------------------------------
// Email notifications (uses MediaWiki core mail; configure $wgSMTP below)
// ---------------------------------------------------------------------------

$wgAnWikiArticleReviewEmailNotifications = true;

$wgAnWikiArticleReviewNotificationRecipients = [
	'review@example.org',
];

$wgAnWikiArticleReviewNotificationEvents = [
	'submit',
	'resubmit',
	'conflict',
];

$wgAnWikiArticleReviewEmailSubjectPrefix =
	'[支付卡百科新条目审核]';

$wgAnWikiArticleReviewEmailIncludeContentExcerpt = false;
$wgAnWikiArticleReviewEmailContentExcerptLength = 300;

// ---------------------------------------------------------------------------
// MediaWiki core email / SMTP
// AnWikiArticleReview does NOT configure SMTP itself.
// ---------------------------------------------------------------------------

$wgEnableEmail = true;
$wgEmergencyContact = 'wiki@example.org';
$wgPasswordSender = 'wiki@example.org';

$smtpPassword = getenv( 'MEDIAWIKI_SMTP_PASSWORD' );

if ( $smtpPassword === false || $smtpPassword === '' ) {
	throw new RuntimeException(
		'MEDIAWIKI_SMTP_PASSWORD is not configured'
	);
}

$wgSMTP = [
	'host'      => 'tls://smtp.example.org',
	'IDHost'    => 'example.org',
	'localhost' => 'example.org',
	'port'      => 587,
	'auth'      => true,
	'username'  => 'wiki@example.org',
	'password'  => $smtpPassword,
];

// ---------------------------------------------------------------------------
// JobQueue (required for async email delivery)
// For production, configure a real job runner. Example for a simple setup:
// ---------------------------------------------------------------------------
// $wgJobRunRate = 1;
// Or run: php maintenance/run.php runJobs
