# Native NETCONF support for LibreNMS

Status: **Design / lab setup (no native NETCONF implementation yet)**

## Project goal

Contribute first-class NETCONF support upstream to LibreNMS. Devices should ultimately be monitorable using:

- **NETCONF only** (initial supported target)
- **SNMPv3 only** (retain existing LibreNMS behavior)
- **SNMPv3 + NETCONF** (long-term hybrid per-device operation)

This is a generic LibreNMS feature, **not** a Ciena-specific collector and **not** a production deployment project. Vendor-specific YANG adapters will be added after the transport and model contracts are established.

Initial test environment: an isolated personal lab VM; **no corporate production LibreNMS, MariaDB, Ciena equipment, credentials, or monitoring networks**.

## Native means native

The intended outcome is a mergeable change to [librenms/librenms](https://github.com/librenms/librenms), not a sidecar service whose database writes imitate discovery. The implementation should use LibreNMS device models, discovery/polling scheduling, existing data storage, UI and tests.

This directory in `Null0Engineer/LibreNMS` holds design notes, lab instructions and later isolated prototypes. Actual upstream source patches will need a dedicated development checkout/fork of the official upstream repository, whose default branch is `master`. Do not transplant an experimental module into corporate production.

## Scope and security

- Read-only NETCONF over authenticated SSH, typically TCP 830.
- Implement NETCONF capability exchange (`<hello>`), session lifecycle, timeout/error management, `<get>`, and optionally read-only `<get-config>`.
- No configuration changes (`<edit-config>`, `<copy-config>`, `<delete-config>`, `<commit>`, `<kill-session>` are outside project scope).
- Enforce SSH host key verification; never commit credentials, session logs containing secrets, or production data.
- Avoid assuming one vendor's YANG path is universal. Capabilities must be checked and normalized.
- Maintain backward compatibility with SNMPv3 devices. Authentication/security settings for SNMP examples use SHA authentication + AES privacy and authPriv.
- New optional functionality must not increase baseline polling latency for devices with NETCONF disabled.

## Documents

- [Architecture and upstream integration points](./ARCHITECTURE.md)
- [Dedicated lab plan](./LAB.md)
- [Roadmap and acceptance tests](./ROADMAP.md)

## Upstream collaboration

- [LibreNMS contribution instructions](https://docs.librenms.org/Developing/Getting-Started/)
- [NETCONF/YANG community feature discussion](https://community.librenms.org/t/netconf-yang-support/28110)
- [LibreNMS upstream source](https://github.com/librenms/librenms)

Request architectural feedback from maintainers before submitting a broad transport abstraction. Favor small, tested pull requests with a clear separation of generic transport, device onboarding, YANG normalization and vendor adapters.
