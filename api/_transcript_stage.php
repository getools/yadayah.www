<?php
/**
 * Transcript machine-pipeline stage for admin list rows (2026-10-10).
 *
 * The list's Transcribe/Transcript button only reflects the HUMAN review state
 * (Transcribe / Pending · bookmark / Approved / Errors). This adds the MACHINE
 * stages so an operator can see where an item is in processing:
 *   engines transcribing (STT jobs) → editable build queued / running / failed
 *   → built (fast, AI cleanup still queued) → built + AI cleanup done.
 * Returns a SQL expression (a json object) for a correlated subquery on $col
 * (e.g. 'fi.feed_item_key'); the browser renders it via txStageLineHtml().
 * Text-pattern checks only (no ::jsonb on job_params) so one malformed row can
 * never break the list query.
 */
function txStageSelectSql(string $col): string {
    return "json_build_object(
        'stt_active', (SELECT COUNT(*) FROM yy_feed_item_transcript_job sj
                        WHERE sj.feed_item_key = $col AND sj.job_status IN ('pending','running')),
        'stt_running', (SELECT COUNT(*) FROM yy_feed_item_transcript_job sj
                        WHERE sj.feed_item_key = $col AND sj.job_status = 'running'),
        'init', (SELECT json_build_object(
                        'status',  ij.job_status,
                        'fast',    (ij.job_params LIKE '%\"llm_reconcile\": false%' OR ij.job_params LIKE '%\"llm_reconcile\":false%'),
                        'error',   left(ij.job_error, 300),
                        'created', ij.job_created,
                        'done',    ij.job_completed)
                   FROM yy_feed_item_transcript_init_job ij
                  WHERE ij.job_item_key = $col
                  ORDER BY ij.job_key DESC LIMIT 1),
        'ai_at', (SELECT MAX(sn.snapshot_dtime) FROM yy_transcript_snapshot sn
                   WHERE sn.feed_item_key = $col
                     AND sn.snapshot_reason LIKE 'pre auto LLM reconcile%'
                     AND sn.snapshot_reason NOT LIKE '%incomplete%'),
        'edit_status', (SELECT st.edit_status FROM yy_feed_item_transcript_status st WHERE st.feed_item_key = $col)
    )";
}
