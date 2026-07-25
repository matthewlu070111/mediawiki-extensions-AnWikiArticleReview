# AnWikiArticleReview

中文文档见 [README_cn.md](README_cn.md)。

MediaWiki extension for **new article submission and editorial qualification review**.

Ordinary registered users do **not** use the standard editor. They:

1. Choose a new page title (`Special:ChooseArticleTitle`)
2. Write the article body (`Special:SubmitArticle`)
3. Submit for review
4. Wait for a reviewer to approve or reject

On approval the extension:

- Creates the formal page with the **submitter as first-revision author**
- Adds the submitter to a configurable **approved** user group
- Records reviewer and audit events
- Optionally notifies administrators by email (via MediaWiki core mail)

This extension handles **new page review only**. It does not moderate edits to existing pages.

---

## Features

- Two-step title → body submission flow
- One main submission per user (`UNIQUE submitter`)
- One main submission per normalized title (`UNIQUE namespace + dbkey`)
- Append-only content revisions (resubmit does not create a second list row)
- Centralized status machine: pending / approved / rejected / withdrawn / conflict
- Reviewer UI: list, preview, approve, reject
- Admin notification retry UI
- Async email via JobQueue + MediaWiki `UserMailer` (no custom SMTP client)
- Configurable title hint (plain text, HTML-escaped)
- Navigation links and missing-page prompt for unapproved users
- i18n: English, Simplified Chinese, Traditional Chinese

---

## Requirements

| Component   | Version   |
|------------|-----------|
| MediaWiki  | 1.46+     |
| PHP        | 8.1+      |
| Database   | MariaDB 10.5+ / MySQL / SQLite / PostgreSQL |

---

## Installation

1. Place this directory under `extensions/AnWikiArticleReview`.

2. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'AnWikiArticleReview' );
```

3. Configure permissions (see below) and extension options.

4. Run the updater:

```bash
php maintenance/run.php update
```

Running `update` twice is safe (idempotent schema).

5. Assign users to the `article-reviewer` group for review access.

See also `LocalSettings.example.php` for a complete example.

---

## Upgrade

1. Replace extension files.
2. Run `php maintenance/run.php update`.
3. Review `CHANGELOG.md` for configuration changes.
4. Purge caches if needed: `php maintenance/run.php rebuildLocalisationCache`.

---

## Uninstall

1. Remove `wfLoadExtension( 'AnWikiArticleReview' )` and related config from `LocalSettings.php`.
2. Optionally drop tables (irreversible):

```sql
DROP TABLE IF EXISTS anwiki_article_review_notification;
DROP TABLE IF EXISTS anwiki_article_review_event;
DROP TABLE IF EXISTS anwiki_article_review_revision;
DROP TABLE IF EXISTS anwiki_article_review_submission;
```

3. Delete the extension directory.

---

## Permission configuration

AnWikiArticleReview does **not** grant core `edit` / `createpage` to ordinary users.

Recommended model:

```php
// Anonymous: read only
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['createpage'] = false;
$wgGroupPermissions['*']['createtalk'] = false;

// Registered users: no direct edit
$wgGroupPermissions['user']['edit'] = false;
$wgGroupPermissions['user']['createpage'] = false;
$wgGroupPermissions['user']['createtalk'] = false;

// Approved editors (promoted on approval)
$wgGroupPermissions['approved']['edit'] = true;
$wgGroupPermissions['approved']['createpage'] = true;
$wgGroupPermissions['approved']['createtalk'] = true;
$wgGroupPermissions['approved']['writeapi'] = true;
```

### Extension rights

| Right | Default groups | Purpose |
|-------|----------------|---------|
| `article-review-submit` | `user` | Submit / view own submission |
| `article-review-review` | `article-reviewer`, `sysop` | Review list, approve, reject |
| `article-review-admin` | `sysop` | Notifications admin, resets |

### User groups

```php
$wgAddGroups['sysop'][] = 'article-reviewer';
$wgRemoveGroups['sysop'][] = 'article-reviewer';
$wgAddGroups['bureaucrat'][] = 'approved';
$wgRemoveGroups['bureaucrat'][] = 'approved';
```

Extension config:

```php
$wgAnWikiArticleReviewApprovedGroup = 'approved';
$wgAnWikiArticleReviewPromoteOnApprove = true;
```

---

## Special pages

| Page | Audience | Purpose |
|------|----------|---------|
| `Special:ChooseArticleTitle` | Submitters | Choose / validate title |
| `Special:SubmitArticle/<title>` | Submitters | Body (WikiEditor toolbar), preview, submit |
| `Special:MyArticleSubmission` | Submitters | Own submission, resubmit, withdraw |
| `Special:ArticleReview` | Reviewers | List and process submissions |
| `Special:ReviewNotifications` | Admins | Email notification status / retry |

Reviewer routes:

- `Special:ArticleReview` / `…/pending`
- `…/rejected`, `…/approved`, `…/conflict`, `…/all`
- `…/view/{id}`
- `…/notifications` → redirects to `Special:ReviewNotifications`

---

## Title selection page

Users start at **Special:ChooseArticleTitle**.

### Title hint configuration

```php
// Help text under the input (plain text only; always HTML-escaped)
$wgAnWikiArticleReviewTitleHint =
    '请输入支付卡的正式名称，例如“中国农业银行金穗借记卡”。';

// Optional input placeholder (separate from hint)
$wgAnWikiArticleReviewTitlePlaceholder =
    '输入准备创建的页面名称';
```

Rules:

- Do not prefill via MediaWiki’s reserved `title` query parameter; use `newtitle`, `pagename`, or a special-page subpath
- Missing-page prompts link to `Special:SubmitArticle/<PageName>` (not the title chooser)
- Install **WikiEditor** for the standard wikitext toolbar on submit/resubmit forms (soft dependency)
- VisualEditor is not embedded: VE needs a real page edit session; this workflow uses a special-page form before the page exists

- Hint is **not** parsed as wikitext or HTML
- Empty hint falls back to i18n message `anwikiarticlereview-title-hint`
- Cannot be overridden via URL parameters
- Title selection only **pre-checks**; it does **not** reserve the title in the database

---

## Submission and review flow

```text
Choose title → Submit body → PENDING
    → Approve → create page + promote user → APPROVED
    → Reject  → REJECTED → user may resubmit → PENDING
    → Withdraw → WITHDRAWN → user may resubmit → PENDING
    → Page exists at approve time → CONFLICT
```

Approve and reject require **POST + CSRF**. GET cannot change status.

---

## Anti-duplication

Three layers:

1. **Application checks** before insert
2. **Database unique indexes**
   - `UNIQUE (aars_submitter_user_id)`
   - `UNIQUE (aars_namespace, aars_title)`
3. **List reads only the main submission table** (one row per submission; revisions are separate)

---

## Email notifications

### Important principle

> **AnWikiArticleReview does not configure SMTP.**  
> It uses MediaWiki’s mail system. Site operators configure email in `LocalSettings.php` via `$wgEnableEmail`, `$wgSMTP`, `$wgPasswordSender`, etc.

### Extension notification settings

```php
$wgAnWikiArticleReviewEmailNotifications = true;

$wgAnWikiArticleReviewNotificationRecipients = [
    'review@example.org',
];

$wgAnWikiArticleReviewNotificationEvents = [
    'submit',
    'resubmit',
    // optional: 'approve', 'reject', 'conflict'
];

$wgAnWikiArticleReviewEmailSubjectPrefix = '[AnWikiArticleReview]';
$wgAnWikiArticleReviewEmailIncludeContentExcerpt = false;
$wgAnWikiArticleReviewEmailContentExcerptLength = 300;
```

### Async delivery

1. Submission / review transaction commits successfully  
2. Notification rows are created (idempotent unique key)  
3. `SendReviewNotificationJob` is pushed to the JobQueue  
4. Job sends mail via `UserMailer` and updates status  

Email failure **never** rolls back the submission.

### SMTP configuration (MediaWiki core)

```php
$wgEnableEmail = true;
$wgEmergencyContact = 'wiki@example.org';
$wgPasswordSender = 'wiki@example.org';

$smtpPassword = getenv( 'MEDIAWIKI_SMTP_PASSWORD' );
if ( $smtpPassword === false || $smtpPassword === '' ) {
    throw new RuntimeException( 'MEDIAWIKI_SMTP_PASSWORD is not configured' );
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
```

**Replace example domains and addresses before production use.**

#### `$wgSMTP` keys

| Key | Meaning |
|-----|---------|
| `host` | SMTP host; may include `tls://` or `ssl://` |
| `IDHost` | Hostname used in Message-ID |
| `localhost` | HELO/EHLO name |
| `port` | 587 (submission) or 465 (implicit TLS) are common |
| `auth` | Whether to authenticate |
| `username` | SMTP username |
| `password` | SMTP password (use env var; never commit secrets) |

Notes:

- Port **587** is commonly used for SMTP submission with STARTTLS  
- Port **465** is commonly used for implicit TLS  
- Follow your mail provider’s documentation  
- `$wgPasswordSender` usually must match a verified From address  
- PHP / the web server must be allowed to reach the SMTP host  
- Some providers require app-specific passwords  

### Environment variables and password security

**Do:**

- Load SMTP password from `getenv( 'MEDIAWIKI_SMTP_PASSWORD' )` or a secret manager  
- Keep secrets outside the web root and out of Git  

**Do not:**

- Commit production SMTP passwords to Git  
- Put passwords in extension source  
- Put credentials on public wiki pages  
- Echo credentials in error pages  
- Place `.env` files in a web-accessible directory  

### JobQueue

Notifications are sent by jobs. Ensure jobs run:

```bash
# One-shot
php maintenance/run.php runJobs

# Or configure a continuous runner / $wgJobRunRate for small sites
```

### Test email

Run from the **MediaWiki install root** (the directory that contains `LocalSettings.php` and `maintenance/`), not from inside the extension folder.

**Preferred** (extension script name):

```bash
php maintenance/run.php AnWikiArticleReview:SendTestReviewEmail --to=admin@example.org
```

**Alternative** — path must start with `./` or be absolute (plain `extensions/...` is wrong; `run.php` would look under `maintenance/extensions/...`):

```bash
php maintenance/run.php ./extensions/AnWikiArticleReview/maintenance/SendTestReviewEmail.php --to=admin@example.org
```

```bash
php maintenance/run.php /var/www/paysegment/extensions/AnWikiArticleReview/maintenance/SendTestReviewEmail.php --to=admin@example.org
```

If no `--to` is given, the first address in `$wgAnWikiArticleReviewNotificationRecipients` is used.

The script:

- Runs on CLI only  
- Uses MediaWiki core mail  
- Never prints SMTP passwords  
- Exits non-zero on failure  

Before running: confirm the extension is loaded (`wfLoadExtension( 'AnWikiArticleReview' )`) and the file exists at `extensions/AnWikiArticleReview/maintenance/SendTestReviewEmail.php`.

---

## Troubleshooting email

| Check | What to verify |
|-------|----------------|
| Mail enabled | `$wgEnableEmail === true` |
| Recipients | `$wgAnWikiArticleReviewNotificationRecipients` not empty |
| Events | Event type is in `$wgAnWikiArticleReviewNotificationEvents` |
| From address | `$wgPasswordSender` matches provider-verified sender |
| SMTP host/port | Correct host, port, TLS scheme |
| TLS certificates | PHP CA bundle valid |
| Firewall | Outbound 587/465 allowed |
| JobQueue | Jobs are being run |
| Failed list | `Special:ReviewNotifications` |
| Logs | MediaWiki log channels / web server error log |
| Spam folder | Check recipient spam/junk |
| DNS auth | SPF, DKIM, DMARC for the sending domain |

---

## Configuration reference

| Variable | Default | Description |
|----------|---------|-------------|
| `$wgAnWikiArticleReviewAllowedNamespaces` | `[ NS_MAIN ]` | Allowed namespaces |
| `$wgAnWikiArticleReviewApprovedGroup` | `'approved'` | Group granted on approve |
| `$wgAnWikiArticleReviewPromoteOnApprove` | `true` | Auto-add to group |
| `$wgAnWikiArticleReviewTitleHint` | `''` | Title field help (plain text) |
| `$wgAnWikiArticleReviewTitlePlaceholder` | `''` | Title input placeholder |
| `$wgAnWikiArticleReviewMinContentBytes` | `100` | Min body size |
| `$wgAnWikiArticleReviewMaxContentBytes` | `2097152` | Max body size |
| `$wgAnWikiArticleReviewMaxSummaryBytes` | `500` | Max summary size |
| `$wgAnWikiArticleReviewAllowResubmit` | `true` | Allow resubmit |
| `$wgAnWikiArticleReviewAllowWithdraw` | `true` | Allow withdraw |
| `$wgAnWikiArticleReviewRequireConfirmedEmail` | `false` | Require confirmed email (reserved) |
| `$wgAnWikiArticleReviewShowLinkOnMissingPages` | `true` | Missing-page prompt |
| `$wgAnWikiArticleReviewEmailNotifications` | `false` | Enable email |
| `$wgAnWikiArticleReviewNotificationRecipients` | `[]` | Admin emails |
| `$wgAnWikiArticleReviewNotificationEvents` | `['submit','resubmit']` | Events to notify |
| `$wgAnWikiArticleReviewEmailSubjectPrefix` | `'[AnWikiArticleReview]'` | Subject prefix |
| `$wgAnWikiArticleReviewEmailIncludeContentExcerpt` | `false` | Include excerpt |
| `$wgAnWikiArticleReviewEmailContentExcerptLength` | `300` | Excerpt length |

SMTP is **not** an extension config — use core `$wgSMTP`.

---

## Database tables

| Table | Purpose |
|-------|---------|
| `anwiki_article_review_submission` | Main submission (unique user + unique title) |
| `anwiki_article_review_revision` | Append-only body versions |
| `anwiki_article_review_event` | Audit events |
| `anwiki_article_review_notification` | Email send records (idempotent) |

Schema files: `sql/tables.json`, `sql/mysql/`, `sql/sqlite/`, `sql/postgres/`.

---

## Logging

- Failed mail: structured log via notification row `aarn_last_error` (sanitized)  
- Job errors: MediaWiki job log  
- Do not log SMTP passwords or full auth payloads  

---

## Privacy

- Ordinary users cannot see other users’ submissions  
- Title conflict messages do not reveal submitter identity or status  
- Notification recipient emails are masked on the admin UI  
- Emails do not include submitter email, IP, or full body by default  
- Notification table access requires `article-review-admin`  

---

## Development and testing

### Layout

See `PLAN.md` and the `src/` tree for architecture (services, repositories, special pages, jobs).

### PHPUnit

From a MediaWiki installation with this extension loaded:

```bash
# Unit tests (no DB)
composer phpunit -- --testsuite extensions \
  extensions/AnWikiArticleReview/tests/phpunit/unit

# Or via core:
php tests/phpunit/phpunit.php \
  extensions/AnWikiArticleReview/tests/phpunit/unit
```

### Manual checklist

- Anonymous cannot open submit specials  
- Title hint HTML is escaped  
- Duplicate user / title submissions fail cleanly  
- Resubmit does not create a second list row  
- Double approve creates only one page  
- Mail job is idempotent  
- Test mail script works  

---

## License

GPL-2.0-or-later. See `LICENSE`.

---

## Changelog

See `CHANGELOG.md`.
