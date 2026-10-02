# ifstatus_single

A LibreNMS service check for monitoring a **single interface independently** with SNMPv3.

Each LibreNMS service instance represents one interface `ifIndex`. If one interface fails, only that service changes state.

## What it does

`check_ifstatus_single` checks the operational state of one interface and can enrich the service output with:

- interface name and description
- RX/TX optical power
- transceiver type, vendor, and serial number
- LibreNMS port notes

The service returns:

| Code | State | Meaning |
| ---: | --- | --- |
| 0 | OK | Interface is operationally up |
| 2 | CRITICAL | Interface is not operationally up |
| 3 | UNKNOWN | Invalid arguments, SNMP failure, database failure, or device mapping failure |

## Files

- `check_ifstatus_single` — service check script
- `ifstatus_single.conf.example` — example SNMPv3 configuration
- `alert-rule.md` — example LibreNMS alert rule
- `alert-template.blade.php` — example LibreNMS alert template
- `device-group.md` — example dynamic device group

## Installation

Copy the check script to the Nagios/LibreNMS plugin directory:

```bash
sudo cp check_ifstatus_single /usr/lib/nagios/plugins/check_ifstatus_single
sudo chmod 755 /usr/lib/nagios/plugins/check_ifstatus_single
```

## LibreNMS service setup

Create one service for each interface you want to monitor:

```text
Name: <friendly service name>
Check Type: ifstatus_single
Remote Host: <device management IP>
Parameters: <ifIndex>
```

### Use the SNMP ifIndex

The value in **Parameters** is the interface's SNMP/LibreNMS `ifIndex`, not the physical port number.

For example:

```text
Parameters: 21
```

means:

```text
ifIndex = 21
```

It does not necessarily mean switch port 21.

You can look up interface indexes in LibreNMS or query the database:

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

You can also query the device directly:

```bash
snmpwalk -v3 -l authPriv -u USER -a SHA -A AUTH_PASSWORD -x AES -X PRIV_PASSWORD \
  <HOST> 1.3.6.1.2.1.31.1.1.1.1
```

## Usage

Using the built-in credential defaults:

```bash
check_ifstatus_single HOST IFINDEX
check_ifstatus_single -H HOST IFINDEX
```

Using explicit credentials:

```bash
check_ifstatus_single HOST USER AUTH_PASSWORD PRIV_PASSWORD IFINDEX
check_ifstatus_single -H HOST USER AUTH_PASSWORD PRIV_PASSWORD IFINDEX
```

The script currently contains placeholder defaults:

```bash
DEFAULT_USER="librenms"
DEFAULT_AUTH="CHANGEME"
DEFAULT_PRIV="CHANGEME"
```

Replace these locally if you use the default-credential form. Do not commit real credentials.

## SNMP behavior

The check uses SNMPv3 with:

- security level: `authPriv`
- authentication: SHA
- privacy: AES
- timeout: 5 seconds
- retries: 1

It queries:

- `1.3.6.1.2.1.31.1.1.1.1.<ifIndex>` — ifName
- `1.3.6.1.2.1.31.1.1.1.18.<ifIndex>` — ifAlias
- `1.3.6.1.2.1.2.2.1.8.<ifIndex>` — ifOperStatus

## LibreNMS database access

The script reads database settings from:

```text
/opt/librenms/.env
```

Variables used:

```text
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
```

Defaults:

```text
DB_PORT=3306
DB_DATABASE=librenms
DB_USERNAME=librenms
```

`DB_HOST` and `DB_PASSWORD` must be present.

## Device and port mapping

The service IP is mapped back to a LibreNMS device through the `services` table:

```sql
SELECT device_id
FROM services
WHERE service_ip = '<HOST>'
  AND service_type = 'ifstatus_single'
LIMIT 1;
```

The interface is then resolved using:

```text
device_id + ifIndex -> port_id
```

## Optical power

For most platforms, optical values are read from LibreNMS `dbm` sensors associated with the interface.

For Ciena SAOS devices, the script reads `power` sensors named:

```text
rx-<ifIndex>
tx-<ifIndex>
```

LibreNMS stores those values as watts, so they are converted to dBm.

## Transceiver inventory

For Ciena SAOS, transceiver information is read from the LibreNMS `transceivers` table.

For other supported platforms, the script reads transceiver details from `entPhysical`.

## Port notes

LibreNMS port notes are read from `devices_attribs` using:

```text
port_id_notes:<port_id>
```

Embedded newlines are flattened before they are added to the service output.

## Example output

Healthy interface:

```text
OK - Twe1/0/16 RX:-3.10 TX:-4.40 DESC:Server Uplink TYPE:SFP-10G-SR VENDOR:Cisco SERIAL:ABC123 NOTES:Primary uplink
```

Failed interface:

```text
CRITICAL - Twe1/0/16 DOWN RX:-40.00 TX:-4.40 DESC:Server Uplink TYPE:SFP-10G-SR VENDOR:Cisco SERIAL:ABC123 NOTES:Primary uplink
```

## Alerting

The included example rule alerts when the service is not OK while the parent device itself is still reachable:

```text
services.service_status != 0
AND
macros.device_up = 1
```

This helps separate an individual interface failure from a complete device outage.

See:

- `alert-rule.md`
- `alert-template.blade.php`
- `device-group.md`

for the example LibreNMS configuration.

## Requirements

- LibreNMS
- Bash
- Net-SNMP `snmpget`
- MariaDB/MySQL CLI client
- `awk`
- `grep`
- `sed`
- SNMPv3 reachability to the monitored device
- database connectivity from the LibreNMS host

## Validation

Run the check directly:

```bash
/usr/lib/nagios/plugins/check_ifstatus_single <HOST> <IFINDEX>
```

A healthy interface should return `OK`. A non-up interface should return `CRITICAL`, and a query or mapping failure should return `UNKNOWN`.
