#!/usr/bin/env python3
"""
Tests unitaires pour rtorrent-check-space.

Lance avec : python3 test_rtorrent_check_space.py
Aucune connexion rtorrent necessaire — scgi_call est mocke.
Le systeme de fichiers tmp est reel pour verifier les symlinks.
"""

import sys
import os
import unittest
import tempfile
import shutil
import importlib.machinery
import types
from pathlib import Path
from unittest.mock import patch, MagicMock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def _load_check_space():
    sys.modules['rtorrent_scgi'] = MagicMock()
    src = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'rtorrent-check-space')
    loader = importlib.machinery.SourceFileLoader('rtorrent_check_space', src)
    mod = types.ModuleType('rtorrent_check_space')
    mod.__file__ = src
    loader.exec_module(mod)
    return mod


_mod = _load_check_space()

HASH = 'AABBCCDD1122334455667788AABBCCDD11223344'


def _xml_str(value):
    return (
        '<?xml version="1.0"?><methodResponse><params><param>'
        f'<value><string>{value}</string></value>'
        '</param></params></methodResponse>'
    )


def _xml_i8(value):
    return (
        '<?xml version="1.0"?><methodResponse><params><param>'
        f'<value><i8>{value}</i8></value>'
        '</param></params></methodResponse>'
    )


class TestCheckSpace(unittest.TestCase):

    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.nvme = str(Path(self.tmpdir) / 'nvme')
        self.raid = str(Path(self.tmpdir) / 'raid')
        os.makedirs(self.nvme)
        os.makedirs(self.raid)

    def tearDown(self):
        shutil.rmtree(self.tmpdir)

    def _run(self, torrent_name, size_bytes, nvme_free_bytes, margin_gb=50):
        """Lance check_space avec scgi_call mocke et disk_usage mocke."""
        responses = {
            'd.size_bytes': _xml_i8(size_bytes),
            'd.name':       _xml_str(torrent_name),
        }

        def fake_scgi_call(xml):
            for method, resp in responses.items():
                if f'<methodName>{method}</methodName>' in xml:
                    return resp
            return '<methodResponse/>'

        captured_scgi = []
        def fake_scgi_simple(method, *args):
            captured_scgi.append((method,) + args)
            return '<methodResponse/>'

        usage = types.SimpleNamespace(free=nvme_free_bytes, used=0, total=nvme_free_bytes)

        with patch.object(_mod, 'scgi_call', side_effect=fake_scgi_call), \
             patch.object(_mod, 'scgi_simple', side_effect=fake_scgi_simple), \
             patch('shutil.disk_usage', return_value=usage):
            result = _mod.check_space(HASH, self.nvme, self.raid, margin_gb)

        return result, captured_scgi

    # ── Cas NVMe ──────────────────────────────────────────────────────────────

    def test_nvme_symlink_created(self):
        """Assez de place sur NVMe : symlink RAID/name -> NVMe/name cree."""
        name = 'Mon.Film.2023.1080p'
        free = 500 * 1024**3   # 500 GB libre
        size = 10  * 1024**3   # 10 GB torrent

        result, scgi = self._run(name, size, free, margin_gb=50)

        self.assertEqual(result, 'nvme')
        symlink = Path(self.raid) / name
        self.assertTrue(symlink.is_symlink())
        self.assertEqual(symlink.readlink(), Path(self.nvme) / name)
        # Aucun appel SCGI (pas de redirection vers RAID)
        self.assertEqual(scgi, [])

    def test_nvme_symlink_not_duplicated(self):
        """Symlink deja existant : pas recree, pas d'erreur."""
        name = 'Serie.S01'
        symlink = Path(self.raid) / name
        symlink.symlink_to(Path(self.nvme) / name)

        result, _ = self._run(name, 1 * 1024**3, 500 * 1024**3)

        self.assertEqual(result, 'nvme')
        self.assertTrue(symlink.is_symlink())  # toujours la, pas ecrase

    def test_nvme_empty_name_no_symlink(self):
        """Nom vide (metadata pas encore chargee) : pas de symlink, pas d'erreur."""
        result, scgi = self._run('', 10 * 1024**3, 500 * 1024**3)

        self.assertEqual(result, 'nvme')
        # Aucun symlink cree dans raid
        self.assertEqual(os.listdir(self.raid), [])
        self.assertEqual(scgi, [])

    # ── Cas RAID ──────────────────────────────────────────────────────────────

    def test_raid_when_nvme_full(self):
        """Pas assez de place NVMe : d.directory.set + d.save_full_session appeles."""
        name = 'Gros.Film.4K'
        free = 100 * 1024**3   # 100 GB libre
        size = 80  * 1024**3   # 80 GB + 50 GB marge = 130 GB > 100 GB

        result, scgi = self._run(name, size, free, margin_gb=50)

        self.assertEqual(result, 'raid')
        methods = [s[0] for s in scgi]
        self.assertIn('d.directory.set', methods)
        self.assertIn('d.save_full_session', methods)
        # d.directory.set pointe vers RAID
        dir_set = next(s for s in scgi if s[0] == 'd.directory.set')
        self.assertEqual(dir_set[2], self.raid)
        # Aucun symlink cree
        self.assertEqual(os.listdir(self.raid), [])

    def test_raid_exact_limit(self):
        """Exactement a la limite (libre == besoin) : NVMe accepte."""
        size = 50 * 1024**3
        free = 50 * 1024**3 + 50 * 1024**3  # size + margin exactement

        result, _ = self._run('Film.Limite', size, free, margin_gb=50)
        self.assertEqual(result, 'nvme')

    def test_raid_one_byte_short(self):
        """Un octet manquant par rapport a la marge : RAID."""
        size = 50 * 1024**3
        free = 50 * 1024**3 + 50 * 1024**3 - 1  # un octet de moins

        result, _ = self._run('Film.Limite', size, free, margin_gb=50)
        self.assertEqual(result, 'raid')

    # ── Robustesse ────────────────────────────────────────────────────────────

    def test_no_unicode_in_output(self):
        """Aucun caractere non-ASCII dans la sortie (evite crash latin-1 rtorrent)."""
        import io
        buf = io.StringIO()
        with patch('sys.stdout', buf):
            self._run('Film.Test', 10 * 1024**3, 500 * 1024**3)
        output = buf.getvalue()
        self.assertTrue(output.isascii(), f'Caractere non-ASCII dans : {output!r}')

    def test_no_unicode_in_output_raid(self):
        """Meme verification pour la branche RAID."""
        import io
        buf = io.StringIO()
        with patch('sys.stdout', buf):
            self._run('Film.Test', 80 * 1024**3, 100 * 1024**3, margin_gb=50)
        output = buf.getvalue()
        self.assertTrue(output.isascii(), f'Caractere non-ASCII dans : {output!r}')


if __name__ == '__main__':
    unittest.main(verbosity=2)
