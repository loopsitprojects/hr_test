<?php
$f = fopen('C:\\Users\\LENOVO\\.gemini\\antigravity\\brain\\12d2c8e6-4cdb-4b66-a5d2-40c0eb786534\\.system_generated\\logs\\transcript_full.jsonl', 'r');
$idx = 0;
while (($line = fgets($f)) !== false) {
    if (strpos($line, 'Kavindi Witharana') !== false) {
        echo "Found in line $idx! Length: " . strlen($line) . "\n";
        $data = json_decode($line, true);
        if ($data && isset($data['content'])) {
            file_put_contents('storage/app/raw_dataset.csv', $data['content']);
            echo "Saved content to storage/app/raw_dataset.csv\n";
        }
    }
    $idx++;
}
fclose($f);
