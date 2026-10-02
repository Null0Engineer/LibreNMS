# Ciena Pseudowire Collector

Custom LibreNMS integration for Ciena SAOS pseudowires.

The collector connects to Ciena devices over SSH, parses pseudowire and L2VPN/FPS configuration, resolves each pseudowire to a LibreNMS port, resolves the peer device where possible, and writes the result into LibreNMS's native `pseudowires` table.

## Files

- `ciena-pseudowire-collector.php` — authoritative per-device collector copied from the live LibreNMS server
- `ciena-pseudowire-run-all.php` — runs the collector against every enabled `ciena-saos` device
- `ciena-pseudowire-collector.service` — systemd oneshot service
- `ciena-pseudowire-collector.timer` — systemd timer
- `ciena_lsr_ids.sql` — required custom helper-table schema
- `validation.md` — sanitized examples and validation SQL

## Production paths

```text
/opt/librenms/scripts/ciena-pseudowire-collector.php
/opt/librenms/ciena-pseudowire-run-all.php
/etc/systemd/system/ciena-pseudowire-collector.service
/etc/systemd/system/ciena-pseudowire-collector.timer
```

The collector reuses:

```text
/opt/librenms/config/ciena-xcvr.json
/opt/librenms/config/ciena-xcvr.env
```

The real SSH credentials must remain outside GitHub.

## Commands collected over SSH

```text
show pseudowires
show running config | grep l2v
show running config | grep fps
show running config | grep lsr
```

## Parsed fields

The live parser extracts:

- pseudowire ID
- pseudowire name
- peer IP
- operational state
- inbound label
- outbound label
- flags
- L2VPN service

Not every parsed field is persisted in LibreNMS's native pseudowire table.

## Mapping chain

The collector maps:

```text
pseudowire name
  -> L2VPN forwarding-domain
  -> FPS forwarding-domain
  -> logical-port
  -> LibreNMS ports.port_id
```

A pseudowire is skipped when it has no L2VPN service, no forwarding-domain mapping, no logical port, or no matching LibreNMS port.

## Local LSR-ID helper table

The collector parses the local LSR ID and upserts it into:

```text
ciena_lsr_ids
```

The helper table maps:

```text
device_id <-> lsr_id <-> hostname/sysName
```

This table is required for peer-device resolution when the pseudowire peer address is an LSR ID that does not match a normal LibreNMS interface address.

See `ciena_lsr_ids.sql`.

## Peer-device resolution

The collector first attempts to resolve the peer through:

```text
devices -> ports -> ipv4_addresses
```

If no match exists, it looks up the peer address in:

```text
ciena_lsr_ids.lsr_id
```

If neither lookup succeeds, `peer_device_id` is stored as `0`.

## LibreNMS pseudowire fields written

The collector writes:

- device_id
- port_id
- peer_device_id
- peer_ldp_id
- cpwVcID
- cpwOid
- pw_type = ethernet
- pw_psntype = mpls
- pw_local_mtu = 0
- pw_peer_mtu = 0
- pw_descr

The live pseudowire operational state is included in collector logging, but this implementation does not write it to a dedicated native pseudowire state field.

## Cleanup behavior

After a successful device collection, the collector deletes pseudowire rows for that device whose `cpwVcID` was not present in the current seen set.

## Run-all wrapper

The wrapper selects all enabled Ciena SAOS devices:

```sql
SELECT device_id, hostname
FROM devices
WHERE os = 'ciena-saos'
  AND disabled = 0
ORDER BY hostname;
```

It runs the per-device collector sequentially and continues after individual device failures.

## systemd service

```text
User=librenms
Group=librenms
WorkingDirectory=/opt/librenms
ExecStart=/usr/bin/php /opt/librenms/ciena-pseudowire-run-all.php
```

## systemd timer

```text
OnBootSec=3min
OnCalendar=*:3/5
```

The pseudowire collection is intentionally offset from the IS-IS timer.

## Dependencies

- LibreNMS PHP environment
- PHP CLI
- Expect
- OpenSSH client
- `timeout`
- working `ciena-xcvr.json`
- working local `ciena-xcvr.env`
- MariaDB access through LibreNMS
- required `ciena_lsr_ids` helper table
- SSH reachability from LibreNMS to the Ciena devices

## Security

Do not commit:

- production SSH credentials
- real management IP addresses
- real LSR IDs
- production hostnames/sysNames
- site/circuit/customer-identifying values

Use sanitized examples in documentation.

## Validation

See `validation.md` for:

- helper-table validation
- native pseudowire-table validation
- sanitized example data


## Production ownership and permissions

Current production permissions:

```text
/etc/systemd/system/ciena-pseudowire-collector.service
  root:root 0644

/etc/systemd/system/ciena-pseudowire-collector.timer
  root:root 0644

/opt/librenms/ciena-pseudowire-run-all.php
  librenms:librenms 0775

/opt/librenms/config/ciena-xcvr.env
  librenms:librenms 0600

/opt/librenms/config/ciena-xcvr.json
  librenms:librenms 0664

/opt/librenms/scripts/ciena-pseudowire-collector.php
  librenms:librenms 0775
```

Recommended rebuild commands:

```bash
sudo chown root:root /etc/systemd/system/ciena-pseudowire-collector.service
sudo chown root:root /etc/systemd/system/ciena-pseudowire-collector.timer
sudo chmod 644 /etc/systemd/system/ciena-pseudowire-collector.service
sudo chmod 644 /etc/systemd/system/ciena-pseudowire-collector.timer

sudo chown librenms:librenms /opt/librenms/ciena-pseudowire-run-all.php
sudo chown librenms:librenms /opt/librenms/config/ciena-xcvr.env
sudo chown librenms:librenms /opt/librenms/config/ciena-xcvr.json
sudo chown librenms:librenms /opt/librenms/scripts/ciena-pseudowire-collector.php

sudo chmod 775 /opt/librenms/ciena-pseudowire-run-all.php
sudo chmod 600 /opt/librenms/config/ciena-xcvr.env
sudo chmod 664 /opt/librenms/config/ciena-xcvr.json
sudo chmod 775 /opt/librenms/scripts/ciena-pseudowire-collector.php
```

## Known-good validation

Run manually as the LibreNMS account:

```bash
sudo -u librenms php /opt/librenms/ciena-pseudowire-run-all.php
```

Healthy devices should finish with output similar to:

```text
DONE <device>: inserted=<n>, updated=<n>, skipped=<n>, seen=<n>
```

The collector can legitimately emit `SKIP` messages for pseudowires that have no L2VPN service or cannot currently be mapped to a forwarding-domain/logical-port.

Then validate systemd:

```bash
systemctl status ciena-pseudowire-collector.timer --no-pager
systemctl status ciena-pseudowire-collector.service --no-pager
journalctl -u ciena-pseudowire-collector.service -n 100 --no-pager
```

Finally validate:

- `ciena_lsr_ids`
- LibreNMS native `pseudowires`

using the queries in `validation.md`.

## Devices without pseudowires

The run-all wrapper executes against every enabled `ciena-saos` device.

A Ciena device with no parsed pseudowires currently causes the per-device collector to exit non-zero with:

```text
No pseudowires parsed from <device>
```

The wrapper logs the failure and continues to the next device.

This is preserved production behavior.

## Transient mapping behavior

Operational logs show that a device can occasionally return pseudowire rows while one or more supporting configuration commands fail to provide the expected LSR-ID or forwarding-domain mappings during that same collection cycle.

Typical symptoms include:

```text
LOCAL LSR-ID not found
SKIP PW ... no forwarding-domain mapping found
```

A later collection may succeed without any configuration change.

This matters because the collector depends on multiple CLI outputs from the same SSH session:

```text
show pseudowires
show running config | grep l2v
show running config | grep fps
show running config | grep lsr
```

When troubleshooting, compare multiple consecutive collector runs before treating a single mapping failure as authoritative.

## Native LibreNMS pseudowire schema

The production LibreNMS `pseudowires` table contains:

```text
pseudowire_id
device_id
port_id
peer_device_id
peer_ldp_id
cpwVcID
cpwOid
pw_type
pw_psntype
pw_local_mtu
pw_peer_mtu
pw_descr
```

There is no dedicated operational-state column in this schema, which is why the collector logs the live UP/DOWN state but does not persist it into the native pseudowire row.


## Custom database schema

Unlike the XCVR and IS-IS collectors, the pseudowire integration requires one custom helper table:

```text
ciena_lsr_ids
```

Authoritative schema:

```sql
CREATE TABLE `ciena_lsr_ids` (
  `device_id` int(10) unsigned NOT NULL,
  `lsr_id` varchar(46) NOT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `sysName` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `lsr_id` (`lsr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Create it from `ciena_lsr_ids.sql` during a rebuild.

The LibreNMS `pseudowires` table itself is native and should **not** be manually recreated from this repository.

## Rebuild checklist

1. Create the custom helper table using `ciena_lsr_ids.sql`.
2. Deploy:

```text
/opt/librenms/scripts/ciena-pseudowire-collector.php
/opt/librenms/ciena-pseudowire-run-all.php
```

3. Deploy/reuse the shared Ciena config and local credential file:

```text
/opt/librenms/config/ciena-xcvr.json
/opt/librenms/config/ciena-xcvr.env
```

4. Apply the documented ownership and modes.
5. Copy the systemd service/timer into `/etc/systemd/system/`.
6. Reload systemd and enable the timer:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ciena-pseudowire-collector.timer
```

7. Run manually:

```bash
sudo -u librenms php /opt/librenms/ciena-pseudowire-run-all.php
```

8. Validate both `ciena_lsr_ids` and native `pseudowires` rows using `validation.md`.
9. Review at least a few consecutive collector cycles before diagnosing a one-off forwarding-domain/LSR mapping miss as a configuration failure.

## Shared Ciena dependency

This project shares `ciena-xcvr.json` and `ciena-xcvr.env` with the XCVR and IS-IS collectors.

The pseudowire timer is deliberately offset from IS-IS:

```text
IS-IS:      *:1/5
Pseudowire: *:3/5
```

Both collectors process devices sequentially, so the effective interval can exceed five minutes if a full run takes longer than the nominal timer cadence.
