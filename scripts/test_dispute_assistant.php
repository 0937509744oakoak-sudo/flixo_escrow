<?php
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/DisputeAssistantService.php';

echo "Dispute AI enabled: " . (DisputeAssistantService::isEnabled() ? 'yes' : 'no') . "\n";
echo "OpenRouter configured: " . (DisputeAssistantService::isOpenRouterConfigured() ? 'yes' : 'no') . "\n";
echo "AI configured: " . (DisputeAssistantService::isAiConfigured() ? 'yes' : 'no') . "\n";
echo "Model: " . (defined('DISPUTE_AI_MODEL') ? DISPUTE_AI_MODEL : 'n/a') . "\n\n";

$disputeId = (int)($argv[1] ?? 0);
if ($disputeId <= 0) {
    $row = $conn->query("SELECT id FROM disputes WHERE status IN ('SELLER_RESPONSE','EVIDENCE_REVIEW','SETTLEMENT_PENDING','PENDING') ORDER BY created_at DESC LIMIT 1");
    $disputeId = (int)($row->fetch_assoc()['id'] ?? 0);
}

if ($disputeId <= 0) {
    echo "No open dispute found. Usage: php scripts/test_dispute_assistant.php [dispute_id]\n";
    exit(1);
}

echo "Analyzing dispute #{$disputeId}...\n\n";

$service = new DisputeAssistantService($conn);
$result = $service->analyze($disputeId, 'cli_test', true);

if (($result['status'] ?? '') === 'error') {
    echo "ERROR: " . ($result['error_message'] ?? 'unknown') . "\n";
    exit(1);
}

echo "Provider: {$result['provider']}\n";
echo "Summary: {$result['summary']}\n";
echo "Recommended: {$result['recommended_action']}\n";
echo "Fault: buyer {$result['buyer_fault_pct']}% / seller {$result['seller_fault_pct']}%\n";
echo "Confidence: {$result['confidence']}%\n";
echo "Suggested refund: ฿" . number_format($result['suggested_buyer_refund'], 2) . "\n";
echo "Suggested payout: ฿" . number_format($result['suggested_seller_payout'], 2) . "\n";
if (!empty($result['key_findings'])) {
    echo "Findings:\n";
    foreach ($result['key_findings'] as $f) {
        echo "  - {$f}\n";
    }
}
echo "\nAnalysis ID: " . ($result['analysis_id'] ?? 0) . "\n";
