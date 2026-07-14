<?php
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/DisputeFlowService.php';

$flow = new DisputeFlowService($conn);
$id = (int)($argv[1] ?? 2);
$d = $flow->syncAndGet($id);
$phase = $flow->getPhaseInfo($d);
$judge = $flow->canAdminJudge($d, false);

echo "Dispute #{$id}\n";
echo "Status: {$d['status']}\n";
echo "Phase: {$phase['label']}\n";
echo "Seller due: " . ($d['seller_response_due_at'] ?? '-') . "\n";
echo "Evidence due: " . ($d['evidence_due_at'] ?? '-') . "\n";
echo "Admin can judge: " . ($judge['ok'] ? 'yes' : 'no — ' . $judge['reason']) . "\n";
