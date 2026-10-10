-- Ask Yada local LLM: learning sources = books + Yada's own transcript speech + Yada's own community/Chat writing (2026-10-10)
INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code, setting_value_code, setting_sort, setting_value, setting_label)
SELECT 'app', 'ask-llm', v.code, v.vcode, v.sort, v.val, v.label
FROM (VALUES
    ('yada-user-key',       'int',  10, '6',                     'Yada''s own member account (Craig Winn): his posts and Chat messages are a source'),
    ('sources',             'text', 11, 'book,transcript,post',  'Sources the model may search: book, transcript, post, dm, glossary'),
    ('transcript-speakers', 'text', 12, 'yada',                  'Transcript passages: yada (only Yada''s speech) | yada+unlabeled (also videos not yet speaker-labelled)')
) AS v(code, vcode, sort, val, label)
WHERE NOT EXISTS (SELECT 1 FROM yy_setting s WHERE s.setting_scope_code = 'app' AND s.setting_group_code = 'ask-llm' AND s.setting_code = v.code);
SELECT setting_code, setting_value FROM yy_setting WHERE setting_group_code = 'ask-llm' AND setting_sort >= 10 ORDER BY setting_sort;
