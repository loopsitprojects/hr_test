<?php
$transcriptPath = 'C:\\Users\\LENOVO\\.gemini\\antigravity\\brain\\12d2c8e6-4cdb-4b66-a5d2-40c0eb786534\\.system_generated\\logs\\transcript_full.jsonl';
$lines = file($transcriptPath);
$d = json_decode($lines[0], true);
echo substr($d['content'], 0, 500) . "\n";
echo "...\n";
echo substr($d['content'], -500) . "\n";
echo "Full length: " . strlen($d['content']) . "\n";
file_put_contents('storage/app/user_prompt_full.txt', $d['content']);
