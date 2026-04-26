-- Ask Yada RAG tables: feedback, embeddings, and learned corrections

-- Mod feedback on Ask Yada responses
CREATE TABLE IF NOT EXISTS yy_ask_feedback (
    ask_feedback_key serial PRIMARY KEY,
    ask_session_key int REFERENCES yy_ask_session(ask_session_key),
    ask_session_log_key int,
    user_key int REFERENCES yy_user(user_key),         -- mod who gave feedback
    feedback_rating varchar(20) NOT NULL,               -- good, needs_correction, bad
    feedback_corrected_answer text,                     -- the correct answer if needs_correction
    feedback_note text,                                 -- mod's notes
    feedback_dtime timestamptz NOT NULL DEFAULT NOW(),
    feedback_active_flag boolean NOT NULL DEFAULT TRUE,
    ask_feedback_revision_dtime timestamptz DEFAULT NOW(),
    ask_feedback_revision_user_key int DEFAULT 0,
    ask_feedback_revision_num int DEFAULT 1
);

-- Embeddings of Q&A pairs for similarity search
-- Uses Claude's embedding dimension (1024 for voyage-3-lite or 1536 for text-embedding-3-small)
CREATE TABLE IF NOT EXISTS yy_ask_embedding (
    ask_embedding_key serial PRIMARY KEY,
    source_type varchar(20) NOT NULL,                   -- question, answer, correction, qanda
    source_key int,                                     -- FK to session_log or qanda
    content_text text NOT NULL,                         -- the text that was embedded
    embedding vector(1024),                             -- the vector embedding
    metadata jsonb,                                     -- extra context (question, topic, etc.)
    embedding_model varchar(50),                        -- model used for embedding
    embedding_dtime timestamptz NOT NULL DEFAULT NOW(),
    ask_embedding_revision_dtime timestamptz DEFAULT NOW(),
    ask_embedding_revision_user_key int DEFAULT 0,
    ask_embedding_revision_num int DEFAULT 1
);

-- Index for fast similarity search
CREATE INDEX IF NOT EXISTS idx_ask_embedding_vector ON yy_ask_embedding
    USING ivfflat (embedding vector_cosine_ops) WITH (lists = 20);

CREATE INDEX IF NOT EXISTS idx_ask_embedding_source ON yy_ask_embedding (source_type, source_key);

-- Learned system prompt additions from mod corrections
CREATE TABLE IF NOT EXISTS yy_ask_learned (
    ask_learned_key serial PRIMARY KEY,
    learned_type varchar(20) NOT NULL DEFAULT 'correction',  -- correction, guideline, fact
    learned_question text,                              -- the question pattern
    learned_answer text NOT NULL,                       -- the correct/approved answer
    learned_priority int DEFAULT 0,                     -- higher = more important
    learned_active_flag boolean NOT NULL DEFAULT TRUE,
    user_key int REFERENCES yy_user(user_key),          -- mod who approved
    ask_feedback_key int REFERENCES yy_ask_feedback(ask_feedback_key),
    learned_dtime timestamptz NOT NULL DEFAULT NOW(),
    ask_learned_revision_dtime timestamptz DEFAULT NOW(),
    ask_learned_revision_user_key int DEFAULT 0,
    ask_learned_revision_num int DEFAULT 1
);

-- Create rev tables
CREATE TABLE IF NOT EXISTS yy_ask_feedback_rev (
    ask_feedback_revision_id bigserial PRIMARY KEY,
    ask_feedback_revision_delete_dtime timestamptz,
    LIKE yy_ask_feedback INCLUDING DEFAULTS
);

CREATE TABLE IF NOT EXISTS yy_ask_embedding_rev (
    ask_embedding_revision_id bigserial PRIMARY KEY,
    ask_embedding_revision_delete_dtime timestamptz,
    LIKE yy_ask_embedding INCLUDING DEFAULTS
);

CREATE TABLE IF NOT EXISTS yy_ask_learned_rev (
    ask_learned_revision_id bigserial PRIMARY KEY,
    ask_learned_revision_delete_dtime timestamptz,
    LIKE yy_ask_learned INCLUDING DEFAULTS
);
