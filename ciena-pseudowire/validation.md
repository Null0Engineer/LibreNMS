# Ciena Pseudowire Validation

The production collector uses LibreNMS's native `pseudowires` table plus a custom helper table named `ciena_lsr_ids`.

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
FROM ciena_lsr_ids
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
