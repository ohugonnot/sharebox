<?php
/**
 * Bootstrap de la démo Docker.
 *
 * Objectif : peupler les affiches via EXACTEMENT le même chemin de code qu'une
 * install réelle — pas de mapping en dur, pas de seed naïf. On reproduit ce que
 * font un admin (marquer un dossier "movies") puis un premier visiteur (browse →
 * enqueue), puis on lance le VRAI worker qui matche tout via tmdb_match
 * (retry/backoff + cache + scoring identiques à la prod).
 *
 * Appelé par docker/entrypoint.sh en tâche de fond après le démarrage des services,
 * uniquement si SHAREBOX_DEMO_DATA=true et SHAREBOX_TMDB_API_KEY défini.
 *
 * Usage: php demo-bootstrap.php <mediaDir>
 */

require '/app/db.php';
require_once '/app/functions.php';

$mediaDir = rtrim(($argv[1] ?? '') ?: '/media', '/'); // défaut robuste si l'argument est vide

if (!defined('TMDB_API_KEY') || !TMDB_API_KEY) {
    fwrite(STDERR, "demo-bootstrap: TMDB_API_KEY absent, rien à faire.\n");
    exit(0);
}

$db = get_db();

// Dossiers de la démo affichés en mode "films" (fichiers vidéo à plat).
// Tout le reste (Series, Anime, saisons) est en "series" par défaut et découvert
// récursivement par le worker via discover_folders().
$movieDirs   = ['Films'];
$videoExts   = ['mp4','mkv','avi','m4v','mov','wmv','flv','webm','ts','m2ts','mpg','mpeg'];

// 1. Config admin + enqueue des fichiers vidéo (= ce que fait POST ?folder_type_set
//    puis le browse ?posters). Sans folder_type='movies', les fichiers vidéo ne sont
//    jamais enqueués (cf. handlers/tmdb.php, branche $isMovies).
$setType = $db->prepare("INSERT INTO folder_posters (path, folder_type) VALUES (:p, 'movies')
                         ON CONFLICT(path) DO UPDATE SET folder_type = 'movies'");
$enqueue = $db->prepare("INSERT OR IGNORE INTO folder_posters (path) VALUES (:p)");

foreach ($movieDirs as $d) {
    $dirPath = realpath("$mediaDir/$d");
    if (!$dirPath || !is_dir($dirPath)) continue;
    $setType->execute([':p' => $dirPath]);
    foreach (scandir($dirPath) as $f) {
        if ($f[0] === '.' || is_dir("$dirPath/$f")) continue;
        if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $videoExts, true)) {
            $enqueue->execute([':p' => "$dirPath/$f"]);
        }
    }
}

echo "demo-bootstrap: dossiers films marqués + fichiers enqueués, lancement du worker…\n";

// 2. Le vrai worker : discover (Series/Anime/saisons) + match (tmdb_match) +
//    auto-verify + propagation parent→enfant + posters de saison.
passthru(escapeshellarg(find_php_cli()) . ' /app/tools/tmdb-worker.php');
