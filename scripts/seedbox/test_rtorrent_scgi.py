#!/usr/bin/env python3
"""
Tests unitaires pour rtorrent_scgi.py

Lance avec : python3 test_rtorrent_scgi.py
Aucune connexion rtorrent nécessaire — le socket est mocké.
"""

import sys
import os
import unittest
from unittest.mock import patch, MagicMock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import rtorrent_scgi


def make_response(xml_body: str) -> bytes:
    """Simule une réponse HTTP SCGI avec headers + corps."""
    return b'HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\n\r\n' + xml_body.encode('utf-8')


def mock_scgi(response_xml: str):
    """Context manager qui mocke scgi_call pour retourner response_xml."""
    return patch.object(rtorrent_scgi, 'scgi_call', return_value=response_xml)


# ── scgi_call ─────────────────────────────────────────────────────────────────

class TestScgiCall(unittest.TestCase):

    def _make_socket(self, recv_data: bytes):
        sock = MagicMock()
        # recv retourne les données en deux chunks puis b'' pour signaler la fin
        sock.recv.side_effect = [recv_data, b'']
        return sock

    @patch('rtorrent_scgi.socket.socket')
    def test_strips_http_headers(self, mock_socket_cls):
        """Le corps XML est extrait après les headers HTTP."""
        body = b'<methodResponse><params><param><value><i8>1</i8></value></param></params></methodResponse>'
        raw = b'HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\n\r\n' + body
        mock_socket_cls.return_value = self._make_socket(raw)

        result = rtorrent_scgi.scgi_call('<?xml version="1.0"?><methodCall/>')
        self.assertEqual(result, body.decode('utf-8'))

    @patch('rtorrent_scgi.socket.socket')
    def test_no_headers(self, mock_socket_cls):
        """Réponse sans headers HTTP retournée telle quelle."""
        body = b'<methodResponse/>'
        mock_socket_cls.return_value = self._make_socket(body)

        result = rtorrent_scgi.scgi_call('<?xml version="1.0"?><methodCall/>')
        self.assertEqual(result, '<methodResponse/>')

    @patch('rtorrent_scgi.socket.socket')
    def test_netstring_format(self, mock_socket_cls):
        """Vérifie que la requête SCGI est bien formatée (netstring + virgule)."""
        mock_socket_cls.return_value = self._make_socket(b'<methodResponse/>')
        xml = '<?xml version="1.0"?><methodCall/>'
        rtorrent_scgi.scgi_call(xml)

        sent = mock_socket_cls.return_value.sendall.call_args[0][0]
        body = xml.encode('utf-8')
        # La netstring doit commencer par "len(headers):" et se terminer par ","
        colon_pos = sent.index(b':')
        header_len = int(sent[:colon_pos])
        headers = sent[colon_pos + 1: colon_pos + 1 + header_len]
        comma = sent[colon_pos + 1 + header_len: colon_pos + 2 + header_len]
        self.assertEqual(comma, b',')
        self.assertIn(b'CONTENT_LENGTH\x00', headers)
        self.assertIn(b'SCGI\x001\x00', headers)
        self.assertIn(str(len(body)).encode(), headers)
        # Le corps XML suit la virgule
        self.assertEqual(sent[colon_pos + 2 + header_len:], body)


# ── download_list ─────────────────────────────────────────────────────────────

class TestDownloadList(unittest.TestCase):

    def test_parses_hashes(self):
        """Extrait correctement les hashes de 40 caractères."""
        xml = '''<?xml version="1.0" encoding="UTF-8"?>
        <methodResponse><params><param><value><array><data>
          <value><string>AABBCCDD112233440000AABBCCDD112233440000</string></value>
          <value><string>1122334455667788990011223344556677889900</string></value>
        </data></array></value></param></params></methodResponse>'''

        with mock_scgi(xml):
            result = rtorrent_scgi.download_list()

        self.assertEqual(result, [
            'AABBCCDD112233440000AABBCCDD112233440000',
            '1122334455667788990011223344556677889900',
        ])

    def test_ignores_non_hash_strings(self):
        """Ignore les chaînes qui ne sont pas des hashes (longueur != 40)."""
        xml = '''<?xml version="1.0"?>
        <methodResponse><params><param><value><array><data>
          <value><string>AABBCCDD112233440000AABBCCDD112233440000</string></value>
          <value><string>troplong_AABBCCDD112233440000AABBCCDD112233440000</string></value>
          <value><string>court</string></value>
        </data></array></value></param></params></methodResponse>'''

        with mock_scgi(xml):
            result = rtorrent_scgi.download_list()

        self.assertEqual(len(result), 1)
        self.assertEqual(result[0], 'AABBCCDD112233440000AABBCCDD112233440000')

    def test_empty_list(self):
        """Retourne une liste vide si aucun torrent."""
        xml = '''<?xml version="1.0"?>
        <methodResponse><params><param><value><array><data>
        </data></array></value></param></params></methodResponse>'''

        with mock_scgi(xml):
            result = rtorrent_scgi.download_list()

        self.assertEqual(result, [])


# ── multicall ─────────────────────────────────────────────────────────────────

class TestMulticall(unittest.TestCase):

    def _xml_response(self, *per_call_values):
        """
        Construit une réponse system.multicall.
        per_call_values : liste de listes de (tag, value), ex. [('string', 'foo'), ('i8', '1')]
        """
        items = ''
        for call_vals in per_call_values:
            inner = ''.join(
                f'<value><{tag}>{val}</{tag}></value>'
                for tag, val in call_vals
            )
            items += f'<value><array><data>{inner}</data></array></value>'
        return (
            '<?xml version="1.0" encoding="UTF-8"?>'
            '<methodResponse><params><param>'
            f'<value><array><data>{items}</data></array></value>'
            '</param></params></methodResponse>'
        )

    def test_string_values(self):
        """Parse correctement les valeurs <string>."""
        xml = self._xml_response(
            [('string', 'Les.Inseparables.2023')],
            [('string', '/mnt/nvme-seedbox/ropixv2/downloads/Les.Inseparables.2023')],
        )
        with mock_scgi(xml):
            result = rtorrent_scgi.multicall([
                {'method': 'd.name',      'params': ['HASH']},
                {'method': 'd.directory', 'params': ['HASH']},
            ])
        self.assertEqual(result, [
            'Les.Inseparables.2023',
            '/mnt/nvme-seedbox/ropixv2/downloads/Les.Inseparables.2023',
        ])

    def test_i8_integer_values(self):
        """Parse correctement les entiers <i8> (format rtorrent pour les grands entiers)."""
        xml = self._xml_response(
            [('i8', '1')],           # d.complete
            [('i8', '224655058')],   # d.up.total
        )
        with mock_scgi(xml):
            result = rtorrent_scgi.multicall([
                {'method': 'd.complete',  'params': ['HASH']},
                {'method': 'd.up.total',  'params': ['HASH']},
            ])
        self.assertEqual(result, ['1', '224655058'])

    def test_mixed_types(self):
        """Parse un batch mixte string + i8 sur plusieurs torrents (cas réel nvme-to-raid)."""
        xml = self._xml_response(
            [('string', 'Torrent.A')],   # d.name torrent 1
            [('i8', '1')],               # d.complete torrent 1
            [('string', '/mnt/nvme-seedbox/ropixv2/downloads/Torrent.A')],  # d.directory
            [('string', 'Torrent.B')],   # d.name torrent 2
            [('i8', '0')],               # d.complete torrent 2
            [('string', '/home/storage/users/ropixv2/downloads/Torrent.B')],
        )
        with mock_scgi(xml):
            result = rtorrent_scgi.multicall([
                {'method': 'd.name',      'params': ['H1']},
                {'method': 'd.complete',  'params': ['H1']},
                {'method': 'd.directory', 'params': ['H1']},
                {'method': 'd.name',      'params': ['H2']},
                {'method': 'd.complete',  'params': ['H2']},
                {'method': 'd.directory', 'params': ['H2']},
            ])

        self.assertEqual(len(result), 6)
        # Torrent 1
        self.assertEqual(result[0], 'Torrent.A')
        self.assertEqual(result[1], '1')
        self.assertIn('nvme', result[2])
        # Torrent 2
        self.assertEqual(result[3], 'Torrent.B')
        self.assertEqual(result[4], '0')
        self.assertIn('storage', result[5])

    def test_zero_integer(self):
        """<i8>0</i8> retourne '0' et non une chaîne vide."""
        xml = self._xml_response([('i8', '0')])
        with mock_scgi(xml):
            result = rtorrent_scgi.multicall([{'method': 'd.is_open', 'params': ['H']}])
        self.assertEqual(result, ['0'])

    def test_empty_response(self):
        """Retourne une liste vide si la réponse est vide."""
        xml = '''<?xml version="1.0"?>
        <methodResponse><params><param>
          <value><array><data></data></array></value>
        </param></params></methodResponse>'''
        with mock_scgi(xml):
            result = rtorrent_scgi.multicall([])
        self.assertEqual(result, [])

    def test_empty_string_value(self):
        """<string></string> retourne '0' (valeur nulle)."""
        xml = self._xml_response([('string', '')])
        with mock_scgi(xml):
            result = rtorrent_scgi.multicall([{'method': 'd.name', 'params': ['H']}])
        self.assertEqual(result, ['0'])


# ── scgi_simple ───────────────────────────────────────────────────────────────

class TestScgiSimple(unittest.TestCase):

    def test_no_args(self):
        """Construit un appel sans paramètres."""
        with mock_scgi('<methodResponse/>') as m:
            rtorrent_scgi.scgi_simple('download_list')
        xml_sent = m.call_args[0][0]
        self.assertIn('<methodName>download_list</methodName>', xml_sent)
        self.assertIn('<params></params>', xml_sent)

    def test_single_arg(self):
        """Construit un appel avec un paramètre (hash)."""
        with mock_scgi('<methodResponse/>') as m:
            rtorrent_scgi.scgi_simple('d.start', 'AABBCCDD' * 5)
        xml_sent = m.call_args[0][0]
        self.assertIn('<methodName>d.start</methodName>', xml_sent)
        self.assertIn('AABBCCDD' * 5, xml_sent)

    def test_two_args(self):
        """Construit un appel avec deux paramètres (ex. d.directory.set)."""
        with mock_scgi('<methodResponse/>') as m:
            rtorrent_scgi.scgi_simple('d.directory.set', 'HASH40CHARSAABBCCDD1122334455667788', '/new/path')
        xml_sent = m.call_args[0][0]
        self.assertIn('<methodName>d.directory.set</methodName>', xml_sent)
        self.assertEqual(xml_sent.count('<param>'), 2)
        self.assertIn('/new/path', xml_sent)


if __name__ == '__main__':
    unittest.main(verbosity=2)
