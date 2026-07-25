# Changelog

All notable changes to AnWikiArticleReview are documented in this file.

## [0.1.3] — 2026-07-25

### Fixed

- **ChooseArticleTitle** no longer prefills the title field with `Special:…` itself. MediaWiki’s reserved `title` query parameter was being treated as a prefill value; intentional prefill now uses `newtitle`, `pagename`, or a special-page subpage path only.
- Title-field **hint** now uses HTMLForm `help-message` / plain `help` with `help-inline`, so the configured or default hint text actually appears under the input.
- Missing-page prompt links to **`Special:SubmitArticle/<PageName>`** instead of `ChooseArticleTitle?title=…`, and copy now says users can edit and submit the page.

### Improved

- Submit and resubmit forms use the standard `#wpTextbox1` edit control and load **WikiEditor** (toolbar) when that extension is installed, instead of a bare monospace textarea.
- Soft dependency only: without WikiEditor the form still works as a plain textarea.

## [1.0.0] — 2026-07-24

### Added

- Initial release targeting MediaWiki **1.46+**
- Maintenance script invocation documented for `run.php` (`AnWikiArticleReview:SendTestReviewEmail`)
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
