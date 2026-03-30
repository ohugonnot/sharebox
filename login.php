<?php
/**
 * ShareBox - Login page
 */

require_once __DIR__ . '/auth.php';

ensure_admin_exists();

if (is_logged_in()) {
    header('Location: /share/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!check_rate_limit($ip)) {
        $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username !== '' && $password !== '') {
            $db = get_db();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                clear_rate_limit($ip);
                session_regenerate_id(true);
                $_SESSION['sharebox_user'] = $user['username'];
                $_SESSION['sharebox_role'] = $user['role'];
                $_SESSION['sharebox_private'] = (int)($user['private'] ?? 0);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: /share/');
                exit;
            } else {
                record_failed_attempt($ip);
                $error = 'Identifiants incorrects.';
            }
        } else {
            $error = 'Veuillez remplir tous les champs.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>ShareBox</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg-void: #06080c;
            --bg-deep: #0c0e14;
            --bg-card: #111420;
            --bg-input: #0d1018;
            --accent: #f0a030;
            --accent-dim: rgba(240, 160, 48, .08);
            --accent-glow: rgba(240, 160, 48, .15);
            --text: #d8dce8;
            --text-dim: #5a6078;
            --border: rgba(255, 255, 255, .04);
            --border-focus: rgba(240, 160, 48, .4);
            --red: #e8453c;
        }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--bg-void);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* ── Atmospheric background ── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 50% 0%, rgba(240, 160, 48, .04) 0%, transparent 60%),
                radial-gradient(ellipse 60% 80% at 20% 100%, rgba(15, 20, 40, .8) 0%, transparent 50%),
                radial-gradient(ellipse 50% 50% at 80% 80%, rgba(240, 160, 48, .02) 0%, transparent 40%);
            pointer-events: none;
            z-index: 0;
        }

        /* ── Grain overlay ── */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            opacity: .035;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            background-size: 128px;
            pointer-events: none;
            z-index: 0;
        }

        .scene {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 400px;
            padding: 1.5rem;
        }

        /* ── Card ── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 3rem 2.2rem 2.5rem;
            position: relative;
            overflow: hidden;
            animation: cardIn .8s cubic-bezier(.16, 1, .3, 1) both;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 140px;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            opacity: .6;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(24px) scale(.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Logo ── */
        .logo {
            text-align: center;
            margin-bottom: 2.4rem;
            animation: fadeIn .6s .2s both;
        }

        .logo-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--accent-dim);
            border: 1px solid rgba(240, 160, 48, .12);
            margin-bottom: 1rem;
        }

        .logo-mark svg {
            width: 22px;
            height: 22px;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -.03em;
            color: var(--text);
        }

        .logo-text span {
            color: var(--accent);
        }

        .logo-sub {
            font-size: .75rem;
            color: var(--text-dim);
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-top: .4rem;
            font-weight: 400;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Form ── */
        .form {
            animation: fadeIn .6s .35s both;
        }

        .field {
            margin-bottom: 1.2rem;
            position: relative;
        }

        .field label {
            display: block;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text-dim);
            margin-bottom: .5rem;
        }

        .field-input-wrap {
            position: relative;
        }

        .field-icon {
            position: absolute;
            left: .9rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            pointer-events: none;
            transition: color .25s;
        }

        .field input {
            width: 100%;
            padding: .75rem .9rem .75rem 2.6rem;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-family: 'Sora', sans-serif;
            font-size: .9rem;
            font-weight: 400;
            outline: none;
            transition: border-color .25s, box-shadow .25s;
        }

        .field input::placeholder {
            color: var(--text-dim);
            opacity: .5;
        }

        .field input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px var(--accent-dim), inset 0 0 0 1px var(--border-focus);
        }

        .field input:focus ~ .field-icon,
        .field input:not(:placeholder-shown) ~ .field-icon {
            color: var(--accent);
        }

        /* ── Submit ── */
        .submit-btn {
            width: 100%;
            padding: .85rem;
            margin-top: .6rem;
            background: var(--accent);
            color: var(--bg-void);
            border: none;
            border-radius: 10px;
            font-family: 'Sora', sans-serif;
            font-size: .88rem;
            font-weight: 600;
            letter-spacing: .01em;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform .15s, box-shadow .25s;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(240, 160, 48, .25);
        }

        .submit-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(240, 160, 48, .15);
        }

        .submit-btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.15) 0%, transparent 50%);
            pointer-events: none;
        }

        /* ── Error ── */
        .error-msg {
            background: rgba(232, 69, 60, .08);
            border: 1px solid rgba(232, 69, 60, .15);
            border-radius: 10px;
            padding: .7rem .9rem;
            margin-bottom: 1.2rem;
            font-size: .82rem;
            color: var(--red);
            display: flex;
            align-items: center;
            gap: .5rem;
            animation: shake .4s ease-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-6px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(2px); }
        }

        /* ── Footer ── */
        .card-footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.2rem;
            border-top: 1px solid var(--border);
            animation: fadeIn .6s .5s both;
        }

        .card-footer p {
            font-size: .72rem;
            color: var(--text-dim);
            letter-spacing: .02em;
        }

        /* ── Floating particles ── */
        .particle {
            position: fixed;
            width: 2px;
            height: 2px;
            border-radius: 50%;
            background: var(--accent);
            opacity: 0;
            pointer-events: none;
            z-index: 0;
            animation: drift linear infinite;
        }

        @keyframes drift {
            0% { opacity: 0; transform: translateY(0); }
            10% { opacity: .3; }
            90% { opacity: .3; }
            100% { opacity: 0; transform: translateY(-100vh); }
        }
    </style>
</head>
<body>
    <!-- Floating particles -->
    <div class="particle" style="left:12%;animation-duration:18s;animation-delay:0s"></div>
    <div class="particle" style="left:28%;animation-duration:22s;animation-delay:4s"></div>
    <div class="particle" style="left:55%;animation-duration:20s;animation-delay:2s"></div>
    <div class="particle" style="left:72%;animation-duration:16s;animation-delay:6s"></div>
    <div class="particle" style="left:88%;animation-duration:24s;animation-delay:1s"></div>
    <div class="particle" style="left:40%;animation-duration:19s;animation-delay:8s"></div>

    <div class="scene">
        <div class="card">
            <div class="logo">
                <div class="logo-mark">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M21 3h-8v2h5.59L11 12.59 12.41 14 20 6.41V12h2V3z" fill="#f0a030"/>
                        <path d="M3 5v16h16v-7h-2v5H5V7h5V5H3z" fill="#f0a030" opacity=".5"/>
                    </svg>
                </div>
                <div class="logo-text">Share<span>Box</span></div>
                <div class="logo-sub">Private Streaming & Sharing</div>
            </div>

            <?php if ($error): ?>
                <div class="error-msg">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on" class="form">
                <div class="field">
                    <label for="username">Utilisateur</label>
                    <div class="field-input-wrap">
                        <input type="text" id="username" name="username" placeholder="Entrez votre identifiant"
                               autocomplete="username" autofocus required
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        <div class="field-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label for="password">Mot de passe</label>
                    <div class="field-input-wrap">
                        <input type="password" id="password" name="password" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;"
                               autocomplete="current-password" required>
                        <div class="field-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        </div>
                    </div>
                </div>
                <button type="submit" class="submit-btn">Connexion</button>
            </form>

            <div class="card-footer">
                <p>Acc&egrave;s restreint &mdash; membres uniquement</p>
            </div>
        </div>
    </div>
</body>
</html>
