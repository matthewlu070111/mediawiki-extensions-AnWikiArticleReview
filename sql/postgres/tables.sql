-- AnWikiArticleReview PostgreSQL schema

CREATE TABLE anwiki_article_review_submission (
  aars_id SERIAL PRIMARY KEY,
  aars_submitter_user_id INTEGER NOT NULL,
  aars_namespace INTEGER NOT NULL,
  aars_title TEXT NOT NULL,
  aars_status INTEGER NOT NULL DEFAULT 0,
  aars_current_revision_id INTEGER NULL,
  aars_created_at TIMESTAMPTZ NOT NULL,
  aars_updated_at TIMESTAMPTZ NOT NULL,
  aars_reviewer_user_id INTEGER NULL,
  aars_reviewed_at TIMESTAMPTZ NULL,
  aars_review_comment TEXT NULL,
  aars_page_id INTEGER NULL,
  aars_published_revision_id INTEGER NULL,
  aars_row_version INTEGER NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX aars_submitter_user_id
  ON anwiki_article_review_submission (aars_submitter_user_id);
CREATE UNIQUE INDEX aars_namespace_title
  ON anwiki_article_review_submission (aars_namespace, aars_title);
CREATE INDEX aars_status_updated
  ON anwiki_article_review_submission (aars_status, aars_updated_at);
CREATE INDEX aars_reviewer_user_id
  ON anwiki_article_review_submission (aars_reviewer_user_id);

CREATE TABLE anwiki_article_review_revision (
  aarr_id SERIAL PRIMARY KEY,
  aarr_submission_id INTEGER NOT NULL,
  aarr_author_user_id INTEGER NOT NULL,
  aarr_content TEXT NOT NULL,
  aarr_summary TEXT NOT NULL,
  aarr_sha1 TEXT NOT NULL,
  aarr_created_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX aarr_submission_created
  ON anwiki_article_review_revision (aarr_submission_id, aarr_created_at);

CREATE TABLE anwiki_article_review_event (
  aare_id SERIAL PRIMARY KEY,
  aare_submission_id INTEGER NOT NULL,
  aare_actor_user_id INTEGER NOT NULL,
  aare_action TEXT NOT NULL,
  aare_old_status INTEGER NULL,
  aare_new_status INTEGER NOT NULL,
  aare_comment TEXT NULL,
  aare_created_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX aare_submission_created
  ON anwiki_article_review_event (aare_submission_id, aare_created_at);
CREATE INDEX aare_action
  ON anwiki_article_review_event (aare_action);

CREATE TABLE anwiki_article_review_notification (
  aarn_id SERIAL PRIMARY KEY,
  aarn_event_id INTEGER NOT NULL,
  aarn_recipient TEXT NOT NULL,
  aarn_recipient_hash TEXT NOT NULL,
  aarn_notification_type TEXT NOT NULL,
  aarn_status TEXT NOT NULL DEFAULT 'queued',
  aarn_attempt_count INTEGER NOT NULL DEFAULT 0,
  aarn_last_error TEXT NULL,
  aarn_created_at TIMESTAMPTZ NOT NULL,
  aarn_sent_at TIMESTAMPTZ NULL,
  aarn_updated_at TIMESTAMPTZ NOT NULL
);

CREATE UNIQUE INDEX aarn_event_recipient_type
  ON anwiki_article_review_notification (
    aarn_event_id, aarn_recipient_hash, aarn_notification_type
  );
CREATE INDEX aarn_status_updated
  ON anwiki_article_review_notification (aarn_status, aarn_updated_at);
