<?php
/** @var array $latestAiAnalysis */
/** @var float $order amount from parent scope */
if (empty($latestAiAnalysis)) {
    return;
}
$action = $latestAiAnalysis['recommended_action'] ?? 'NEEDS_REVIEW';
$buyerPct = (int)($latestAiAnalysis['buyer_fault_pct'] ?? 50);
$sellerPct = (int)($latestAiAnalysis['seller_fault_pct'] ?? 50);
?>
<div class="ai-result">
    <p><strong><?php echo safeText($latestAiAnalysis['summary'] ?? ''); ?></strong></p>
    <span class="ai-badge <?php echo DisputeAssistantService::actionBadgeClass($action); ?>">
        แนะนำ: <?php echo safeText(DisputeAssistantService::actionLabel($action)); ?>
    </span>
    <span class="ai-confidence">
        ความมั่นใจ <?php echo (int)($latestAiAnalysis['confidence'] ?? 0); ?>%
        · <?php
            $provider = $latestAiAnalysis['provider'] ?? '';
            echo $provider === 'groq' ? 'Groq AI'
                : ($provider === 'openrouter' ? 'OpenRouter AI'
                : ($provider === 'gemini' ? 'Gemini AI' : 'Heuristic'));
        ?>
        · <?php echo date('d/m/Y H:i', strtotime($latestAiAnalysis['created_at'] ?? 'now')); ?>
    </span>

    <div class="ai-fault-bar">
        <div class="ai-fault-buyer" style="width:<?php echo $buyerPct; ?>%" title="ผู้ซื้อ <?php echo $buyerPct; ?>%"></div>
        <div class="ai-fault-seller" style="width:<?php echo $sellerPct; ?>%" title="ผู้ขาย <?php echo $sellerPct; ?>%"></div>
    </div>
    <small>น้ำหนักความผิด: ผู้ซื้อ <?php echo $buyerPct; ?>% / ผู้ขาย <?php echo $sellerPct; ?>%</small>

    <?php if (($latestAiAnalysis['suggested_buyer_refund'] ?? 0) > 0 || ($latestAiAnalysis['suggested_seller_payout'] ?? 0) > 0): ?>
        <p style="margin-top:8px;font-size:13px;">
            💰 แนะนำ: คืนผู้ซื้อ ฿<?php echo number_format((float)$latestAiAnalysis['suggested_buyer_refund'], 2); ?>
            / ให้ผู้ขาย ฿<?php echo number_format((float)$latestAiAnalysis['suggested_seller_payout'], 2); ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($latestAiAnalysis['reasoning'])): ?>
        <p style="font-size:13px;color:#475569;margin-top:8px;"><?php echo safeText($latestAiAnalysis['reasoning']); ?></p>
    <?php endif; ?>

    <?php if (!empty($latestAiAnalysis['key_findings'])): ?>
        <div style="margin-top:12px;">
            <strong>ประเด็นสำคัญ:</strong>
            <?php foreach ($latestAiAnalysis['key_findings'] as $finding): ?>
                <div class="ai-finding">• <?php echo safeText($finding); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="dispute-ai-actions">
        <?php if ($action === DisputeAssistantService::ACTION_REFUND): ?>
            <button type="button" class="dispute-admin-btn refund" style="width:100%;flex-direction:row;justify-content:center;gap:8px;" onclick="judgeDispute(<?php echo (int)$dispute['order_id']; ?>, 'refund_buyer')">
                <span class="material-symbols-outlined">check</span> ใช้คำแนะนำ: คืนผู้ซื้อ
            </button>
        <?php elseif ($action === DisputeAssistantService::ACTION_PAYOUT): ?>
            <button type="button" class="dispute-admin-btn payout" style="width:100%;flex-direction:row;justify-content:center;gap:8px;" onclick="judgeDispute(<?php echo (int)$dispute['order_id']; ?>, 'payout_seller')">
                <span class="material-symbols-outlined">check</span> ใช้คำแนะนำ: ให้ผู้ขาย
            </button>
        <?php elseif ($action === DisputeAssistantService::ACTION_SPLIT): ?>
            <button type="button" class="dispute-admin-btn split" style="width:100%;flex-direction:row;justify-content:center;gap:8px;" onclick="applyAiSplit(<?php echo (float)$latestAiAnalysis['suggested_buyer_refund']; ?>, <?php echo (float)$latestAiAnalysis['suggested_seller_payout']; ?>)">
                <span class="material-symbols-outlined">check</span> ใช้คำแนะนำ: แบ่งเงิน
            </button>
        <?php endif; ?>
    </div>
</div>
