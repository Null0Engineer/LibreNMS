-- Ciena IS-IS collector validation
--
-- Verifies that Ciena SAOS adjacencies are being written to LibreNMS's
-- native isis_adjacencies table and mapped to valid LibreNMS ports.

SELECT
    ia.device_id,
    d.hostname,
    ia.port_id,
    p.ifName,
    ia.ifIndex,
    ia.isisISAdjState,
    ia.isisISAdjNeighSysID,
    ia.isisISAdjNeighSysType,
    ia.isisISAdjAreaAddress,
    ia.isisISAdjLastUpTime
FROM isis_adjacencies ia
LEFT JOIN devices d ON d.device_id = ia.device_id
LEFT JOIN ports p ON p.port_id = ia.port_id
WHERE d.os = 'ciena-saos'
ORDER BY d.hostname, p.ifName;
