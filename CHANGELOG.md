# Changelog

All notable changes to AnWikiArticleReview are documented in this file.

## [1.0.0] — 2026-07-24

### Added

- Initial release for MediaWiki 1.43+
- Special pages: ChooseArticleTitle, SubmitArticle, MyArticleSubmission, ArticleReview, ReviewNotifications
- Database tables: submission, revision, event, notification
- Unique constraints: one submission per user, one per normalized title
- Status machine: pending, approved, rejected, withdrawn, conflict
- Approval creates formal page with submitter as first author and promotes to approved group
- Async email notifications via JobQueue and MediaWiki UserMailer
- Maintenance script: `maintenance/SendTestReviewEmail.php`
- i18n: en, zh-hans, zh-hant, qqq
- ResourceLoader modules for title, submit, and review UI
- Navigation and missing-page hooks
- Unit tests for state machine and models
- README and LocalSettings.example.php with full SMTP documentation
