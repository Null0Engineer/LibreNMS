# ifstatus_single

LibreNMS service check for monitoring a **single interface independently** by SNMPv3.

Each LibreNMS service instance represents one interface `ifIndex`. If one port fails, only that service goes critical.

## Files

- `check_ifstatus_single` — authoritative service check currently used on the LibreNMS server
- `ifstatus_single.conf.example` — reference only; the deployed script does not require it
- `alert-rule.md` — preserved live alert-rule behavior
- `alert-template.blade.php` — preserved LibreNMS alert template
- `device-group.md` — preserved live Front End ISP Switches dynamic-device-group definition

## Deployment

This repository is for storage, documentation, and version control only. It is **not** intended to be cloned onto the LibreNMS server.

Copy the script to:

```text
/usr/lib/nagios/plugins/check_ifstatus_single
```

Then:

```bash
sudo chmod 755 /usr/lib/nagios/plugins/check_ifstatus_single
```

## Service creation

When adding the service in LibreNMS, use:

```text
Name: <friendly service name>
Check Type: ifstatus_single
Remote Host: <device management IP>
Parameters: <ifIndex>
```

### Important: Parameters uses ifIndex, not the physical port number

The value in **Parameters** must be the interface's SNMP/LibreNMS **ifIndex**.

For example:

```text
Parameters: 21
```

does **not** mean "switch port 21."

It means:

```text
ifIndex = 21
```

The physical/logical interface associated with that ifIndex might be something completely different, such as:

```text
Twe1/0/16
Gi1/0/48
Ethernet1/3
```

Always look up the interface's actual ifIndex before creating the service.

You can verify it in LibreNMS by checking the port details, or directly in the database:

```sql
SELECT
    port_id,
    ifIndex,
    ifName,
    ifAlias
FROM ports
WHERE device_id = <DEVICE_ID>
ORDER BY ifIndex;
```

You can also verify it by SNMP:

```bash
snmpwalk -v3 -l authPriv -u USER -a SHA -A AUTH_PASSWORD -x AES -X PRIV_PASSWORD \
  <HOST> 1.3.6.1.2.1.31.1.1.1.1
```

This returns the mapping between ifIndex values and interface names.

## Supported invocation formats

### Default credentials

```bash
check_ifstatus_single HOST IFINDEX
check_ifstatus_single -H HOST IFINDEX
```

The script uses its built-in defaults:

```bash
DEFAULT_USER="librenms"
DEFAULT_AUTH="CHANGEME"
DEFAULT_PRIV="CHANGEME"
```

Replace the placeholder authentication and privacy passwords in the deployed copy with the correct local credentials. Do not commit real credentials to GitHub.

### Explicit / legacy credentials

```bash
check_ifstatus_single HOST USER AUTH_PASSWORD PRIV_PASSWORD IFINDEX
check_ifstatus_single -H HOST USER AUTH_PASSWORD PRIV_PASSWORD IFINDEX
```

This preserves compatibility with existing LibreNMS service definitions that pass credentials explicitly.

## SNMP behavior

The script uses SNMPv3:

- Security level: `authPriv`
- Authentication protocol: SHA
- Privacy protocol: AES
- Timeout: 5 seconds
- Retries: 1

It queries:

- `1.3.6.1.2.1.31.1.1.1.1.<ifIndex>` — ifName
- `1.3.6.1.2.1.31.1.1.1.18.<ifIndex>` — ifAlias
- `1.3.6.1.2.1.2.2.1.8.<ifIndex>` — ifOperStatus

If the interface state is `up`, the service returns OK.

Any other valid operational state returns CRITICAL.

A failed SNMP query returns UNKNOWN.

## LibreNMS database connection

The script reads all database connection details from:

```text
/opt/librenms/.env
```

The following variables are used:

```text
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
```

Defaults are applied only where appropriate:

```text
DB_PORT=3306
DB_DATABASE=librenms
DB_USERNAME=librenms
```

`DB_HOST` and `DB_PASSWORD` must be present.

This supports installations where MariaDB is remote from the LibreNMS poller/web server.

The LibreNMS server must have TCP connectivity to the configured database host and port.

## Device mapping

The checked host is mapped to a LibreNMS device through the services table:

```sql
SELECT device_id
FROM services
WHERE service_ip = '<HOST>'
  AND service_type = 'ifstatus_single'
LIMIT 1;
```

The interface is then mapped using:

```text
device_id + ifIndex -> port_id
```

## Optical power

### Cisco and other platforms

LibreNMS optical sensors are read from the `sensors` table using:

```text
sensor_class = dbm
```

and descriptions matching:

```text
<ifName> Receive Power
<ifName> Transmit Power
```

### Ciena SAOS

When:

```text
devices.os = ciena-saos
```

the script uses `sensor_class=power` with:

```text
rx-<ifIndex>
tx-<ifIndex>
```

LibreNMS stores those values as watts, so the script converts them to dBm:

```text
dBm = 10 * log10(watts * 1000)
```

## Transceiver inventory

### Ciena SAOS

Transceiver details are read from the `transceivers` table using `device_id` and `port_id`.

Fields used:

- type/model
- vendor
- serial

### Other platforms

Transceiver information is read from `entPhysical` using:

```text
device_id
entPhysicalName = ifName
```

Fields used:

- entPhysicalModelName
- entPhysicalMfgName
- entPhysicalSerialNum

## Port notes

Per-port notes are read from `devices_attribs` using:

```text
attrib_type = port_id_notes:<port_id>
```

Embedded newlines are flattened before being included in the service output.

## Exit states

| Code | State | Meaning |
| ---: | --- | --- |
| 0 | OK | Interface operational state is up |
| 2 | CRITICAL | Interface operational state is not up |
| 3 | UNKNOWN | Invalid arguments, SNMP failure, DB settings failure, or device mapping failure |

## Example output

Healthy interface:

```text
OK - Twe1/0/16 RX:-3.10 TX:-4.40 DESC:Hanley Desk TYPE:SFP-10G-SR VENDOR:Cisco SERIAL:ABC123 NOTES:Desk uplink
```

Failed interface:

```text
CRITICAL - Twe1/0/16 DOWN RX:-40.00 TX:-4.40 DESC:Hanley Desk TYPE:SFP-10G-SR VENDOR:Cisco SERIAL:ABC123 NOTES:Desk uplink
```

## LibreNMS alert rule

The live alert condition is:

```text
services.service_status != 0
AND
macros.device_up = 1
```

The device-up condition is intentional: it keeps the interface/service alert from becoming the primary alert when the whole monitored device is down.

The live rule is named:

```text
ISP - Service up/down - Front End Switches
```

and uses the alert template:

```text
Cisco - Internet Port status
```

The live notification cadence is configured for an immediate alert and hourly repeats while the outage remains active:

```text
steps 1-∞
start 0s
step 3600s
```

See `alert-rule.md` and `alert-template.blade.php` in this folder for the preserved rule behavior and template body.

The service check itself reports the current state only. Alert timing remains a LibreNMS responsibility.

## Dynamic device group

The live dynamic device group is:

```text
Front End ISP Switches
```

Its preserved rule is based on front-end switch hardware:

```text
devices.hardware LIKE '%c9500%'
OR devices.hardware LIKE '%c1300%'
```

Historical explicit-IP exceptions are intentionally documented only as commented placeholders because management addresses may change.

The alert rule is scoped to this device group. The **alert condition** remains:

```text
services.service_status != 0
AND
macros.device_up = 1
```

See `device-group.md` for the full preserved group definition.

## Requirements

The LibreNMS host needs:

- Bash
- Net-SNMP `snmpget`
- MariaDB/MySQL CLI client
- `awk`
- `grep`
- `sed`
- network reachability to the monitored device for SNMPv3
- network reachability to the LibreNMS database server

## Design rule

Each interface is monitored independently.

One failed interface should affect only its own `ifstatus_single` service. Optical and transceiver data enrich the service output but do not determine whether the interface is OK or CRITICAL.


## Rebuild checklist

1. Copy `check_ifstatus_single` to `/usr/lib/nagios/plugins/check_ifstatus_single`.
2. Apply executable permissions:

```bash
sudo chmod 755 /usr/lib/nagios/plugins/check_ifstatus_single
```

3. Configure local SNMPv3 credentials in the deployed copy or use the explicit legacy parameter form.
4. Confirm the LibreNMS database variables are present in `/opt/librenms/.env`.
5. Recreate the `Front End ISP Switches` device group from `device-group.md`.
6. Recreate the alert rule from `alert-rule.md`.
7. Recreate/import the alert template from `alert-template.blade.php`.
8. Create one LibreNMS service per monitored interface using the **ifIndex** as the service parameter.
9. Test the check directly and verify LibreNMS receives the expected service state.

## Validation

Direct check:

```bash
/usr/lib/nagios/plugins/check_ifstatus_single <HOST> <IFINDEX>
```

Expected healthy result begins with:

```text
OK -
```

A non-up interface should return CRITICAL, and an SNMP/database/mapping failure should return UNKNOWN.

When troubleshooting a service definition, confirm that the configured parameter is the actual SNMP `ifIndex`, not the printed switch-port number.
