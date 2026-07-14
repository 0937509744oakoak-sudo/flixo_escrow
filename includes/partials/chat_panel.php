<?php
/** @var string $chat_partner */
/** @var array<string, array{preview: string, time: string, initial: string, display_name?: string}> $sidebar_partners */
/** @var array<string, string> $chat_display_names */

$chat_partner = $chat_partner ?? '';
$has_partner = ($chat_partner !== '');
$chat_display_names = $chat_display_names ?? [];
$partner_display = $has_partner
    ? ($chat_display_names[$chat_partner] ?? userDisplayName($conn, $chat_partner))
    : '';
$partner_initial = $has_partner ? strtoupper(mb_substr($partner_display, 0, 1)) : '';
$sidebar_partners = $sidebar_partners ?? [];
$support_username = $support_username ?? (defined('SUPPORT_USERNAME') ? SUPPORT_USERNAME : 'FLIXO_Support');
?>
<div class="chat-page chat-page--embedded<?php echo $has_partner ? ' has-partner' : ''; ?>"
     id="chat-page-root"
     data-support-user="<?php echo htmlspecialchars($support_username, ENT_QUOTES, 'UTF-8'); ?>">
    <aside class="chat-sidebar" aria-label="รายการแชท">
        <div class="chat-sidebar-search">
            <div class="chat-sidebar-search-wrap">
                <span class="material-symbols-outlined">search</span>
                <input type="search" id="chat-sidebar-filter" placeholder="ค้นหาการสนทนา..." autocomplete="off">
            </div>
        </div>
        <div class="chat-sidebar-list" id="chat-sidebar-list">
            <?php if (empty($sidebar_partners)): ?>
                <div class="chat-sidebar-empty">
                    <span class="material-symbols-outlined">chat</span>
                    <p>ยังไม่มีการสนทนา</p>
                </div>
            <?php else: ?>
                <?php foreach ($sidebar_partners as $name => $info): ?>
                <?php $is_support_row = !empty($info['is_support']) || strcasecmp($name, $support_username) === 0; ?>
                <button type="button"
                        class="chat-sidebar-item<?php echo $is_support_row ? ' chat-sidebar-item--support' : ''; ?><?php echo $name === $chat_partner ? ' is-active' : ''; ?>"
                        data-partner="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                        data-display-name="<?php echo htmlspecialchars($info['display_name'] ?? $name, ENT_QUOTES, 'UTF-8'); ?>"
                        data-chat-partner-link>
                    <div class="chat-sidebar-avatar"><?php echo $is_support_row ? '🛡️' : htmlspecialchars($info['initial']); ?></div>
                    <div class="chat-sidebar-info">
                        <div class="chat-sidebar-name"><?php echo $is_support_row ? 'ทีมงาน FLIXO' : htmlspecialchars($info['display_name'] ?? $name); ?></div>
                        <div class="chat-sidebar-preview"><?php echo htmlspecialchars($info['preview']); ?></div>
                    </div>
                    <div class="chat-sidebar-time"><?php echo htmlspecialchars($info['time']); ?></div>
                </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <main class="chat-main">
        <div class="chat-main-bg" aria-hidden="true"></div>

        <div id="chat-inbox-placeholder" class="chat-inbox-placeholder"<?php echo $has_partner ? ' style="display:none;"' : ''; ?>>
            <div class="chat-inbox-placeholder-inner">
                <span class="material-symbols-outlined">forum</span>
                <h2>ห้องคุยคู่สัญญา</h2>
                <p>เลือกการสนทนาจากรายการด้านซ้ายเพื่อคุยกับอีกฝ่าย</p>
                <button type="button" class="chat-inbox-cta" data-chat-partner-link data-partner="<?php echo htmlspecialchars($support_username, ENT_QUOTES, 'UTF-8'); ?>">เปิดแชททีมงาน FLIXO</button>
                <?php if (empty($sidebar_partners) || count($sidebar_partners) <= 1): ?>
                <button type="button" class="chat-inbox-cta chat-inbox-cta--secondary" data-app-tab="home">ไปดูประกาศขายเพื่อเริ่มคุย</button>
                <?php endif; ?>
            </div>
        </div>

        <div id="chat-room-panel" class="chat-room-panel"<?php echo $has_partner ? '' : ' style="display:none;"'; ?>>
            <header class="chat-room-header">
                <div class="chat-room-header-left">
                    <button type="button" class="chat-back-btn" data-chat-back title="กลับรายการแชท">
                        <span class="material-symbols-outlined">arrow_back</span>
                    </button>
                    <div class="chat-partner-avatar" id="chat-partner-avatar">
                        <?php echo htmlspecialchars($partner_initial); ?>
                        <span class="online-dot" aria-hidden="true"></span>
                    </div>
                    <div class="chat-partner-meta">
                        <h2 id="chat-title"><?php echo $has_partner ? (strcasecmp($chat_partner, $support_username) === 0 ? 'ทีมงาน FLIXO' : htmlspecialchars($partner_display)) : ''; ?></h2>
                        <p><?php echo ($has_partner && strcasecmp($chat_partner, $support_username) === 0) ? 'ช่วยเหลือบัญชี • eKYC • ข่าวสาร' : 'คู่สัญญา • พร้อมสนทนา'; ?></p>
                    </div>
                </div>
                <div class="chat-header-actions">
                    <button type="button" class="chat-action-btn chat-action-primary" onclick="toggleShareProductModal()">
                        <span class="material-symbols-outlined">storefront</span>
                        <span>ส่งประกาศ</span>
                    </button>
                    <button type="button" class="chat-action-btn chat-action-secondary" onclick="toggleOfferModal()">
                        <span class="material-symbols-outlined">handshake</span>
                        <span>ตกลงดีลเอง</span>
                    </button>
                </div>
            </header>

            <div id="chat-body" class="chat-messages">
                <div class="chat-loading">
                    <div class="chat-loading-spinner"></div>
                    <p>กำลังโหลดการสนทนา...</p>
                </div>
            </div>

            <footer class="chat-compose">
                <div class="chat-compose-inner">
                    <div class="chat-compose-actions">
                        <button type="button" class="chat-compose-icon-btn" title="แนบไฟล์" onclick="document.getElementById('chat-image-input').click()">
                            <span class="material-symbols-outlined">add_circle</span>
                        </button>
                        <input type="file" id="chat-image-input" style="display:none;" accept="image/*" onchange="sendChatImage(this)">
                    </div>
                    <div class="chat-compose-input-wrap">
                        <input type="text" id="full-chat-input"
                               placeholder="พิมพ์ข้อความ..."
                               autocomplete="off"
                               onkeypress="if(event.key==='Enter') sendMessage()">
                    </div>
                    <button type="button" class="chat-send-btn" onclick="sendMessage()" title="ส่งข้อความ">
                        <span class="material-symbols-outlined">send</span>
                    </button>
                </div>
                <p class="chat-compose-note">เงินถูกเก็บใน Escrow จนกว่าจะได้รับของ — ไม่ต้องโอนให้คนแปลกหน้าโดยตรง</p>
            </footer>
        </div>
    </main>
</div>
