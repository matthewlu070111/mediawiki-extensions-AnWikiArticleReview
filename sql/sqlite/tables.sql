-- AnWikiArticleReview SQLite schema

CREATE TABLE /*_*/anwiki_article_review_submission (
  aars_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  aars_submitter_user_id INTEGER UNSIGNED NOT NULL,
  aars_namespace INTEGER NOT NULL,
  aars_title BLOB NOT NULL,
  aars_status INTEGER UNSIGNED NOT NULL DEFAULT 0,
  aars_current_revision_id INTEGER UNSIGNED NULL,
  aars_created_at BLOB NOT NULL,
  aars_updated_at BLOB NOT NULL,
  aars_reviewer_user_id INTEGER UNSIGNED NULL,
  aars_reviewed_at BLOB NULL,
  aars_review_comment BLOB NULL,
  aars_page_id INTEGER UNSIGNED NULL,
  aars_published_revision_id INTEGER UNSIGNED NULL,
  aars_row_version INTEGER UNSIGNED NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX aars_submitter_user_id
  ON /*_*/anwiki_article_review_submission (aars_submitter_user_id);
CREATE UNIQUE INDEX aars_namespace_title
  ON /*_*/anwiki_article_review_submission (aars_namespace, aars_title);
CREATE INDEX aars_status_updated
  ON /*_*/anwiki_article_review_submission (aars_status, aars_updated_at);
CREATE INDEX aars_reviewer_user_id
  ON /*_*/anwiki_article_review_submission (aars_reviewer_user_id);

CREATE TABLE /*_*/anwiki_article_review_revision (
  aarr_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  aarr_submission_id INTEGER UNSIGNED NOT NULL,
  aarr_author_user_id INTEGER UNSIGNED NOT NULL,
  aarr_content BLOB NOT NULL,
  aarr_summary BLOB NOT NULL,
  aarr_sha1 BLOB NOT NULL,
  aarr_created_at BLOB NOT NULL
);

CREATE INDEX aarr_submission_created
  ON /*_*/anwiki_article_review_revision (aarr_submission_id, aarr_created_at);

CREATE TABLE /*_*/anwiki_article_review_event (
  aare_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  aare_submission_id INTEGER UNSIGNED NOT NULL,
  aare_actor_user_id INTEGER UNSIGNED NOT NULL,
  aare_action BLOB NOT NULL,
  aare_old_status INTEGER UNSIGNED NULL,
  aare_new_status INTEGER UNSIGNED NOT NULL,
  aare_comment BLOB NULL,
  aare_created_at BLOB NOT NULL
);

CREATE INDEX aare_submission_created
  ON /*_*/anwiki_article_review_event (aare_submission_id, aare_created_at);
CREATE INDEX aare_action
  ON /*_*/anwiki_article_review_event (aare_action);

CREATE TABLE /*_*/anwiki_article_review_notification (
  aarn_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  aarn_event_id INTEGER UNSIGNED NOT NULL,
  aarn_recipient BLOB NOT NULL,
  aarn_recipient_hash BLOB NOT NULL,
  aarn_notification_type BLOB NOT NULL,
  aarn_status BLOB NOT NULL DEFAULT 'queued',
  aarn_attempt_count INTEGER UNSIGNED NOT NULL DEFAULT 0,
  aarn_last_error BLOB NULL,
  aarn_created_at BLOB NOT NULL,
  aarn_sent_at BLOB NULL,
  aarn_updated_at BLOB NOT NULL
);

CREATE UNIQUE INDEX aarn_event_recipient_type
  ON /*_*/anwiki_article_review_notification (
    aarn_event_id, aarn_recipient_hash, aarn_notification_type
  );
CREATE INDEX aarn_status_updated
  ON /*_*/anwiki_article_review_notification (aarn_status, aarn_updated_at);
