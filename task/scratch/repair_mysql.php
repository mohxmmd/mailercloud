<?php

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mailer_cloud', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("GRANT ALL PRIVILEGES ON mailer_cloud.* TO 'flink'@'%' IDENTIFIED BY 'flinkpassword'");
$pdo->exec("GRANT ALL PRIVILEGES ON mailer_cloud.* TO 'root'@'%' IDENTIFIED BY ''");
$pdo->exec("FLUSH PRIVILEGES");
echo "ALL USERS GRANTED OK!\n";
