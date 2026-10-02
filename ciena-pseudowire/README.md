# Ciena Pseudowire Collector

A LibreNMS integration for collecting Ciena SAOS pseudowire information over SSH.

The collector parses pseudowire, L2VPN, FPS, and LSR configuration from the Ciena CLI, maps each pseudowire to a LibreNMS port, resolves the peer device when possible, and stores the result in LibreNMS's native `pseudowires` table.

## Files

- `ciena-pseudowire-collector.php` — per-device collector
- `ciena-pseudowire-run-all.php` — runs the collector against enabled Ciena SAOS devices
- `ciena-pseudowire-collector.service` — systemd oneshot service
- `ciena-pseudowire-collector.timer` — systemd timer
- `ciena_lsr_ids.sql` — schema for the helper table used for peer resolution
- `validation.md` — validation examples and queries

## Suggested paths

```text
/opt/librenms/scripts/ciena-pseudowire-collector.php
/opt/librenms/ciena-pseudowire-run-all.php
/etc/systemd/system/ciena-pseudowire-collector.service
/etc/systemd/system/ciena-pseudowire-collector.timer
```

The collector also uses:

```text
/opt/librenms/config/ciena-xcvr.json
/opt/librenms/config/ciena-xcvr.env
```

Keep real SSH credentials outside version control.

## CLI commands collected

The collector runs:

```text
show pseudowires
show running config | grep l2v
show running config | grep fps
show running config | grep lsr
```

## Data collected

The parser extracts:

- pseudowire ID
- pseudowire name
- peer IP
- operational state
- inbound label
- outbound label
- flags
- L2VPN service

Not every parsed field maps directly to a LibreNMS pseudowire column.

## Mapping

The collector resolves each pseudowire through:

```text
pseudowire name
  -> L2VPN forwarding-domain
  -> FPS forwarding-domain
  -> logical-port
  -> LibreNMS ports.port_id
```

A pseudowire is skipped if the required L2VPN, forwarding-domain, logical-port, or LibreNMS port mapping cannot be resolved.

## LSR-ID helper table

Ciena pseudowire peers may use an LSR ID that does not match a normal LibreNMS interface address.

The collector stores local LSR IDs in:

```text
ciena_lsr_ids
```

The table maps:

```text
device_id <-> lsr_id <-> hostname/sysName
```

Create it using `ciena_lsr_ids.sql`.

## Peer resolution

The collector first attempts to resolve a peer through LibreNMS:

```text
devices -> ports -> ipv4_addresses
```

If that does not match, it checks:

```text
ciena_lsr_ids.lsr_id
```

If neither method finds the peer, `peer_device_id` is stored as `0`.

## LibreNMS fields written

The collector writes:

- `device_id`
- `port_id`
- `peer_device_id`
- `peer_ldp_id`
- `cpwVcID`
- `cpwOid`
- `pw_type = ethernet`
- `pw_psntype = mpls`
- `pw_local_mtu = 0`
- `pw_peer_mtu = 0`
- `pw_descr`

The CLI operational state is logged by the collector, but the LibreNMS pseudowire schema used here does not contain a dedicated operational-state field.

## Cleanup behavior

After a successful device collection, pseudowire rows for that device that were not present in the latest collection are removed from the LibreNMS `pseudowires` table.

## Run-all behavior

The wrapper selects enabled Ciena SAOS devices:

```sql
SELECT device_id, hostname
FROM devices
WHERE os = 'ciena-saos'
  AND disabled = 0
ORDER BY hostname;
```

Devices are processed sequentially, and the wrapper continues after an individual device failure.

## systemd

The included service runs as `librenms` and executes:

```text
/usr/bin/php /opt/librenms/ciena-pseudowire-run-all.php
```

The included timer uses:

```text
OnBootSec=3min
OnCalendar=*:3/5
```

The timer is offset from the IS-IS collector so the two jobs do not normally begin at the same time.

Enable it with:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ciena-pseudowire-collector.timer
```

Check status with:

```bash
systemctl status ciena-pseudowire-collector.timer
systemctl status ciena-pseudowire-collector.service
journalctl -u ciena-pseudowire-collector.service -n 100 --no-pager
```

## Permissions

A typical setup is:

```text
/etc/systemd/system/ciena-pseudowire-collector.service   root:root          0644
/etc/systemd/system/ciena-pseudowire-collector.timer     root:root          0644
/opt/librenms/ciena-pseudowire-run-all.php               librenms:librenms  0775
/opt/librenms/config/ciena-xcvr.env                      librenms:librenms  0600
/opt/librenms/config/ciena-xcvr.json                     librenms:librenms  0664
/opt/librenms/scripts/ciena-pseudowire-collector.php     librenms:librenms  0775
```

## Dependencies

- LibreNMS PHP environment
- PHP CLI
- Expect
- OpenSSH client
- `timeout`
- working `ciena-xcvr.json`
- local `ciena-xcvr.env`
- MariaDB access through LibreNMS
- `ciena_lsr_ids` helper table
- SSH reachability to the Ciena devices

## Custom database table

The pseudowire collector requires one helper table:

```text
ciena_lsr_ids
```

Schema:

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

The LibreNMS `pseudowires` table itself is native to LibreNMS and should not be recreated from this project.

## Validation

Run:

```bash
sudo -u librenms php /opt/librenms/ciena-pseudowire-run-all.php
```

A successful device collection should end with output similar to:

```text
DONE <device>: inserted=<n>, updated=<n>, skipped=<n>, seen=<n>
```

`SKIP` messages can be normal when a pseudowire cannot be mapped to an L2VPN service, forwarding domain, or logical port.

Use the queries in `validation.md` to verify:

- `ciena_lsr_ids`
- LibreNMS `pseudowires`

## Devices without pseudowires

The wrapper currently attempts collection on every enabled `ciena-saos` device.

A device with no parsed pseudowires may return:

```text
No pseudowires parsed from <device>
```

The wrapper logs the result and continues.

## Transient mapping failures

The collector depends on several CLI commands returning consistent data during the same SSH session.

A single run may occasionally produce messages such as:

```text
LOCAL LSR-ID not found
SKIP PW ... no forwarding-domain mapping found
```

A later run may succeed without a configuration change. When troubleshooting, compare multiple consecutive collection cycles before assuming the device configuration is wrong.

## Shared Ciena configuration

This collector shares `ciena-xcvr.json` and `ciena-xcvr.env` with the related Ciena collectors in this repository.

The default timer offsets are:

```text
IS-IS:      *:1/5
Pseudowire: *:3/5
```

Both collectors process devices sequentially, so their effective collection interval can be longer than five minutes on larger device sets.
