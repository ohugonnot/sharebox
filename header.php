<?php
/** Shared app header — include after require_once auth.php
 *  $header_subtitle : string  — subtitle text (default: 'Secure file sharing & streaming')
 *  $header_back     : bool    — show '← Fichiers' instead of 'Admin' link (default: false)
 */
$header_subtitle ??= 'Secure file sharing & streaming';
$header_back     ??= false;
?>
<header class="app-header" style="display:flex;justify-content:space-between;align-items:center">
    <div style="display:flex;align-items:center;gap:.7rem">
        <div class="app-logo">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M21 3h-8v2h5.59L11 12.59 12.41 14 20 6.41V12h2V3z" fill="#0c0e14"/>
                <path d="M3 5v16h16v-7h-2v5H5V7h5V5H3z" fill="#0c0e14"/>
            </svg>
        </div>
        <div>
            <div class="app-title">Share<span style="color:var(--accent)">Box</span></div>
            <div class="app-subtitle"><?= htmlspecialchars($header_subtitle) ?></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:.8rem">
        <?php if (is_executable('/usr/local/bin/seedbox-adduser')): ?>
            <a href="/" target="_blank" style="color:var(--text-secondary);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">ruTorrent</a>
        <?php endif; ?>
        <?php if ($header_back): ?>
            <a href="/share/" style="color:var(--text-secondary);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">← Fichiers</a>
        <?php elseif (($_SESSION['sharebox_role'] ?? '') === 'admin'): ?>
            <a href="/share/admin.php" style="color:var(--accent);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid rgba(240,160,48,.2);border-radius:var(--radius-sm)">Admin</a>
        <?php endif; ?>
        <span style="color:var(--text-secondary);font-size:.85rem"><?= htmlspecialchars(get_current_user_name() ?? '') ?></span>
        <button onclick="ouvrirModalCompte()" style="color:var(--text-secondary,#8892a4);font-size:.8rem;background:none;border:1px solid var(--border,rgba(255,255,255,.04));border-radius:var(--radius-sm,6px);padding:.3rem .6rem;cursor:pointer">Mon compte</button>
        <a href="/share/logout.php" style="color:var(--text-muted);font-size:.8rem;text-decoration:none;padding:.3rem .6rem;border:1px solid var(--border);border-radius:var(--radius-sm)">Logout</a>
    </div>
</header>
<div id="modal-compte" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;align-items:center;justify-content:center">
    <div style="background:var(--bg-card,#111420);border:1px solid var(--border-strong,rgba(255,255,255,.08));border-radius:14px;padding:1.5rem;width:100%;max-width:360px;margin:1rem">
        <div style="font-size:1rem;font-weight:600;margin-bottom:1.2rem">Changer le mot de passe</div>
        <div class="modal-compte-label">Mot de passe actuel</div>
        <input type="password" id="mdp-actuel" class="modal-compte-input" autocomplete="current-password">
        <div class="modal-compte-label">Nouveau mot de passe</div>
        <input type="password" id="mdp-nouveau" class="modal-compte-input" autocomplete="new-password">
        <div class="modal-compte-label">Confirmation</div>
        <input type="password" id="mdp-confirm" class="modal-compte-input" autocomplete="new-password">
        <div id="mdp-error" style="display:none;color:var(--red,#e8453c);font-size:.82rem;margin-top:.5rem"></div>
        <div style="display:flex;justify-content:flex-end;gap:.6rem;margin-top:1.2rem">
            <button onclick="fermerModalCompte()" style="padding:.4rem .8rem;background:transparent;color:var(--text-dim,#5a6078);border:1px solid var(--border-strong,rgba(255,255,255,.08));border-radius:6px;cursor:pointer;font-family:inherit;font-size:.82rem">Annuler</button>
            <button id="mdp-submit" onclick="soumettreChangementMdp()" style="padding:.4rem .8rem;background:var(--accent,#f0a030);color:#000;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600">Enregistrer</button>
        </div>
    </div>
</div>
