# Isolated LibreNMS NETCONF development lab

Status: proposed. **No VM has been provisioned.**

## Proposed VM

- Name: `librenms-netconf-dev`
- OS: Ubuntu Server 24.04 LTS
- Hypervisor: personal/home Proxmox or equivalent, **not a corporate host**
- Starting resources: 4 vCPU, 8 GiB RAM, 100 GiB virtual disk (adjust after actual usage)
- Network: dedicated home lab VLAN; route/firewall so it cannot reach corporate management or production SQL subnets
- Software: LibreNMS upstream development checkout, its own local MariaDB, PHP dependencies, test tools, and a local simulated NETCONF endpoint
- Access: outbound OS/package/GitHub updates; inbound SSH/web access only from selected home administration clients

The upstream LibreNMS development guide allows Linux VMs and explicitly recommends keeping changes off production servers:
https://docs.librenms.org/Developing/Getting-Started/

## Isolation rules

1. Never reuse `DB_HOST=10.66.62.11` or corporate LibreNMS credentials, data exports, VLANs, device IPs or read-only monitoring keys in this lab.
2. Use a completely new database instance; `DB_DATABASE=librenms` is fine **only if it is local to the lab**.
3. Do not permit the lab VM to initiate sessions toward corporate networks; ideally use separate routing/firewall policy.
4. Do not enable NETCONF on corporate devices as part of this project.
5. Use a simulated read-only NETCONF server and sanitized YANG fixtures until the transport and security tests pass.
6. Do not use `StrictHostKeyChecking=no` or other host-key bypasses for NETCONF.
7. Configure separate test credentials; keep secrets out of Git.

## Initial deployment sequence

1. Provision the VM and verify separation from work networks.
2. Install Ubuntu updates and prerequisites following the **current** upstream LibreNMS documentation: https://docs.librenms.org/Installation/Install-LibreNMS/
3. Deploy and verify LibreNMS with a **local** lab MariaDB, without adding any production devices.
4. Build a separate upstream development checkout/fork for code changes. Use `master` as the upstream base branch unless the upstream repository changes its default.
5. Build or select an isolated simulated NETCONF endpoint; record its exact version and supported capabilities.
6. Capture sanitized `<hello>` and `<rpc-reply>` fixtures for tests. Verify the server offers the desired data models before writing adapters.
7. Implement and test read-only NETCONF connectivity, including disabled/unreachable/authentication-error/unsupported-model cases.
8. Only then enable experimental NETCONF-only onboarding in the lab and test polling/alerts.

## Day-one checks

- Local LibreNMS `validate.php` passes with no connection to production.
- Lab database host is `127.0.0.1` or the explicitly isolated lab database host.
- Test endpoint responds with an appropriate NETCONF `<hello>` over the SSH NETCONF subsystem.
- No SNMP is configured on the NETCONF-only test target.
- Negative tests cover no server, bad credentials, bad host key, timeout, malformed XML, and unsupported capabilities.
- The working SNMPv3 path remains unaffected after experimental changes.

## Native development vs sidecar

Standalone test clients and fixtures can live in this repository. The **actual native code** must be developed against an upstream LibreNMS checkout/fork so that the eventual pull request applies cleanly. Do not patch the corporate `/opt/librenms` installation.
