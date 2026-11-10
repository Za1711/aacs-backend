<?php

return [
    'host'      => env('MQTT_HOST', 'localhost'),
    'port'      => (int) env('MQTT_PORT', 1883),
    'client_id' => env('MQTT_CLIENT_ID', 'laravel-backend'),
    'username'  => env('MQTT_USERNAME', null),
    'password'  => env('MQTT_PASSWORD', null),
    'use_tls'   => (bool) env('MQTT_USE_TLS', false),

    'topics' => [
        'telemetry'    => 'room/{room_id}/telemetry',
        'node_battery' => 'room/{room_id}/node/battery',
        'occupancy'    => 'room/{room_id}/occupancy',
        'ac_command'   => 'room/{room_id}/ac/command',
        'command'      => 'room/{room_id}/command',
        'ac_status'    => 'room/{room_id}/ac/status',
        'control_log'  => 'room/{room_id}/control/log',
        'notification' => 'room/{room_id}/notification',
    ],

    'node_battery_timeout' => (int) env('MQTT_NODE_BATTERY_TIMEOUT', 30),
];
