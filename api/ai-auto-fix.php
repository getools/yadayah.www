<?php
/**
 * AI Auto-Fix — sends PHP errors to Claude for diagnosis and automatic repair.
 * Called by cron-monitor.php when pattern-based fixes can't resolve an error.
 *
 * Usage: $result = aiAutoFix($errorMessage, $errorDetail, $filePath, $lineNumber);
 * Returns: ['fixed' => bool, 'action' => string, 'changes' => string|null]
 */

function readEnvKeyMonitor(string $name): string {
    $val = getenv($name);
    if ($val) return $val;
    $envPaths = [
        dirname(__DIR__) . '/.env',
        realpath(__DIR__ . '/..') . '/.env',
    ];
    foreach ($envPaths as $envFile) {
        if ($envFile && file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, $name . '=') === 0) {
                    return trim(substr($line, strlen($name) + 1));
                }
            }
        }
    }
    return '';
}

function aiAutoFix(string $errorMessage, string $errorDetail, ?string $filePath = null, ?int $lineNumber = null): array {
    $apiKey = readEnvKeyMonitor('ANTHROPIC_API_KEY');
    if (!$apiKey) {
        return ['fixed' => false, 'action' => 'AI fix skipped: no API key'];
    }

    // Read the source file if we have a path
    $sourceContext = '';
    $fullPath = null;
    if ($filePath) {
        // Normalize path — strip container prefix, resolve to local
        $relPath = preg_replace('#^/var/www/html/#', '', $filePath);
        $fullPath = __DIR__ . '/../' . $relPath;
        if (!file_exists($fullPath)) {
            $fullPath = __DIR__ . '/' . basename($relPath);
        }
        if (file_exists($fullPath)) {
            $source = file_get_contents($fullPath);
            $lines = explode("\n", $source);
            $totalLines = count($lines);

            // Show surrounding context (80 lines around the error)
            if ($lineNumber && $lineNumber > 0) {
                $start = max(0, $lineNumber - 40);
                $end = min($totalLines, $lineNumber + 40);
                $contextLines = [];
                for ($i = $start; $i < $end; $i++) {
                    $prefix = ($i + 1 === $lineNumber) ? '>>> ' : '    ';
                    $contextLines[] = $prefix . ($i + 1) . ': ' . $lines[$i];
                }
                $sourceContext = "File: {$relPath} ({$totalLines} lines)\nError at line {$lineNumber}:\n" . implode("\n", $contextLines);
            } else {
                // Show first 80 lines if no specific line
                $sourceContext = "File: {$relPath} ({$totalLines} lines)\n" . implode("\n", array_map(function($i) use ($lines) {
                    return '    ' . ($i + 1) . ': ' . $lines[$i];
                }, range(0, min(79, $totalLines - 1))));
            }
        }
    }

    // Also try to get the DB schema context if it's a SQL error
    $schemaContext = '';
    if (preg_match('/relation "(\w+)"/', $errorMessage . ' ' . $errorDetail, $tm)) {
        $tableName = $tm[1];
        try {
            $db = function_exists('getDb') ? getDb() : null;
            if ($db) {
                $cols = $db->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
                $cols->execute([$tableName]);
                $colList = $cols->fetchAll(PDO::FETCH_ASSOC);
                if ($colList) {
                    $schemaContext = "\nTable schema for {$tableName}:\n";
                    foreach ($colList as $c) {
                        $schemaContext .= "  {$c['column_name']} ({$c['data_type']})\n";
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    $prompt = "You are a PHP/PostgreSQL auto-fix agent for a production web application. An error has occurred and you MUST fix it. You are expected to apply fixes — do not decline unless the error is truly ambiguous with multiple equally valid fixes.\n\n"
        . "ERROR MESSAGE:\n{$errorMessage}\n\n"
        . "FULL ERROR DETAIL:\n{$errorDetail}\n\n";
    if ($sourceContext) $prompt .= "SOURCE CODE:\n{$sourceContext}\n\n";
    if ($schemaContext) $prompt .= "DATABASE CONTEXT:\n{$schemaContext}\n";
    $prompt .= "\nAnalyze the error and provide a fix. Respond in this exact JSON format:\n"
        . "```json\n"
        . "{\n"
        . "  \"diagnosis\": \"Brief explanation of what's wrong\",\n"
        . "  \"can_fix\": true,\n"
        . "  \"fix_type\": \"file_edit\" or \"sql\" or \"both\",\n"
        . "  \"file_edits\": [{\"file\": \"relative/path.php\", \"find\": \"exact string to find\", \"replace\": \"replacement string\"}],\n"
        . "  \"sql\": [\"SQL statement to run\"],\n"
        . "  \"summary\": \"One-line description of what was fixed\"\n"
        . "}\n```\n\n"
        . "Rules:\n"
        . "- You MUST attempt a fix. Set can_fix to true and provide file_edits or sql.\n"
        . "- Only fix the specific error, don't refactor unrelated code\n"
        . "- file_edits.find must be an EXACT substring copied from the source code shown above — match whitespace and indentation precisely\n"
        . "- file_edits.file should be the relative path from the web root (e.g. 'api/filename.php')\n"
        . "- For undefined variables: initialize them before first use\n"
        . "- For missing columns: use ALTER TABLE ADD COLUMN with the appropriate type\n"
        . "- For syntax errors: fix the syntax (missing braces, semicolons, etc.)\n"
        . "- SQL statements must be safe (no DROP TABLE, no DELETE without WHERE)\n"
        . "- Only set can_fix to false if the source code is not available or the error is in a third-party library\n"
        . "- Respond ONLY with the JSON block, no other text";

    // Call Claude API
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 4000,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return ['fixed' => false, 'action' => 'AI fix failed: HTTP ' . $httpCode];
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';

    // Extract JSON from response
    if (preg_match('/```json\s*(.*?)\s*```/s', $text, $jm)) {
        $fix = json_decode($jm[1], true);
    } else {
        $fix = json_decode($text, true);
    }

    if (!$fix || !is_array($fix)) {
        return ['fixed' => false, 'action' => 'AI fix: could not parse response'];
    }

    if (empty($fix['can_fix'])) {
        return ['fixed' => false, 'action' => 'AI diagnosis: ' . ($fix['diagnosis'] ?? 'unknown')];
    }

    $actions = [];

    // Apply file edits
    if (!empty($fix['file_edits']) && is_array($fix['file_edits'])) {
        foreach ($fix['file_edits'] as $edit) {
            $editPath = __DIR__ . '/../' . $edit['file'];
            if (!file_exists($editPath)) {
                $editPath = __DIR__ . '/' . basename($edit['file']);
            }
            if (!file_exists($editPath)) {
                $actions[] = "File not found: {$edit['file']}";
                continue;
            }
            $content = file_get_contents($editPath);
            if (strpos($content, $edit['find']) === false) {
                $actions[] = "String not found in {$edit['file']}";
                continue;
            }
            $newContent = str_replace($edit['find'], $edit['replace'], $content);
            if ($newContent !== $content) {
                file_put_contents($editPath, $newContent);
                $actions[] = "Fixed {$edit['file']}";
            }
        }
    }

    // Apply SQL fixes
    if (!empty($fix['sql']) && is_array($fix['sql'])) {
        try {
            $db = function_exists('getDb') ? getDb() : null;
            if ($db) {
                foreach ($fix['sql'] as $sql) {
                    // Safety check — block dangerous operations
                    $upper = strtoupper(trim($sql));
                    if (preg_match('/^(DROP\s+TABLE|TRUNCATE|DELETE\s+FROM\s+\w+\s*$)/i', $upper)) {
                        $actions[] = "Blocked unsafe SQL: " . substr($sql, 0, 100);
                        continue;
                    }
                    $db->exec($sql);
                    $actions[] = "Executed SQL: " . substr($sql, 0, 200);
                }
            }
        } catch (Throwable $e) {
            $actions[] = "SQL failed: " . $e->getMessage();
        }
    }

    $summary = $fix['summary'] ?? $fix['diagnosis'] ?? 'AI auto-fix applied';
    $actionStr = $summary . ' | ' . implode('; ', $actions);

    return [
        'fixed' => !empty($actions),
        'action' => substr($actionStr, 0, 2000),
        'diagnosis' => $fix['diagnosis'] ?? '',
    ];
}
