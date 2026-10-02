# ifstatus_single Device Group

This documents the live LibreNMS dynamic device group used to scope the front-end ISP switch alerting.

## Group name

```text
Front End ISP Switches
```

## Description

```text
By Default the C9500 and C1300 Switches are the primary Front End switches for this company.
```

## Group type

```text
Dynamic
```

## Live rule

```text
devices.hardware LIKE '%c9500%'
OR devices.hardware LIKE '%c1300%'

# Historical IP exceptions — leave commented because addresses may change:
# OR ipv4_addresses.ipv4_address = "<IP_ADDRESS_1>"
# OR ipv4_addresses.ipv4_address = "<IP_ADDRESS_2>"
```

## Purpose

This group defines which devices are considered front-end ISP switches for the `ifstatus_single` alerting workflow.

The hardware matches include:

- Cisco C9500 platforms
- Cisco C1300 platforms

The live environment previously used explicit IP exceptions in addition to the hardware matches. Those are intentionally documented only as commented placeholders because management IP addresses may change.

If an exception is required later, add the current device IP deliberately rather than relying on an old address from this repository.

## Relationship to alerting

The live alert rule `ISP - Service up/down - Front End Switches` is attached to this group.

The service alert condition itself remains:

```text
services.service_status != 0
AND
macros.device_up = 1
```

This means the device must:

1. Be in the `Front End ISP Switches` group.
2. Have a non-OK `ifstatus_single` service.
3. Still be considered up by LibreNMS.

That combination produces the intended ISP/front-end interface alert.
