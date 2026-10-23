<?php

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mailer_cloud', 'root', '');
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='mysql' AND engine='Aria'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    try {
        $res = $pdo->query("REPAIR TABLE mysql.`$t` EXTENDED")->fetchAll(PDO::FETCH_ASSOC);
        echo "Repaired $t: " . json_encode($res) . "\n";
    } catch (Exception $e) {
        echo "Could not repair $t: " . $e->getMessage() . "\n";
    }
}
