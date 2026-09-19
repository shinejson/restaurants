<?php
/**
 * Control-plane tables: platform staff, sessions, settings, audit, notifications.
 */
use Resto\Database\ControlPlaneSchema;

return [
    'up' => static function (PDO $conn, string $driver): void {
        // The whole control plane is declared in one place so the schema can be
        // read (and reviewed) as a single document.
        ControlPlaneSchema::migrate($conn, $driver);
    },
];
