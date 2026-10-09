-- Ask Yada (local LLM) prototype — schema
-- Applied 2026-10-09. New tables only; no existing table is ALTERed.
--
--   yy_ask_chunk          retrieval corpus: books + transcripts + glossary, FTS + pgvector
--   yy_ask_job            queue: member chat questions (kind=chat) and test-set runs (kind=eval)
--   yy_ask_eval_question  curated historical questions used to compare models
--   yy_ask_eval_run       one model/config pass over the eval set
--   trg_yy_ask_llm_dm_enqueue  member DMs the "Ask Yada" bot user -> job (+ optional ack)
--
-- The Puget worker never touches the DB directly; it talks to api/ask-llm.php.

BEGIN;

-- ── Retrieval corpus ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS yy_ask_chunk (
    ask_chunk_key          bigserial PRIMARY KEY,
    chunk_source_type      varchar(20)  NOT NULL,          -- book | transcript | glossary
    chunk_source_key       integer      NOT NULL,          -- volume_key | feed_item_key | word_key
    chunk_seq              integer      NOT NULL DEFAULT 0,-- order within the source
    chunk_title            text,                           -- "Series / Volume — Chapter" | video title | word
    chunk_locator          jsonb,                          -- where it came from (page, chapter, paragraph keys, timestamps…)
    chunk_text             text         NOT NULL,
    chunk_hash             char(32)     NOT NULL,          -- md5(title|text); embedding is cleared when it changes
    chunk_tsv              tsvector GENERATED ALWAYS AS
                               (to_tsvector('english', coalesce(chunk_title, '') || ' ' || chunk_text)) STORED,
    chunk_embedding        vector(1024),
    chunk_embedding_model  varchar(60),
    chunk_embedding_dtime  timestamptz,
    chunk_dtime            timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT uq_ask_chunk_source UNIQUE (chunk_source_type, chunk_source_key, chunk_seq)
);
CREATE INDEX IF NOT EXISTS idx_ask_chunk_tsv     ON yy_ask_chunk USING gin (chunk_tsv);
CREATE INDEX IF NOT EXISTS idx_ask_chunk_vec     ON yy_ask_chunk USING hnsw (chunk_embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS idx_ask_chunk_pending ON yy_ask_chunk (ask_chunk_key) WHERE chunk_embedding IS NULL;

-- ── Eval set + runs ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS yy_ask_eval_question (
    eval_question_key      serial PRIMARY KEY,
    ask_session_log_key    integer REFERENCES yy_ask_session_log(ask_session_log_key) ON DELETE SET NULL,
    question_text          text         NOT NULL,
    baseline_answer        text,                           -- what the current Ask Yada answered
    baseline_model         varchar(100),
    baseline_rating        smallint,
    reference_answer       text,                           -- admin-corrected / ideal answer, if any
    eval_tags              text,
    eval_active_flag       boolean      NOT NULL DEFAULT true,
    eval_dtime             timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_ask_eval_question_log ON yy_ask_eval_question (ask_session_log_key)
    WHERE ask_session_log_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS yy_ask_eval_run (
    eval_run_key           serial PRIMARY KEY,
    run_label              varchar(120) NOT NULL,
    run_model              varchar(80)  NOT NULL,          -- ollama tag, e.g. gpt-oss:120b
    run_config             jsonb        NOT NULL DEFAULT '{}'::jsonb,  -- prompt version, retrieval knobs…
    run_notes              text,
    user_key               integer REFERENCES yy_user(user_key),
    run_dtime              timestamptz  NOT NULL DEFAULT now()
);

-- ── Job queue ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS yy_ask_job (
    ask_job_key            bigserial PRIMARY KEY,
    job_kind               varchar(10)  NOT NULL DEFAULT 'chat',    -- chat | eval
    job_status             varchar(12)  NOT NULL DEFAULT 'queued',  -- queued | running | done | failed | cancelled
    job_priority           smallint     NOT NULL DEFAULT 0,         -- higher first; chat=10, eval=0
    thread_key             integer REFERENCES yy_community_dm_thread(thread_key) ON DELETE CASCADE,
    message_key            integer,                                 -- latest member message this job answers
    user_key               integer REFERENCES yy_user(user_key),
    question_text          text         NOT NULL,
    eval_run_key           integer REFERENCES yy_ask_eval_run(eval_run_key) ON DELETE CASCADE,
    eval_question_key      integer REFERENCES yy_ask_eval_question(eval_question_key) ON DELETE CASCADE,
    job_model              varchar(80),                             -- requested (eval) / actually used
    job_attempts           smallint     NOT NULL DEFAULT 0,
    job_worker             varchar(60),
    job_lease_until        timestamptz,
    job_progress           varchar(200),                            -- worker heartbeat text
    job_error              text,
    answer_text            text,                                    -- model output (markdown)
    answer_meta            jsonb,                                   -- sources, tool calls, tokens, timings
    posted_message_key     integer,
    review_rating          smallint,                                -- 0-100, same scale as ask_log_rating
    review_note            text,
    review_corrected       text,
    review_user_key        integer REFERENCES yy_user(user_key),
    review_dtime           timestamptz,
    job_dtime              timestamptz  NOT NULL DEFAULT now(),
    job_started_dtime      timestamptz,
    job_finished_dtime     timestamptz,
    CONSTRAINT ck_ask_job_kind   CHECK (job_kind IN ('chat', 'eval')),
    CONSTRAINT ck_ask_job_status CHECK (job_status IN ('queued', 'running', 'done', 'failed', 'cancelled'))
);
CREATE INDEX IF NOT EXISTS idx_ask_job_claim  ON yy_ask_job (job_priority DESC, ask_job_key) WHERE job_status = 'queued';
CREATE INDEX IF NOT EXISTS idx_ask_job_thread ON yy_ask_job (thread_key, job_status);
CREATE INDEX IF NOT EXISTS idx_ask_job_run    ON yy_ask_job (eval_run_key);
CREATE UNIQUE INDEX IF NOT EXISTS uq_ask_job_run_question ON yy_ask_job (eval_run_key, eval_question_key)
    WHERE eval_run_key IS NOT NULL;

-- Push job changes to admin dashboards (generic primitive, see job-notify-triggers.sql)
DROP TRIGGER IF EXISTS trg_yy_ask_job_notify_ins ON yy_ask_job;
CREATE TRIGGER trg_yy_ask_job_notify_ins AFTER INSERT ON yy_ask_job
    FOR EACH ROW EXECUTE FUNCTION yy_job_notify('ask_llm_job', 'ask_job_key', 'job_status', 'job_progress');
DROP TRIGGER IF EXISTS trg_yy_ask_job_notify_upd ON yy_ask_job;
CREATE TRIGGER trg_yy_ask_job_notify_upd AFTER UPDATE ON yy_ask_job
    FOR EACH ROW WHEN (OLD.job_status IS DISTINCT FROM NEW.job_status OR OLD.job_progress IS DISTINCT FROM NEW.job_progress)
    EXECUTE FUNCTION yy_job_notify('ask_llm_job', 'ask_job_key', 'job_status', 'job_progress');

-- ── Bot user ────────────────────────────────────────────────────────
INSERT INTO yy_user (user_code, user_name_full, user_display_name, user_name_display, user_handle,
                     user_bio, user_active_flag, user_verified,
                     user_email_on_reply, user_email_on_mention, user_email_on_watch, user_email_on_dm)
SELECT 'ask-yada', 'Ask Yada', 'Ask Yada', 'Ask Yada', 'askyada',
       'Send me a question and I will research it in Yada''s books and presentations, then reply here.',
       true, true, false, false, false, false
WHERE NOT EXISTS (SELECT 1 FROM yy_user WHERE user_code = 'ask-yada');

-- ── Settings (scope app, group ask-llm) ─────────────────────────────
INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value_code, setting_sort, setting_value, setting_label)
SELECT 'app', 'ask-llm', v.code, v.vcode, v.sort, v.val, v.label
FROM (VALUES
    ('bot-user-key',   'int',  1, (SELECT user_key::text FROM yy_user WHERE user_code = 'ask-yada'), 'Bot user that members message'),
    ('access',         'text', 2, 'admins', 'Who may ask: off | admins | members'),
    ('ack-message',    'text', 3, 'Thank you for your question. I am researching it now and will reply in this conversation. A thorough answer can take a while.', 'Instant acknowledgement (blank = none)'),
    ('closed-message', 'text', 4, 'Ask Yada in Chat is still being tested and is not open to members yet. Please use the Ask Yada page in the meantime.', 'Reply when the sender is not allowed (blank = silent)'),
    ('daily-limit',    'int',  5, '20', 'Questions per member per 24h'),
    ('limit-message',  'text', 6, 'You have reached today''s limit for questions. Please try again tomorrow.', 'Reply when over the daily limit'),
    ('worker-token',   'text', 7, encode(gen_random_bytes(24), 'hex'), 'Bearer token for the Puget worker'),
    ('chat-model',     'text', 8, 'gpt-oss:120b', 'Ollama model used for member questions'),
    ('exclude-series', 'text', 9, '8', 'Comma list of series_key never retrieved (matches current Ask Yada)')
) AS v(code, vcode, sort, val, label)
WHERE NOT EXISTS (SELECT 1 FROM yy_setting s WHERE s.setting_scope_code = 'app' AND s.setting_group_code = 'ask-llm' AND s.setting_code = v.code);

-- ── Chat hook ───────────────────────────────────────────────────────
-- Runs inside the member's "send message" transaction, so it must NEVER raise:
-- every path is wrapped and degrades to "do nothing" with a WARNING.
CREATE OR REPLACE FUNCTION trg_yy_ask_llm_dm_enqueue() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_bot     integer;
    v_access  text;
    v_limit   integer;
    v_reply   text;
    v_job     bigint;
    v_allowed boolean;
BEGIN
    BEGIN
        SELECT NULLIF(setting_value, '')::integer INTO v_bot FROM yy_setting
         WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code = 'bot-user-key';
        IF v_bot IS NULL OR NEW.user_key IS NULL OR NEW.user_key = v_bot OR NOT NEW.message_active_flag THEN
            RETURN NEW;
        END IF;

        -- Only 1:1 threads with the bot
        IF NOT EXISTS (SELECT 1 FROM yy_community_dm_thread t
                         JOIN yy_community_dm_participant p ON p.thread_key = t.thread_key AND p.user_key = v_bot
                        WHERE t.thread_key = NEW.thread_key AND NOT t.thread_is_group) THEN
            RETURN NEW;
        END IF;

        SELECT setting_value INTO v_access FROM yy_setting
         WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code = 'access';
        v_allowed := CASE coalesce(v_access, 'off')
                       WHEN 'members' THEN true
                       WHEN 'admins'  THEN EXISTS (SELECT 1 FROM yy_user_role ur WHERE ur.user_key = NEW.user_key AND ur.role_key = 2)
                       ELSE false END;

        IF NOT v_allowed THEN
            SELECT setting_value INTO v_reply FROM yy_setting
             WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code = 'closed-message';
        ELSE
            -- Follow-ups sent before the worker picks the job up fold into the same job;
            -- the worker reads the whole thread anyway.
            SELECT ask_job_key INTO v_job FROM yy_ask_job
             WHERE thread_key = NEW.thread_key AND job_status = 'queued' AND job_kind = 'chat'
             ORDER BY ask_job_key DESC LIMIT 1;
            IF v_job IS NOT NULL THEN
                UPDATE yy_ask_job SET message_key = NEW.message_key,
                                      question_text = question_text || E'\n\n' || NEW.message_body
                 WHERE ask_job_key = v_job;
                RETURN NEW;
            END IF;

            SELECT NULLIF(setting_value, '')::integer INTO v_limit FROM yy_setting
             WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code = 'daily-limit';
            IF v_limit IS NOT NULL AND v_limit > 0
               AND (SELECT count(*) FROM yy_ask_job WHERE user_key = NEW.user_key AND job_kind = 'chat'
                       AND job_dtime > now() - interval '24 hours') >= v_limit THEN
                SELECT setting_value INTO v_reply FROM yy_setting
                 WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code = 'limit-message';
            ELSE
                INSERT INTO yy_ask_job (job_kind, job_priority, thread_key, message_key, user_key, question_text)
                VALUES ('chat', 10, NEW.thread_key, NEW.message_key, NEW.user_key, NEW.message_body);
                SELECT setting_value INTO v_reply FROM yy_setting
                 WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code = 'ack-message';
            END IF;
        END IF;

        IF coalesce(v_reply, '') <> '' THEN
            -- clock_timestamp(): sort strictly after the member's message (now() is the txn start)
            INSERT INTO yy_community_dm_message (thread_key, user_key, message_body, message_dtime)
            VALUES (NEW.thread_key, v_bot, v_reply, clock_timestamp());
        END IF;
    EXCEPTION WHEN OTHERS THEN
        RAISE WARNING 'ask-llm enqueue skipped for message %: %', NEW.message_key, SQLERRM;
    END;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_yy_ask_llm_dm_enqueue ON yy_community_dm_message;
CREATE TRIGGER trg_yy_ask_llm_dm_enqueue AFTER INSERT ON yy_community_dm_message
    FOR EACH ROW EXECUTE FUNCTION trg_yy_ask_llm_dm_enqueue();

COMMIT;
