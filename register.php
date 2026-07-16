<?php
require_once 'includes/init.php';
require_once 'includes/legal.php';
require_once 'models/User.php';
require_once 'models/Consent.php';

$csrf_token = getCsrfToken();
$redirectAfter = trim($_GET['redirect'] ?? $_POST['redirect'] ?? '');
if ($redirectAfter !== '' && (str_contains($redirectAfter, '://') || str_starts_with($redirectAfter, '//'))) {
    $redirectAfter = '';
}

if (!empty($_SESSION['user'])) {
    redirectTo($redirectAfter !== '' ? $redirectAfter : 'dashboard.php');
}

if (isPostRequest()) {
    verifyCsrfToken($_POST['csrf_token'] ?? '', 'html');

    $username = sanitizeUsername(getPostValue('username'));
    $password = $_POST['password'] ?? '';
    $email = filter_var(getPostValue('email'), FILTER_VALIDATE_EMAIL) ?: '';
    $real_name = trim(getPostValue('real_name'));
    $phone = sanitizePhone(getPostValue('phone'));

    if (empty($real_name)) {
        $error = "กรุณากรอกชื่อที่แสดงต่อผู้อื่น";
    } elseif (empty($email)) {
        $error = "กรุณากรอกอีเมลให้ถูกต้อง";
    } elseif (empty($password) || mb_strlen($password) < 6) {
        $error = "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร";
    } elseif (empty($username) || mb_strlen($username) < 3) {
        // Auto username from email if left blank-ish
        $base = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0] ?? '');
        $username = sanitizeUsername($base !== '' ? $base : ('user' . substr(bin2hex(random_bytes(3)), 0, 6)));
        if (mb_strlen($username) < 3) {
            $error = "กรุณากรอกรหัสเข้าสู่ระบบ (3-32 ตัวอักษร)";
        }
    }

    if (!isset($error) && (empty($_POST['consent_terms']) || empty($_POST['consent_privacy']))) {
        $error = "กรุณายอมรับข้อกำหนดการใช้งานและนโยบายความเป็นส่วนตัว";
    }

    if (!isset($error)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userModel = new User($conn);

        if ($userModel->findByEmail($email)) {
            $error = "อีเมลนี้ถูกใช้งานแล้ว";
        } else {
            $baseUser = $username;
            $tries = 0;
            while ($userModel->findByUsername($username) && $tries < 8) {
                $username = sanitizeUsername($baseUser . substr(bin2hex(random_bytes(2)), 0, 4));
                $tries++;
            }
            if ($userModel->findByUsername($username)) {
                $error = "รหัสเข้าสู่ระบบนี้ถูกใช้แล้ว กรุณาเลือกชื่ออื่น";
            } else {
                $userId = $userModel->create($username, $passwordHash, $email, $real_name, $phone);
                if ($userId) {
                    $consentModel = new Consent($conn);
                    $consentModel->record($userId, 'terms_of_service', TERMS_VERSION);
                    $consentModel->record($userId, 'privacy_policy', PRIVACY_VERSION);

                    session_regenerate_id(true);
                    $_SESSION['user'] = $username;
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['role'] = 'user';

                    redirectTo($redirectAfter !== '' ? $redirectAfter : 'dashboard.php');
                } else {
                    $error = "เกิดข้อผิดพลาดทางเทคนิค กรุณาลองใหม่";
                }
            }
        }
    }
}

$page_title = "สมัครสมาชิก";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>FLIXO - สมัครสมาชิกใหม่</title>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "tertiary-fixed-dim": "#4edea3",
                        "tertiary-container": "#008259",
                        "on-secondary": "#ffffff",
                        "error": "#ba1a1a",
                        "on-tertiary": "#ffffff",
                        "on-primary-fixed-variant": "#003fa4",
                        "on-primary-container": "#f8f7ff",
                        "surface-tint": "#0054d6",
                        "surface-dim": "#cbdbf5",
                        "on-error-container": "#93000a",
                        "on-surface": "#0b1c30",
                        "inverse-surface": "#213145",
                        "background": "#f8f9ff",
                        "on-secondary-fixed": "#131b2e",
                        "on-primary": "#ffffff",
                        "on-error": "#ffffff",
                        "surface": "#f8f9ff",
                        "on-background": "#0b1c30",
                        "surface-variant": "#d3e4fe",
                        "primary-fixed": "#dae1ff",
                        "on-primary-fixed": "#001849",
                        "error-container": "#ffdad6",
                        "on-tertiary-fixed-variant": "#005236",
                        "inverse-primary": "#b3c5ff",
                        "surface-container-highest": "#d3e4fe",
                        "on-secondary-container": "#5c647a",
                        "surface-container-high": "#dce9ff",
                        "tertiary": "#006645",
                        "surface-container-lowest": "#ffffff",
                        "secondary-fixed": "#dae2fd",
                        "secondary-container": "#dae2fd",
                        "on-surface-variant": "#5c647a", 
                        "on-tertiary-container": "#e1ffec",
                        "surface-bright": "#f8f9ff",
                        "secondary-fixed-dim": "#bec6e0",
                        "outline": "#a2a7b8", 
                        "secondary": "#565e74",
                        "tertiary-fixed": "#6ffbbe",
                        "outline-variant": "#e2e5f1", 
                        "primary": "#0050cb",
                        "inverse-on-surface": "#eaf1ff",
                        "surface-container": "#e5eeff",
                        "primary-fixed-dim": "#b3c5ff",
                        "on-secondary-fixed-variant": "#3f465c",
                        "on-tertiary-fixed": "#002113",
                        "primary-container": "#0066ff",
                        "surface-container-low": "#eff4ff"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.125rem",
                        "lg": "0.5rem",
                        "xl": "1rem",
                        "2xl": "1.5rem",
                        "3xl": "2rem",
                        "full": "9999px"
                    },
                    "spacing": {
                        "xs": "4px",
                        "gutter": "24px",
                        "container-max": "1440px",
                        "md": "16px",
                        "margin-mobile": "16px",
                        "lg": "24px",
                        "unit": "4px",
                        "sm": "8px",
                        "xl": "32px",
                        "2xl": "48px"
                    },
                    "fontFamily": {
                        "headline-md": ["Hanken Grotesk"],
                        "label-md": ["Inter"],
                        "display-lg": ["Hanken Grotesk"],
                        "headline-lg-mobile": ["Hanken Grotesk"],
                        "body-lg": ["Inter"],
                        "headline-lg": ["Hanken Grotesk"],
                        "title-lg": ["Inter"],
                        "numeric-data": ["Inter"],
                        "body-md": ["Inter"]
                    }
                },
            },
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9ff; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        /* Custom Mesh Gradient Background */
        .bg-mesh {
            background-color: #f8f9ff;
            background-image: 
                radial-gradient(at 0% 0%, hsla(217, 100%, 95%, 1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(220, 100%, 90%, 0.5) 0, transparent 50%), 
                radial-gradient(at 100% 0%, hsla(210, 100%, 95%, 1) 0, transparent 50%), 
                radial-gradient(at 0% 100%, hsla(215, 100%, 94%, 1) 0, transparent 50%), 
                radial-gradient(at 100% 100%, hsla(220, 100%, 92%, 1) 0, transparent 50%);
        }
        .soft-float {
            box-shadow: 
                0 4px 6px -1px rgba(0, 84, 214, 0.05), 
                0 10px 15px -3px rgba(0, 84, 214, 0.03),
                0 20px 25px -5px rgba(0, 84, 214, 0.02),
                0 30px 50px -10px rgba(0, 84, 214, 0.05);
        }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="min-h-screen flex flex-col bg-mesh relative overflow-x-hidden">

<div class="absolute top-[-10%] left-[-5%] w-[400px] h-[400px] bg-primary/5 rounded-full blur-[100px] pointer-events-none"></div>
<div class="absolute bottom-[5%] right-[-5%] w-[500px] h-[500px] bg-tertiary/5 rounded-full blur-[120px] pointer-events-none"></div>
<div class="absolute top-[20%] right-[10%] w-[300px] h-[300px] bg-primary/5 rounded-full blur-[80px] pointer-events-none opacity-50"></div>

<header class="bg-surface/80 backdrop-blur-md border-b border-outline-variant/50 w-full top-0 z-50">
    <div class="flex justify-between items-center w-full px-margin-mobile md:px-gutter max-w-container-max mx-auto h-16">
        <a href="index.php" class="font-headline-md text-headline-md font-bold text-primary hover:opacity-80 transition-opacity cursor-pointer">FLIXO</a>
    </div>
</header>

<main class="flex-grow flex items-center justify-center relative z-10 py-12 md:py-16">
    <div class="container px-margin-mobile flex flex-col items-center justify-center w-full">
        
        <div class="w-full max-w-[580px] bg-white/90 backdrop-blur-xl rounded-3xl soft-float border border-white/50 p-6 md:p-10 transition-all duration-500">
            <div class="flex flex-col items-center mb-8">
                <div class="p-4 bg-primary/5 rounded-2xl mb-4">
                      <img alt="FLIXO" class="h-12 w-12" src="assets/icons/icon-192.svg"/>
                </div>
                <h1 class="font-headline-md text-headline-md text-on-surface text-center mb-2">สมัครสมาชิกฟรี</h1>
                <p class="font-body-md text-body-md text-on-surface-variant text-center max-w-[360px]">เงินพักกลางจนกว่าของถึงมือ — เริ่มซื้อขายได้ทันทีหลังสมัคร</p>
            </div>

            <?php if(isset($error)): ?>
                <div class="bg-error-container text-on-error-container p-4 rounded-2xl flex items-center gap-2 mb-6 border border-error/10 font-body-md text-body-md">
                    <span class="material-symbols-outlined text-error text-[20px]">error</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''): ?>
            <?php $googleConsentInline = true; require __DIR__ . '/includes/google_signin.php'; ?>
            <div class="flex items-center gap-3 my-6">
                <div class="flex-1 h-px bg-outline-variant/50"></div>
                <span class="font-label-md text-label-md text-on-surface-variant whitespace-nowrap">หรือสมัครด้วยอีเมล</span>
                <div class="flex-1 h-px bg-outline-variant/50"></div>
            </div>
            <?php endif; ?>

            <form class="flex flex-col gap-6" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                <?php if ($redirectAfter !== ''): ?>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectAfter); ?>">
                <?php endif; ?>

                    <div class="flex flex-col gap-2 sm:col-span-2">
                        <label class="font-label-md text-label-md text-on-surface-variant ml-1" for="real_name">ชื่อที่แสดงต่อผู้อื่น</label>
                        <div class="relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline group-focus-within:text-primary transition-colors">badge</span>
                            <input class="w-full pl-[52px] pr-4 py-[14px] bg-slate-50/50 border border-outline-variant/60 rounded-2xl focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all font-body-md text-body-md text-on-surface placeholder:text-outline" 
                                   id="real_name" name="real_name" placeholder="เช่น สมชาย ใจดี" type="text" 
                                   value="<?php echo isset($real_name) ? htmlspecialchars($real_name) : ''; ?>" required autocomplete="name"/>
                        </div>
                        <p class="font-body-md text-body-md text-on-surface-variant ml-1" style="font-size:13px;margin:0;">แสดงในแชท ประกาศขาย และออเดอร์</p>
                    </div>

                <div class="flex flex-col gap-2">
                    <label class="font-label-md text-label-md text-on-surface-variant ml-1" for="email">อีเมล</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline group-focus-within:text-primary transition-colors">mail</span>
                        <input class="w-full pl-[52px] pr-4 py-[14px] bg-slate-50/50 border border-outline-variant/60 rounded-2xl focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all font-body-md text-body-md text-on-surface placeholder:text-outline" 
                               id="email" name="email" placeholder="example@mail.com" type="email" 
                               value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>" required autocomplete="email"/>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-label-md text-label-md text-on-surface-variant ml-1" for="password">รหัสผ่าน</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline group-focus-within:text-primary transition-colors">lock</span>
                        <input class="w-full pl-[52px] pr-[52px] py-[14px] bg-slate-50/50 border border-outline-variant/60 rounded-2xl focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all font-body-md text-body-md text-on-surface placeholder:text-outline" 
                               id="password" name="password" placeholder="อย่างน้อย 6 ตัวอักษร" type="password" required minlength="6" autocomplete="new-password"/>
                        <button class="absolute right-4 top-1/2 -translate-y-1/2 text-outline hover:text-on-surface-variant transition-colors w-8 h-8 flex items-center justify-center rounded-full hover:bg-black/5" type="button" id="password-toggle">
                            <span class="material-symbols-outlined text-[20px]">visibility</span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-label-md text-label-md text-on-surface-variant ml-1" for="username">รหัสเข้าสู่ระบบ <span class="font-normal">(ไม่บังคับ)</span></label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline group-focus-within:text-primary transition-colors">account_circle</span>
                        <input class="w-full pl-[52px] pr-4 py-[14px] bg-slate-50/50 border border-outline-variant/60 rounded-2xl focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all font-body-md text-body-md text-on-surface placeholder:text-outline" 
                               id="username" name="username" placeholder="ว่างไว้ได้ — ระบบสร้างจากอีเมลให้" type="text" 
                               value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>" autocomplete="username"/>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <label class="font-label-md text-label-md text-on-surface-variant ml-1" for="phone">เบอร์โทร <span class="font-normal">(ไม่บังคับ — เพิ่มทีหลังได้)</span></label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline group-focus-within:text-primary transition-colors">call</span>
                        <input class="w-full pl-[52px] pr-4 py-[14px] bg-slate-50/50 border border-outline-variant/60 rounded-2xl focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all font-body-md text-body-md text-on-surface placeholder:text-outline" 
                               id="phone" name="phone" placeholder="0812345678" type="tel" 
                               value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>" maxlength="10" inputmode="numeric" autocomplete="tel"/>
                    </div>
                </div>

                <div class="flex flex-col gap-3 p-4 bg-slate-50/80 border border-outline-variant/40 rounded-2xl">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="consent_terms" value="1" class="mt-1 rounded border-outline-variant" required
                            <?php echo !empty($_POST['consent_terms']) ? 'checked' : ''; ?>/>
                        <span class="font-body-md text-body-md text-on-surface-variant">
                            ฉันยอมรับ <a href="terms_of_service.php" target="_blank" class="text-primary font-semibold hover:underline">ข้อกำหนดการใช้งาน</a>
                            (เวอร์ชัน <?php echo safeText(TERMS_VERSION); ?>)
                        </span>
                    </label>
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="consent_privacy" value="1" class="mt-1 rounded border-outline-variant" required
                            <?php echo !empty($_POST['consent_privacy']) ? 'checked' : ''; ?>/>
                        <span class="font-body-md text-body-md text-on-surface-variant">
                            ฉันยอมรับ <a href="privacy_policy.php" target="_blank" class="text-primary font-semibold hover:underline">นโยบายความเป็นส่วนตัว (PDPA)</a>
                            และยินยอมให้จัดเก็บข้อมูลส่วนบุคคล (เวอร์ชัน <?php echo safeText(PRIVACY_VERSION); ?>)
                        </span>
                    </label>
                </div>

                <button type="submit" class="w-full h-14 bg-primary text-on-primary font-headline-md text-headline-md rounded-2xl shadow-lg shadow-primary/20 hover:bg-primary-container hover:shadow-xl hover:shadow-primary/30 active:scale-[0.98] transition-all duration-200 flex items-center justify-center gap-2 mt-2">
                    <span>สมัครแล้วเริ่มใช้งาน</span>
                    <span class="material-symbols-outlined transition-transform duration-300 group-hover:translate-x-1">arrow_forward</span>
                </button>
            </form>

            <div class="mt-8 text-center pt-6 border-t border-outline-variant/30">
                <p class="font-body-md text-body-md text-on-surface-variant">
                    มีบัญชีอยู่แล้ว? 
                    <a class="text-primary font-bold hover:text-primary-container transition-all ml-1" href="login.php">เข้าสู่ระบบที่นี่</a>
                </p>
            </div>
        </div>
        
        <div class="mt-8 flex justify-center items-center gap-6 text-on-surface-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[20px]">verified_user</span>
                <span class="text-label-md font-label-md">เงินพักกลางปลอดภัย</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[20px]">handshake</span>
                <span class="text-label-md font-label-md">โอนเมื่อของถึงมือ</span>
            </div>
        </div>

    </div>
</main>

<footer class="w-full bg-white/50 backdrop-blur-md border-t border-outline-variant/30 mt-auto relative z-20">
    <div class="w-full py-6 px-4 md:px-8 max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
        <div class="flex flex-col items-center md:items-start gap-1">
            <span class="font-headline-md text-headline-md font-bold text-primary">FLIXO</span>
            <p class="font-label-md text-label-md text-on-surface-variant">© 2026 FLIXO Escrow. All rights reserved.</p>
        </div>
        <nav class="flex flex-wrap justify-center gap-x-6 gap-y-2">
            <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="privacy_policy.php">Privacy Policy</a>
            <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="terms_of_service.php">Terms of Service</a>
            <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="mailto:<?php echo htmlspecialchars(defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS ? MAIL_FROM_ADDRESS : 'support@flixo.app'); ?>">ติดต่อฝ่ายสนับสนุน</a>
        </nav>
    </div>
</footer>

<script>
    // การแสดงผลเปิด/ปิด รหัสผ่าน
    const togglePasswordBtn = document.querySelector('#password-toggle');
    const toggleIcon = togglePasswordBtn?.querySelector('.material-symbols-outlined');
    const passwordInput = document.querySelector('#password');

    if (togglePasswordBtn && passwordInput && toggleIcon) {
        togglePasswordBtn.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            toggleIcon.textContent = type === 'password' ? 'visibility' : 'visibility_off';
        });
    }

</script>

<script src="assets/js/main.js?v=<?php echo time(); ?>"></script>

</body>
</html>