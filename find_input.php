<?php
$transcriptPath = 'C:\\Users\\LENOVO\\.gemini\\antigravity\\brain\\12d2c8e6-4cdb-4b66-a5d2-40c0eb786534\\.system_generated\\logs\\transcript_full.jsonl';
$lines = file($transcriptPath);
echo "Total lines in transcript_full: " . count($lines) . "\n";
foreach ($lines as $i => $l) {
    $d = json_decode($l, true);
    echo "Line $i: type=" . ($d['type'] ?? 'none') . ", source=" . ($d['source'] ?? 'none') . ", content_len=" . strlen($d['content'] ?? '') . "\n";
    if (isset($d['content']) && strlen($d['content']) > 1000) {
        file_put_contents('storage/app/full_attendance_input.txt', $d['content']);
        echo "Found large content in Line $i, saved to storage/app/full_attendance_input.txt!\n";
    }
}
