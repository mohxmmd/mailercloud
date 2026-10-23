<?php

require 'vendor/autoload.php';

use longlang\phpkafka\Producer\Producer;
use longlang\phpkafka\Producer\ProducerConfig;

$ip = '172.22.222.199';
echo "Connecting to Kafka on {$ip}:9092...\n";
$config = new ProducerConfig();
$config->setBootstrapServer("{$ip}:9092");
$config->setBrokers(["{$ip}:9092"]);
$config->setUpdateBrokers(false);
$config->setAcks(1);
$config->setConnectTimeout(3.0);
$config->setSendTimeout(3.0);
$config->setRecvTimeout(3.0);

$producer = new Producer($config);
echo "Sending test message...\n";
$producer->send('submission-events', json_encode(['test' => 'hello']), 'test-key');
echo "Message sent successfully!\n";
$producer->close();
echo "Done.\n";
