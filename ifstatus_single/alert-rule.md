# ifstatus_single Alert Rule

This documents the live LibreNMS alerting behavior used with `ifstatus_single`.

## Rule name

```text
ISP - Service up/down - Front End Switches
```

## Rule condition

The live rule evaluates:

```text
services.service_status != 0 AND macros.device_up = 1
```

This means:

- the `ifstatus_single` service must be in a non-OK state
- the parent device itself must still be considered up

The `macros.device_up = 1` condition is important because it prevents the service-level alert from being the primary alert when the entire device is unreachable.

## Severity

```text
Critical
```

## Alert timing / delivery

The live rule is configured to send outage notifications once per hour.

Visible step configuration:

```text
steps 1-∞
start 0s
step 3600s
```

That produces an initial alert and hourly repeats while the condition remains active.

## Attached alert template

The rule uses the alert template:

```text
Cisco - Internet Port status
```

See:

```text
alert-template.blade.php
```

for the template body stored with this project.

## Service behavior

A healthy `ifstatus_single` service returns:

```text
service_status = 0
```

A failed service returns a non-zero status and is eligible for this rule as long as the device itself remains up.

## Scope

The rule is intended for ISP/front-end interface monitoring. The rule should be attached only to the appropriate LibreNMS devices/device groups for this monitoring purpose.

## Recovery

When the service returns to OK, LibreNMS sends the recovery using the same alert template. The template switches its heading and interface status based on:

```php
$alert->state == 0
```

## Why device_up is included

Do not simplify this rule to only:

```text
services.service_status != 0
```

The live configuration also requires:

```text
macros.device_up = 1
```

This keeps interface/service failures separate from complete device-down events.
