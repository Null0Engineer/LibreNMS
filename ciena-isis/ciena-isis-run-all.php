<?php

$init_modules = [];
require_once '/opt/librenms/includes/init.php';

$collector = '/opt/librenms/scripts/ciena-isis-collector.php';

if (!is_readable($collector)) {
    fwrite(STDERR, "Collector not found or not readable: {$collector}\n");
    exit(1);
}

$devices = dbFetchRows(
    "SELECT device_id, hostname FROM devices WHERE os = ? AND disabled = 0 ORDER BY hostname",
    ['ciena-saos']
);

if (empty($devices)) {
    echo "No enabled ciena-saos devices found.\n";
    exit(0);
}

foreach ($devices as $device) {
    $device_id = (int) $device['device_id'];
    $hostname = $device['hostname'];

    echo "\n===== Running Ciena ISIS collector for {$hostname} / device_id {$device_id} =====\n";

    $command = sprintf(
        'php %s %d 2>&1',
        escapeshellarg($collector),
        $device_id
    );

    passthru($command, $return_code);

    if ($return_code !== 0) {
        echo "ERROR: Collector failed for {$hostname} / device_id {$device_id} with exit code {$return_code}\n";
    }
}

echo "\nDone running Ciena ISIS collector for all ciena-saos devices.\n";
