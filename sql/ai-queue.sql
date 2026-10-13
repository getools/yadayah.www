-- Puget AI job queue (2026-10-13) — see api/_ai_queue.php.
--
-- One row per kind of work the Puget GPU box can be asked to do. The queue
-- across ALL job tables is ordered by class priority DESC, then the job's own
-- in-class priority DESC, then submitted ASC. "Exclusive" classes need the
-- whole card (broker lease / qwen2.5:72b) and never run beside anything else;
-- shared classes (resident TTS/STT engines) may run side by side.

CREATE TABLE IF NOT EXISTS yy_ai_job_class (
    ai_job_class_code          text PRIMARY KEY,
    ai_job_class_name          text NOT NULL,
    ai_job_class_description   text NOT NULL DEFAULT '',
    ai_job_class_priority      integer NOT NULL DEFAULT 50,
    ai_job_class_exclusive_flag boolean NOT NULL DEFAULT false,
    ai_job_class_est_secs      integer NOT NULL DEFAULT 300,
    ai_job_class_sort          integer NOT NULL DEFAULT 0,
    ai_job_class_dtime         timestamptz NOT NULL DEFAULT now(),
    ai_job_class_revision_dtime timestamptz NOT NULL DEFAULT now(),
    ai_job_class_revision_user_key integer NOT NULL DEFAULT 0,
    ai_job_class_revision_num  integer NOT NULL DEFAULT 1
);

INSERT INTO yy_ai_job_class (ai_job_class_code, ai_job_class_name, ai_job_class_description,
                             ai_job_class_priority, ai_job_class_exclusive_flag, ai_job_class_est_secs, ai_job_class_sort)
SELECT * FROM (VALUES
  ('tts_preview',      'TTS preview',             'Short voice preview from the TTS editor (yy_tts_preview_job).',                     100, false,   10,  10),
  ('ask',              'Ask Yada answer',         'Local-LLM chat answer, gpt-oss:120b (yy_ask_job kind chat, claimed by ask_worker.py on Puget).', 90, true, 180, 20),
  ('ask_eval',         'Ask Yada eval run',       'Batch evaluation question (yy_ask_job kind eval).',                                    20, true,   300,  95),
  ('tts_build',        'TTS chapter build',       'Narrating one book chapter (yy_tts_audio).',                                          80, false, 1800,  30),
  ('tts_qa',           'TTS QA check',            'Re-transcribing built audio to check it (yy_tts_qa_job).',                             70, false,   40,  40),
  ('image',            'Image generation',        'AI tab - image (yy_t2i_job). Broker lease stops STT/TTS engines.',                    60, true,   100,  50),
  ('video',            'Video generation',        'AI tab - video (yy_i2v_job). Broker lease stops STT/TTS engines.',                    60, true,   300,  60),
  ('audio_gen',        'Audio / music generation','AI tab - audio (yy_t2a_job). Broker lease stops STT/TTS engines.',                    60, true,   120,  70),
  ('transcript_build', 'Transcript build + AI cleanup', 'Consensus transcript build; its LLM pass (qwen2.5:72b) needs the whole card (yy_feed_item_transcript_init_job).', 50, true, 600, 80),
  ('stt',              'Transcription (STT)',     'One speech-to-text engine run on one feed item (yy_feed_item_transcript_job).',       40, false,  120,  90),
  ('llm_backfill',     'Transcript AI cleanup (backlog)', 'Background LLM cleanup of older transcripts (transcript-llm-backfill.php cron).', 10, true, 900, 100)
) v
WHERE NOT EXISTS (SELECT 1 FROM yy_ai_job_class);

-- One push channel for the admin toolbar widget: fires on INSERT and on
-- status change only (progress ticks would be noise here).
DO $$
DECLARE t record;
BEGIN
  FOR t IN SELECT * FROM (VALUES
      ('yy_tts_audio',                     'tts_audio_key',                'tts_audio_status'),
      ('yy_tts_preview_job',               'job_key',                      'job_status'),
      ('yy_tts_qa_job',                    'job_key',                      'job_status'),
      ('yy_t2i_job',                       't2i_job_key',                  't2i_job_status'),
      ('yy_i2v_job',                       'i2v_job_key',                  'i2v_job_status'),
      ('yy_t2a_job',                       't2a_job_key',                  't2a_job_status'),
      ('yy_feed_item_transcript_job',      'feed_item_transcript_job_key', 'job_status'),
      ('yy_feed_item_transcript_init_job', 'job_key',                      'job_status'),
      ('yy_ask_job',                       'ask_job_key',                  'job_status')
  ) x(tbl, keycol, statcol) LOOP
    EXECUTE format('DROP TRIGGER IF EXISTS %I ON %I', 'trg_' || t.tbl || '_aiq_ins', t.tbl);
    EXECUTE format('DROP TRIGGER IF EXISTS %I ON %I', 'trg_' || t.tbl || '_aiq_upd', t.tbl);
    EXECUTE format('CREATE TRIGGER %I AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION yy_job_notify(%L, %L, %L, %L)',
                   'trg_' || t.tbl || '_aiq_ins', t.tbl, 'ai_queue', t.keycol, t.statcol, t.statcol);
    EXECUTE format('CREATE TRIGGER %I AFTER UPDATE ON %I FOR EACH ROW WHEN (OLD.%I::text IS DISTINCT FROM NEW.%I::text) EXECUTE FUNCTION yy_job_notify(%L, %L, %L, %L)',
                   'trg_' || t.tbl || '_aiq_upd', t.tbl, t.statcol, t.statcol, 'ai_queue', t.keycol, t.statcol, t.statcol);
  END LOOP;
END $$;
