<?php

$init_modules = [];
require_once '/opt/librenms/includes/init.php';

$config_file = '/opt/librenms/config/ciena-xcvr.json';
$env_file = '/opt/librenms/config/ciena-xcvr.env';
$state_file = '/opt/librenms/storage/app/ciena-isis-state.json';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php ciena-isis-collector.php <device_id>\n");
    exit(1);
}

$device_id = (int) $argv[1];

if (!is_readable($config_file)) {
    fwrite(STDERR, "Cannot read $config_file\n");
    exit(1);
}

if (!is_readable($env_file)) {
    fwrite(STDERR, "Cannot read $env_file\n");
    exit(1);
}

$config = json_decode(file_get_contents($config_file), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "JSON error: " . json_last_error_msg() . "\n");
    exit(1);
}

foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B'\""));
}

$username = getenv($config['transport']['username_env']);
$password = getenv($config['transport']['password_env']);
$port = (int) ($config['transport']['port'] ?? 22);
$timeout = (int) ($config['transport']['timeout'] ?? 30);

if (!$username || !$password) {
    fwrite(STDERR, "Missing SSH username or password from env file\n");
    exit(1);
}

$device = dbFetchRow('SELECT * FROM devices WHERE device_id = ?', [$device_id]);

if (!$device) {
    fwrite(STDERR, "No LibreNMS device found for device_id $device_id\n");
    exit(1);
}

if (($device['os'] ?? '') !== ($config['os'] ?? 'ciena-saos')) {
    fwrite(STDERR, "Skipping {$device['hostname']} because OS is {$device['os']}\n");
    exit(0);
}

$host = $device['hostname'];

function load_isis_state(string $state_file): array
{
    if (!is_readable($state_file)) {
        return [];
    }

    $data = json_decode(file_get_contents($state_file), true);

    if (!is_array($data)) {
        return [];
    }

    return $data;
}

function save_isis_state(string $state_file, array $state): void
{
    $dir = dirname($state_file);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $tmp = $state_file . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $state_file);
}

function state_key(int $device_id, string $index): string
{
    return $device_id . ':' . $index;
}

function calculate_state_duration(array &$state_store, int $device_id, string $index, string $new_state, int $now): int
{
    $key = state_key($device_id, $index);

    if (!isset($state_store[$key]) || !is_array($state_store[$key])) {
        $state_store[$key] = [
            'state' => $new_state,
            'last_change' => $now,
        ];

        return 1;
    }

    $old_state = strtolower((string) ($state_store[$key]['state'] ?? ''));
    $last_change = (int) ($state_store[$key]['last_change'] ?? $now);

    if ($old_state !== $new_state) {
        $state_store[$key] = [
            'state' => $new_state,
            'last_change' => $now,
        ];

        return 1;
    }

    if ($last_change < 1 || $last_change > $now) {
        $state_store[$key]['last_change'] = $now;
        return 1;
    }

    return max(1, $now - $last_change);
}

function mark_all_isis_down(int $device_id, string $reason, array &$state_store, int $now): void
{
    $rows = dbFetchRows(
        'SELECT id, `index`, isisISAdjState, isisISAdjNeighSysID, isisISAdjAreaAddress
         FROM isis_adjacencies
         WHERE device_id = ?',
        [$device_id]
    );

    foreach ($rows as $row) {
        $index = (string) ($row['index'] ?? '');
        $duration = $index !== '' ? calculate_state_duration($state_store, $device_id, $index, 'down', $now) : 1;

        dbUpdate(
            [
                'isisISAdjState' => 'down',
                'isisISAdjLastUpTime' => $duration,
            ],
            'isis_adjacencies',
            'id = ?',
            [$row['id']]
        );

        echo "DOWN ISIS {$row['isisISAdjAreaAddress']} -> {$row['isisISAdjNeighSysID']}: {$reason}, duration {$duration}s\n";
    }
}

function strip_terminal_junk(string $value): string
{
    $value = preg_replace('/\x1B\[[0-9;?]*[A-Za-z]/', '', $value);
    $value = str_replace("\r", '', $value);
    $value = preg_replace('/[[:cntrl:]]/', '', $value);
    return trim($value);
}

function expect_escape(string $value): string
{
    return str_replace(
        ['\\', '"', '$', '[', ']'],
        ['\\\\', '\\"', '\\$', '\\[', '\\]'],
        $value
    );
}

function run_ciena_expect_session(
    string $host,
    string $username,
    string $password,
    int $port,
    int $timeout,
    array $commands
): string {
    $expect_file = tempnam('/tmp', 'ciena-isis-expect-');

    if ($expect_file === false) {
        return '';
    }

    $prompt_re = '[>#]\s*$';

    $expect = "#!/usr/bin/expect -f\n";
    $expect .= "set timeout " . (int) $timeout . "\n";
    $expect .= "match_max 1000000\n";
    $expect .= "log_user 1\n";
    $expect .= "spawn ssh -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=" . (int) $timeout . " -p " . (int) $port . " " . expect_escape($username) . "@" . expect_escape($host) . "\n";
    $expect .= "expect {\n";
    $expect .= "    -re \"(?i)password:\" { send \"" . expect_escape($password) . "\\r\" }\n";
    $expect .= "    -re {" . $prompt_re . "} { }\n";
    $expect .= "    timeout { exit 10 }\n";
    $expect .= "    eof { exit 11 }\n";
    $expect .= "}\n";
    $expect .= "expect {\n";
    $expect .= "    -re {" . $prompt_re . "} { }\n";
    $expect .= "    timeout { }\n";
    $expect .= "}\n";

    foreach ($commands as $command) {
        $expect .= "send \"" . expect_escape($command) . "\\r\"\n";
        $expect .= "expect {\n";
        $expect .= "    -re {" . $prompt_re . "} { }\n";
        $expect .= "    timeout { }\n";
        $expect .= "    eof { exit 12 }\n";
        $expect .= "}\n";
    }

    $expect .= "send \"exit\\r\"\n";
    $expect .= "expect eof\n";

    file_put_contents($expect_file, $expect);
    chmod($expect_file, 0700);

    $command = 'timeout ' . (int) (($timeout * 2) + (count($commands) * $timeout)) . ' ' . escapeshellarg($expect_file) . ' 2>&1';
    $output = shell_exec($command) ?? '';

    unlink($expect_file);

    return $output;
}

function parse_isis_neighbors(string $output): array
{
    $neighbors = [];

    foreach (explode("\n", $output) as $line) {
        $line = strip_terminal_junk($line);

        if (!preg_match('/^\|\s*(P2P|LAN|Broadcast)\s+\|\s+(.+?)\s+\|\s+(.+?)\s+\|\s+(.+?)\s+\|\s+(Up|Down|Init|Failed)\s+\|\s+([0-9-]+)\s+\|\s+(\S+)\s+\|\s+(.+?)\s+\|$/i', $line, $m)) {
            continue;
        }

        $neighbors[] = [
            'neighbor_type' => trim($m[1]),
            'system_id' => trim($m[2]),
            'interface' => trim($m[3]),
            'snpa' => trim($m[4]),
            'state' => strtolower(trim($m[5])),
            'hold_time' => trim($m[6]),
            'level' => strtoupper(trim($m[7])),
            'protocol' => trim($m[8]),
        ];
    }

    return $neighbors;
}

function parse_configured_isis_interfaces(string $output): array
{
    $interfaces = [];

    foreach (explode("\n", $output) as $line) {
        $line = strip_terminal_junk($line);

        if (preg_match('/^isis\s+instance\s+[\'"]([^\'"]+)[\'"]\s+interfaces\s+interface\s+[\'"]([^\'"]+)[\'"]/', $line, $m)) {
            $interfaces[trim($m[2])] = true;
        }
    }

    return array_keys($interfaces);
}

function parse_fp_to_logical_port(string $output): array
{
    $map = [];

    foreach (explode("\n", $output) as $line) {
        $line = strip_terminal_junk($line);

        if (preg_match('/^fps\s+fp\s+[\'"]([^\'"]+)[\'"]\s+fd-name\s+[\'"]([^\'"]+)[\'"]\s+logical-port\s+[\'"]([^\'"]+)[\'"]/', $line, $m)) {
            $fp = trim($m[1]);
            $fd = trim($m[2]);
            $logical_port = trim($m[3]);

            if (!isset($map[$fp])) {
                $map[$fp] = $logical_port;
            }

            if (!isset($map[$fd])) {
                $map[$fd] = $logical_port;
            }
        }
    }

    return $map;
}

function resolve_port_for_isis_interface(int $device_id, string $isis_interface, array $fp_to_port): ?array
{
    $logical_port = $fp_to_port[$isis_interface] ?? null;

    if ($logical_port) {
        $port = dbFetchRow(
            'SELECT port_id, ifIndex, ifName, ifDescr, ifAlias
             FROM ports
             WHERE device_id = ?
             AND (ifName = ? OR ifDescr = ? OR ifAlias = ?)
             LIMIT 1',
            [$device_id, $logical_port, $logical_port, $logical_port]
        );

        if ($port) {
            return $port;
        }
    }

    $port = dbFetchRow(
        'SELECT port_id, ifIndex, ifName, ifDescr, ifAlias
         FROM ports
         WHERE device_id = ?
         AND (
             ifName = ?
             OR ifDescr = ?
             OR ifAlias = ?
             OR ifDescr LIKE ?
             OR ifAlias LIKE ?
         )
         LIMIT 1',
        [
            $device_id,
            $isis_interface,
            $isis_interface,
            $isis_interface,
            $isis_interface . '%',
            $isis_interface . '%',
        ]
    );

    return $port ?: null;
}

function resolve_existing_isis_port(int $device_id, string $index): ?array
{
    return dbFetchRow(
        'SELECT p.port_id, p.ifIndex, p.ifName, p.ifDescr, p.ifAlias
         FROM isis_adjacencies ia
         JOIN ports p ON p.port_id = ia.port_id
         WHERE ia.device_id = ?
         AND ia.`index` = ?
         LIMIT 1',
        [$device_id, $index]
    ) ?: null;
}

function build_isis_index(string $isis_interface, string $neighbor_system_id): string
{
    return substr(sha1($isis_interface . '::' . $neighbor_system_id), 0, 16);
}

$now = time();
$state_store = load_isis_state($state_file);

$commands = [
    'show isis neighbors',
    'show running config | grep isis',
    'show running config | grep fps',
];

$output = run_ciena_expect_session($host, $username, $password, $port, $timeout, $commands);

if ($output === '') {
    mark_all_isis_down($device_id, 'device unreachable or no SSH output', $state_store, $now);
    save_isis_state($state_file, $state_store);
    fwrite(STDERR, "No output returned from $host. Marked existing ISIS adjacencies down.\n");
    exit(1);
}

$neighbors = parse_isis_neighbors($output);
$configured_interfaces = parse_configured_isis_interfaces($output);
$configured_lookup = array_fill_keys($configured_interfaces, true);
$fp_to_port = parse_fp_to_logical_port($output);

if (empty($neighbors) && empty($configured_interfaces)) {
    mark_all_isis_down($device_id, 'ISIS data unavailable from device', $state_store, $now);
    save_isis_state($state_file, $state_store);
    fwrite(STDERR, "No ISIS neighbors or configured ISIS interfaces parsed from $host. Marked existing ISIS adjacencies down.\n");
    exit(1);
}

$seen_indexes = [];
$inserted = 0;
$updated = 0;
$marked_down = 0;
$deleted = 0;
$skipped = 0;
$used_existing_port = 0;

foreach ($neighbors as $neighbor) {
    $isis_interface = $neighbor['interface'];
    $neighbor_system_id = $neighbor['system_id'];
    $index = build_isis_index($isis_interface, $neighbor_system_id);

    $existing = dbFetchRow(
        'SELECT id, isisISAdjState, isisISAdjLastUpTime
         FROM isis_adjacencies
         WHERE device_id = ?
         AND `index` = ?
         LIMIT 1',
        [$device_id, $index]
    );

    $port = resolve_port_for_isis_interface($device_id, $isis_interface, $fp_to_port);

    if (!$port) {
        $port = resolve_existing_isis_port($device_id, $index);

        if ($port) {
            $used_existing_port++;
            echo "FALLBACK ISIS {$isis_interface} -> {$neighbor_system_id}: using existing LibreNMS port {$port['ifName']}\n";
        }
    }

    if (!$port) {
        echo "SKIP ISIS {$isis_interface} -> {$neighbor_system_id}: no LibreNMS port mapping found\n";
        $skipped++;
        continue;
    }

    $state = $neighbor['state'] === 'up' ? 'up' : 'down';
    $state_duration = calculate_state_duration($state_store, $device_id, $index, $state, $now);

    $data = [
        'device_id' => $device_id,
        'index' => $index,
        'port_id' => (int) $port['port_id'],
        'ifIndex' => (int) $port['ifIndex'],
        'isisCircAdminState' => 'on',
        'isisISAdjState' => $state,
        'isisISAdjNeighSysType' => $neighbor['level'],
        'isisISAdjNeighSysID' => $neighbor_system_id,
        'isisISAdjNeighPriority' => '',
        'isisISAdjLastUpTime' => $state_duration,
        'isisISAdjAreaAddress' => $isis_interface,
        'isisISAdjIPAddrType' => '',
        'isisISAdjIPAddrAddress' => '',
    ];

    if ($existing && isset($existing['id'])) {
        dbUpdate($data, 'isis_adjacencies', 'id = ?', [$existing['id']]);
        $updated++;
        echo "UPDATE ISIS {$isis_interface} -> {$neighbor_system_id}: state {$state}, port {$port['ifName']}, duration {$state_duration}s\n";
    } else {
        dbInsert($data, 'isis_adjacencies');
        $inserted++;
        echo "INSERT ISIS {$isis_interface} -> {$neighbor_system_id}: state {$state}, port {$port['ifName']}, duration {$state_duration}s\n";
    }

    $seen_indexes[] = $index;
}

$existing_rows = dbFetchRows(
    'SELECT id, `index`, isisISAdjState, isisISAdjLastUpTime, isisISAdjAreaAddress
     FROM isis_adjacencies
     WHERE device_id = ?',
    [$device_id]
);

foreach ($existing_rows as $row) {
    $index = (string) $row['index'];

    if (in_array($index, $seen_indexes, true)) {
        continue;
    }

    $isis_interface = $row['isisISAdjAreaAddress'] ?? '';

    if ($isis_interface !== '' && isset($configured_lookup[$isis_interface])) {
        $state_duration = calculate_state_duration($state_store, $device_id, $index, 'down', $now);

        dbUpdate(
            [
                'isisISAdjState' => 'down',
                'isisISAdjLastUpTime' => $state_duration,
            ],
            'isis_adjacencies',
            'id = ?',
            [$row['id']]
        );

        if ($row['isisISAdjState'] !== 'down') {
            $marked_down++;
            echo "DOWN ISIS {$isis_interface} / {$index}: interface still configured, neighbor not seen, duration {$state_duration}s\n";
        } else {
            echo "UPDATE DOWN ISIS {$isis_interface} / {$index}: duration {$state_duration}s\n";
        }
    } else {
        \Illuminate\Support\Facades\DB::delete(
            'DELETE FROM isis_adjacencies WHERE id = ?',
            [$row['id']]
        );

        unset($state_store[state_key($device_id, $index)]);

        $deleted++;
        echo "DELETE ISIS {$index}: interface no longer configured\n";
    }
}

save_isis_state($state_file, $state_store);

echo "DONE {$host}: inserted={$inserted}, updated={$updated}, down={$marked_down}, deleted={$deleted}, skipped={$skipped}, fallback={$used_existing_port}, seen=" . count($seen_indexes) . "\n";