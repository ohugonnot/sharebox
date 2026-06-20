<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour les handlers de streaming et la cohérence entre composants.
 * Vérifie les invariants critiques identifiés lors de l'audit du player.
 */
class StreamingHandlersTest extends TestCase
{
    // ── Remux handler : audio filter configurable + default doux ────────

    /**
     * Le filtre audio remux est désormais configurable via FFMPEG_REMUX_AUDIO_FILTER.
     * Default 'aresample=async=1:min_hard_comp=0.100:first_pts=0' = resampling
     * doux (hard correct uniquement si drift > 100ms) qui évite les glitches
     * sur fichiers proprement timbrés. L'ancien async=2000 était trop agressif.
     */
    public function testRemuxAudioFilterIsConfigurable(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        $this->assertStringContainsString(
            "defined('FFMPEG_REMUX_AUDIO_FILTER')",
            $source,
            'FFMPEG_REMUX_AUDIO_FILTER doit être une constante overridable'
        );
        $this->assertStringContainsString(
            'aresample=async=1:min_hard_comp=0.100:first_pts=0',
            $source,
            'Default doit être aresample=async=1:min_hard_comp=0.100 (resync doux)'
        );
    }

    public function testRemuxHandlerUsesConfigurableFilter(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_remux.php');
        $this->assertStringContainsString(
            'FFMPEG_REMUX_AUDIO_FILTER',
            $source,
            'stream_remux.php doit utiliser la constante FFMPEG_REMUX_AUDIO_FILTER'
        );
        // Plus de hardcoding async=2000
        $this->assertStringNotContainsString(
            'aresample=async=2000',
            $source,
            'Remux ne doit plus avoir async=2000 hardcodé (passé en config)'
        );
    }

    /**
     * -fps_mode passthrough : préserve les PTS vidéo originaux en -c:v copy.
     * Sans ça, ffmpeg peut régénérer les PTS depuis fps → offset audio constant.
     */
    public function testRemuxUsesFpsModePassthrough(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_remux.php');
        $this->assertStringContainsString(
            '-fps_mode passthrough',
            $source,
            'Remux doit utiliser -fps_mode passthrough pour préserver les PTS exacts'
        );
    }

    // ── Remux handler : register_shutdown_function pour le slot ─────────

    public function testRemuxHasShutdownSlotRelease(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_remux.php');
        $this->assertStringContainsString(
            'register_shutdown_function',
            $source,
            'Remux doit avoir register_shutdown_function pour libérer le slot si exit précoce'
        );
    }

    public function testTranscodeHasShutdownSlotRelease(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_transcode.php');
        $this->assertStringContainsString(
            'register_shutdown_function',
            $source,
            'Transcode doit avoir register_shutdown_function pour libérer le slot si exit précoce'
        );
    }

    /**
     * Vérifie la cohérence : tous les handlers qui acquièrent un slot doivent
     * avoir un register_shutdown_function pour le libérer.
     *
     * @dataProvider streamHandlerProvider
     */
    public function testAllStreamHandlersWithSlotHaveShutdown(string $handler): void
    {
        $path = __DIR__ . '/../handlers/' . $handler;
        if (!file_exists($path)) {
            $this->markTestSkipped($handler . ' not found');
        }
        $source = file_get_contents($path);
        if (!str_contains($source, 'acquireStreamSlot')) {
            // Ce handler n'utilise pas de slot — pas besoin de shutdown
            $this->assertTrue(true);
            return;
        }
        $this->assertStringContainsString(
            'register_shutdown_function',
            $source,
            $handler . ' acquiert un slot mais n\'a pas de register_shutdown_function'
        );
    }

    public static function streamHandlerProvider(): array
    {
        return [
            'remux'     => ['stream_remux.php'],
            'transcode' => ['stream_transcode.php'],
        ];
    }

    // ── HLS cleanup : pas de unlink sur . et .. ─────────────────────────

    public function testHlsCleanupFiltersDotsFromGlob(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        // Le glob GLOB_BRACE avec .* peut matcher . et .. — vérifier qu'on filtre
        if (str_contains($source, "GLOB_BRACE")) {
            $this->assertStringContainsString(
                "array_filter",
                $source,
                'Le glob avec GLOB_BRACE et .* doit filtrer . et .. via array_filter'
            );
        } else {
            $this->assertTrue(true, 'Pas de GLOB_BRACE — pas de risque');
        }
    }

    // ── Transcode : aresample=async=3000 ────────────────────────────────

    public function testTranscodeUsesAsyncThreeThousand(): void
    {
        $result = buildFilterGraph(720, 0);
        $this->assertStringContainsString(
            'aresample=async=3000',
            $result,
            'Transcode filter_complex doit utiliser async=3000 pour la resync agressive'
        );
    }

    // ── fMP4 muxer : min_frag_duration >= 2s ────────────────────────────

    public function testFmp4FragDurationIsReasonable(): void
    {
        $result = buildFmp4MuxerArgs();
        preg_match('/-min_frag_duration (\d+)/', $result, $m);
        $this->assertNotEmpty($m);
        $fragDuration = (int)$m[1];
        // >= 2s pour VOD streaming, <= 10s pour garder la réactivité
        $this->assertGreaterThanOrEqual(2000000, $fragDuration, 'Fragment trop court → overhead muxing');
        $this->assertLessThanOrEqual(10000000, $fragDuration, 'Fragment trop long → latence de buffering');
    }

    // ── Probe handler : retourne les champs requis par le player JS ─────

    /**
     * Vérifie que le probe cache contient les champs que le player JS attend.
     * Si un champ manque, chooseModeFromProbe() peut choisir le mauvais mode.
     */
    public function testProbeResultContainsRequiredFields(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/probe.php');
        // Le json_encode final doit inclure ces clés
        foreach (['audio', 'subtitles', 'duration', 'videoHeight', 'videoCodec', 'isMP4', 'isMKV', 'colorTransfer'] as $field) {
            $this->assertStringContainsString(
                "'" . $field . "'",
                $source,
                'Le probe doit retourner le champ ' . $field . ' (requis par player.js)'
            );
        }
    }

    // ── Probe cache invalidation : vérifie isMP4/isMKV ──────────────────

    public function testProbeCacheInvalidationChecksContainerFields(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/probe.php');
        // Le probe invalide les vieilles entrées cache sans isMP4/isMKV
        $this->assertStringContainsString("isset(\$decoded['isMP4'])", $source);
        $this->assertStringContainsString("isset(\$decoded['isMKV'])", $source);
    }

    // ── Cohérence async entre remux et transcode ────────────────────────

    /**
     * Invariant : remux utilise un async plus doux que transcode.
     * Raison : en remux (-c:v copy), les timestamps vidéo sont préservés,
     * donc l'audio doit faire des micro-ajustements (async=1 + min_hard_comp).
     * En transcode, tout est reprocessé, donc resync plus agressive (async=3000).
     */
    public function testAsyncValueDiffersBetweenRemuxAndTranscode(): void
    {
        $functions = file_get_contents(__DIR__ . '/../functions.php');
        $transcode = buildFilterGraph(720, 0);

        // Le remux async vit dans la constante FFMPEG_REMUX_AUDIO_FILTER (functions.php)
        preg_match("/FFMPEG_REMUX_AUDIO_FILTER.*?async=(\d+)/", $functions, $remuxMatch);
        preg_match('/async=(\d+)/', $transcode, $transcodeMatch);

        $this->assertNotEmpty($remuxMatch, 'FFMPEG_REMUX_AUDIO_FILTER doit avoir un async=');
        $this->assertNotEmpty($transcodeMatch, 'Transcode doit avoir un aresample async');
        $this->assertLessThan(
            (int)$transcodeMatch[1],
            (int)$remuxMatch[1],
            'Remux async doit être inférieur à transcode async'
        );
    }

    // ── validateFilterMode ──────────────────────────────────────────────

    /** @dataProvider validFilterProvider */
    public function testValidateFilterModeAcceptsValid(string $mode): void
    {
        $this->assertSame($mode, validateFilterMode($mode));
    }

    public static function validFilterProvider(): array
    {
        return [
            'none'        => ['none'],
            'anime'       => ['anime'],
            'detail'      => ['detail'],
            'night'       => ['night'],
            'deinterlace' => ['deinterlace'],
            'hdr'         => ['hdr'],
        ];
    }

    /** @dataProvider invalidFilterProvider */
    public function testValidateFilterModeRejectsInvalid(string $mode): void
    {
        $this->assertSame('none', validateFilterMode($mode));
    }

    public static function invalidFilterProvider(): array
    {
        return [
            'empty'      => [''],
            'garbage'    => ['foobar'],
            'sql_inject' => ["'; DROP TABLE--"],
            'path'       => ['../etc/passwd'],
        ];
    }

    // ── write_stream_info : $is_new_stream toujours défini ──────────────

    public function testWriteStreamInfoHasExplicitIsNewStreamInit(): void
    {
        $source = file_get_contents(__DIR__ . '/../download.php');
        // Vérifier que $is_new_stream est initialisé AVANT le if/else
        $initPos = strpos($source, '$is_new_stream = false');
        $ifPos = strpos($source, 'if (file_exists($file))');
        $this->assertNotFalse($initPos, '$is_new_stream doit être initialisé explicitement');
        $this->assertLessThan($ifPos, $initPos, '$is_new_stream = false doit précéder le if');
    }

    // ── Player JS : AbortController pour keyframe fetch ─────────────────

    public function testPlayerJsUsesAbortControllerForKeyframe(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $this->assertStringContainsString(
            'keyframeAbort',
            $source,
            'player.js doit stocker un AbortController pour annuler les keyframe lookups obsolètes'
        );
        // Vérifier qu'on abort() avant de lancer un nouveau fetch
        $abortPos = strpos($source, 'keyframeAbort) S.keyframeAbort.abort()');
        $fetchPos = strpos($source, "keyframe=' + t.toFixed");
        $this->assertNotFalse($abortPos, 'Doit appeler abort() sur le précédent controller');
        $this->assertLessThan($fetchPos, $abortPos, 'abort() doit précéder le nouveau fetch');
    }

    // ── Player JS : state machine fields ────────────────────────────────

    /**
     * Vérifie que l'objet S dans player.js contient tous les champs critiques.
     * Régression : un champ manquant cause des undefined silencieux en JS.
     */
    public function testPlayerJsStateHasRequiredFields(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $requiredFields = [
            'step', 'confirmed', 'offset', 'duration',
            'audioIdx', 'quality', 'filter', 'burnSub',
            'isMP4', 'isMKV', 'speed', 'dragging',
            'seekPending', 'hasFailed', 'stallCount',
            'videoHeight', 'seekGen', 'keyframeAbort',
        ];
        foreach ($requiredFields as $field) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($field) . '\s*:/',
                $source,
                'player.js state S doit contenir le champ ' . $field
            );
        }
    }

    // ── Player JS : HEVC ne tente pas native quand !hevcSupported ──────

    /**
     * Vérifie que le bloc HEVC dans _chooseModeFromProbe n'a qu'un seul
     * `return 'native'` (le chemin hevcSupported). Le fallback natif aveugle
     * causait un flash d'erreur visible avant la cascade vers transcode.
     */
    public function testPlayerHevcDoesNotFallbackToNative(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Extraire le bloc HEVC complet (de "if (c === 'hevc')" jusqu'au "return 'transcode'")
        preg_match("/if \(c === 'hevc'\).*?return 'transcode'/s", $source, $matches);
        $this->assertNotEmpty($matches, 'Le bloc HEVC doit exister et se terminer par transcode');
        $hevcBlock = $matches[0];
        $nativeCount = substr_count($hevcBlock, "return 'native'");
        $this->assertSame(1, $nativeCount, 'Le bloc HEVC doit avoir exactement un return native (quand hevcSupported)');
    }

    // ── Player JS : data-track attributes sur les sélecteurs ────────────

    public function testPlayerJsUsesDataTrackAttributes(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        foreach (['audio', 'quality', 'filter', 'subtitle'] as $track) {
            $this->assertStringContainsString(
                "dataset.track = '" . $track . "'",
                $source,
                "Le sélecteur $track doit avoir data-track='$track'"
            );
        }
        // Les lookups doivent utiliser data-track au lieu de previousElementSibling
        $this->assertStringNotContainsString(
            'previousElementSibling',
            $source,
            'Les lookups doivent utiliser data-track, pas previousElementSibling'
        );
    }

    // ── Player JS : canPlay utilise un élément caché ────────────────────

    public function testPlayerJsCachesCanPlayElement(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $this->assertStringContainsString(
            '_canPlayEl',
            $source,
            'canPlay doit utiliser un élément video caché (_canPlayEl) au lieu de createElement à chaque appel'
        );
        // createElement('video') pour _canPlayEl doit apparaître exactement une fois
        // (pas dans la fonction canPlay elle-même)
        $this->assertStringNotContainsString(
            "function canPlay(mime) { var t = document.createElement('video')",
            $source,
            'canPlay ne doit plus créer un élément à chaque appel'
        );
    }

    // ── HLS handler : flock protection au démarrage ─────────────────────

    public function testHlsHasFlockStartupProtection(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        $this->assertStringContainsString(
            '.startup.lock',
            $source,
            'HLS doit utiliser un fichier .startup.lock pour la protection flock'
        );
        $this->assertStringContainsString(
            'flock($startupLock, LOCK_EX)',
            $source,
            'HLS doit acquérir un lock exclusif avant de démarrer ffmpeg'
        );
    }

    // ── HLS handler : segment wait a un timeout borné ─────────────────

    public function testHlsSegmentWaitHasBoundedTimeout(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        // Le busy-poll des segments doit avoir un timeout borné (max 5s = 50 × 100ms)
        $this->assertStringContainsString(
            'usleep(100000)',
            $source,
            'Le segment wait loop doit utiliser usleep(100ms) pour le polling'
        );
        $this->assertStringContainsString(
            '$w < 50',
            $source,
            'Le segment wait loop doit être borné à 50 itérations (5s max)'
        );
    }

    // ── HLS handler : segment filename regex rejette path traversal ─────

    public function testHlsSegmentRegexRejectsTraversal(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        // Doit avoir un preg_match strict sur le nom de segment
        $this->assertMatchesRegularExpression(
            '/preg_match.*seg.*\\\\d.*\\.ts/',
            $source,
            'HLS doit valider le nom de segment avec une regex stricte (segN.ts)'
        );
    }

    // ── Remux/Transcode : slot explicitement nullifié après release ─────

    public function testStreamHandlersReleaseSlotViaShutdownOnly(): void
    {
        foreach (['stream_remux.php', 'stream_transcode.php'] as $handler) {
            $source = file_get_contents(__DIR__ . '/../handlers/' . $handler);
            // Le slot doit être libéré uniquement par le shutdown function (pas de double release)
            $this->assertStringContainsString(
                'register_shutdown_function',
                $source,
                $handler . ' doit utiliser register_shutdown_function pour releaseStreamSlot'
            );
            $this->assertStringContainsString(
                '// Slot released by shutdown function',
                $source,
                $handler . ' ne doit pas appeler releaseStreamSlot explicitement (géré par shutdown)'
            );
        }
    }

    // ── download.php : write_stream_info pour tous les modes ────────────

    public function testAllStreamModesHaveWriteStreamInfo(): void
    {
        $source = file_get_contents(__DIR__ . '/../download.php');
        foreach (['native', 'remux', 'transcode', 'hls'] as $mode) {
            // Chercher write_stream_info avec 'mode' => '$mode' avant le require du handler
            $this->assertStringContainsString(
                "'mode'         => '" . $mode . "'",
                $source,
                "download.php doit appeler write_stream_info avec mode=$mode"
            );
        }
    }

    // ── Transcode handler : Accept-Ranges: none ─────────────────────────

    public function testTranscodeHasAcceptRangesNone(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_transcode.php');
        $this->assertStringContainsString(
            "header('Accept-Ranges: none')",
            $source,
            'Transcode doit envoyer Accept-Ranges: none (pipe ffmpeg ne supporte pas les Range requests)'
        );
    }

    public function testRemuxHasAcceptRangesNone(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_remux.php');
        $this->assertStringContainsString(
            "header('Accept-Ranges: none')",
            $source,
            'Remux doit envoyer Accept-Ranges: none'
        );
    }

    // ── Player JS : transferCfgTo inclut le filtre ─────────────────────

    public function testPlayerJsTransferCfgToIncludesFilter(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Extraire le corps de transferCfgTo
        preg_match('/function transferCfgTo.*?\{(.*?)\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'transferCfgTo doit exister');
        $body = $m[1];
        $this->assertStringContainsString(
            'filter:',
            $body,
            'transferCfgTo doit transférer le filtre à l\'épisode suivant'
        );
    }

    // ── Player JS : late probe restart utilise realTime() ──────────────

    public function testPlayerJsLateProbeRestartUsesRealTime(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le bloc "late probe restart" doit appeler startStream(realTime()), pas startStream(savedPos)
        preg_match('/late probe restart.*?startStream\((\w+(?:\(\))?)\)/s', $source, $m);
        $this->assertNotEmpty($m, 'Le bloc late probe restart doit exister');
        $this->assertSame(
            'realTime()',
            $m[1],
            'Late probe restart doit utiliser realTime() pour reprendre à la position courante, pas savedPos'
        );
    }

    // ── Player JS : _syncTrack appelé après correction keyframe ────────

    public function testPlayerJsSyncTrackAfterKeyframeCorrection(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Après "S.offset = d.pts", on doit trouver _syncTrack() dans les lignes suivantes (conditionné sur isIOS)
        preg_match('/S\.offset = d\.pts;(.{0,200})/s', $source, $m);
        $this->assertNotEmpty($m, 'La correction keyframe S.offset = d.pts doit exister');
        $this->assertStringContainsString(
            '_syncTrack()',
            $m[1],
            'Après correction keyframe (S.offset = d.pts), _syncTrack() doit être appelé pour resync iOS <track>'
        );
    }

    // ── Player JS : un seul listener 'ended' (pas deux séparés) ─────────

    public function testPlayerJsSingleEndedListener(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $count = substr_count($source, "addEventListener('ended'");
        // Un dans le bloc isVideo (combiné position+autonext), un pour l'audio (error handler section)
        // Le fallback audio peut en avoir un aussi — on vérifie qu'il n'y en a pas plus de 2
        $this->assertLessThanOrEqual(
            2,
            $count,
            'player.js ne doit pas avoir plus de 2 listeners ended (un vidéo combiné, un audio fallback)'
        );
    }

    // ── validateBurnSub utilisé dans les handlers ───────────────────────

    /**
     * @dataProvider burnSubHandlerProvider
     */
    public function testHandlersUseValidateBurnSub(string $handler): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/' . $handler);
        if (!str_contains($source, 'burnSub')) {
            $this->assertTrue(true); // Handler n'utilise pas burnSub
            return;
        }
        $this->assertStringContainsString(
            'validateBurnSub',
            $source,
            $handler . ' doit utiliser validateBurnSub() au lieu de max(0, ...)'
        );
        $this->assertStringNotContainsString(
            'max(0, (int)$_GET',
            $source,
            $handler . ' ne doit plus utiliser max(0, ...) pour burnSub (remplacé par validateBurnSub)'
        );
    }

    public static function burnSubHandlerProvider(): array
    {
        return [
            'transcode' => ['stream_transcode.php'],
            'hls'       => ['stream_hls.php'],
        ];
    }

    // ── HLS cleanup : TOCTOU protégé par flock ─────────────────────────

    public function testHlsCleanupUsesFlockForCheckAndDelete(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        // Le check pid + rm -rf doit être protégé par flock sur .startup.lock
        // pour éviter le TOCTOU entre la lecture du pidFile et le rm -rf
        $this->assertStringContainsString(
            'flock -x 200',
            $source,
            'Le cleanup HLS doit acquérir un flock exclusif (fd 200) avant check+rm'
        );
        // Le flock doit rediriger vers .startup.lock
        $this->assertStringContainsString(
            '200>',
            $source,
            'Le fd 200 doit être redirigé vers le fichier .startup.lock'
        );
        $this->assertStringContainsString(
            '.startup.lock',
            $source
        );
    }

    // ── Player JS : un seul listener 'playing' principal ────────────────

    /**
     * Vérifie que le listener 'playing' principal est unique (pas dupliqué).
     * Historiquement il y avait deux listeners séparés : un pour le mode
     * confirmation et un pour le stall watchdog. Fusionnés pour clarté.
     */
    public function testPlayerJsPlayingListenerConsolidated(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Compter les listeners 'playing' sur player (pas sur playBtn etc.)
        // Le pattern "player.addEventListener('playing'" est le seul correct
        $count = substr_count($source, "player.addEventListener('playing'");
        // Un principal (fusionné) + un dans le bloc isVideo pour l'UI (playBtn icon)
        $this->assertLessThanOrEqual(
            2,
            $count,
            'player.js doit avoir au max 2 listeners playing (1 principal fusionné + 1 UI dans isVideo)'
        );
    }

    // ── Player JS : startStream clear le stall watchdog ────────────────

    public function testPlayerJsStartStreamClearsStallWatchdog(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Extraire le corps de startStream
        preg_match('/function startStream\(resumeAt\)\s*\{(.*?)\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'startStream doit exister');
        $this->assertStringContainsString(
            'clearStallWatchdog()',
            $m[1],
            'startStream doit appeler clearStallWatchdog() pour annuler les retries fantômes'
        );
    }

    // ── Player JS : resume "Début" synchronise les sélecteurs ──────────

    public function testPlayerJsResumeBannerSyncsDropdowns(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Extraire le bloc "Début" (noBtn click handler)
        preg_match('/noBtn\.addEventListener.*?\{(.*?)\}\);/s', $source, $m);
        $this->assertNotEmpty($m, 'Le handler noBtn doit exister');
        $body = $m[1];
        // Doit synchroniser le dropdown qualité
        $this->assertStringContainsString(
            'data-track="quality"',
            $body,
            'Le bouton Début doit synchroniser le sélecteur de qualité'
        );
        // Doit synchroniser le dropdown audio
        $this->assertStringContainsString(
            'data-track="audio"',
            $body,
            'Le bouton Début doit synchroniser le sélecteur audio'
        );
    }

    // ── Player JS : subtitle fetch vérifie r.ok ────────────────────────

    public function testPlayerJsSubtitleFetchChecksResponseOk(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le fetch subtitle doit vérifier r.ok
        $this->assertStringContainsString(
            '!r.ok',
            $source,
            'Le fetch sous-titres doit vérifier r.ok pour détecter les erreurs serveur'
        );
        // Doit rejeter les réponses vides
        $this->assertStringContainsString(
            '!t.trim()',
            $source,
            'Le fetch sous-titres doit rejeter les réponses VTT vides'
        );
    }

    // ── Player JS : restoreCfgUI valide les index ──────────────────────

    public function testPlayerJsRestoreCfgUIValidatesIndices(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Extraire le corps de restoreCfgUI
        preg_match('/function restoreCfgUI\(\)\s*\{(.*?)\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'restoreCfgUI doit exister');
        $body = $m[1];
        // Si l'option audio n'existe pas, S.audioIdx doit être reset
        $this->assertStringContainsString(
            'audio index',
            $body,
            'restoreCfgUI doit détecter et loguer les index audio invalides'
        );
        // Si l'option qualité n'existe pas, S.quality doit être reset
        $this->assertStringContainsString(
            'quality',
            $body,
            'restoreCfgUI doit détecter les qualités invalides'
        );
    }

    // ── Player JS : mode badge gère quality hors liste ─────────────────

    public function testPlayerJsModeBadgeHandlesInvalidQuality(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le mode badge doit gérer qi < 0 (quality pas dans allQ)
        $this->assertStringContainsString(
            'qi < 0',
            $source,
            'Le mode badge doit gérer le cas où S.quality n\'est pas dans allQ (qi < 0)'
        );
    }

    // ── Player JS : error messages catégorisés par MediaError code ─────

    public function testPlayerJsHasErrorMessageHelper(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $this->assertStringContainsString(
            'function errorMessage(',
            $source,
            'player.js doit avoir un helper errorMessage(errCode, mode)'
        );
    }

    /**
     * Les 4 codes MediaError doivent avoir des messages distincts pour
     * aider l'user à comprendre la cause (pas tout "Lecture impossible").
     * 1=ABORTED, 2=NETWORK, 3=DECODE, 4=SRC_NOT_SUPPORTED.
     */
    public function testPlayerJsErrorMessagesDifferByCode(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        preg_match('/function errorMessage.*?\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'errorMessage doit exister');
        // Doit gérer les 4 codes
        $this->assertStringContainsString('errCode === 1', $m[0], 'errorMessage doit traiter MEDIA_ERR_ABORTED (1)');
        $this->assertStringContainsString('errCode === 2', $m[0], 'errorMessage doit traiter MEDIA_ERR_NETWORK (2)');
        $this->assertStringContainsString('errCode === 3', $m[0], 'errorMessage doit traiter MEDIA_ERR_DECODE (3)');
        $this->assertStringContainsString('errCode === 4', $m[0], 'errorMessage doit traiter MEDIA_ERR_SRC_NOT_SUPPORTED (4)');
        // Décode en transcode = serveur, décode en native = fichier
        $this->assertStringContainsString(
            "mode === 'transcode'",
            $m[0],
            'errorMessage doit différencier erreur décode serveur vs client'
        );
    }

    public function testPlayerJsOnFailUsesErrorMessage(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // onFail doit utiliser errorMessage() (pas un message hardcodé)
        preg_match('/function onFail.*?^    \}/sm', $source, $m);
        $this->assertNotEmpty($m, 'onFail doit exister');
        $this->assertStringContainsString(
            'errorMessage(errCode',
            $m[0],
            'onFail doit appeler errorMessage(errCode, mode) au lieu de hardcoder les messages'
        );
    }

    // ── Player JS : network error exhaustion → erreur définitive ───────

    public function testPlayerJsNetworkErrorExhaustedShowsError(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Après 3 retries réseau (errCode === 2), doit afficher une erreur au lieu de cascader
        $this->assertStringContainsString(
            'erreur r',
            mb_strtolower($source),
            'player.js doit afficher une erreur réseau persistante après épuisement des retries'
        );
        // Vérifier qu'il y a un return après l'erreur réseau épuisée
        preg_match('/Erreur r.*seau persistante.*?return;/s', $source, $m);
        $this->assertNotEmpty($m, 'L\'erreur réseau épuisée doit return sans cascader le mode');
    }

    // ── Player JS : VP9 vérifie nativeAudio ────────────────────────────

    public function testPlayerJsVp9ChecksNativeAudio(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le bloc vp9 doit tester nativeAudio
        preg_match("/c === 'vp9'.*?return 'transcode';/s", $source, $m);
        $this->assertNotEmpty($m, 'Le bloc VP9 doit exister dans _chooseModeFromProbe');
        $this->assertStringContainsString(
            'nativeAudio',
            $m[0],
            'VP9 doit vérifier nativeAudio avant de déclarer natif'
        );
    }

    // ── Player JS : Subs.render compare avant innerHTML ─────────────────

    public function testPlayerJsSubsRenderComparesBeforeDom(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        preg_match('/render:\s*function\(\)\s*\{(.*?)\n        \},/s', $source, $m);
        $this->assertNotEmpty($m, 'Subs.render doit exister');
        $this->assertStringContainsString(
            '_lastTxt',
            $m[1],
            'Subs.render doit comparer le texte via _lastTxt avant de toucher le DOM'
        );
    }

    // ── Player JS : VTT sanitize strips unknown tags ────────────────────

    public function testPlayerJsVttSanitizeStripsUnknownTags(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le sanitize doit utiliser un placeholder pour les tags connus
        // au lieu d'échapper tout puis ré-autoriser
        $this->assertStringContainsString(
            "\\x00",
            $source,
            'VTT sanitize doit utiliser des placeholders pour préserver les tags connus et strip les inconnus'
        );
    }

    // ── Player JS : VTT positioning (line: → top/bottom) ────────────────

    public function testPlayerJsParsesVttLineSetting(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        $this->assertStringContainsString(
            'function parseVttLine',
            $source,
            'parseVTT doit avoir un helper parseVttLine pour extraire line:N%'
        );
        // Le parser doit matcher line:N% (avec %)
        $this->assertMatchesRegularExpression(
            '/\\\\bline:.*%/',
            $source,
            'parseVttLine doit matcher line:N% via regex'
        );
    }

    public function testPlayerJsCueHasPosField(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Chaque cue parsé doit avoir un pos: 'top' | 'bottom'
        $this->assertMatchesRegularExpression(
            "/pos:.*'top'.*'bottom'/s",
            $source,
            'parseVTT doit assigner pos: top|bottom selon line:N%'
        );
    }

    public function testPlayerJsHasTopOverlay(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Subs doit avoir _divTop pour les cues top
        $this->assertStringContainsString(
            '_divTop',
            $source,
            'Subs doit avoir _divTop pour les cues VTT positionnés en haut'
        );
        // CSS class dédiée
        $css = file_get_contents(__DIR__ . '/../player.css');
        $this->assertStringContainsString(
            'sub-overlay-top',
            $css,
            'player.css doit avoir une classe sub-overlay-top'
        );
    }

    public function testPlayerJsRendersMultipleConcurrentCues(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le render doit looper sur les cues actifs (pas juste this._idx)
        // pour permettre top + bottom simultanés
        preg_match('/render:\s*function\(\)\s*\{(.*?)\n        \},/s', $source, $m);
        $this->assertNotEmpty($m, 'Subs.render doit exister');
        $this->assertStringContainsString(
            'topTxt',
            $m[1],
            'Subs.render doit séparer topTxt et bottomTxt'
        );
        $this->assertStringContainsString(
            'bottomTxt',
            $m[1],
            'Subs.render doit séparer topTxt et bottomTxt'
        );
    }

    public function testPlayerJsIosTrackPreservesLinePosition(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le _syncTrack iOS doit ajouter line:10% pour les cues top
        // (sinon iOS native fullscreen affiche tout en bas)
        $this->assertStringContainsString(
            "line:10%",
            $source,
            '_syncTrack iOS doit préserver line:10% pour les cues top'
        );
    }

    // ── Player JS : mode badge reset filter en natif ────────────────────

    public function testPlayerJsModeBadgeResetsFilterOnNative(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Dans le cycle mode badge, quand probeNative → 'native', filter doit être reset
        preg_match("/probeNative\).*?S\.step = S\.confirmed = 'native'(.*?)return;/s", $source, $m);
        $this->assertNotEmpty($m, 'Le branch probeNative → native doit exister dans le mode badge');
        $this->assertStringContainsString(
            "S.filter = 'none'",
            $m[1],
            'Le mode badge doit reset S.filter à none quand on repasse en natif'
        );
    }

    // ── Player JS : touch-seek utilise seekToFraction ───────────────────

    public function testPlayerJsTouchSeekUsesSeekToFraction(): void
    {
        $source = file_get_contents(__DIR__ . '/../player.js');
        // Le touchend dans la zone vidéo (swipe seek) doit utiliser seekToFraction
        preg_match('/touchend.*?swiping.*?duration(.*?)\}\);/s', $source, $m);
        $this->assertNotEmpty($m, 'Le handler touchend swipe doit exister');
        $this->assertStringContainsString(
            'seekToFraction',
            $m[0],
            'Le touch-seek doit utiliser seekToFraction (avec debounce) au lieu de startStream direct'
        );
    }

    // ── HLS handler : setsid pour ffmpeg background ─────────────────────

    public function testHlsUsesSetsidForFfmpeg(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        $this->assertStringContainsString(
            'setsid',
            $source,
            'HLS doit lancer ffmpeg via setsid pour survivre au kill du process PHP'
        );
    }

    // ── HLS handler : startup poll seg0.ts (pas juste filesize m3u8) ────

    /**
     * filesize($m3u8) > 20 trigger avant le 1er segment réel parce que ffmpeg
     * écrit le header m3u8 (~80 bytes) au démarrage. Solution : poll seg0.ts
     * directement, qui n'apparaît que quand un chunk a été encodé.
     */
    public function testHlsStartupPollsSeg0NotJustM3u8(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        $this->assertStringContainsString(
            '/seg0.ts',
            $source,
            'HLS startup doit poller seg0.ts pour détecter le premier vrai segment'
        );
        // Le check filesize > 20 sur m3u8 ne doit plus exister (signal peu fiable)
        $this->assertStringNotContainsString(
            'filesize($m3u8) > 20',
            $source,
            'HLS ne doit plus se baser sur filesize($m3u8) > 20 (header ffmpeg = faux positif)'
        );
    }

    /**
     * Cold-start HEVC 4K + burnSub peut prendre 15-20s. 15s d'origine était trop court.
     * 30s = 300 × 100ms.
     */
    public function testHlsStartupTimeoutIs30Seconds(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        $this->assertStringContainsString(
            '$w < 300',
            $source,
            'Le startup wait loop doit être de 300 itérations (30s) pour HEVC 4K cold-start'
        );
    }

    /**
     * Si ffmpeg meurt prématurément (probe mauvais codec, fichier corrompu),
     * inutile d'attendre 30s. Check /proc/PID périodique pour court-circuiter.
     */
    public function testHlsStartupShortCircuitsOnFfmpegDeath(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        // Le startup wait doit checker /proc/PID périodiquement
        preg_match('/\$w < 300.*?usleep\(100000\)/s', $source, $m);
        $this->assertNotEmpty($m, 'Le startup wait loop doit exister');
        $this->assertStringContainsString(
            "file_exists('/proc/'",
            $m[0],
            'Le startup wait doit court-circuiter si ffmpeg meurt (check /proc/PID)'
        );
    }

    /**
     * Le 504 doit retourner un m3u8 syntaxiquement valide pour que les
     * clients (Safari iOS notamment) gèrent l'erreur proprement, pas un
     * tag custom non-standard #EXT-X-ERROR.
     */
    public function testHlsTimeoutResponseIsValidM3u8(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/stream_hls.php');
        // L'ancien tag custom non-standard ne doit plus exister
        $this->assertStringNotContainsString(
            'EXT-X-ERROR',
            $source,
            'Le 504 ne doit pas utiliser le tag non-standard EXT-X-ERROR'
        );
        // Le timeout response doit avoir un m3u8 valide (EXT-X-ENDLIST)
        $this->assertStringContainsString(
            'EXT-X-ENDLIST',
            $source,
            'Le 504 timeout doit retourner un m3u8 valide avec EXT-X-ENDLIST'
        );
    }

    // ── TMDB handler : seasonPattern inclut saga/arc/part ───────────────

    public function testTmdbSeasonPatternIncludesSagaArcPart(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/tmdb.php');
        preg_match('/seasonPattern\s*=\s*\'(.*?)\'/s', $source, $m);
        $this->assertNotEmpty($m, 'seasonPattern doit exister dans tmdb.php');
        $pattern = $m[1];
        $this->assertMatchesRegularExpression('/saga/', $pattern, 'seasonPattern doit matcher saga');
        $this->assertMatchesRegularExpression('/arc/', $pattern, 'seasonPattern doit matcher arc');
        $this->assertMatchesRegularExpression('/part/', $pattern, 'seasonPattern doit matcher part');
    }

    // ── TMDB handler : tmdb_set invalide les enfants saison ─────────────

    public function testTmdbSetInvalidatesSeasonChildren(): void
    {
        $source = file_get_contents(__DIR__ . '/../handlers/tmdb.php');
        // Quand tmdb_set change le tmdb_id, les enfants hérités doivent être invalidés
        $this->assertStringContainsString(
            'resetSeason',
            $source,
            'tmdb_set doit invalider les enfants saison quand le tmdb_id parent change'
        );
        $this->assertStringContainsString(
            'old_id',
            $source,
            'tmdb_set doit comparer l\'ancien tmdb_id pour détecter un changement'
        );
    }

    // ── Worker : LIMIT sur la requête pending ───────────────────────────

    public function testWorkerUsesLimitOnPendingQuery(): void
    {
        $source = file_get_contents(__DIR__ . '/../tools/tmdb-worker.php');
        $this->assertMatchesRegularExpression(
            '/SELECT.*poster_url IS NULL.*LIMIT\s+\d+/si',
            $source,
            'Le worker doit paginer la requête pending avec LIMIT'
        );
    }

    // ── Worker : lock file chmod 0644 ───────────────────────────────────

    public function testWorkerLockFileChmod0644(): void
    {
        $source = file_get_contents(__DIR__ . '/../tools/tmdb-worker.php');
        $this->assertStringContainsString(
            '0644',
            $source,
            'Le lock file du worker doit être 0644 (pas world-writable)'
        );
        $this->assertStringNotContainsString(
            '0666',
            $source,
            'Le lock file ne doit pas être 0666'
        );
    }

    // ── Worker : matching délégué à tmdb_match (boucle word-removal) ─────

    public function testWorkerUsesTmdbMatch(): void
    {
        $source = file_get_contents(__DIR__ . '/../tools/tmdb-worker.php');
        // La recherche du candidat passe désormais par tmdb_match (word-removal + cache).
        $this->assertStringContainsString(
            'tmdb_match(',
            $source,
            'Le worker doit déléguer la recherche du candidat à tmdb_match'
        );
    }

    // ── Recherche TMDB : word truncation garde au moins 1 mot ───────────

    public function testTmdbMatchKeepsAtLeastOneWord(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        // La boucle word-removal de tmdb_match borne le minimum à au moins 1 mot.
        $this->assertStringContainsString(
            'max(1, count($words) - 4)',
            $source,
            'tmdb_match doit garder au minimum 1 mot et borner la récursion à 5 essais'
        );
    }

    // ── Recherche TMDB : rate limit ≥ 250ms (dans tmdb_search_candidates) ─

    public function testTmdbSearchRateLimit(): void
    {
        $source = file_get_contents(__DIR__ . '/../functions.php');
        // Le usleep entre les requêtes TMDB vit maintenant dans tmdb_search_candidates.
        preg_match_all('/usleep\((\d+)\)/', $source, $matches);
        $this->assertNotEmpty($matches[1], 'functions.php doit avoir des usleep pour le rate limit');
        $apiSleeps = array_filter($matches[1], fn($us) => (int)$us >= 250000);
        $this->assertNotEmpty(
            $apiSleeps,
            'tmdb_search_candidates doit avoir au moins un usleep ≥ 250ms pour le rate limit TMDB'
        );
    }

    // ── download.php : poster loading vérifie has-poster ────────────────

    public function testPosterLoadingChecksHasPoster(): void
    {
        $source = file_get_contents(__DIR__ . '/../download.php');
        // loadPosterImage doit vérifier has-poster en début de fonction
        preg_match('/function loadPosterImage\(.*?\{(.*?)\n    \}/s', $source, $m);
        $this->assertNotEmpty($m, 'loadPosterImage doit exister');
        $this->assertStringContainsString(
            'has-poster',
            $m[1],
            'loadPosterImage doit vérifier has-poster pour éviter la race condition'
        );
    }

    // ── download.php : grid cards utilisent contain ─────────────────────

    public function testGridCardsUseContain(): void
    {
        $source = file_get_contents(__DIR__ . '/../download.php');
        $this->assertStringContainsString(
            'contain:',
            $source,
            'Les grid cards doivent utiliser CSS contain pour limiter les reflows'
        );
    }

    // ── download.php : context menu flip-up ─────────────────────────────

    public function testContextMenuFlipsUp(): void
    {
        $source = file_get_contents(__DIR__ . '/../download.php');
        $this->assertStringContainsString(
            'flip-up',
            $source,
            'Le context menu doit avoir un flip-up pour éviter le débordement bas du viewport'
        );
    }

    // ── download.php : scroll jank debounce ─────────────────────────────

    public function testMobileScrollDebounced(): void
    {
        $source = file_get_contents(__DIR__ . '/../download.php');
        $this->assertStringContainsString(
            'requestAnimationFrame',
            $source,
            'Le scroll listener mobile doit être debounced via requestAnimationFrame'
        );
    }
}
