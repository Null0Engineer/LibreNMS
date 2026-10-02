# Ciena IS-IS Collector

A LibreNMS integration for collecting IS-IS adjacency information from Ciena SAOS devices over SSH.

This is useful when the IS-IS information needed for monitoring is available through the Ciena CLI but is not exposed in a way LibreNMS can use through its normal SNMP discovery path.

The collector parses Ciena CLI output, maps adjacencies to LibreNMS ports, and stores the results in LibreNMS's native `isis_adjacencies` table.

## Files

- `ciena-isis-collector.php` — per-device collector
- `ciena-isis-run-all.php` — runs the collector against enabled Ciena SAOS devices
- `ciena-isis-collector.service` — systemd oneshot service
- `ciena-isis-collector.timer` — systemd timer
- `ciena-isis-state.example.json` — example persistent adjacency state file
- `ciena-xcvr.json` — shared Ciena SSH/CLI parsing configuration
- `ciena-xcvr.env.example` — example credential environment file
- `validation.sql` — SQL validation query
- `validation.md` — validation notes and examples

## Suggested paths

```text
/opt/librenms/scripts/ciena-isis-collector.php
/opt/librenms/ciena-isis-run-all.php
/opt/librenms/storage/app/ciena-isis-state.json
/opt/librenms/config/ciena-xcvr.json
/opt/librenms/config/ciena-xcvr.env
/etc/systemd/system/ciena-isis-collector.service
/etc/systemd/system/ciena-isis-collector.timer
```

## Credentials

The collector expects these environment variables:

```text
CIENA_XCVR_SSH_USER
CIENA_XCVR_SSH_PASS
```

Use `ciena-xcvr.env.example` as a template and keep the real credential file outside version control.

## Running the collector

Run a single device by LibreNMS device ID:

```bash
php /opt/librenms/scripts/ciena-isis-collector.php <device_id>
```

Run all enabled Ciena SAOS devices:

```bash
sudo -u librenms php /opt/librenms/ciena-isis-run-all.php
```

## CLI commands collected

The collector runs:

```text
show isis neighbors
show running config | grep isis
show running config | grep fps
```

SSH commands are executed through a temporary Expect script.

## Data collected

The parser extracts information such as:

- neighbor type
- neighbor system ID
- IS-IS interface
- SNPA
- adjacency state
- hold time
- IS-IS level
- protocol

Only `up` is stored as an up adjacency state. Other parsed states are treated as down.

## Stable adjacency ID

Each adjacency receives a stable index generated from:

```text
SHA1(<isis_interface>::<neighbor_system_id>)
```

The first 16 characters are used.

This lets the same adjacency keep a consistent identity across collector runs.

## Port mapping

Ciena IS-IS interface names do not always directly match LibreNMS interface names.

The collector uses FPS configuration to map:

```text
FP / FD name -> logical-port
```

It then tries to resolve the logical port against LibreNMS `ports` using:

- `ifName`
- `ifDescr`
- `ifAlias`

If that fails, it tries the IS-IS interface name directly.

If a previously known adjacency already has a valid LibreNMS port mapping, that mapping may be reused as a fallback.

Adjacencies that cannot be mapped safely are skipped.

## LibreNMS table

Adjacencies are stored in:

```text
isis_adjacencies
```

Fields used include:

- `device_id`
- `index`
- `port_id`
- `ifIndex`
- `isisCircAdminState`
- `isisISAdjState`
- `isisISAdjNeighSysType`
- `isisISAdjNeighSysID`
- `isisISAdjLastUpTime`
- `isisISAdjAreaAddress`

## State tracking

Adjacency state history is kept in:

```text
/opt/librenms/storage/app/ciena-isis-state.json
```

Each entry is keyed as:

```text
<device_id>:<adjacency_index>
```

Example:

```json
{
  "state": "up",
  "last_change": 1700000000
}
```

When the state changes, `last_change` is reset. While the state remains unchanged, the collector calculates the elapsed time since that transition.

## Failure behavior

The collector is intentionally conservative:

- if SSH fails, existing adjacencies are marked down rather than deleted
- if no usable IS-IS data can be collected, existing adjacencies are marked down
- if a configured interface remains but a neighbor disappears, the adjacency remains and is marked down
- if the IS-IS interface itself is removed from configuration, the adjacency row and saved state are removed

This avoids destroying topology data because of a temporary CLI or connectivity problem.

## Run-all behavior

The wrapper selects enabled Ciena SAOS devices:

```sql
SELECT device_id, hostname
FROM devices
WHERE os = 'ciena-saos'
  AND disabled = 0
ORDER BY hostname;
```

Devices are processed sequentially.

## systemd

The included service runs as the `librenms` user and executes:

```text
/usr/bin/php /opt/librenms/ciena-isis-run-all.php
```

The included timer uses:

```text
OnBootSec=1min
OnCalendar=*:1/5
```

Enable it with:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ciena-isis-collector.timer
```

Check status with:

```bash
systemctl status ciena-isis-collector.timer
systemctl status ciena-isis-collector.service
journalctl -u ciena-isis-collector.service
```

Because the wrapper processes devices sequentially, a full collection may take longer than the nominal five-minute timer interval. systemd will not run a second instance of the same oneshot service while the current run is still active.

## Permissions

A typical setup is:

```text
/etc/systemd/system/ciena-isis-collector.service   root:root       0644
/etc/systemd/system/ciena-isis-collector.timer     root:root       0644
/opt/librenms/ciena-isis-run-all.php                librenms:librenms 0664
/opt/librenms/config/ciena-xcvr.env                 librenms:librenms 0600
/opt/librenms/config/ciena-xcvr.json                librenms:librenms 0664
/opt/librenms/scripts/ciena-isis-collector.php      librenms:librenms 0664
/opt/librenms/storage/app/ciena-isis-state.json     librenms:librenms 0644
```

## Dependencies

- LibreNMS PHP environment
- PHP CLI
- Expect
- OpenSSH client
- `timeout`
- SSH reachability to the Ciena devices
- database access through LibreNMS
- `ciena-xcvr.json`
- local `ciena-xcvr.env`

## Validation

Run:

```bash
sudo -u librenms php /opt/librenms/ciena-isis-run-all.php
```

A successful device collection should end with output similar to:

```text
DONE <device>: inserted=0, updated=<n>, down=0, deleted=0, skipped=0, fallback=0, seen=<n>
```

Then use `validation.sql` or the queries in `validation.md` to confirm the expected rows exist in `isis_adjacencies`.

## Devices without IS-IS

The run-all wrapper currently attempts collection on every enabled `ciena-saos` device.

A device with no configured IS-IS neighbors or interfaces may return a non-zero result such as:

```text
No ISIS neighbors or configured ISIS interfaces parsed ...
```

The wrapper logs the result and continues to the next device.

## Shared Ciena configuration

This collector uses the same `ciena-xcvr.json` and `ciena-xcvr.env` configuration used by the related Ciena collectors in this repository.
