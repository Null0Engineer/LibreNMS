# LibreNMS Add-ons and Integrations

A collection of custom LibreNMS checks, collectors, and supporting configuration built to fill a few gaps in day-to-day monitoring.

These projects are focused on practical integrations that are useful in environments where the information exists on the device, but LibreNMS does not expose it exactly the way you want out of the box.

## Projects

### [ifstatus_single](./ifstatus_single)

A LibreNMS service check for monitoring a **single interface independently** with SNMPv3.

It can report:

- interface operational state
- interface name and description
- RX/TX optical power
- transceiver type, vendor, and serial number
- LibreNMS port notes

Each monitored interface is its own service, so one failed port does not cause every monitored interface on the device to alert.

---

### [Ciena IS-IS Collector](./ciena-isis)

Collects IS-IS adjacency information from Ciena SAOS devices over SSH and writes the results into LibreNMS's native `isis_adjacencies` table.

The collector handles:

- Ciena CLI parsing
- IS-IS neighbor discovery
- logical-port to LibreNMS port mapping
- adjacency state tracking
- persistent state-change timing
- systemd-based scheduled collection

This is mainly useful when the CLI exposes the information you need but the normal SNMP discovery path does not.

---

### [Ciena Pseudowire Collector](./ciena-pseudowire)

Collects Ciena SAOS pseudowire information over SSH and maps it into LibreNMS's native `pseudowires` table.

The collector resolves:

- pseudowire IDs and names
- peer addresses
- L2VPN forwarding domains
- FPS logical ports
- LibreNMS port mappings
- peer LibreNMS devices
- Ciena LSR IDs

A small helper table, `ciena_lsr_ids`, is used when a pseudowire peer is identified by an LSR ID instead of a normal interface address.

## Repository Layout

```text
LibreNMS/
├── ifstatus_single/
├── ciena-isis/
└── ciena-pseudowire/
```

Each project has its own README with installation, configuration, behavior, and validation details.

## Requirements

Requirements vary by project, but these tools may use:

- LibreNMS
- PHP CLI
- Bash
- MariaDB/MySQL client access
- Net-SNMP
- OpenSSH
- Expect
- systemd

See the README inside each project directory for the exact requirements.

## Credentials and Sensitive Data

Real credentials should never be committed to this repository.

Example configuration files use placeholders such as:

```text
CHANGEME
```

or environment variables for usernames and passwords.

Keep device credentials, management addresses, customer information, circuit details, and other environment-specific secrets in local configuration files.

## Compatibility

These projects were written for real LibreNMS deployments and may depend on specific LibreNMS database tables, device OS definitions, or CLI output formats.

LibreNMS and vendor software change over time, so review the code and test it in your environment before relying on it.

## Disclaimer

This is an independent community repository.

It is not affiliated with, endorsed by, or maintained by LibreNMS, Ciena, Cisco, or any other vendor referenced in the code or documentation.

Use it, modify it, break it, fix it, and preferably test it before pointing it at anything important.
