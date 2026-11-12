<?php

return [
    'remote_online_timeout_seconds'    => (int) env('REMOTE_ONLINE_TIMEOUT_SECONDS', 20),
    'node_link_active_timeout_seconds' => (int) env('NODE_LINK_ACTIVE_TIMEOUT_SECONDS', 30),
    'node_offline_confirm_seconds'     => (int) env('NODE_OFFLINE_CONFIRM_SECONDS', 60),
    'remote_telemetry_retention_days'  => (int) env('REMOTE_TELEMETRY_RETENTION_DAYS', 30),
    'node_telemetry_retention_days'    => (int) env('NODE_TELEMETRY_RETENTION_DAYS', 30),
];
