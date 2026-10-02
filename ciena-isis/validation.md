# Ciena IS-IS Validation

Use this query after deployment or troubleshooting to confirm the collector is populating LibreNMS correctly.

## SQL query

```sql
SELECT
    ia.device_id,
    d.hostname,
    ia.port_id,
    p.ifName,
    ia.ifIndex,
    ia.isisISAdjState,
    ia.isisISAdjNeighSysID,
    ia.isisISAdjNeighSysType,
    ia.isisISAdjAreaAddress,
    ia.isisISAdjLastUpTime
FROM isis_adjacencies ia
LEFT JOIN devices d ON d.device_id = ia.device_id
LEFT JOIN ports p ON p.port_id = ia.port_id
WHERE d.os = 'ciena-saos'
ORDER BY d.hostname, p.ifName;
```

## Sanitized example output

The real production values are intentionally not stored in GitHub.

```text
+-----------+----------------+---------+--------+---------+----------------+---------------------+-----------------------+----------------------+---------------------+
| device_id | hostname       | port_id | ifName | ifIndex | isisISAdjState | isisISAdjNeighSysID | isisISAdjNeighSysType | isisISAdjAreaAddress | isisISAdjLastUpTime |
+-----------+----------------+---------+--------+---------+----------------+---------------------+-----------------------+----------------------+---------------------+
|       101 | 192.0.2.11     |    5001 | 1      |       1 | up             | CORE-A              | L1                    | SITE-A-SITE-B        |              864000 |
|       102 | 192.0.2.12     |    5002 | 21     |      21 | up             | CORE-B              | L1                    | SITE-B-SITE-C        |             1728000 |
|       102 | 192.0.2.12     |    5003 | 22     |      22 | up             | CORE-C              | L1                    | SITE-B-SITE-D        |             2592000 |
|       103 | 192.0.2.13     |    5004 | 2      |       2 | up             | CORE-A              | L1                    | SITE-C-SITE-A        |             3456000 |
+-----------+----------------+---------+--------+---------+----------------+---------------------+-----------------------+----------------------+---------------------+
```

## What to verify

A healthy result should show:

- one row per discovered adjacency
- the expected Ciena device
- a valid LibreNMS `port_id`
- the correct interface `ifName` / `ifIndex`
- `isisISAdjState = up` for healthy neighbors
- the expected IS-IS neighbor system ID
- the expected IS-IS level
- the expected Ciena IS-IS interface / area-address field
- a state duration that increases while the adjacency remains in the same state

## Sanitization note

Do not commit real:

- management IP addresses
- production hostnames
- neighbor system IDs
- circuit/site names
- interface descriptions that expose customer or carrier details
