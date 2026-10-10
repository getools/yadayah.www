-- Ask Yada local LLM: per-source search weights (user request 2026-10-10).
-- Replaces the on/off 'sources' + 'transcript-speakers' settings: weight 0 = never searched.
-- Transcript lines with no speaker label are assumed to be Yada ('Speaker unknown').
BEGIN;
INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value_code, setting_sort, setting_value, setting_label)
SELECT 'app', 'ask-llm', v.code, 'decimal', v.sort, v.val, v.label
FROM (VALUES
    ('weight-book',               13, '1.0', 'Weight: Books'),
    ('weight-transcript-yada',    14, '1.0', 'Weight: Transcripts, speaker Yada'),
    ('weight-transcript-unknown', 15, '0.8', 'Weight: Transcripts, speaker unknown (assumed Yada)'),
    ('weight-post',               16, '1.0', 'Weight: Yada''s community posts'),
    ('weight-dm',                 17, '0',   'Weight: Yada''s Chat messages (private conversations)'),
    ('weight-glossary',           18, '0',   'Weight: Glossary')
) AS v(code, sort, val, label)
WHERE NOT EXISTS (SELECT 1 FROM yy_setting s WHERE s.setting_scope_code = 'app' AND s.setting_group_code = 'ask-llm' AND s.setting_code = v.code);

DELETE FROM yy_setting
 WHERE setting_scope_code = 'app' AND setting_group_code = 'ask-llm' AND setting_code IN ('sources', 'transcript-speakers');
COMMIT;
SELECT setting_code, setting_value FROM yy_setting WHERE setting_group_code = 'ask-llm' AND setting_code LIKE 'weight-%' ORDER BY setting_sort;
