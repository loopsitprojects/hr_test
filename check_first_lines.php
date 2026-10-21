<?php
$f = fopen('C:\\Users\\LENOVO\\.gemini\\antigravity\\brain\\12d2c8e6-4cdb-4b66-a5d2-40c0eb786534\\.system_generated\\logs\\transcript_full.jsonl', 'r');
for ($i = 0; $i < 10; $i++) {
    $line = fgets($f);
    $d = json_decode($line, true);
    echo "Line $i: step=" . ($d['step_index'] ?? '') . ", type=" . ($d['type'] ?? '') . ", len=" . strlen($line) . "\n";
}
fclose($f);
