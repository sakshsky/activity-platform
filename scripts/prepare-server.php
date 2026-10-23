<?php

require __DIR__.'/../server/vendor/autoload.php';

$server = realpath(__DIR__.'/../server');
$environment = $server.'/.env';
if (! is_file($environment) && ! copy($server.'/.env.example', $environment)) {
    throw new RuntimeException('Cannot create server/.env.');
}
$settings = Dotenv\Dotenv::parse(file_get_contents($environment));
if (trim($settings['APP_KEY'] ?? '') !== '' || getenv('APP_KEY')) {
    echo "Existing application key preserved.\n";
    exit(0);
}
$process = new Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'key:generate'], $server);
$process->setTimeout(60);
exit($process->run(function (string $type, string $buffer): void { echo $buffer; }));
