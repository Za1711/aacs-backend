<?php
require 'vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

try {
    $mqtt = new MqttClient('f62988abf955495196dcc5ef9ec94248.s1.eu.hivemq.cloud', 8883, 'test-publish-' . uniqid());
    $settings = (new ConnectionSettings())
        ->setUsername('AACS')
        ->setPassword('Capstone123')
        ->setUseTls(true)
        ->setTlsVerifyPeer(false)
        ->setTlsVerifyPeerName(false);
        
    $mqtt->connect($settings, true);
    echo "CONNECTED!\n";
    
    // Kirim perintah ON ke ESP32 - GANTI angka 8 sesuai ID ruangan terakhirmu
    $payload = json_encode([
        'command' => 'POWER',
        'value' => 'ON',
        'mode' => 'MANUAL',
        'timestamp' => time(),
    ]);
    
    $mqtt->publish('room/8/ac/command', $payload, 0);
    echo "PUBLISHED to room/8/ac/command: $payload\n";
    
    $mqtt->disconnect();
    echo "DONE!\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
