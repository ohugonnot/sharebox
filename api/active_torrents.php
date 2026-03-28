<?php
/**
 * API active_torrents — Liste des torrents actifs via rtorrent SCGI
 */
require_once __DIR__ . '/dashboard_helpers.php';

if (!defined('RTORRENT_SOCK')) {
    define('RTORRENT_SOCK', '');
}

/**
 * Appel XML-RPC vers rtorrent via socket Unix SCGI.
 * Encode les paramètres en tant que strings (suffisant pour d.multicall2).
 *
 * @throws RuntimeException si le socket est inaccessible ou la réponse invalide
 */
function scgi_call_rt(string $sockPath, string $method, string ...$params): mixed
{
    // Construction de la requête XML-RPC
    $xml = '<?xml version="1.0"?><methodCall><methodName>'
         . htmlspecialchars($method, ENT_XML1)
         . '</methodName><params>';
    foreach ($params as $p) {
        $xml .= '<param><value><string>'
              . htmlspecialchars($p, ENT_XML1)
              . '</string></value></param>';
    }
    $xml .= '</params></methodCall>';

    // Encapsulation SCGI
    $headers = "CONTENT_LENGTH\x00" . strlen($xml) . "\x00SCGI\x001\x00";
    $packet  = strlen($headers) . ':' . $headers . ',' . $xml;

    $fp = @stream_socket_client('unix://' . $sockPath, $errno, $errstr, 2.0);
    if ($fp === false) {
        throw new \RuntimeException("rtorrent unavailable ($errstr)");
    }

    stream_set_timeout($fp, 5);
    fwrite($fp, $packet);

    $response = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false || $chunk === '') break;
        $response .= $chunk;
    }
    fclose($fp);

    // Séparation en-têtes HTTP / corps XML-RPC
    $sep  = strpos($response, "\r\n\r\n");
    $body = ($sep !== false) ? substr($response, $sep + 4) : $response;

    return xmlrpc_parse_response($body);
}

/**
 * Parse une réponse XML-RPC en valeur PHP native.
 */
function xmlrpc_parse_response(string $xmlStr): mixed
{
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($xmlStr);
    if (!$xml) return null;
    $value = $xml->params->param->value ?? null;
    if (!$value) return null;
    return xmlrpc_value_to_php($value);
}

/**
 * Convertit récursivement un nœud XML-RPC SimpleXML en valeur PHP.
 */
function xmlrpc_value_to_php(\SimpleXMLElement $v): mixed
{
    if (isset($v->array)) {
        $result = [];
        foreach ($v->array->data->value as $item) {
            $result[] = xmlrpc_value_to_php($item);
        }
        return $result;
    }
    if (isset($v->int))     return (int)(string)$v->int;
    if (isset($v->{'i4'})) return (int)(string)$v->{'i4'};
    if (isset($v->i8))      return (int)(string)$v->i8;
    if (isset($v->double))  return (float)(string)$v->double;
    if (isset($v->boolean)) return (bool)(int)(string)$v->boolean;
    if (isset($v->string))  return (string)$v->string;
    // Valeur par défaut : string brut
    return (string)$v;
}

/**
 * Retourne la liste des torrents actifs.
 * En cas d'erreur : ['downloads' => [], 'uploads' => [], 'error' => '...']
 * @return array<string, mixed>
 */
function get_torrents_from_rtorrent(string $sockPath = RTORRENT_SOCK): array
{
    if ($sockPath === '') {
        return ['downloads' => [], 'uploads' => []];
    }
    try {
        $raw = scgi_call_rt(
            $sockPath,
            'd.multicall2',
            '',
            'main',
            'd.name=',
            'd.up.rate=',
            'd.down.rate=',
            'd.completed_chunks=',
            'd.size_chunks='
        );
    } catch (\RuntimeException $e) {
        return ['downloads' => [], 'uploads' => [], 'error' => 'rtorrent unavailable'];
    }

    if (!is_array($raw)) {
        return ['downloads' => [], 'uploads' => [], 'error' => 'invalid response'];
    }

    $downloads = [];
    $uploads   = [];

    foreach ($raw as $t) {
        if (!is_array($t) || count($t) < 5) continue;
        [$name, $up_rate, $down_rate, $completed, $total] = $t;

        $up_rate   = (int)$up_rate;
        $down_rate = (int)$down_rate;
        $total_i   = (int)$total;
        $progress  = $total_i > 0 ? round((int)$completed / $total_i * 100, 1) : 0.0;

        // Seuil : 50 KB/s minimum pour apparaître (filtre les torrents quasi-inactifs)
        if ($down_rate > 51200) {
            $downloads[] = [
                'name'     => (string)$name,
                'down_mbs' => round($down_rate / 1048576, 2),
                'progress' => $progress,
            ];
        }
        if ($up_rate > 51200) {
            $uploads[] = [
                'name'   => (string)$name,
                'up_mbs' => round($up_rate / 1048576, 2),
            ];
        }
    }

    // Tri décroissant par vitesse
    usort($downloads, fn($a, $b) => $b['down_mbs'] <=> $a['down_mbs']);
    usort($uploads,   fn($a, $b) => $b['up_mbs']   <=> $a['up_mbs']);

    return ['downloads' => $downloads, 'uploads' => $uploads];
}

// Sortie HTTP uniquement si appelé directement (pas inclus depuis un test CLI)
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(get_torrents_from_rtorrent());
}
