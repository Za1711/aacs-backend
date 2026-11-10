<?php

namespace App\Services;

use App\Jobs\PublishMqttCommand;
use Illuminate\Support\Facades\Log;

class MqttService
{
    public function buildTopic(string $topicKey, int $roomId): string
    {
        $template = config("mqtt.topics.{$topicKey}");
        return str_replace('{room_id}', (string) $roomId, $template);
    }

    public function publish(string $topic, array $payload): bool
    {
        try {
            // Gunakan dispatchSync agar exception tertangkap saat menggunakan queue sync
            PublishMqttCommand::dispatchSync($topic, $payload);
            return true;
        } catch (\Throwable $e) {
            Log::error("MqttService publish failed: " . $e->getMessage(), ['topic' => $topic]);
            return false;
        }
    }

    public function publishAcCommand(int $roomId, string $command, mixed $value): bool
    {
        $topic = $this->buildTopic('ac_command', $roomId);

        $payload = [
            'command'   => $command,
            'value'     => $value,
            'timestamp' => time(),
        ];

        try {
            PublishMqttCommand::dispatchSync($topic, $payload);
            
            Log::info("MQTT AC command dispatched", [
                'topic'   => $topic,
                'command' => $command,
                'room_id' => $roomId
            ]);
            
            return true;
        } catch (\Throwable $e) {
            Log::error("MqttService publishAcCommand failed", [
                'topic'     => $topic,
                'room_id'   => $roomId,
                'command'   => $command,
                'exception' => get_class($e),
                'message'   => $e->getMessage()
            ]);
            return false;
        }
    }

    public function publishPowerCommand(int $roomId, string $power): bool
    {
        return $this->publishAcCommand($roomId, 'POWER', strtoupper($power));
    }
}
