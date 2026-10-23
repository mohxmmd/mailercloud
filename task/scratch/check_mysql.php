<?php

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mailer_cloud', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->query('SELECT User, Host FROM mysql.user');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['User'] . '@' . $row['Host'] . PHP_EOL;
}

echo "Configuring privileges for external / Docker access...\n";
try {
    $pdo->exec("CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY ''");
    $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION");
    $pdo->exec("FLUSH PRIVILEGES");
    echo "Privileges updated successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
