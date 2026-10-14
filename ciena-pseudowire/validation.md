# Ciena Pseudowire Validation

The production collector uses LibreNMS's native `pseudowires` table plus a custom helper table named `librenms_custom.ciena_lsr_ids` on the same MariaDB instance.

## Helper table schema

See `ciena_lsr_ids.sql`.

## Sanitized helper-table example

```text
+-----------+--------------+--------------+-------------+---------------------+
| device_id | lsr_id       | hostname     | sysName     | updated_at          |
+-----------+--------------+--------------+-------------+---------------------+
|       101 | 198.51.100.1 | 192.0.2.11   | ciena-a     | 2026-01-01 12:00:00 |
|       102 | 198.51.100.2 | 192.0.2.12   | ciena-b     | 2026-01-01 12:00:00 |
|       103 | 198.51.100.3 | 192.0.2.13   | ciena-c     | 2026-01-01 12:00:00 |
+-----------+--------------+--------------+-------------+---------------------+
```

The example values above are intentionally non-production documentation ranges and names.

Do not commit real:

- management IP addresses
- LSR IDs
- production hostnames
- sysNames
- circuit/site/customer-identifying names

## Validate the helper table

```sql
SELECT
    device_id,
    lsr_id,
    hostname,
    sysName,
    updated_at
FROM librenms_custom.ciena_lsr_ids
ORDER BY device_id;
```

## Validate collected pseudowires

```sql
SELECT
    pw.pseudowire_id,
    pw.device_id,
    d.hostname,
    pw.port_id,
    p.ifName,
    pw.peer_device_id,
    pw.peer_ldp_id,
    pw.cpwVcID,
    pw.pw_descr,
    pw.pw_type,
    pw.pw_psntype
FROM pseudowires pw
LEFT JOIN devices d ON d.device_id = pw.device_id
LEFT JOIN ports p ON p.port_id = pw.port_id
WHERE d.os = 'ciena-saos'
ORDER BY d.hostname, pw.cpwVcID;
```

## Runtime database-access test

The LibreNMS GUI displays native `pseudowires` rows, not the database used to resolve a peer. The collector uses the existing LibreNMS connection with a fully qualified helper table name.

Run on the LibreNMS application server:

```bash
sudo -u librenms php -r '
$init_modules = [];
require_once "/opt/librenms/includes/init.php";
var_export(dbFetchRows("SELECT device_id, lsr_id FROM librenms_custom.ciena_lsr_ids ORDER BY device_id"));
echo PHP_EOL;
'
```

A successful SELECT verifies that the application connection can read the helper table. It does not, by itself, prove that the collector invoked the fallback lookup: the fallback runs only when peer resolution through `devices -> ports -> ipv4_addresses` does not find a match. Verify native `peer_device_id` results and the collector's real run behavior before retiring the old table.

## Migration comparison

While both tables still exist, run:

```sql
SELECT 'original' AS source, COUNT(*) AS rows_found FROM librenms.ciena_lsr_ids
UNION ALL
SELECT 'new', COUNT(*) FROM librenms_custom.ciena_lsr_ids;
```

Check individual records and timestamps as well. The original table should be removed from the native `librenms` schema only after final backup and successful collection tests. This removes LibreNMS's extra-table schema validation warning.
