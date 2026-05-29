<?php
/**
 * Cron — Sauvegarde passive de la base SQLite vers share.db.bak.
 * Crontab : 0 * * * * php /var/www/sharebox/cron/backup_db.php  (toutes les heures)
 *
 * Auparavant déclenché paresseusement dans get_db() à chaque requête web : un
 * simple GET pouvait lancer un @copy() de toute la base (coûteux sur carte SD).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

backup_db();
