<?php
header('Content-Type: application/json; charset=utf-8');

// Cache SQLite : évite de relancer ffprobe si le fichier n'a pas changé
$mtime = filemtime($resolvedPath);
$cached = $db->prepare("SELECT result FROM probe_cache WHERE path = :p AND mtime = :m");
$cached->execute([':p' => $resolvedPath, ':m' => $mtime]);
if ($row = $cached->fetch()) {
    // Invalider les entrées cache sans isMP4/isMKV (champs ajoutés après coup)
    $decoded = json_decode($row['result'], true);
    if (isset($decoded['isMP4']) && isset($decoded['isMKV'])) {
        stream_log('PROBE cache-hit | ' . basename($resolvedPath) . ' | codec=' . ($decoded['videoCodec'] ?? '?') . ' h=' . ($decoded['videoHeight'] ?? '?') . ' audio=' . count($decoded['audio'] ?? []) . ' subs=' . count($decoded['subtitles'] ?? []));
        echo $row['result'];
        exit;
    }
}

$probeFp = acquireProbeSlot();
if (!$probeFp) {
    stream_log('PROBE 429 | ' . basename($resolvedPath) . ' | all probe slots busy');
    http_response_code(429);
    echo json_encode(['error' => 'too_many_probes']);
    exit;
}

$cmd = 'timeout ' . PROBE_TIMEOUT . ' ffprobe -v error -show_entries format=duration,format_name -show_entries stream=index,codec_type,codec_name,width,height:stream_tags=language,title -of json '
    . escapeshellarg($resolvedPath) . ' 2>/dev/null';
$output = shell_exec($cmd);
$data = json_decode($output, true);
$duration = (float)($data['format']['duration'] ?? 0);
$formatName = $data['format']['format_name'] ?? '';
$isMP4 = str_contains($formatName, 'mp4') || str_contains($formatName, 'mov');
$isMKV = str_contains($formatName, 'matroska') || str_contains($formatName, 'webm');
$audio = [];
$subs = [];
$audioIdx = 0;
$subIdx = 0;
$videoHeight = 0;
$videoCodec = '';
foreach (($data['streams'] ?? []) as $s) {
    $lang  = $s['tags']['language'] ?? '';
    $title = strip_tags($s['tags']['title'] ?? '');
    if ($s['codec_type'] === 'video' && !$videoHeight && isset($s['height'])
        && !in_array($s['codec_name'] ?? '', ['mjpeg', 'png', 'bmp'])) {
        $videoHeight = (int)$s['height'];
        $videoCodec = $s['codec_name'] ?? '';
    } elseif ($s['codec_type'] === 'audio') {
        $label = $lang ? strtoupper($lang) : 'Piste ' . ($audioIdx + 1);
        if ($title) $label .= ' — ' . $title;
        $audio[] = ['index' => $audioIdx, 'stream' => $s['index'], 'codec' => $s['codec_name'], 'lang' => $lang, 'label' => $label];
        $audioIdx++;
    } elseif ($s['codec_type'] === 'subtitle') {
        $imageCodecs = ['hdmv_pgs_subtitle', 'dvd_subtitle', 'dvb_subtitle', 'dvb_teletext', 'xsub', 'eia_608', 'eia_708'];
        $subType = in_array($s['codec_name'] ?? '', $imageCodecs) ? 'image' : 'text';
        $label = $lang ? strtoupper($lang) : 'Sous-titre ' . ($subIdx + 1);
        if ($title) $label .= ' — ' . $title;
        if ($subType === 'image') $label .= ' ★'; // indique burn-in requis
        $subs[] = ['index' => $subIdx, 'stream' => $s['index'], 'codec' => $s['codec_name'], 'lang' => $lang, 'label' => $label, 'type' => $subType];
        $subIdx++;
    }
}
$result = json_encode(['audio' => $audio, 'subtitles' => $subs, 'duration' => $duration, 'videoHeight' => $videoHeight, 'videoCodec' => $videoCodec, 'isMP4' => $isMP4, 'isMKV' => $isMKV]);
stream_log('PROBE ffprobe | ' . basename($resolvedPath) . ' | codec=' . $videoCodec . ' h=' . $videoHeight . ' dur=' . round($duration) . 's fmt=' . $formatName . ' audio=' . count($audio) . ' subs=' . count($subs));

// Stocker en cache (best-effort : on ignore si la DB est encore verrouillée)
try {
    $db->prepare("INSERT OR REPLACE INTO probe_cache (path, mtime, result) VALUES (:p, :m, :r)")
       ->execute([':p' => $resolvedPath, ':m' => $mtime, ':r' => $result]);
} catch (PDOException $e) { /* lock résiduel — le probe sera recalculé au prochain appel */ }

releaseProbeSlot($probeFp);
echo $result;

// Pré-cache du PREMIER sous-titre texte en background (évite l'attente au premier clic)
// Un seul pour ne pas lancer N ffmpeg en parallèle sur un gros fichier
$firstTextSub = null;
foreach ($subs as $s) { if ($s['type'] === 'text') { $firstTextSub = $s; break; } }
if ($firstTextSub) {
    $subCached = $db->prepare("SELECT 1 FROM subtitle_cache WHERE path = :p AND track = :t AND mtime = :m");
    $s = $firstTextSub;
    {
        $subCached->execute([':p' => $resolvedPath, ':t' => $s['index'], ':m' => $mtime]);
        if (!$subCached->fetch()) {
            $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';
            $bgCmd = 'timeout ' . SUBTITLE_EXTRACT_TIMEOUT . ' ffmpeg -i ' . escapeshellarg($resolvedPath)
                . ' -map 0:s:' . $s['index'] . ' -f webvtt pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
            $dbPath = DB_PATH;
            // Extraction + cache en background via un script inline
            $shellCmd = '(' . $bgCmd . ') | php -r '
                . escapeshellarg(
                    '$vtt=file_get_contents("php://stdin");'
                    . 'if(!$vtt)exit;'
                    . '$db=new PDO("sqlite:' . $dbPath . '");'
                    . '$db->exec("PRAGMA busy_timeout=5000");'
                    . '$s=$db->prepare("INSERT OR REPLACE INTO subtitle_cache(path,track,mtime,vtt)VALUES(:p,:t,:m,:v)");'
                    . '$s->execute([":p"=>' . var_export($resolvedPath, true) . ',":t"=>' . $s['index'] . ',":m"=>' . $mtime . ',":v"=>$vtt]);'
                )
                . ' >/dev/null 2>&1 &';
            shell_exec($shellCmd);
            stream_log('SUBTITLE bg-precache | track=' . $s['index'] . ' | ' . basename($resolvedPath));
        }
    }
}
// Les autres pistes seront extraites à la demande (cache on first request)

exit;
