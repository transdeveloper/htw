<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ScanQueueClient
{
    private $amqpUrl;
    private $queueName;
    private $allowOnly32;

    public function __construct($amqpUrl = 'amqp://guest:guest@localhost:5672/', $queueName = 'scanner_tasks', $allowOnly32 = false)
    {
        $this->amqpUrl = $amqpUrl;
        $this->queueName = $queueName;
        $this->allowOnly32 = $allowOnly32;
    }

    private function isRestrictedIP(string $ip): bool
    {
        $restricted = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '224.0.0.0/4',
            '240.0.0.0/4',
            '100.64.0.0/10',
            '192.0.2.0/24',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '192.0.0.0/24',
            '0.0.0.0/8'
        ];

        foreach ($restricted as $cidr) {
            if ($this->ipInCIDR($ip, $cidr)) return true;
        }

        return false;
    }

    public function addTask(string $cidr, array $portRanges): String
    {
        if ($this->isRestrictedIP(explode('/', $cidr)[0])) {
            throw new InvalidArgumentException("The CIDR range " . explode('/', $cidr)[0] . " is a restricted IP block (private, loopback, or reserved), and cannot be used.");
        }

        if (!$this->isValidCIDR($cidr)) {
            if ($this->allowOnly32) {
                throw new InvalidArgumentException("Invalid CIDR format or not allowed (only /32 is permitted)");
            } else {
                throw new InvalidArgumentException("Invalid CIDR format or not allowed (only /16 to /32 are permitted)");
            }
        }

        $validRanges = $this->validatePortRanges($portRanges);

        $parsedUrl = parse_url($this->amqpUrl);
        $connection = new AMQPStreamConnection(
            $parsedUrl['host'],
            $parsedUrl['port'] ?? 5672,
            $parsedUrl['user'] ?? 'guest',
            $parsedUrl['pass'] ?? 'guest',
            urldecode($parsedUrl['path'] ?? '/') // decode %2F to /
            // trim($parsedUrl['path'] ?? '/', '/')
        );

        $channel = $connection->channel();
        $channel->queue_declare($this->queueName, false, true, false, false);

        $task = json_encode([
            'cidr' => $cidr,
            'portRanges' => $validRanges,
            'timestamp' => date('c'),
        ], JSON_UNESCAPED_SLASHES);

        $msg = new AMQPMessage($task, ['delivery_mode' => 2, 'content_type' => 'application/json']);
        $channel->basic_publish($msg, '', $this->queueName);

        // echo "Task added:\n$task\n";

        $channel->close();
        $connection->close();
        return $task;
    }

    private function isValidCIDR(string $cidr): bool
    {
        if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $cidr)) {
            return false;
        }
    
        [$ip, $mask] = explode('/', $cidr);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
    
        $mask = (int)$mask;
    
        if ($this->allowOnly32) {
            return $mask === 32;
        }
    
        // Default: allow only /16 to /32
        return $mask >= 16 && $mask <= 32;
    }

    private function ipInCIDR(string $ip, string $cidr): bool
    {
        list($cidrIp, $netmask) = explode('/', $cidr);
        $netmask = (int) $netmask;
    
        // Convert IP address to binary format
        $ipBinary = ip2long($ip);
        $cidrIpBinary = ip2long($cidrIp);
    
        if ($ipBinary === false || $cidrIpBinary === false) {
            return false; // Invalid IP format
        }
    
        // Mask the IP with the netmask and compare
        $mask = -1 << (32 - $netmask);
        $cidrIpBinary &= $mask;
        $ipBinary &= $mask;
    
        return $cidrIpBinary === $ipBinary;
    }

    private function validatePortRanges(array $ranges): array
    {
        foreach ($ranges as $range) {
            if (!preg_match('/^\d{1,5}-\d{1,5}$/', $range)) {
                throw new InvalidArgumentException("Invalid port range: $range");
            }

            [$start, $end] = array_map('intval', explode('-', $range));
            if ($start < 0 || $start > 65535 || $end < 0 || $end > 65535 || $start > $end) {
                throw new InvalidArgumentException("Invalid port values in range: $range");
            }
        }

        return $ranges;
    }
}