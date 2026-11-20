<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config.php';

$db = null;
$databaseError = null;
try {
    $db = database();
} catch (Throwable $exception) {
    $databaseError = $exception->getMessage();
}

$result = $_SESSION['last_result'] ?? null;
$notice = $databaseError ? 'Database unavailable. Demo verification works in this session; configure MySQL to keep a shared invoice history.' : null;
$error = null;
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])
    || !preg_match('/^[a-f0-9]{64}$/', $_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalized(string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function validate_document(array $file, string $label): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Select a valid {$label} file.");
    }
    if (($file['size'] ?? 0) < 1 || $file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException("Each document must be smaller than 10 MB.");
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $bytes = file_get_contents((string) $file['tmp_name']);
    if ($bytes === false) {
        throw new RuntimeException("Could not read the {$label} file.");
    }

    $valid = match ($extension) {
        'pdf' => str_starts_with($bytes, '%PDF-'),
        'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
        'jpg', 'jpeg' => str_starts_with($bytes, "\xff\xd8\xff"),
        default => false,
    };
    if (!$valid) {
        throw new RuntimeException("{$label} must be a valid PDF, PNG, or JPEG file.");
    }

    return [
        'path' => (string) $file['tmp_name'],
        'name' => basename((string) $file['name']),
        'extension' => $extension,
        'mime' => match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        },
    ];
}

function demo_documents(string $scenario): array
{
    $po = [
        'supplier_name' => 'Northstar Office Supply',
        'invoice_number' => 'PO-2026-104',
        'invoice_date' => '2026-11-18',
        'currency' => 'USD',
        'items' => [
            ['description' => 'Ergonomic chair', 'quantity' => 10, 'unit_price' => 120.00],
            ['description' => 'Desk lamp', 'quantity' => 5, 'unit_price' => 40.00],
        ],
        'total' => 1400.00,
    ];
    $invoice = [
        'supplier_name' => 'Northstar Office Supply',
        'invoice_number' => $scenario === 'inflated' ? 'INV-2026-002' : 'INV-2026-001',
        'invoice_date' => '2026-11-18',
        'currency' => 'USD',
        'items' => [
            ['description' => 'Ergonomic chair', 'quantity' => 10, 'unit_price' => $scenario === 'inflated' ? 150.00 : 120.00],
            ['description' => 'Desk lamp', 'quantity' => 5, 'unit_price' => 40.00],
        ],
        'total' => $scenario === 'inflated' ? 1700.00 : 1400.00,
    ];
    return [$invoice, $po];
}

function validate_extraction(array $data, string $label): array
{
    foreach (['supplier_name', 'invoice_number', 'invoice_date', 'currency', 'items', 'total'] as $field) {
        if (!array_key_exists($field, $data)) {
            throw new RuntimeException("Gemini extraction for {$label} is missing '{$field}'.");
        }
    }
    if (!is_string($data['supplier_name']) || trim($data['supplier_name']) === ''
        || !is_string($data['invoice_number']) || trim($data['invoice_number']) === ''
        || !is_string($data['currency']) || !preg_match('/^[A-Za-z]{3}$/', $data['currency'])
        || !is_array($data['items']) || !is_numeric($data['total']) || (float) $data['total'] < 0) {
        throw new RuntimeException("Gemini returned invalid structured data for {$label}.");
    }
    $date = date_create_immutable((string) $data['invoice_date']);
    if (!$date) {
        throw new RuntimeException("Gemini returned an invalid date for {$label}.");
    }

    $items = [];
    foreach ($data['items'] as $item) {
        if (!is_array($item) || !isset($item['description'], $item['quantity'], $item['unit_price'])
            || !is_string($item['description']) || !is_numeric($item['quantity'])
            || !is_numeric($item['unit_price']) || (float) $item['quantity'] <= 0
            || (float) $item['unit_price'] < 0) {
            throw new RuntimeException("Gemini returned an invalid line item for {$label}.");
        }
        $items[] = [
            'description' => trim($item['description']),
            'quantity' => (float) $item['quantity'],
            'unit_price' => round((float) $item['unit_price'], 2),
        ];
    }
    if ($items === []) {
        throw new RuntimeException("Gemini could not find line items in the {$label}.");
    }
    return [
        'supplier_name' => trim($data['supplier_name']),
        'invoice_number' => trim($data['invoice_number']),
        'invoice_date' => $date->format('Y-m-d'),
        'currency' => strtoupper($data['currency']),
        'items' => $items,
        'total' => round((float) $data['total'], 2),
    ];
}

function gemini_generate(array $request, string $apiKey): array
{
    $model = env_value('GEMINI_MODEL', 'gemini-2.5-flash');
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $model)) {
        throw new RuntimeException('GEMINI_MODEL contains unsupported characters.');
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent';
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nx-goog-api-key: {$apiKey}",
        'content' => json_encode($request, JSON_THROW_ON_ERROR),
        'timeout' => 90,
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($response === false || $status < 200 || $status >= 300) {
        $detail = is_string($response) ? json_decode($response, true) : null;
        $message = is_array($detail) ? ($detail['error']['message'] ?? $detail['message'] ?? null) : null;
        throw new RuntimeException('Gemini API request failed' . ($status ? " (HTTP {$status})" : '') . ($message ? ": {$message}" : '. Check the API key and network connection.'));
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Gemini returned an unreadable response.');
    }
    return $decoded;
}

function extract_documents(array $invoiceFile, array $poFile): array
{
    $apiKey = env_value('GEMINI_API_KEY');
    if ($apiKey === '') {
        throw new RuntimeException('Set GEMINI_API_KEY to extract uploaded documents. Demo scenarios work without it.');
    }

    $parts = [[
        'text' => 'Extract supplier_name, invoice_number, invoice_date, currency, total, and items (description, quantity, unit_price) from the invoice and purchase order documents. Do not infer missing values: use null for missing scalar values and an empty array for missing items. The invoice is the supplier bill; the purchase order is the buyer order.',
    ]];
    foreach ([['Invoice', $invoiceFile], ['Purchase order', $poFile]] as [$label, $document]) {
        $bytes = file_get_contents($document['path']);
        if ($bytes === false) {
            throw new RuntimeException("Could not read {$label} document.");
        }
        $parts[] = ['text' => $label . ':'];
        $parts[] = ['inlineData' => [
            'mimeType' => $document['mime'],
            'data' => base64_encode($bytes),
        ]];
    }

    $documentSchema = [
        'type' => 'OBJECT',
        'properties' => [
            'supplier_name' => ['type' => 'STRING'],
            'invoice_number' => ['type' => 'STRING'],
            'invoice_date' => ['type' => 'STRING'],
            'currency' => ['type' => 'STRING'],
            'total' => ['type' => 'NUMBER'],
            'items' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'description' => ['type' => 'STRING'],
                        'quantity' => ['type' => 'NUMBER'],
                        'unit_price' => ['type' => 'NUMBER'],
                    ],
                    'required' => ['description', 'quantity', 'unit_price'],
                ],
            ],
        ],
        'required' => ['supplier_name', 'invoice_number', 'invoice_date', 'currency', 'total', 'items'],
    ];
    $response = gemini_generate([
        'systemInstruction' => ['parts' => [[
            'text' => 'You extract invoice data only. Do not assess fraud, legitimacy, or approval. Return the requested JSON structure with exactly the keys invoice and purchase_order.',
        ]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'OBJECT',
                'properties' => [
                    'invoice' => $documentSchema,
                    'purchase_order' => $documentSchema,
                ],
                'required' => ['invoice', 'purchase_order'],
            ],
            'maxOutputTokens' => 2500,
        ],
    ], $apiKey);

    $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
    $data = is_string($text) ? json_decode($text, true) : null;
    if (!is_array($data) || !isset($data['invoice'], $data['purchase_order'])
        || !is_array($data['invoice']) || !is_array($data['purchase_order'])) {
        $blockReason = $response['promptFeedback']['blockReason'] ?? null;
        if (is_string($blockReason)) {
            throw new RuntimeException('Gemini could not process these documents (' . $blockReason . ').');
        }
        throw new RuntimeException('Gemini did not return valid invoice and purchase-order JSON.');
    }
    return [
        validate_extraction($data['invoice'], 'invoice'),
        validate_extraction($data['purchase_order'], 'purchase order'),
    ];
}

function verify_invoice(array $invoice, array $purchaseOrder, bool $isDuplicate): array
{
    $issues = [];
    $risk = 0;
    $addIssue = static function (string $code, string $message, int $points) use (&$issues, &$risk): void {
        $issues[] = ['code' => $code, 'message' => $message];
        $risk += $points;
    };
    if (normalized($invoice['supplier_name']) !== normalized($purchaseOrder['supplier_name'])) {
        $addIssue('SUPPLIER_MISMATCH', 'Supplier on the invoice does not match the purchase order.', 30);
    }
    if ($invoice['currency'] !== $purchaseOrder['currency']) {
        $addIssue('CURRENCY_MISMATCH', 'Invoice and purchase order currencies do not match.', 25);
    }

    $poItems = [];
    foreach ($purchaseOrder['items'] as $item) {
        $poItems[normalized($item['description'])] = $item;
    }
    foreach ($invoice['items'] as $item) {
        $key = normalized($item['description']);
        if (!isset($poItems[$key])) {
            $addIssue('UNMATCHED_ITEM', 'Invoice item "' . $item['description'] . '" is not listed on the purchase order.', 20);
            continue;
        }
        $ordered = $poItems[$key];
        if (abs($item['quantity'] - $ordered['quantity']) > 0.0001) {
            $addIssue('QUANTITY_MISMATCH', 'Quantity mismatch for "' . $item['description'] . '": ordered ' . $ordered['quantity'] . ', invoiced ' . $item['quantity'] . '.', 20);
        }
        if (abs($item['unit_price'] - $ordered['unit_price']) > 0.01) {
            $addIssue('UNIT_PRICE_MISMATCH', 'Unit price mismatch for "' . $item['description'] . '": PO ' . number_format($ordered['unit_price'], 2) . ', invoice ' . number_format($item['unit_price'], 2) . '.', 25);
        }
    }
    $expectedTotal = round(array_sum(array_map(
        static fn(array $item): float => (float) $item['quantity'] * (float) $item['unit_price'],
        $purchaseOrder['items'],
    )), 2);
    $submittedTotal = round((float) $invoice['total'], 2);
    $difference = round($submittedTotal - $expectedTotal, 2);
    if (abs($difference) > 0.01) {
        $addIssue('TOTAL_MISMATCH', 'Invoice total differs from the purchase order total by ' . number_format(abs($difference), 2) . ' ' . $invoice['currency'] . '.', 20);
    }
    if ($isDuplicate) {
        $addIssue('DUPLICATE', 'This supplier invoice number has already been submitted.', 100);
    }

    $status = $isDuplicate ? 'DUPLICATE' : ($issues === [] ? 'APPROVED' : 'FLAGGED');
    return [
        'status' => $status,
        'risk_score' => min(100, $isDuplicate ? 100 : $risk),
        'issues' => $issues,
        'expected_total' => $expectedTotal,
        'submitted_total' => $submittedTotal,
        'difference' => $difference,
    ];
}

function save_invoice(PDO $db, array $invoice, array $combined, array $verification, string $documentHash, string $identityHash, ?array $hedera): int
{
    $statement = $db->prepare(
        'INSERT INTO invoices (supplier_name, invoice_number, invoice_date, amount, currency, document_hash, identity_hash, status, extracted_data, issues, hedera_transaction_id, hedera_topic_id)
         VALUES (:supplier_name, :invoice_number, :invoice_date, :amount, :currency, :document_hash, :identity_hash, :status, :extracted_data, :issues, :hedera_transaction_id, :hedera_topic_id)'
    );
    $statement->execute([
        'supplier_name' => $invoice['supplier_name'],
        'invoice_number' => $invoice['invoice_number'],
        'invoice_date' => $invoice['invoice_date'],
        'amount' => $invoice['total'],
        'currency' => $invoice['currency'],
        'document_hash' => $documentHash,
        'identity_hash' => $identityHash,
        'status' => $verification['status'],
        'extracted_data' => json_encode($combined, JSON_THROW_ON_ERROR),
        'issues' => json_encode($verification['issues'], JSON_THROW_ON_ERROR),
        'hedera_transaction_id' => $hedera['transaction_id'] ?? null,
        'hedera_topic_id' => $hedera['topic_id'] ?? null,
    ]);
    return (int) $db->lastInsertId();
}

function submit_hedera(array $payload): array
{
    $accountId = env_value('HEDERA_ACCOUNT_ID');
    $privateKey = env_value('HEDERA_PRIVATE_KEY');
    $topicId = env_value('HEDERA_TOPIC_ID');
    if ($accountId === '' || $privateKey === '' || $topicId === '') {
        return ['success' => false, 'message' => 'Hedera is not configured. No transaction was submitted.'];
    }
    if (!function_exists('proc_open')) {
        return ['success' => false, 'message' => 'PHP proc_open is disabled; Hedera submission is unavailable.'];
    }
    $command = [env_value('NODE_BINARY', 'node'), __DIR__ . DIRECTORY_SEPARATOR . 'hedera.js'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($process)) {
        return ['success' => false, 'message' => 'Could not start the Hedera helper.'];
    }
    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $response = is_string($stdout) ? json_decode(trim($stdout), true) : null;
    if ($exitCode !== 0 || !is_array($response) || ($response['success'] ?? false) !== true) {
        $message = is_array($response) ? ($response['error'] ?? null) : null;
        $message ??= trim((string) $stderr) ?: 'The Hedera helper exited without confirming a transaction.';
        error_log('TrustInvoice Hedera submission failed: ' . $message);
        return ['success' => false, 'message' => $message];
    }
    return $response;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_history') {
    try {
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid clear-history request. Reload the page and try again.');
        }

        unset($_SESSION['demo_history'], $_SESSION['demo_identities'], $_SESSION['last_result']);
        $result = null;
        if ($db instanceof PDO) {
            try {
                $db->exec('DELETE FROM invoices');
                $notice = 'Verification history cleared.';
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '42S02') {
                    throw $exception;
                }
                $notice = 'This browser\'s demo history was cleared. No invoice table was found.';
            }
        } else {
            $notice = 'This browser\'s demo history was cleared. No shared database is connected.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'clear_history') {
    try {
        $scenario = (string) ($_POST['scenario'] ?? '');
        if (in_array($scenario, ['valid', 'duplicate', 'inflated'], true)) {
            [$invoice, $purchaseOrder] = demo_documents($scenario === 'duplicate' ? 'valid' : $scenario);
            $demo = true;
        } else {
            if (!$db instanceof PDO) {
                throw new RuntimeException('Live verification requires MySQL. Configure the database and import database.sql first; demo scenarios work without it.');
            }
            $invoiceFile = validate_document($_FILES['invoice'] ?? [], 'invoice');
            $poFile = validate_document($_FILES['purchase_order'] ?? [], 'purchase order');
            [$invoice, $purchaseOrder] = extract_documents($invoiceFile, $poFile);
            $demo = false;
        }

        $identityHash = hash('sha256', normalized($invoice['supplier_name']) . '|' . normalized($invoice['invoice_number']));
        $documentHash = $demo
            ? hash('sha256', json_encode([$invoice, $purchaseOrder], JSON_THROW_ON_ERROR))
            : hash_file('sha256', $invoiceFile['path']);
        $duplicate = false;
        if ($scenario === 'duplicate') {
            $duplicate = true;
        } elseif ($db instanceof PDO) {
            $check = $db->prepare('SELECT id FROM invoices WHERE identity_hash = :identity_hash AND status <> \'DUPLICATE\' LIMIT 1');
            $check->execute(['identity_hash' => $identityHash]);
            $duplicate = (bool) $check->fetchColumn();
        } elseif ($demo && isset($_SESSION['demo_identities'][$identityHash])) {
            $duplicate = true;
        }

        $verification = verify_invoice($invoice, $purchaseOrder, $duplicate);
        $recordId = null;
        $hedera = [
            'success' => false,
            'message' => $verification['status'] === 'DUPLICATE'
                ? 'Duplicate submissions are not registered as new Hedera events.'
                : 'No transaction has been submitted.',
        ];
        $combined = ['invoice' => $invoice, 'purchase_order' => $purchaseOrder, 'verification' => $verification];
        if ($db instanceof PDO) {
            $recordId = save_invoice($db, $invoice, $combined, $verification, $documentHash, $identityHash, null);
        } elseif ($demo) {
            $_SESSION['demo_identities'][$identityHash] = true;
        }
        if ($verification['status'] !== 'DUPLICATE') {
            $hedera = submit_hedera([
                'event_id' => bin2hex(random_bytes(12)),
                'document_hash' => $documentHash,
                'identity_hash' => $identityHash,
                'status' => $verification['status'],
                'recorded_at' => gmdate('c'),
            ]);
            if ($hedera['success'] && $db instanceof PDO && $recordId !== null) {
                $update = $db->prepare('UPDATE invoices SET hedera_transaction_id = :transaction_id, hedera_topic_id = :topic_id WHERE id = :id');
                $update->execute([
                    'transaction_id' => $hedera['transaction_id'],
                    'topic_id' => $hedera['topic_id'],
                    'id' => $recordId,
                ]);
            }
        }

        $result = [
            'id' => $recordId,
            'invoice' => $invoice,
            'purchase_order' => $purchaseOrder,
            'verification' => $verification,
            'document_hash' => $documentHash,
            'identity_hash' => $identityHash,
            'hedera' => $hedera,
            'demo' => $demo,
        ];
        $_SESSION['last_result'] = $result;
        $notice = $demo ? 'Demo scenario • synthetic data only' : 'Invoice checked against its purchase order.';
        if (!$db instanceof PDO) {
            $notice .= ' This result is in-memory only because MySQL is not connected.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$history = [];
$counts = ['total' => 0, 'APPROVED' => 0, 'FLAGGED' => 0, 'DUPLICATE' => 0];
if ($db instanceof PDO) {
    try {
        $counts['total'] = (int) $db->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        foreach (['APPROVED', 'FLAGGED', 'DUPLICATE'] as $status) {
            $count = $db->prepare('SELECT COUNT(*) FROM invoices WHERE status = :status');
            $count->execute(['status' => $status]);
            $counts[$status] = (int) $count->fetchColumn();
        }
        $history = $db->query('SELECT id, supplier_name, invoice_number, invoice_date, amount, currency, status, hedera_transaction_id, created_at FROM invoices ORDER BY created_at DESC, id DESC LIMIT 10')->fetchAll();
    } catch (Throwable $exception) {
        $notice = 'Database is connected but not ready. Import database.sql, then reload. ' . $exception->getMessage();
    }
}
if (!$db instanceof PDO && !empty($_SESSION['last_result'])) {
    $counts['total'] = count($_SESSION['demo_history'] ?? []);
    foreach ($_SESSION['demo_history'] ?? [] as $row) {
        $counts[$row['status']]++;
    }
    $history = $_SESSION['demo_history'] ?? [];
}
if (isset($result) && is_array($result) && !isset($result['id']) && $result['demo']) {
    $_SESSION['demo_history'] ??= [];
    if (!isset($_SESSION['demo_history'][0]) || ($_SESSION['demo_history'][0]['invoice_number'] ?? '') !== $result['invoice']['invoice_number'] || ($_SESSION['demo_history'][0]['status'] ?? '') !== $result['verification']['status']) {
        array_unshift($_SESSION['demo_history'], [
            'id' => 'Demo',
            'supplier_name' => $result['invoice']['supplier_name'],
            'invoice_number' => $result['invoice']['invoice_number'],
            'invoice_date' => $result['invoice']['invoice_date'],
            'amount' => $result['invoice']['total'],
            'currency' => $result['invoice']['currency'],
            'status' => $result['verification']['status'],
            'hedera_transaction_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $_SESSION['demo_history'] = array_slice($_SESSION['demo_history'], 0, 10);
    }
    $counts = ['total' => count($_SESSION['demo_history']), 'APPROVED' => 0, 'FLAGGED' => 0, 'DUPLICATE' => 0];
    foreach ($_SESSION['demo_history'] as $row) {
        $counts[$row['status']]++;
    }
    $history = $_SESSION['demo_history'];
}
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, ['dashboard', 'history', 'proof'], true)) {
    $page = 'dashboard';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TrustInvoice — Invoice assurance</title>
    <link rel="stylesheet" href="style.css">
    <script defer src="script.js"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">T</span><span>trust<span class="brand-light">invoice</span><small>INVOICE ASSURANCE</small></span></a>
        <div class="nav-label">WORKSPACE</div>
        <nav class="navigation">
            <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="index.php"><span class="nav-icon">▦</span>Overview</a>
            <a href="#upload"><span class="nav-icon">＋</span>Verify invoice</a>
            <a class="<?= $page === 'history' ? 'active' : '' ?>" href="index.php?page=history"><span class="nav-icon">◷</span>Verification history</a>
            <a class="<?= $page === 'proof' ? 'active' : '' ?>" href="index.php?page=proof#result"><span class="nav-icon">◇</span>Hedera proof</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="network-pill"><span class="pulse-dot"></span> HEDERA TESTNET</div>
            <div class="sidebar-user"><span class="avatar">TI</span><span><b>TrustInvoice</b><small>Hackathon workspace</small></span><span class="more">···</span></div>
        </div>
    </aside>
    <main class="main">
        <header class="topbar"><div class="breadcrumb">Workspace <span>/</span> <?= $page === 'history' ? 'Verification history' : ($page === 'proof' ? 'Hedera proof' : 'Overview') ?></div><div class="topbar-right"><span class="secure-label"><span>●</span> Secure verification</span><span class="top-avatar">TI</span></div></header>
        <div class="content">
            <section class="page-heading">
                <div><div class="eyebrow">INVOICE ASSURANCE PLATFORM</div><h1><?= $page === 'history' ? 'Verification history' : ($page === 'proof' ? 'Hedera proof' : 'Good afternoon') ?></h1><p><?= $page === 'history' ? 'A clear audit trail of every submitted invoice.' : ($page === 'proof' ? 'Tamper-evident proof for your latest verification.' : 'Review your payable risk at a glance.') ?></p></div>
                <a class="button button-dark" href="#upload"><span>＋</span> Verify invoice</a>
            </section>

            <?php if ($notice): ?><div class="notice"><span>ⓘ</span><?= h($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert error"><strong>Verification could not be completed.</strong><span><?= h($error) ?></span></div><?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
            <section class="stat-grid" aria-label="Invoice summary">
                <article class="stat-card"><div class="stat-top"><span>Total processed</span><span class="stat-icon blue">▤</span></div><strong><?= $counts['total'] ?></strong><small>Invoices checked</small></article>
                <article class="stat-card"><div class="stat-top"><span>Approved</span><span class="stat-icon green">✓</span></div><strong><?= $counts['APPROVED'] ?></strong><small>Matched to purchase order</small></article>
                <article class="stat-card"><div class="stat-top"><span>Needs review</span><span class="stat-icon orange">!</span></div><strong><?= $counts['FLAGGED'] ?></strong><small>Discrepancies detected</small></article>
                <article class="stat-card"><div class="stat-top"><span>Duplicates</span><span class="stat-icon red">↗</span></div><strong><?= $counts['DUPLICATE'] ?></strong><small>Previously submitted</small></article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
            <section class="panel upload-panel" id="upload">
                <div class="panel-heading"><div><div class="eyebrow">NEW VERIFICATION</div><h2>Verify an invoice</h2><p>Upload an invoice and its matching purchase order.</p></div><span class="step-badge">01 <span>/ 01</span></span></div>
                <form method="post" enctype="multipart/form-data" id="upload-form" class="upload-form">
                    <div class="file-grid">
                        <label class="file-drop" for="invoice-file"><span class="file-icon invoice-icon">↥</span><strong>Supplier invoice</strong><span>PDF, PNG or JPEG · Max 10 MB</span><span class="file-name" data-for="invoice-file">Choose a file or drop it here</span><input id="invoice-file" type="file" name="invoice" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"></label>
                        <label class="file-drop" for="po-file"><span class="file-icon po-icon">▤</span><strong>Purchase order</strong><span>PDF, PNG or JPEG · Max 10 MB</span><span class="file-name" data-for="po-file">Choose a file or drop it here</span><input id="po-file" type="file" name="purchase_order" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"></label>
                    </div>
                    <div class="form-footer"><span><span class="lock">♢</span> Documents are processed securely and not written to Hedera.</span><button class="button button-dark" type="submit" id="submit-button">Analyze invoice <span>→</span></button></div>
                </form>
            </section>
            <section class="demo-strip"><div><span class="demo-tag">DEMO MODE</span><strong>Try the three scenarios</strong><p>Synthetic sample data. No API credentials or uploaded documents required.</p></div><div class="demo-actions"><form method="post"><button name="scenario" value="valid" class="button button-outline">✓ Valid invoice</button></form><form method="post"><button name="scenario" value="duplicate" class="button button-outline">↗ Duplicate</button></form><form method="post"><button name="scenario" value="inflated" class="button button-outline">! Inflated price</button></form></div></section>
            <?php endif; ?>

            <?php if ($page === 'history' || $page === 'dashboard'): ?>
            <section class="panel history-panel">
                <div class="panel-heading history-heading"><div><div class="eyebrow">AUDIT TRAIL</div><h2><?= $page === 'history' ? 'All verifications' : 'Recent verifications' ?></h2></div><div class="history-actions"><?php if ($page === 'dashboard'): ?><a href="index.php?page=history" class="text-link">View history <span>→</span></a><?php endif; ?><form method="post" onsubmit="return confirm('Clear all verification history? This cannot be undone.');"><input type="hidden" name="action" value="clear_history"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><button class="button button-clear" type="submit">Clear history</button></form></div></div>
                <?php if ($history === []): ?><div class="empty-state"><span class="empty-icon">▤</span><strong>No verifications yet</strong><p>Upload an invoice or try one of the demo scenarios to get started.</p></div>
                <?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>SUPPLIER / INVOICE</th><th>INVOICE DATE</th><th>AMOUNT</th><th>STATUS</th><th>HEDERA PROOF</th></tr></thead><tbody>
                    <?php foreach ($history as $row): ?><tr><td><strong><?= h($row['supplier_name']) ?></strong><small><?= h($row['invoice_number']) ?></small></td><td><?= h($row['invoice_date']) ?></td><td><?= h($row['currency']) ?> <?= number_format((float) $row['amount'], 2) ?></td><td><span class="status status-<?= strtolower(h($row['status'])) ?>"><span></span><?= h($row['status']) ?></span></td><td><?= !empty($row['hedera_transaction_id']) ? '<span class="proof-linked">● On ledger</span>' : '<span class="proof-pending">Not registered</span>' ?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if (is_array($result) && ($page === 'dashboard' || $page === 'proof')): $v = $result['verification']; ?>
            <section class="result-section" id="result">
                <div class="result-banner result-<?= strtolower(h($v['status'])) ?>">
                    <div class="result-check"><?= $v['status'] === 'APPROVED' ? '✓' : ($v['status'] === 'DUPLICATE' ? '↗' : '!') ?></div>
                    <div><div class="eyebrow">VERIFICATION RESULT<?= $result['demo'] ? ' · DEMO' : '' ?></div><h2><?= h($v['status']) ?></h2><p><?= $v['status'] === 'APPROVED' ? 'Invoice details match the purchase order.' : ($v['status'] === 'DUPLICATE' ? 'This invoice identity has already been submitted.' : 'Review the discrepancies before approving payment.') ?></p></div>
                    <div class="risk-score"><strong><?= (int) $v['risk_score'] ?><small>/100</small></strong><span>Risk score</span></div>
                </div>
                <div class="result-grid">
                    <article class="panel detail-panel"><div class="panel-heading"><div><div class="eyebrow">FINANCIAL CHECK</div><h2>Invoice comparison</h2></div></div>
                        <div class="money-rows"><div><span>Purchase order total</span><strong><?= h($result['invoice']['currency']) ?> <?= number_format($v['expected_total'], 2) ?></strong></div><div><span>Submitted invoice total</span><strong><?= h($result['invoice']['currency']) ?> <?= number_format($v['submitted_total'], 2) ?></strong></div><div class="difference-row"><span>Difference</span><strong class="<?= $v['difference'] > .01 ? 'text-danger' : 'text-good' ?>"><?= $v['difference'] > 0 ? '+' : '' ?><?= h($result['invoice']['currency']) ?> <?= number_format($v['difference'], 2) ?></strong></div></div>
                        <?php if ($v['issues']): ?><div class="issues-list"><h3>Detected issues</h3><?php foreach ($v['issues'] as $issue): ?><div class="issue-row"><span>!</span><?= h($issue['message']) ?></div><?php endforeach; ?></div><?php else: ?><div class="success-note">✓ No discrepancies detected</div><?php endif; ?>
                    </article>
                    <article class="panel proof-panel"><div class="panel-heading"><div><div class="eyebrow">TAMPER-EVIDENT RECORD</div><h2>Hedera proof</h2></div><span class="network-small"><i></i> TESTNET</span></div>
                        <?php if ($result['hedera']['success'] ?? false): ?><div class="proof-success"><span>✓</span> Verified on Hedera</div><dl class="proof-data"><dt>Transaction ID</dt><dd class="mono"><?= h($result['hedera']['transaction_id']) ?></dd><dt>Consensus timestamp</dt><dd><?= h($result['hedera']['consensus_timestamp'] ?? 'Confirmed') ?></dd><dt>Topic ID</dt><dd class="mono"><?= h($result['hedera']['topic_id']) ?></dd></dl><a class="explorer-link" target="_blank" rel="noopener noreferrer" href="<?= h($result['hedera']['explorer_url'] ?? '#') ?>">View in HashScan <span>↗</span></a>
                        <?php else: ?><div class="proof-pending-box"><span class="pending-icon">◇</span><strong>No Hedera transaction recorded</strong><p><?= h($result['hedera']['message'] ?? 'Hedera is not configured.') ?></p><small>Fingerprints are shown for reference only; a proof is never claimed unless HCS confirms it.</small></div><?php endif; ?>
                        <div class="fingerprints"><div><span>Invoice file · SHA-256</span><code><?= h($result['document_hash']) ?></code></div><div><span>Invoice identity · SHA-256</span><code><?= h($result['identity_hash']) ?></code></div></div>
                    </article>
                </div>
                <div class="privacy-note"><span>ⓘ</span> Only invoice fingerprints, the status, and non-sensitive event metadata are submitted to Hedera. Invoice content is never sent to the ledger.</div>
            </section>
            <?php endif; ?>
            <footer class="footer"><span>TrustInvoice <span>·</span> Built for the Hedera Cross Campus Challenge 2026</span><span>DEMO APPLICATION</span></footer>
        </div>
    </main>
</div>
</body>
</html>
