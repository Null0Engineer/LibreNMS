# Proposed native NETCONF architecture

Status: initial design, **not** implemented.

## Protocol modes

| Mode | SNMPv3 | NETCONF | Definition |
| --- | --- | --- | --- |
| snmp | yes | no | Existing LibreNMS behavior unchanged |
| netconf | no | yes | First implementation target; no SNMP dependency |
| hybrid | yes | yes | Future: both methods enabled; module/field ownership explicitly selected |

Avoid fallback behavior that silently changes which protocol owns a metric. Device availability, source priority, poll failures, and module support must be documented separately.

## Source review: upstream integration points

Source reviewed on 2026-10-14 against `librenms/librenms` default branch. Verify before implementation because upstream changes frequently.

- `LibreNMS/Modules/Core.php`: `shouldDiscover()` and `shouldPoll()` currently gate on `$connectivity->snmpIsAvailable()`; both methods use `SnmpQuery`. A NETCONF-only device cannot simply enable this native Core module without new logic or a NETCONF-aware equivalent.
- `LibreNMS/Polling/ConnectivityHelper.php`: availability and `hasAvailability()` are oriented to existing ICMP/SNMP status. Need protocol-specific reachability, separate from general ICMP.
- `app/Models/Device.php`: device SNMP configuration already has a dedicated model representation. Add cleanly isolated NETCONF configuration and prevent credentials from being logged or dumped.
- Discovery / polling runner, module status, device onboarding UI and data persistence all require design review before declaring NETCONF-only support complete.

Upstream source links:
- https://github.com/librenms/librenms/blob/master/LibreNMS/Modules/Core.php
- https://github.com/librenms/librenms/blob/master/LibreNMS/Polling/ConnectivityHelper.php
- https://github.com/librenms/librenms/blob/master/app/Models/Device.php

## Proposed component boundaries

1. **NETCONF settings:** per-device enabled mode, port (default 830), timeout, SSH identity/credentials, host-key trust, advertised capabilities.
2. **NETCONF session/transport:** NETCONF-over-SSH subsystem, capability negotiation, message-id correlation, RPC framing (base:1.0 and base:1.1 as negotiated), safe parsing, bounded reads, timeout/close.
3. **Read-only RPC interface:** `get(filter)`, `getConfig(source, filter)` when needed; typed transport/RPC errors. No generic write API.
4. **YANG data adapters:** capability-driven mapping from supported standard/vendor models to normalized LibreNMS concepts (device info, interfaces, state).
5. **Native LibreNMS integration:** NETCONF-only onboarding path, discovery and polling module eligibility, status, health, graphs and alerting; no requirement for SNMP bootstrap.
6. **Hybrid policy:** later choose data source per module or metric; prevent duplicated ports/sensors and collision over existing fields.

## Open technical decisions

- Prefer a PHP-native integration compatible with current LibreNMS dependencies and coding standards. Evaluate NETCONF-over-SSH implementation and licensing; do **not** claim an SSH library supports the NETCONF subsystem until verified. A short standalone proof-of-concept in a lab is permitted but is not the final integrated implementation.
- NETCONF servers advertise supported capability URIs; do not infer `ietf-interfaces`, `ietf-system`, uptime or operational state availability without checking the specific server.
- Host identity mapping and OS detection without `sysObjectID` must be explicit.
- Add-device flow must accept NETCONF-only authentication and must not force an SNMP probe.
- Clearly distinguish ICMP reachability from NETCONF session/RPC health for outage and alerting semantics.
- Protect the XML parser against external entities and excessive payloads; sanitize device-supplied XML in logs.
- Standard NETCONF interaction is request/response; event subscriptions/notifications are a separate future feature.

## Upstream strategy

Propose interface and capability contracts for feedback first. A reviewable patch sequence could be:
1. Transport/session abstraction + unit tests.
2. Persisted device configuration + connection test (no changes to SNMP-only devices).
3. NETCONF-only onboarding and core status integration.
4. A single supported interface operational-state module + fixtures.
5. Hybrid operation and additional YANG model/vendor adapters.

Exact sequencing is subject to maintainer feedback.
