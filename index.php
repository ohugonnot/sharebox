<?php
/**
 * Page d'administration de l'application Share
 * Affiche les liens existants (en cartes) + le navigateur de fichiers
 * Protégée par htpasswd via nginx
 */

require_once __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Mode fragment : retourne juste le HTML des liens (pour AJAX)
if (isset($_GET['fragment']) && $_GET['fragment'] === 'links') {
    afficher_liens();
    exit;
}

/**
 * Génère le HTML des cartes de liens actifs
 */
function afficher_liens(): void {
    $db = get_db();
    $links = $db->query("SELECT * FROM links ORDER BY created_at DESC")->fetchAll();

    if (empty($links)) {
        echo '<div class="empty-msg"><span class="empty-icon">&#x1F517;</span>Aucun lien de partage pour le moment</div>';
        return;
    }

    echo '<div class="link-grid">';

    foreach ($links as $link) {
        $name = htmlspecialchars($link['name']);
        $token = htmlspecialchars($link['token']);
        $shortToken = $token;
        $type = $link['type'];
        $dlUrl = '/dl/' . $token;
        $dlCount = (int)$link['download_count'];
        $created = date('d/m/Y', strtotime($link['created_at']));

        // Badge type
        $typeIcon = $type === 'folder'
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="color:var(--accent)"><path d="M2 6a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--blue)"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

        // Expiration
        $expired = $link['expires_at'] !== null && strtotime($link['expires_at']) < time();
        if ($link['expires_at'] === null) {
            $expHtml = '<span class="link-meta-val">Jamais</span>';
        } elseif ($expired) {
            $expHtml = '<span class="link-meta-val expired">Expiré</span>';
        } else {
            $expHtml = '<span class="link-meta-val">' . htmlspecialchars(date('d/m/Y H:i', strtotime($link['expires_at']))) . '</span>';
        }

        // Mot de passe
        $pwdHtml = $link['password_hash'] !== null
            ? '<span class="badge badge-password">&#x1F512; mdp</span>'
            : '';

        $expiredClass = $expired ? ' is-expired' : '';
        $linkId = (int)$link['id'];

        echo "<div class=\"link-card{$expiredClass}\">";
        echo "<div class=\"link-card-top\">";
        echo "<div class=\"link-card-icon\">{$typeIcon}</div>";
        echo "<div class=\"link-card-info\">";
        echo "<div class=\"link-card-name\" title=\"" . htmlspecialchars($link['path']) . "\">{$name}</div>";
        echo "<a class=\"token-link\" href=\"{$dlUrl}\" target=\"_blank\">{$shortToken}</a>";
        echo "</div>";
        echo "<button class=\"btn btn-danger btn-sm\" onclick=\"supprimerLien({$linkId})\">";
        echo "<svg width=\"13\" height=\"13\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M3 6h18\"/><path d=\"M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2\"/><path d=\"M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6\"/></svg>";
        echo "</button>";
        echo "</div>";
        echo "<div class=\"link-card-bottom\">";
        echo "<div class=\"link-meta\"><span class=\"link-meta-label\">Expire</span>{$expHtml}</div>";
        echo "<div class=\"link-meta\"><span class=\"link-meta-label\">Téléch.</span><span class=\"link-meta-val\">{$dlCount}</span></div>";
        echo "<div class=\"link-meta\"><span class=\"link-meta-label\">Créé</span><span class=\"link-meta-val\">{$created}</span></div>";
        if ($pwdHtml) echo $pwdHtml;
        echo "</div>";

        // Barre d'actions : Copier + Email
        $hasPwd = $link['password_hash'] !== null ? 'true' : 'false';
        $pwdJs = $link['password_plain'] ? addcslashes($link['password_plain'], "'\\") : '';
        $pwdAttr = htmlspecialchars($pwdJs, ENT_QUOTES);
        echo "<div class=\"link-card-actions\">";
        echo "<button class=\"btn btn-ghost btn-sm\" onclick=\"copierInfoLien('{$dlUrl}', {$hasPwd}, '{$pwdAttr}', this)\">";
        echo "<svg width=\"13\" height=\"13\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"9\" y=\"9\" width=\"13\" height=\"13\" rx=\"2\"/><path d=\"M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1\"/></svg> Copier";
        echo "</button>";
        echo "<button class=\"btn btn-ghost btn-sm\" onclick=\"envoyerEmail({$linkId})\">";
        echo "<svg width=\"13\" height=\"13\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z\"/><polyline points=\"22,6 12,13 2,6\"/></svg> Email";
        echo "</button>";
        echo "<button class=\"btn btn-ghost btn-sm\" onclick=\"afficherQR('{$dlUrl}', this)\">";
        echo "<svg width=\"13\" height=\"13\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"14\" width=\"3\" height=\"3\"/><line x1=\"21\" y1=\"14\" x2=\"21\" y2=\"17\"/><line x1=\"17\" y1=\"21\" x2=\"21\" y2=\"21\"/></svg> QR";
        echo "</button>";
        echo "</div>";

        echo "</div>";
    }

    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>ShareBox</title>
    <link rel="stylesheet" href="/share/style.css">
</head>
<body>

<div class="app">
    <header class="app-header">
        <div class="app-logo">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M21 3h-8v2h5.59L11 12.59 12.41 14 20 6.41V12h2V3z" fill="#0c0e14"/>
                <path d="M3 5v16h16v-7h-2v5H5V7h5V5H3z" fill="#0c0e14"/>
            </svg>
        </div>
        <div>
            <div class="app-title">Share<span style="color:var(--accent)">Box</span></div>
            <div class="app-subtitle">Secure file sharing & streaming</div>
        </div>
    </header>

    <section class="section">
        <div class="section-header">Liens actifs</div>
        <div id="links-container">
            <?php afficher_liens(); ?>
        </div>
    </section>

    <section class="section">
        <div class="section-header">Parcourir les fichiers</div>
        <div id="breadcrumb" class="breadcrumb"></div>
        <div class="panel">
            <ul id="file-list" class="file-list">
                <li class="file-item"><div class="empty-msg">Chargement&hellip;</div></li>
            </ul>
        </div>
    </section>
</div>

<script src="/share/app.js"></script>
</body>
</html>
