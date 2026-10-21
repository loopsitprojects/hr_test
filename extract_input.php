<?php
$transcriptPath = 'C:\\Users\\LENOVO\\.gemini\\antigravity\\brain\\12d2c8e6-4cdb-4b66-a5d2-40c0eb786534\\.system_generated\\logs\\transcript_full.jsonl';
if (!file_exists($transcriptPath)) {
    $transcriptPath = 'C:\\Users\\LENOVO\\.gemini\\antigravity\\brain\\12d2c8e6-4cdb-4b66-a5d2-40c0eb786534\\.system_generated\\logs\\transcript.jsonl';
}

if (!file_exists($transcriptPath)) {
    echo "Transcript not found\n";
    exit(1);
}

$handle = fopen($transcriptPath, 'r');
$userContent = '';
while (($line = fgets($handle)) !== false) {
    $data = json_decode($line, true);
    if ($data && isset($data['type']) && $data['type'] === 'USER_INPUT') {
        $userContent = $data['content'];
        break;
    }
}
fclose($handle);

echo "Found user input length: " . strlen($userContent) . "\n";
// Save the extracted user input to storage/app/full_attendance_input.txt
file_put_contents('storage/app/full_attendance_input.txt', $userContent);
echo "Saved to storage/app/full_attendance_input.txt\n";
