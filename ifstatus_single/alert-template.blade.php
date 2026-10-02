@if ($alert->state == 0)
<h1 style="color: Green;">
ALL CLEAR<br>
SERVICE RESTORED<br>
NO ACTION REQUIRED
</h1>
@else
<h1 style="color: Red;">
ACTION REQUIRED<br>
ISP / FRONT END SERVICE DOWN<br>
ACTION REQUIRED
</h1>
@endif

<br>

Device Information:<br>
Device Name: {{ $alert->sysName }}<br>
Severity: {{ $alert->severity }}<br>
IP Address: {{ $alert->ip }}<br>
OS: {{ $alert->os }}<br>
Serial Number: {{ $alert->serial }}<br>

<br>

Alert Details:<br>
Rule: {{ $alert->name }}<br>
Alert Time: {{ $alert->timestamp }}<br>

@if ($alert->state == 0)
Alert Recover Time: {{ date('Y-m-d H:i:s') }}<br>
Outage Time: {{ $alert->elapsed }}<br>
@endif

<br>

Affected Service Information:<br>

@foreach ($alert->faults as $fault)

@php

$message = $fault['service_message'];

preg_match('/CRITICAL - ([^ ]+)/', $message, $port_match);
preg_match('/RX:([^ ]+)/', $message, $rx_match);
preg_match('/TX:([^ ]+)/', $message, $tx_match);

preg_match('/DESC:(.*?) TYPE:/', $message, $desc_match);
preg_match('/TYPE:(.*?) VENDOR:/', $message, $type_match);
preg_match('/VENDOR:(.*?) SERIAL:/', $message, $vendor_match);
preg_match('/SERIAL:(.*?) NOTES:/', $message, $serial_match);
preg_match('/NOTES:(.*)$/', $message, $notes_match);

$port = trim($port_match[1] ?? 'Unknown');
$rx = trim($rx_match[1] ?? 'N/A');
$tx = trim($tx_match[1] ?? 'N/A');

$desc = trim($desc_match[1] ?? 'None');
$type = trim($type_match[1] ?? 'Unknown');
$vendor = trim($vendor_match[1] ?? 'Unknown');
$opticserial = trim($serial_match[1] ?? 'Unknown');

$notes = trim($notes_match[1] ?? 'None');

$notes = str_replace('\n', '<br>', $notes);

@endphp

Service Name:<br>
{{ $fault['service_name'] }}<br>

<br>

Port Information:<br>
Port: {{ $port }}<br>

@if ($alert->state == 0)
Status: UP / RESTORED<br>
@else
Status: DOWN<br>
@endif

Description: {{ $desc }}<br>

<br>

Optic Information:<br>
Type: {{ $type }}<br>
Vendor: {{ $vendor }}<br>
Serial: {{ $opticserial }}<br>

<br>

Optic Health:<br>
RX Power: {{ $rx }} dBm<br>
TX Power: {{ $tx }} dBm<br>

<br>

Port Notes:<br>
{!! $notes !!}<br>

<br>

Raw Service Message:<br>
{{ $fault['service_message'] }}<br>

<br>

@endforeach

Management Link:<br>
<a href="https://{{ $alert->ip }}/">https://{{ $alert->ip }}/</a>
