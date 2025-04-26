<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once 'ScanQueueClient.php';

header('Content-Type: text/html; charset=utf-8');

function generateCSRFToken(): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

function verifyCSRFToken(string $token): bool {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    unset($_SESSION['csrf_token']); // Single use
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cidr = trim($_POST['cidr'] ?? null);
    $portsParam = trim($_POST['ports'] ?? null);
    $csrf = $_POST['csrf'] ?? '';

    header('Content-Type: application/json');

    if (!$cidr || !$portsParam || !$csrf) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters or CSRF token']);
        exit;
    }

    if (!verifyCSRFToken($csrf)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or expired CSRF token']);
        exit;
    }

    $ports = array_map('trim', explode(',', $portsParam));

    try {
        $client = new ScanQueueClient(
            getenv('AMQP_URL') ?: 'amqp://guest:guest@localhost:5672/',
            getenv('AMQP_TASKS_QUEUE') ?: 'scanner_tasks',
            false // true = limit to /32
        );
        // $client->addTask($cidr, $ports);
        echo json_encode(['status' => 'success', 'portRanges' => $ports, 'task' => json_decode($client->addTask($cidr, $ports))]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// === GET request ===
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Submit Scan Task</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <style>
        body {
            background: #111;
            color: #7fff7f;
            font-family: monospace;
            padding: 1em;
            filter: contrast(1.2) brightness(1.1) saturate(1.2);
            position: relative;
            overflow-x: hidden;
            max-width:500px;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 99vw;
            height: 99vh;
            z-index: 9998;
            pointer-events: none;
            background: repeating-linear-gradient(
                0deg,
                rgba(0, 255, 0, 0.05),
                rgba(0, 255, 0, 0.05) 0.15px,
                transparent 1px,
                transparent 3px
            );
            mix-blend-mode: overlay;
            animation: flicker 0.15s infinite;
        }

        @keyframes flicker {
            0%   { opacity: 0.95; }
            50%  { opacity: 1; }
            100% { opacity: 0.97; }
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 1em;
        }

        input[type="text"] {
            background: #222;
            color: #7fff7f;
            border: 1px solid #555;
            padding: 5px;
        }

        button {
            background: #333;
            color: #7fff7f;
            border: 1px solid #555;
            padding: 5px 10px;
        }
        label{
            display: grid;
            gap: 3px;
        }

        .finna{
            background: #fff;
            color: #000;
        }
        a {
            color: #7fcfff;
            text-decoration: none;
        }
    </style>
</head>
    <body>
    <?php
    include 'inc/top.php';
    ?>
    <h2>Submit a Scan Task</h2>
    <form method="POST" action="">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
        <label>
            CIDR:
            <input type="text" name="cidr" placeholder="e.g. 1.2.3.4/32" required>
        </label>
        <label>
            Ports:
            <input type="text" name="ports" placeholder="e.g. 80-80,443-443" required>
            (required for now, stick to one port)
        </label>
        <button type="submit">Queue Task</button>
    </form>
    <hr>
    <p>CSRF token is single-use and refreshes on reload.</p>
    <?php
    include 'inc/footer.php';
    ?>
</body>
</html>