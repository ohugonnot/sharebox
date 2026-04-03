#!/usr/bin/env python3
"""
Tests unitaires pour rtorrent-check-space.

Lance avec : python3 test_rtorrent_check_space.py
Aucune connexion rtorrent necessaire.
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
GB = 1024 ** 3


class TestCheckSpace(unittest.TestCase):

    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.nvme = str(Path(self.tmpdir) / 'nvme')
        self.raid = str(Path(self.tmpdir) / 'raid')
        os.makedirs(self.nvme)
        os.makedirs(self.raid)

    def tearDown(self):
        shutil.rmtree(self.tmpdir)

    def _run(self, name, size_bytes, nvme_free_bytes, is_multi=0, margin_gb=50):
        usage = types.SimpleNamespace(free=nvme_free_bytes, used=0, total=nvme_free_bytes)
        with patch('shutil.disk_usage', return_value=usage):
            result = _mod.check_space(HASH, name, size_bytes, is_multi,
                                      self.nvme, self.raid, margin_gb)
        return result

    # ── Cas NVMe (assez de place) ────────────────────────────────────────────

    def test_nvme_returns_nvme_path(self):
        """Assez de place : retourne le chemin NVMe."""
        result = self._run('Mon.Film.2023.1080p', 10 * GB, 500 * GB)
        self.assertEqual(result, self.nvme)

    def test_nvme_symlink_raid_to_nvme(self):
        """Assez de place : symlink RAID/name -> NVMe/name."""
        name = 'Mon.Film.2023.1080p'
        self._run(name, 10 * GB, 500 * GB)

        symlink = Path(self.raid) / name
        self.assertTrue(symlink.is_symlink())
        self.assertEqual(symlink.readlink(), Path(self.nvme) / name)

    def test_nvme_symlink_not_duplicated(self):
        """Symlink deja existant : pas recree."""
        name = 'Serie.S01'
        symlink = Path(self.raid) / name
        symlink.symlink_to(Path(self.nvme) / name)

        result = self._run(name, 1 * GB, 500 * GB)

        self.assertEqual(result, self.nvme)
        self.assertTrue(symlink.is_symlink())

    def test_nvme_empty_name_no_symlink(self):
        """Nom vide : retourne NVMe, pas de symlink."""
        result = self._run('', 10 * GB, 500 * GB)
        self.assertEqual(result, self.nvme)
        self.assertEqual(os.listdir(self.raid), [])

    def test_nvme_exact_limit(self):
        """Exactement a la limite : NVMe accepte."""
        result = self._run('Film.Limite', 50 * GB, 50 * GB + 50 * GB, margin_gb=50)
        self.assertEqual(result, self.nvme)

    # ── Cas RAID (pas assez de place) ────────────────────────────────────────

    def test_raid_returns_raid_path(self):
        """Pas assez de place : retourne le chemin RAID."""
        result = self._run('Gros.Film.4K', 80 * GB, 100 * GB, margin_gb=50)
        self.assertEqual(result, self.raid)

    def test_raid_no_symlink_on_nvme(self):
        """Pas assez de place : aucun symlink cree sur NVMe."""
        name = 'Gros.Film.4K'
        self._run(name, 80 * GB, 100 * GB, margin_gb=50)
        self.assertFalse((Path(self.nvme) / name).is_symlink())

    def test_raid_no_symlink_on_raid(self):
        """Pas assez de place : aucun symlink cree sur RAID."""
        name = 'Gros.Film.4K'
        self._run(name, 80 * GB, 100 * GB, margin_gb=50)
        self.assertFalse((Path(self.raid) / name).exists())

    def test_raid_one_byte_short(self):
        """Un octet manquant : RAID."""
        result = self._run('Film.Limite', 50 * GB, 50 * GB + 50 * GB - 1, margin_gb=50)
        self.assertEqual(result, self.raid)

    def test_raid_empty_name(self):
        """Nom vide + pas de place : retourne RAID."""
        result = self._run('', 80 * GB, 100 * GB)
        self.assertEqual(result, self.raid)

    # ── Robustesse ────────────────────────────────────────────────────────────

    def test_no_unicode_in_stderr_nvme(self):
        """Aucun caractere non-ASCII dans les logs NVMe."""
        import io
        buf = io.StringIO()
        with patch('sys.stderr', buf):
            self._run('Film.Test', 10 * GB, 500 * GB)
        self.assertTrue(buf.getvalue().isascii())

    def test_no_unicode_in_stderr_raid(self):
        """Aucun caractere non-ASCII dans les logs RAID."""
        import io
        buf = io.StringIO()
        with patch('sys.stderr', buf):
            self._run('Film.Test', 80 * GB, 100 * GB, margin_gb=50)
        self.assertTrue(buf.getvalue().isascii())


if __name__ == '__main__':
    unittest.main(verbosity=2)
