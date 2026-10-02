# Ciena IS-IS Collector

Custom LibreNMS integration for Ciena SAOS devices where usable IS-IS adjacency data is not available through the standard SNMP path.

The collector connects to each Ciena device over SSH, parses live IS-IS/forwarding configuration, maps adjacencies back to LibreNMS ports, and writes the results into LibreNMS's native `isis_adjacencies` table so the data can be displayed and alerted on like normal LibreNMS IS-IS information.

## Files

- `ciena-isis-collector.php` — authoritative per-device collector copied from the live LibreNMS server
- `ciena-isis-run-all.php` — runs the collector against every enabled `ciena-saos` device
- `ciena-isis-state.example.json` — example persistent adjacency state store
- `ciena-xcvr.json` — production transport/parsing configuration used by the collector (no secrets)
- `ciena-xcvr.env.example` — redacted SSH credential variable example
- `validation.sql` — SQL validation query for collected adjacencies
- `validation.md` — sanitized example output and validation guidance

## Production paths

Collector:

```text
/opt/librenms/scripts/ciena-isis-collector.php
```

Run-all wrapper:

```text
/opt/librenms/ciena-isis-run-all.php
```

Persistent state:

```text
/opt/librenms/storage/app/ciena-isis-state.json
```

Configuration:

```text
/opt/librenms/config/ciena-xcvr.json
```

Credential environment file:

```text
/opt/librenms/config/ciena-xcvr.env
```

The production config references these environment variables:

```text
CIENA_XCVR_SSH_USER
CIENA_XCVR_SSH_PASS
```

The real credential environment file must not be committed to GitHub.

## Collector input

The collector is called with a LibreNMS device ID:

```bash
php /opt/librenms/scripts/ciena-isis-collector.php <device_id>
```

It loads LibreNMS directly through:

```php
require_once '/opt/librenms/includes/init.php';
```

and verifies that the target device OS matches the configured Ciena SAOS OS.

## Commands collected over SSH

The collector runs:

```text
show isis neighbors
show running config | grep isis
show running config | grep fps
```

SSH is executed through a temporary Expect script.

## Neighbor parsing

The collector extracts:

- neighbor type
- neighbor system ID
- IS-IS interface
- SNPA
- adjacency state
- hold time
- IS-IS level
- protocol

Neighbor states are normalized so only `up` remains up; other parsed states are stored as down.

## Stable adjacency index

Each adjacency receives a stable index generated from:

```text
SHA1(<isis_interface>::<neighbor_system_id>)
```

The first 16 characters are used.

This allows the same adjacency to retain its identity across collector runs.

## Port mapping

Ciena IS-IS interfaces do not always directly match the LibreNMS interface name.

The collector parses FPS configuration to map:

```text
FP / FD name -> logical-port
```

It then attempts to resolve the logical port against LibreNMS `ports` using:

- ifName
- ifDescr
- ifAlias

If that fails, it attempts to match the IS-IS interface directly.

If a known adjacency previously had a working LibreNMS port mapping, the collector can reuse that existing mapping as a fallback.

If no port can be resolved, the adjacency is skipped rather than writing a bad mapping.

## LibreNMS table

Adjacencies are written to:

```text
isis_adjacencies
```

Important fields include:

- device_id
- index
- port_id
- ifIndex
- isisCircAdminState
- isisISAdjState
- isisISAdjNeighSysType
- isisISAdjNeighSysID
- isisISAdjLastUpTime
- isisISAdjAreaAddress

## State duration tracking

LibreNMS's `isisISAdjLastUpTime` is populated using a persistent local state file:

```text
/opt/librenms/storage/app/ciena-isis-state.json
```

Each key is:

```text
<device_id>:<adjacency_index>
```

and stores:

```json
{
  "state": "up",
  "last_change": 1700000000
}
```

When the state changes, `last_change` is reset. While the state remains unchanged, the collector calculates the number of seconds since that transition.

## Failure handling

The collector is intentionally conservative.

### Device unreachable / no SSH output

Existing IS-IS adjacencies for that device are marked down instead of being deleted.

### No neighbors and no configured IS-IS interfaces parsed

Existing adjacencies are marked down because valid IS-IS data could not be obtained.

### Configured interface remains, neighbor disappears

The adjacency remains in LibreNMS and is marked down.

### IS-IS interface removed from configuration

The adjacency row is deleted and its saved state entry is removed.

This distinction prevents temporary SSH or neighbor failures from erasing the topology.

## Run-all wrapper

`ciena-isis-run-all.php` selects:

```sql
SELECT device_id, hostname
FROM devices
WHERE os = 'ciena-saos'
  AND disabled = 0
ORDER BY hostname;
```

It then runs the collector once per device and reports any non-zero collector exit codes.

## Dependencies

The collector requires:

- LibreNMS PHP environment
- PHP CLI
- Expect
- OpenSSH client
- `timeout`
- database access through LibreNMS
- SSH reachability from the LibreNMS server to the Ciena devices
- a valid `ciena-xcvr.json`
- a valid local `ciena-xcvr.env` containing `CIENA_XCVR_SSH_USER` and `CIENA_XCVR_SSH_PASS`

## Security

Do not store production SSH usernames/passwords in this GitHub repository.

Only the redacted example environment file belongs here.

## Reproducibility status

The project includes the authoritative live collector, run-all wrapper, systemd service/timer, shared transport configuration, redacted credential schema, persistent state-file format, validation SQL, production ownership/permissions, and known runtime behavior.

The only intentionally omitted production data is the real SSH credential file and environment-specific device values.


## systemd service

Production unit:

```text
/etc/systemd/system/ciena-isis-collector.service
```

The service runs as:

```text
User=librenms
Group=librenms
WorkingDirectory=/opt/librenms
ExecStart=/usr/bin/php /opt/librenms/ciena-isis-run-all.php
```

It is a `Type=oneshot` service and waits for network-online plus MariaDB ordering.

## systemd timer

Production unit:

```text
/etc/systemd/system/ciena-isis-collector.timer
```

Schedule:

```text
OnBootSec=1min
OnCalendar=*:1/5
```

That starts the first run about one minute after boot and then schedules the collector every five minutes.

Enable/start with:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ciena-isis-collector.timer
```

Validation:

```bash
systemctl status ciena-isis-collector.timer
systemctl list-timers | grep ciena-isis
journalctl -u ciena-isis-collector.service
```


## Production ownership and permissions

Current production permissions:

```text
/etc/systemd/system/ciena-isis-collector.service
  root:root 0644

/etc/systemd/system/ciena-isis-collector.timer
  root:root 0644

/opt/librenms/ciena-isis-run-all.php
  librenms:librenms 0664

/opt/librenms/config/ciena-xcvr.env
  librenms:librenms 0600

/opt/librenms/config/ciena-xcvr.json
  librenms:librenms 0664

/opt/librenms/scripts/ciena-isis-collector.php
  librenms:librenms 0664

/opt/librenms/storage/app/ciena-isis-state.json
  librenms:librenms 0644
```

Recommended rebuild commands:

```bash
sudo chown root:root /etc/systemd/system/ciena-isis-collector.service
sudo chown root:root /etc/systemd/system/ciena-isis-collector.timer
sudo chmod 644 /etc/systemd/system/ciena-isis-collector.service
sudo chmod 644 /etc/systemd/system/ciena-isis-collector.timer

sudo chown librenms:librenms /opt/librenms/ciena-isis-run-all.php
sudo chown librenms:librenms /opt/librenms/config/ciena-xcvr.env
sudo chown librenms:librenms /opt/librenms/config/ciena-xcvr.json
sudo chown librenms:librenms /opt/librenms/scripts/ciena-isis-collector.php
sudo chown librenms:librenms /opt/librenms/storage/app/ciena-isis-state.json

sudo chmod 664 /opt/librenms/ciena-isis-run-all.php
sudo chmod 600 /opt/librenms/config/ciena-xcvr.env
sudo chmod 664 /opt/librenms/config/ciena-xcvr.json
sudo chmod 664 /opt/librenms/scripts/ciena-isis-collector.php
sudo chmod 644 /opt/librenms/storage/app/ciena-isis-state.json
```

## Known-good validation

Run the collector manually as the LibreNMS service account:

```bash
sudo -u librenms php /opt/librenms/ciena-isis-run-all.php
```

A healthy device should finish with output similar to:

```text
DONE <device>: inserted=0, updated=<n>, down=0, deleted=0, skipped=0, fallback=0, seen=<n>
```

Then validate systemd:

```bash
systemctl status ciena-isis-collector.timer --no-pager
systemctl status ciena-isis-collector.service --no-pager
journalctl -u ciena-isis-collector.service
```

Finally, run the SQL in `validation.sql` and confirm the expected Ciena adjacencies are present.

## Important timer behavior

The production timer is configured for every five minutes:

```text
OnCalendar=*:1/5
```

However, the full run-all job may take longer than five minutes when several Ciena devices are queried sequentially over SSH.

Because `ciena-isis-collector.service` is a single systemd oneshot unit, systemd does not start a second concurrent instance of that same service while the current run is still active. A long collection cycle can therefore extend beyond the nominal five-minute schedule.

This is not necessarily a failure, but it is important when interpreting:

```bash
systemctl status ciena-isis-collector.timer
```

A timer may show no immediate next trigger while the associated service is still active.

## Devices without configured IS-IS

The run-all wrapper selects every enabled device where:

```text
os = ciena-saos
```

A Ciena device with no configured IS-IS neighbors/interfaces currently causes the per-device collector to exit non-zero with:

```text
No ISIS neighbors or configured ISIS interfaces parsed ...
```

The wrapper reports that device as a collector failure, then continues to the next device.

This behavior is preserved intentionally in the stored production code. If non-IS-IS Ciena devices become common, consider either excluding them from the run-all selection or changing the collector's no-IS-IS condition to a clean skip rather than an error.


## Rebuild checklist

1. Deploy the collector to:

```text
/opt/librenms/scripts/ciena-isis-collector.php
```

2. Deploy the wrapper to:

```text
/opt/librenms/ciena-isis-run-all.php
```

3. Deploy/reuse the shared Ciena config and create the local credential file:

```text
/opt/librenms/config/ciena-xcvr.json
/opt/librenms/config/ciena-xcvr.env
```

4. Create the state file if needed:

```bash
sudo -u librenms touch /opt/librenms/storage/app/ciena-isis-state.json
```

5. Apply the ownership and modes documented above.
6. Copy the service and timer into `/etc/systemd/system/`.
7. Reload systemd and enable the timer:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ciena-isis-collector.timer
```

8. Run the wrapper manually as `librenms`.
9. Run `validation.sql` or the query in `validation.md`.
10. Confirm the timer/service logs are clean enough for the expected device population.

## Shared Ciena dependency

This project shares `ciena-xcvr.json` and `ciena-xcvr.env` with the XCVR and pseudowire collectors. A rebuild should use one consistent shared configuration rather than maintaining separate copies on the production server.
