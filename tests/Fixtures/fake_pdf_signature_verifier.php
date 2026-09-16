<?php

$arguments = array_slice($argv, 1);

if (in_array('--health-check', $arguments, true)) {
    echo json_encode(['ok' => true, 'code' => 'ready']);
    exit(0);
}

$contents = isset($arguments[0]) && is_file($arguments[0])
    ? trim((string) file_get_contents($arguments[0]))
    : '';

$result = match ($contents) {
    'valid' => ['ok' => true, 'code' => 'valid_signature'],
    'unsigned' => ['ok' => false, 'code' => 'no_signature'],
    'untrusted' => ['ok' => false, 'code' => 'untrusted_signature'],
    default => ['ok' => false, 'code' => 'invalid_signature'],
};

echo json_encode($result);
exit(($result['ok'] ?? false) ? 0 : 2);
