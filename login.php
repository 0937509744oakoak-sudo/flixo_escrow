<?php
require_once 'includes/init.php';
require_once 'models/User.php'; // 🔥 เรียกใช้ Model เข้ามา

$csrf_token = getCsrfToken();

$redirectAfter = trim($_GET['redirect'] ?? $_POST['redirect'] ?? '');
if ($redirectAfter !== '' && (str_contains($redirectAfter, '://') || str_starts_with($redirectAfter, '//'))) {
    $redirectAfter = '';
}

// หากล็อกอินอยู่แล้ว ให้เด้งไป Dashboard
if (isset($_SESSION['user'])) {
    redirectTo($redirectAfter !== '' ? $redirectAfter : 'dashboard.php');
}

// รับค่าจากฟอร์ม
if (isPostRequest()) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');

    $login = trim(getPostValue('username'));
    $password = $_POST['password'];

    $userModel = new User($conn);
    $user = $userModel->findByLogin($login);

    if (!$user) {
        $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    } elseif ($user['status'] === 'banned') {
        $error = "บัญชีของคุณถูกระงับ โปรดติดต่อฝ่ายสนับสนุน";
    } elseif ($user['status'] !== 'active') {
        $error = "บัญชีของคุณยังไม่สามารถใช้งานได้";
    } elseif (!password_verify($password, $user['password'])) {
        $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    } else {
        // ป้องกัน Session Fixation
        session_regenerate_id(true);
        $_SESSION['user'] = $user['username'];
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        redirectTo($redirectAfter !== '' ? $redirectAfter : 'dashboard.php');
    }
}

$page_title = "Login";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>FLIXO - เข้าสู่ระบบ</title>
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
                    },
                    "fontSize": {
                        "headline-md": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                        "label-md": ["12px", {"lineHeight": "16px", "letterSpacing": "0.05em", "fontWeight": "500"}],
                        "display-lg": ["48px", {"lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "700"}],
                        "headline-lg-mobile": ["24px", {"lineHeight": "32px", "fontWeight": "600"}],
                        "body-lg": ["16px", {"lineHeight": "24px", "fontWeight": "400"}],
                        "headline-lg": ["32px", {"lineHeight": "40px", "letterSpacing": "-0.01em", "fontWeight": "600"}],
                        "title-lg": ["18px", {"lineHeight": "28px", "fontWeight": "600"}],
                        "numeric-data": ["14px", {"lineHeight": "20px", "fontWeight": "600"}],
                        "body-md": ["14px", {"lineHeight": "20px", "fontWeight": "400"}]
                    }
                },
            },
        }
    </script>
   
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9ff;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none !important;
        }
        input[type="password"]::-webkit-contacts-auto-fill-button,
        input[type="password"]::-webkit-credentials-auto-fill-button {
            visibility: hidden;
            pointer-events: none;
            position: absolute;
            right: 0;
        }
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
</head>
<body class="min-h-screen flex flex-col bg-mesh relative overflow-x-hidden">

<div class="absolute top-[-10%] left-[-5%] w-[400px] h-[400px] bg-primary/5 rounded-full blur-[100px] pointer-events-none"></div>
<div class="absolute bottom-[5%] right-[-5%] w-[500px] h-[500px] bg-tertiary/5 rounded-full blur-[120px] pointer-events-none"></div>
<div class="absolute top-[20%] right-[10%] w-[300px] h-[300px] bg-primary/5 rounded-full blur-[80px] pointer-events-none opacity-50"></div>

<header class="bg-surface/80 backdrop-blur-md border-b border-outline-variant/50 docked full-width top-0 z-50">
    <div class="flex justify-between items-center w-full px-margin-mobile md:px-gutter max-w-container-max mx-auto h-16">
        <a href="index.php" class="font-headline-md text-headline-md font-bold text-primary hover:opacity-80 transition-opacity cursor-pointer">FLIXO</a>
    </div>
</header>

<main class="flex-grow flex items-center justify-center relative z-10 py-2xl">
    <div class="container px-margin-mobile flex justify-center w-full">
        <div class="w-full max-w-[480px] bg-white/90 backdrop-blur-xl rounded-3xl soft-float border border-white/50 p-2xl transition-all duration-500">
            <div class="flex flex-col items-center mb-xl">
                <div class="p-md bg-primary/5 rounded-2xl mb-md">
                    <img alt="FLIXO" class="h-12 w-12" src="assets/icons/icon-192.svg"/>
                </div>
                <h1 class="font-headline-md text-headline-md text-on-surface text-center mb-sm">เข้าสู่ระบบ FLIXO</h1>
                <p class="font-body-md text-body-md text-on-surface-variant text-center max-w-[320px]">เงินพักกลางจนกว่าของถึงมือ — ยินดีต้อนรับกลับ</p>
            </div>

            <?php if(isset($error)): ?>
                <div class="bg-error-container text-on-error-container p-md rounded-2xl flex items-center gap-sm mb-lg border border-error/10 font-body-md text-body-md">
                    <span class="material-symbols-outlined text-error text-[20px]">error</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form class="space-y-lg" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                <?php if ($redirectAfter !== ''): ?>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectAfter); ?>">
                <?php endif; ?>
                
                <div class="flex flex-col gap-sm">
                    <label class="font-label-md text-label-md text-on-surface-variant ml-xs" for="username">อีเมลหรือชื่อผู้ใช้</label>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-md top-1/2 -translate-y-1/2 text-outline group-focus-within:text-primary transition-colors">person</span>
                        <input class="w-full pl-[52px] pr-md py-[14px] bg-slate-50/50 border border-outline-variant/60 rounded-2xl focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all font-body-md text-body-md text-on-surface placeholder:text-outline" 
                               id="username" name="username" placeholder="example@mail.com หรือ user123" type="text" 
                               value="<?php echo isset($login) ? htmlspecialchars($login) : (isset($username) ? htmlspecialchars($username) : ''); ?>" required autocomplete="username"/>
                    </div>
                </div>

                <div class="flex flex-col gap-sm">
                    <div class="flex justify-between items-center px-xs">
                        <label class="font-label-md text-label-md text-on-surface-variant" for="password">รหัสผ่าน</label>
                        <a href="forgot_password.php" class="font-label-md text-label-md text-primary hover:underline">ลืมรหัสผ่าน?</a>
                    </div>
                    <div class="relative group">
                        <span class="material-symbols-outlined absolute left-md top-1/2 -translate-y-1/2 text-outline group-focus-within:text-primary transition-colors">lock</span>
                        <input class="w-full pl-[52px] pr-[52px] py-[14px] bg-slate-50/50 border border-outline-variant/60 rounded-2xl focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all font-body-md text-body-md text-on-surface placeholder:text-outline" 
                               id="password" name="password" placeholder="ระบุรหัสผ่านของคุณ" type="password" required autocomplete="current-password"/>
                        <button class="absolute right-md top-1/2 -translate-y-1/2 text-outline hover:text-on-surface-variant transition-colors w-8 h-8 flex items-center justify-center rounded-full hover:bg-black/5" type="button" id="password-toggle">
                            <span class="material-symbols-outlined text-[20px]">visibility</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full h-14 bg-primary text-on-primary font-headline-md text-headline-md rounded-2xl shadow-lg shadow-primary/20 hover:bg-primary-container hover:shadow-xl hover:shadow-primary/30 active:scale-[0.98] transition-all duration-200 flex items-center justify-center gap-sm mt-md">
                    เข้าสู่ระบบ
                    <span class="material-symbols-outlined transition-transform duration-300 group-hover:translate-x-1">arrow_forward</span>
                </button>
            </form>

            <div class="mt-2xl text-center">
                <p class="font-body-md text-body-md text-on-surface-variant">
                    ยังไม่มีบัญชี? 
                    <a class="text-primary font-bold hover:text-primary-container transition-all ml-1" href="register.php<?php echo $redirectAfter !== '' ? '?redirect=' . urlencode($redirectAfter) : ''; ?>">สมัครสมาชิกที่นี่</a>
                </p>
            </div>
        </div>
    </div>
</main>

<footer class="bg-white/50 backdrop-blur-sm border-t border-outline-variant/30 mt-auto">
    <div class="w-full py-xl px-margin-mobile md:px-gutter max-w-container-max mx-auto flex flex-col md:flex-row justify-between items-center gap-md">
        <div class="flex flex-col items-center md:items-start gap-xs">
            <span class="font-headline-md text-headline-md font-bold text-primary">FLIXO</span>
            <p class="font-label-md text-label-md text-on-surface-variant">© 2026 FLIXO Escrow. All rights reserved.</p>
        </div>
        <nav class="flex flex-wrap justify-center gap-x-lg gap-y-sm">
            <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="privacy_policy.php">นโยบายความเป็นส่วนตัว</a>
            <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="terms_of_service.php">ข้อกำหนดการใช้งาน</a>
            <a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="mailto:<?php echo htmlspecialchars(defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS ? MAIL_FROM_ADDRESS : 'support@flixo.app'); ?>">ติดต่อฝ่ายสนับสนุน</a>
        </nav>
    </div>
</footer>

<script>
    // Micro-interaction for the password visibility toggle
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

    // Add subtle animation on form submission
    const loginBtn = document.querySelector('button.bg-primary');
    if (loginBtn) {
        loginBtn.addEventListener('click', () => {
            const icon = loginBtn.querySelector('.material-symbols-outlined');
            if (icon) {
                icon.style.transform = 'translateX(10px)';
                icon.style.opacity = '0';
                setTimeout(() => {
                    icon.style.transform = 'translateX(0)';
                    icon.style.opacity = '1';
                }, 400);
            }
        });
    }
</script>

<script src="assets/js/main.js?v=<?php echo time(); ?>"></script>

</body>
</html>