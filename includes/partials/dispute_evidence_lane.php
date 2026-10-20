<?php
/** @var string $laneTitle */
/** @var string $laneSubtitle */
/** @var string $laneRole buyer|seller */
/** @var array<int, array<string, mixed>> $laneItems */
/** @var bool $showUpload */
/** @var bool $showWaitHint */
/** @var string $waitHint */
/** @var string $csrf_token */
?>
<div class="dispute-evidence-lane dispute-evidence-lane--<?php echo safeText($laneRole); ?>">
    <div class="dispute-evidence-lane-head">
        <span class="dispute-evidence-lane-badge"><?php echo $laneRole === 'buyer' ? '🛒' : '📦'; ?></span>
        <div>
            <h3><?php echo safeText($laneTitle); ?></h3>
            <p><?php echo safeText($laneSubtitle); ?></p>
        </div>
        <span class="dispute-evidence-lane-count"><?php echo count($laneItems); ?> รายการ</span>
    </div>

    <?php if ($laneItems): ?>
        <div class="dispute-evidence-grid">
            <?php foreach ($laneItems as $evidence):
                $ext = strtolower(pathinfo($evidence['file_path'], PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                $fileUrl = safeText(disputeEvidenceUrl((int) $evidence['id']));
            ?>
                <a href="<?php echo $fileUrl; ?>" target="_blank" rel="noopener" class="dispute-evidence-item">
                    <div class="dispute-evidence-thumb">
                        <?php if ($isImage): ?>
                            <img src="<?php echo $fileUrl; ?>" alt="หลักฐาน">
                        <?php elseif ($ext === 'pdf'): ?>
                            <span class="file-icon">📄</span>
                        <?php else: ?>
                            <span class="file-icon">📎</span>
                        <?php endif; ?>
                    </div>
                    <div class="dispute-evidence-meta">
                        <?php if (!empty($evidence['description'])): ?>
                            <strong><?php echo safeText(mb_strimwidth($evidence['description'], 0, 48, '…')); ?></strong>
                        <?php endif; ?>
                        <small><?php echo date('d/m/Y H:i', strtotime($evidence['created_at'])); ?></small>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dispute-evidence-lane-empty">
            <span class="material-symbols-outlined">folder_open</span>
            ยังไม่มีหลักฐานฝั่งนี้
        </div>
    <?php endif; ?>

    <?php if ($showUpload): ?>
        <form method="POST" enctype="multipart/form-data" class="dispute-form dispute-evidence-upload">
            <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
            <label class="dispute-form-label">อัปโหลดหลักฐานของคุณ</label>
            <div class="dispute-file-wrap">
                <input type="file" name="evidence_file" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" required>
            </div>
            <textarea name="evidence_description" class="dispute-textarea" rows="2" placeholder="<?php echo $laneRole === 'buyer' ? 'เช่น รูปสินค้าที่ได้รับ, สินค้าชำรุด...' : 'เช่น รูปหลักฐานจัดส่ง, ใบขนส่ง, ภาพแพ็กสินค้...'; ?>"></textarea>
            <button type="submit" class="dispute-btn-submit">
                <span class="material-symbols-outlined" style="font-size:18px;">upload</span>
                อัปโหลด
            </button>
        </form>
    <?php elseif ($showWaitHint): ?>
        <p class="dispute-form-hint"><?php echo safeText($waitHint); ?></p>
    <?php endif; ?>
</div>
