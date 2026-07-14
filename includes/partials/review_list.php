<?php
/**
 * Reusable review list partial.
 *
 * Expects:
 *   $reviews — list of review rows (with reviewer_display_name optional)
 *   $empty_message — string (optional)
 *   $compact — bool (optional)
 */
if (!class_exists('Review')) {
    require_once __DIR__ . '/../../models/Review.php';
}
if (empty($reviews) || !is_array($reviews)) {
    if (!empty($empty_message)): ?>
        <p class="review-list-empty tx-meta"><?php echo htmlspecialchars($empty_message); ?></p>
    <?php endif;
    return;
}

$compact = !empty($compact);
?>
<div class="review-list<?php echo $compact ? ' review-list--compact' : ''; ?>">
    <?php foreach ($reviews as $review):
        $rating = (int) ($review['rating'] ?? 0);
        $reviewerLabel = $review['reviewer_display_name'] ?? ($review['reviewer_name'] ?? 'ผู้ซื้อ');
        $createdAt = $review['created_at'] ?? '';
        $dateLabel = $createdAt !== '' ? date('d/m/Y', strtotime($createdAt)) : '';
    ?>
    <article class="review-item">
        <div class="review-item-head">
            <div class="review-item-avatar" aria-hidden="true"><?php echo strtoupper(mb_substr($reviewerLabel, 0, 1)); ?></div>
            <div class="review-item-meta">
                <strong class="review-item-name"><?php echo htmlspecialchars($reviewerLabel); ?></strong>
                <?php if ($dateLabel !== ''): ?>
                    <span class="review-item-date tx-meta"><?php echo htmlspecialchars($dateLabel); ?></span>
                <?php endif; ?>
            </div>
            <span class="review-item-stars" title="<?php echo $rating; ?> จาก 5 ดาว"><?php echo Review::starsLabel($rating); ?></span>
        </div>
        <p class="review-item-comment"><?php echo nl2br(htmlspecialchars($review['comment'] ?? '')); ?></p>
    </article>
    <?php endforeach; ?>
</div>
