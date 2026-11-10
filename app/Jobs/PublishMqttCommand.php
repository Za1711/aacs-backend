<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Illuminate\Support\Facades\Log;

class PublishMqttCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; 
    public int $backoff = 2;

    public function __construct(
        public string $topic,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $host = config('mqtt.host', 'f62988abf955495196dcc5ef9ec94248.s1.eu.hivemq.cloud');
        $port     = config('mqtt.port', 8883);
        $username = config('mqtt.username', 'AACS');
        $password = config('mqtt.password', 'Capstone123');

        Log::info("MQTT Job starting", [
            'topic'   => $this->topic,
            'host'    => $host,
            'command' => $this->payload['command'] ?? 'UNKNOWN',
        ]);

        try {
            $mqtt = new MqttClient($host, $port, 'laravel-cmd-' . uniqid());

            // Konfigurasi TLS dan Timeout
            $settings = (new ConnectionSettings())
                ->setUsername($username)
                ->setPassword($password)
                ->setUseTls(true)
                ->setTlsVerifyPeer(false) 
                ->setTlsVerifyPeerName(false)
                ->setConnectTimeout(5) 
                ->setSocketTimeout(5); 

            $mqtt->connect($settings, true);
            $mqtt->publish($this->topic, json_encode($this->payload), 0);
            $mqtt->disconnect();

            Log::info("MQTT Job SUCCESS [{$this->topic}]");
        } catch (\Throwable $e) {
            Log::error("MQTT Job FAILED", [
                'topic'     => $this->topic,
                'command'   => $this->payload['command'] ?? 'UNKNOWN',
                'exception' => get_class($e),
                'message'   => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
