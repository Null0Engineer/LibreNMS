# Milestones and acceptance criteria

## M0: Architecture and lab
- [x] Establish native LibreNMS goal and the NETCONF-only -> hybrid sequence.
- [x] Research upstream Core/ConnectivityHelper SNMP dependencies.
- [x] Write the initial design and isolated lab plan under `Null0Engineer`.
- [ ] Provision a completely separate lab VM.
- [ ] Install a clean upstream LibreNMS development checkout with local-only database.
- [ ] Obtain maintainer feedback on proposed extension points.

## M1: Protocol transport (NETCONF only)
- [ ] Choose a PHP-compatible NETCONF-over-SSH implementation compatible with upstream rules/license.
- [ ] Test SSH host-key verification, credential handling, `netconf` SSH subsystem and `<hello>` exchange.
- [ ] Support negotiated framing for base:1.0 and base:1.1.
- [ ] Implement bounded read-only RPC `<get>` and typed timeout/protocol/auth errors.
- [ ] Unit test with sanitized success/error/capability fixtures.
- [ ] Explicitly prohibit configuration-changing RPCs.

## M2: Native NETCONF-only device
- [ ] Support storing and testing per-device NETCONF settings in LibreNMS.
- [ ] Add a NETCONF-only device **without providing SNMP credentials**.
- [ ] Determine OS/device identity without relying on SNMP `sysDescr` or `sysObjectID`.
- [ ] Integrate with discovery and poll scheduling without the SNMP Core-module gate.
- [ ] Display monitored reachability/health meaningfully when no SNMP exists.
- [ ] Verify one actual native data path (e.g., interface admin/oper status using a server-advertised YANG model).
- [ ] Ensure state is stored and displayed using native LibreNMS systems, not parallel custom tables.

## M3: Hybrid operation
- [ ] Support both NETCONF and SNMPv3 on a single device.
- [ ] Choose metric/module source explicitly and prevent duplicate interface/sensor records.
- [ ] Test failure isolation: one protocol down does not corrupt the other.
- [ ] Confirm no regressions in existing SNMPv3 discovery, polling, graphs, or alerting.

## M4: Community contribution
- [ ] Add integration/unit tests, fixtures, docs, error handling and upgrade path.
- [ ] Follow upstream formatting/checks (`lnms dev:check` and related tests).
- [ ] Split small upstream pull requests and obtain maintainer review.
- [ ] Add further vendor adapters only after generic interface is stable.

## Definition of 'NETCONF only works'

A target with SNMP completely unavailable can be onboarded through normal LibreNMS functionality, polled repeatedly over authenticated NETCONF, produce at least one native operational metric, and have its status/errors appear in the normal LibreNMS interface. A successful standalone NETCONF connection **alone does not qualify**.

## Definition of 'hybrid works'

A single device can use SNMPv3 and NETCONF simultaneously with declared per-module ownership, no duplicate records, predictable failure behavior, and no regression to SNMP-only devices.

## Safety rules

All implementation and validation happens in the home lab. No corporate production server, database, credentials, or equipment should be modified or queried for the project.
