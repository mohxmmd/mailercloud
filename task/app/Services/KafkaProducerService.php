<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use longlang\phpkafka\Producer\Producer;
use longlang\phpkafka\Producer\ProducerConfig;

class KafkaProducerService
{
    protected ?Producer $producer = null;
    protected string $broker;

    public function __construct()
    {
        $this->broker = config('services.kafka.brokers', env('KAFKA_BROKERS', '127.0.0.1:9092'));
    }

    /**
     * Get or initialize the Kafka producer instance.
     */
    public function getProducer(): Producer
    {
        if ($this->producer === null) {
            $config = new ProducerConfig();
            $config->setBootstrapServer($this->broker);
            $config->setUpdateBrokers(true);
            $config->setAcks(1);
            $config->setConnectTimeout(5.0);
            $config->setSendTimeout(5.0);
            $config->setRecvTimeout(5.0);
            $this->producer = new Producer($config);
        }

        return $this->producer;
    }

    /**
     * Send message to Kafka topic synchronously.
     */
    public function send(string $topic, string $value, ?string $key = null): void
    {
        $producer = $this->getProducer();
        $producer->send($topic, $value, $key);
    }

    /**
     * Close producer connection.
     */
    public function close(): void
    {
        if ($this->producer !== null) {
            try {
                $this->producer->close();
            } catch (Exception $e) {
                Log::warning('Error closing Kafka producer: ' . $e->getMessage());
            }
            $this->producer = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
