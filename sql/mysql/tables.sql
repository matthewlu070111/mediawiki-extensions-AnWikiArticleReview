-- AnWikiArticleReview MySQL/MariaDB schema
-- Used as fallback when abstract schema is not applied

CREATE TABLE /*_*/anwiki_article_review_submission (
  aars_id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  aars_submitter_user_id INT UNSIGNED NOT NULL,
  aars_namespace INT NOT NULL,
  aars_title VARBINARY(255) NOT NULL,
  aars_status INT UNSIGNED NOT NULL DEFAULT 0,
  aars_current_revision_id INT UNSIGNED NULL,
  aars_created_at BINARY(14) NOT NULL,
  aars_updated_at BINARY(14) NOT NULL,
  aars_reviewer_user_id INT UNSIGNED NULL,
  aars_reviewed_at BINARY(14) NULL,
  aars_review_comment MEDIUMBLOB NULL,
  aars_page_id INT UNSIGNED NULL,
  aars_published_revision_id INT UNSIGNED NULL,
  aars_row_version INT UNSIGNED NOT NULL DEFAULT 1
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/aars_submitter_user_id
  ON /*_*/anwiki_article_review_submission (aars_submitter_user_id);
CREATE UNIQUE INDEX /*i*/aars_namespace_title
  ON /*_*/anwiki_article_review_submission (aars_namespace, aars_title);
CREATE INDEX /*i*/aars_status_updated
  ON /*_*/anwiki_article_review_submission (aars_status, aars_updated_at);
CREATE INDEX /*i*/aars_reviewer_user_id
  ON /*_*/anwiki_article_review_submission (aars_reviewer_user_id);

CREATE TABLE /*_*/anwiki_article_review_revision (
  aarr_id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  aarr_submission_id INT UNSIGNED NOT NULL,
  aarr_author_user_id INT UNSIGNED NOT NULL,
  aarr_content MEDIUMBLOB NOT NULL,
  aarr_summary VARBINARY(500) NOT NULL,
  aarr_sha1 VARBINARY(40) NOT NULL,
  aarr_created_at BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/aarr_submission_created
  ON /*_*/anwiki_article_review_revision (aarr_submission_id, aarr_created_at);

CREATE TABLE /*_*/anwiki_article_review_event (
  aare_id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  aare_submission_id INT UNSIGNED NOT NULL,
  aare_actor_user_id INT UNSIGNED NOT NULL,
  aare_action VARBINARY(32) NOT NULL,
  aare_old_status INT UNSIGNED NULL,
  aare_new_status INT UNSIGNED NOT NULL,
  aare_comment MEDIUMBLOB NULL,
  aare_created_at BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/aare_submission_created
  ON /*_*/anwiki_article_review_event (aare_submission_id, aare_created_at);
CREATE INDEX /*i*/aare_action
  ON /*_*/anwiki_article_review_event (aare_action);

CREATE TABLE /*_*/anwiki_article_review_notification (
  aarn_id INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  aarn_event_id INT UNSIGNED NOT NULL,
  aarn_recipient VARBINARY(255) NOT NULL,
  aarn_recipient_hash VARBINARY(64) NOT NULL,
  aarn_notification_type VARBINARY(32) NOT NULL,
  aarn_status VARBINARY(16) NOT NULL DEFAULT 'queued',
  aarn_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  aarn_last_error MEDIUMBLOB NULL,
  aarn_created_at BINARY(14) NOT NULL,
  aarn_sent_at BINARY(14) NULL,
  aarn_updated_at BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/aarn_event_recipient_type
  ON /*_*/anwiki_article_review_notification (
    aarn_event_id, aarn_recipient_hash, aarn_notification_type
  );
CREATE INDEX /*i*/aarn_status_updated
  ON /*_*/anwiki_article_review_notification (aarn_status, aarn_updated_at);
