<?php

require 'vendor/autoload.php';

$ref = new ReflectionMethod(\longlang\phpkafka\Producer\Producer::class, 'send');
foreach ($ref->getParameters() as $p) {
    echo $p->getName() . ' ';
}
echo "\n";
