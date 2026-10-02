<?php

$init_modules = [];
require_once '/opt/librenms/includes/init.php';

$config_file = '/opt/librenms/config/ciena-xcvr.json';
$env_file = '/opt/librenms/config/ciena-xcvr.env';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php ciena-pseudowire-collector.php <device_id>\n");
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
    $expect_file = tempnam('/tmp', 'ciena-pw-expect-');

    if ($expect_file === false) {
        return '';
    }

    $expect = "#!/usr/bin/expect -f\n";
    $expect .= "set timeout " . (int) $timeout . "\n";
    $expect .= "log_user 1\n";
    $expect .= "spawn ssh -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=" . (int) $timeout . " -p " . (int) $port . " " . expect_escape($username) . "@" . expect_escape($host) . "\n";
    $expect .= "expect {\n";
    $expect .= "    -re \"(?i)password:\" { send \"" . expect_escape($password) . "\\r\" }\n";
    $expect .= "    -re \">\" { }\n";
    $expect .= "    timeout { exit 10 }\n";
    $expect .= "    eof { exit 11 }\n";
    $expect .= "}\n";
    $expect .= "expect {\n";
    $expect .= "    -re \">\" { }\n";
    $expect .= "    timeout { }\n";
    $expect .= "}\n";

    foreach ($commands as $command) {
        $expect .= "send \"" . expect_escape($command) . "\\r\"\n";
        $expect .= "expect {\n";
        $expect .= "    -re \">\" { }\n";
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

function parse_pseudowires(string $output): array
{
    $records = [];

    foreach (explode("\n", $output) as $line) {
        $line = strip_terminal_junk($line);

        if (!preg_match('/^\|\s*(\d+)\s+\|\s+(.+?)\s+\|\s+([0-9.]+)\s+\|\s+(UP|DOWN)\s+\|\s+([0-9-]+)\s+\|\s+([0-9-]+)\s+\|\s+(\S+)\s+\|\s+(.+?)\s+\|$/i', $line, $m)) {
            continue;
        }

        $records[] = [
            'pw_id' => (int) $m[1],
            'name' => trim($m[2]),
            'peer_ip' => trim($m[3]),
            'oper_state' => strtoupper(trim($m[4])),
            'in_label' => trim($m[5]),
            'out_label' => trim($m[6]),
            'flags' => trim($m[7]),
            'l2vpn_service' => trim($m[8]),
        ];
    }

    return $records;
}

function parse_l2vpn_to_fd(string $output): array
{
    $map = [];

    foreach (explode("\n", $output) as $line) {
        $line = strip_terminal_junk($line);

        if (preg_match('/^l2vpn-services\s+l2vpn\s+[\'"]([^\'"]+)[\'"]\s+forwarding-domain\s+[\'"]([^\'"]+)[\'"]\s+pseudowire\s+[\'"]([^\'"]+)[\'"]/', $line, $m)) {
            $map[trim($m[3])] = [
                'service' => trim($m[1]),
                'fd' => trim($m[2]),
            ];
        }
    }

    return $map;
}

function parse_fd_to_logical_port(string $output): array
{
    $map = [];

    foreach (explode("\n", $output) as $line) {
        $line = strip_terminal_junk($line);

        if (preg_match('/^fps\s+fp\s+[\'"]([^\'"]+)[\'"]\s+fd-name\s+[\'"]([^\'"]+)[\'"]\s+logical-port\s+[\'"]([^\'"]+)[\'"]/', $line, $m)) {
            $fd = trim($m[2]);

            if (!isset($map[$fd])) {
                $map[$fd] = trim($m[3]);
            }
        }
    }

    return $map;
}

function parse_lsr_id(string $output): ?string
{
    foreach (explode("\n", $output) as $line) {
        $line = strip_terminal_junk($line);

        if (preg_match('/ldp\s+instance\s+[\'"]([^\'"]+)[\'"]\s+lsr-id\s+[\'"]([^\'"]+)[\'"]/', $line, $m)) {
            return trim($m[2]);
        }

        if (preg_match('/oc-if:interfaces\s+interface\s+[\'"]loopback1[\'"]\s+ipv4\s+addresses\s+address\s+[\'"]([0-9.]+)[\'"]/', $line, $m)) {
            return trim($m[1]);
        }
    }

    return null;
}

$commands = [
    'show pseudowires',
    'show running config | grep l2v',
    'show running config | grep fps',
    'show running config | grep lsr',
];

$output = run_ciena_expect_session($host, $username, $password, $port, $timeout, $commands);

if ($output === '') {
    fwrite(STDERR, "No output returned from $host\n");
    exit(1);
}

$pseudowires = parse_pseudowires($output);
$pw_to_fd = parse_l2vpn_to_fd($output);
$fd_to_port = parse_fd_to_logical_port($output);
$local_lsr_id = parse_lsr_id($output);

if ($local_lsr_id) {
    \Illuminate\Support\Facades\DB::statement(
        'INSERT INTO ciena_lsr_ids (device_id, hostname, sysName, lsr_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             hostname = VALUES(hostname),
             sysName = VALUES(sysName),
             lsr_id = VALUES(lsr_id),
             updated_at = CURRENT_TIMESTAMP',
        [
            $device_id,
            $device['hostname'],
            $device['sysName'] ?? null,
            $local_lsr_id,
        ]
    );

    echo "LOCAL LSR-ID {$local_lsr_id}\n";
} else {
    echo "LOCAL LSR-ID not found for {$host}\n";
}

if (empty($pseudowires)) {
    fwrite(STDERR, "No pseudowires parsed from $host\n");
    exit(1);
}

$seen = [];
$inserted = 0;
$updated = 0;
$skipped = 0;

foreach ($pseudowires as $pw) {
    $pw_id = (int) $pw['pw_id'];
    $pw_name = $pw['name'];

    if (($pw['l2vpn_service'] ?? '') === 'None') {
        echo "SKIP PW {$pw_id} {$pw_name}: L2VPN Service is None\n";
        $skipped++;
        continue;
    }

    $fd = $pw_to_fd[$pw_name]['fd'] ?? null;

    if (!$fd) {
        echo "SKIP PW {$pw_id} {$pw_name}: no forwarding-domain mapping found\n";
        $skipped++;
        continue;
    }

    $logical_port = $fd_to_port[$fd] ?? null;

    if (!$logical_port) {
        echo "SKIP PW {$pw_id} {$pw_name}: no logical-port found for FD {$fd}\n";
        $skipped++;
        continue;
    }

    $port_id = dbFetchCell(
        'SELECT port_id FROM ports WHERE device_id = ? AND (ifName = ? OR ifDescr = ?) LIMIT 1',
        [$device_id, $logical_port, $logical_port]
    );

    if (!$port_id) {
        echo "SKIP PW {$pw_id} {$pw_name}: no LibreNMS port_id for logical-port {$logical_port}\n";
        $skipped++;
        continue;
    }

    $peer_ip_long = ip2long($pw['peer_ip']);
    $peer_ldp_id = $peer_ip_long === false ? 0 : sprintf('%u', $peer_ip_long);

    $peer_device_id = dbFetchCell(
        'SELECT D.device_id
         FROM devices D
         JOIN ports P ON D.device_id = P.device_id
         JOIN ipv4_addresses A ON P.port_id = A.port_id
         WHERE A.ipv4_address = ?
         LIMIT 1',
        [$pw['peer_ip']]
    );

    if (!$peer_device_id) {
        $peer_device_id = dbFetchCell(
            'SELECT device_id FROM ciena_lsr_ids WHERE lsr_id = ? LIMIT 1',
            [$pw['peer_ip']]
        );
    }

    $peer_device_id = $peer_device_id ?: 0;

    $existing_id = dbFetchCell(
        'SELECT pseudowire_id FROM pseudowires WHERE device_id = ? AND cpwVcID = ? LIMIT 1',
        [$device_id, $pw_id]
    );

    $data = [
        'device_id' => $device_id,
        'port_id' => (int) $port_id,
        'peer_device_id' => (int) $peer_device_id,
        'peer_ldp_id' => (int) $peer_ldp_id,
        'cpwVcID' => $pw_id,
        'cpwOid' => $pw_id,
        'pw_type' => 'ethernet',
        'pw_psntype' => 'mpls',
        'pw_local_mtu' => 0,
        'pw_peer_mtu' => 0,
        'pw_descr' => $pw_name,
    ];

    if ($existing_id) {
        dbUpdate($data, 'pseudowires', 'pseudowire_id = ?', [$existing_id]);
        $updated++;
        echo "UPDATE PW {$pw_id} {$pw_name}: port {$logical_port}, FD {$fd}, peer {$pw['peer_ip']}, peer_device_id {$peer_device_id}, state {$pw['oper_state']}\n";
    } else {
        dbInsert($data, 'pseudowires');
        $inserted++;
        echo "INSERT PW {$pw_id} {$pw_name}: port {$logical_port}, FD {$fd}, peer {$pw['peer_ip']}, peer_device_id {$peer_device_id}, state {$pw['oper_state']}\n";
    }

    $seen[] = $pw_id;
}

if (!empty($seen)) {
    $placeholders = implode(',', array_fill(0, count($seen), '?'));

    \Illuminate\Support\Facades\DB::delete(
        "DELETE FROM pseudowires WHERE device_id = ? AND cpwVcID NOT IN ($placeholders)",
        array_merge([$device_id], $seen)
    );
}

echo "DONE {$host}: inserted={$inserted}, updated={$updated}, skipped={$skipped}, seen=" . count($seen) . "\n";