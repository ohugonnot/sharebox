#!/usr/bin/env python3
"""
Tests end-to-end pour la fonction migrate() de nvme-to-raid.

Lance avec : python3 test_nvme_to_raid.py
Utilise un répertoire tmp réel pour tester rsync + rename atomique.
Les appels SCGI et le module rtorrent_scgi sont mockés.
"""

import sys
import os
import unittest
import tempfile
import shutil
import importlib.util
from pathlib import Path
from unittest.mock import patch, MagicMock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def _load_nvme_to_raid():
    """
    Charge nvme-to-raid comme module Python.
    rtorrent_scgi est mocké pour éviter la connexion socket.
    load_conf n'est jamais appelé (main() non exécutée).
    """
    import importlib.machinery, types

    # Injecter un faux rtorrent_scgi avant le chargement du module
    sys.modules['rtorrent_scgi'] = MagicMock()

    src = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'nvme-to-raid')
    loader = importlib.machinery.SourceFileLoader('nvme_to_raid', src)
    mod = types.ModuleType('nvme_to_raid')
    mod.__file__ = src
    loader.exec_module(mod)  # __name__ != '__main__' → main() non appelée

    return mod


# Charger le module une seule fois pour tous les tests
_mod = _load_nvme_to_raid()


class TestMigrate(unittest.TestCase):

    def setUp(self):
        self.tmpdir = tempfile.mkdtemp()
        self.nvme   = Path(self.tmpdir) / 'nvme'
        self.raid   = Path(self.tmpdir) / 'raid'
        self.nvme.mkdir()
        self.raid.mkdir()

        # Configurer les globals du module pour ce test
        _mod.NVME_DOWNLOADS = str(self.nvme)
        _mod.RAID_DOWNLOADS = str(self.raid)
        _mod.log = lambda msg: None

    def tearDown(self):
        shutil.rmtree(self.tmpdir)

    def _make_torrent(self, name, multi=True):
        """Crée les fichiers source sur le NVMe simulé."""
        src = self.nvme / name
        if multi:
            src.mkdir()
            (src / 'video.mkv').write_bytes(b'fake video data ' * 100)
            (src / 'info.nfo').write_text('nfo content')
        else:
            src.write_bytes(b'single file data ' * 100)
        return src

    def _make_symlink(self, name):
        """Crée le symlink RAID/name → NVMe/name (état normal pré-migration)."""
        symlink = self.raid / name
        symlink.symlink_to(self.nvme / name)
        return symlink

    def _run_migrate(self, torrent):
        """
        Lance migrate() avec SCGI mocké et sleep supprimé.
        Retourne (success, scgi_calls_list).
        """
        captured = []

        def fake_scgi(method, *args):
            captured.append((method,) + args)
            return '<methodResponse/>'

        _mod.scgi_simple = fake_scgi

        with patch('time.sleep'):
            result = _mod.migrate(torrent)

        return result, captured

    def test_migration_multi_file_success(self):
        """Migration réussie d'un torrent multi-fichiers."""
        name = 'Mon.Film.2023.1080p'
        self._make_torrent(name, multi=True)
        self._make_symlink(name)

        torrent = {
            'hash': 'HASH40CHARSAABBCCDD1122334455667788',
            'name': name,
            'is_multi': 1,
            'is_open': 1,
            'size_bytes': 4 * 1024**3,
        }

        ok, scgi = self._run_migrate(torrent)

        self.assertTrue(ok)

        # Source NVMe supprimée
        self.assertFalse((self.nvme / name).exists())

        # Destination RAID existe et contient les fichiers
        dst = self.raid / name
        self.assertTrue(dst.is_dir())
        self.assertFalse(dst.is_symlink())
        self.assertTrue((dst / 'video.mkv').exists())
        self.assertTrue((dst / 'info.nfo').exists())

        # Pas de tmp résiduel
        self.assertFalse((self.raid / (name + '.nvme-tmp')).exists())

        # Appels SCGI dans le bon ordre
        methods = [s[0] for s in scgi]
        self.assertEqual(methods[0], 'd.pause')
        self.assertIn('d.directory.set', methods)
        self.assertIn('d.save_full_session', methods)
        self.assertEqual(methods[-1], 'd.resume')

        # d.directory.set pointe vers RAID
        dir_set = next(s for s in scgi if s[0] == 'd.directory.set')
        self.assertEqual(dir_set[2], str(self.raid))

    def test_migration_single_file_success(self):
        """Migration réussie d'un torrent fichier unique."""
        name = 'Film.mkv'
        self._make_torrent(name, multi=False)
        self._make_symlink(name)

        torrent = {
            'hash': 'HASH40CHARSAABBCCDD1122334455667788',
            'name': name,
            'is_multi': 0,
            'is_open': 1,
            'size_bytes': 2 * 1024**3,
        }

        ok, scgi = self._run_migrate(torrent)

        self.assertTrue(ok)
        self.assertFalse((self.nvme / name).exists())
        dst = self.raid / name
        self.assertTrue(dst.exists())
        self.assertFalse(dst.is_symlink())

    def test_symlink_replaced_by_real_dir(self):
        """Le symlink est bien remplacé par le vrai dossier après migration."""
        name = 'Serie.S01'
        self._make_torrent(name, multi=True)
        symlink = self._make_symlink(name)

        # Avant : symlink
        self.assertTrue(symlink.is_symlink())

        torrent = {
            'hash': 'HASH40CHARSAABBCCDD1122334455667788',
            'name': name,
            'is_multi': 1,
            'is_open': 1,
            'size_bytes': 1024**3,
        }
        ok, _ = self._run_migrate(torrent)

        self.assertTrue(ok)
        # Après : vrai dossier, plus de symlink
        self.assertTrue((self.raid / name).exists())
        self.assertFalse((self.raid / name).is_symlink())
        self.assertTrue((self.raid / name).is_dir())

    def test_no_symlink_still_works(self):
        """Migration sans symlink préexistant (cas de reprise)."""
        name = 'Torrent.Sans.Symlink'
        self._make_torrent(name, multi=True)
        # Pas de symlink créé

        torrent = {
            'hash': 'HASH40CHARSAABBCCDD1122334455667788',
            'name': name,
            'is_multi': 1,
            'is_open': 1,
            'size_bytes': 1024**3,
        }
        ok, _ = self._run_migrate(torrent)

        self.assertTrue(ok)
        self.assertTrue((self.raid / name).is_dir())
        self.assertFalse((self.nvme / name).exists())

    def test_source_not_found(self):
        """Retourne False si la source NVMe n'existe pas."""
        torrent = {
            'hash': 'HASH40CHARSAABBCCDD1122334455667788',
            'name': 'Torrent.Inexistant',
            'is_multi': 1,
            'is_open': 1,
            'size_bytes': 0,
        }
        ok, scgi = self._run_migrate(torrent)

        self.assertFalse(ok)
        # Aucun appel SCGI si source introuvable (pas de pause)
        self.assertEqual(len(scgi), 0)

    def test_resume_called_even_on_rsync_failure(self):
        """d.resume est appelé dans le finally même si rsync échoue."""
        name = 'Torrent.Rsync.Fail'
        self._make_torrent(name, multi=True)

        torrent = {
            'hash': 'HASH40CHARSAABBCCDD1122334455667788',
            'name': name,
            'is_multi': 1,
            'is_open': 1,
            'size_bytes': 1024**3,
        }

        import subprocess as sp
        failing = sp.CompletedProcess([], returncode=23, stdout='', stderr='rsync error simulé')

        with patch('subprocess.run', return_value=failing), patch('time.sleep'):
            captured = []
            _mod.scgi_simple = lambda method, *args: captured.append((method,) + args) or '<methodResponse/>'
            result = _mod.migrate(torrent)

        self.assertFalse(result)
        methods = [s[0] for s in captured]
        self.assertIn('d.pause', methods)
        self.assertIn('d.resume', methods)
        self.assertEqual(methods[-1], 'd.resume')

    def test_was_open_false_no_resume(self):
        """d.resume n'est pas appelé si le torrent était fermé avant migration."""
        name = 'Torrent.Ferme'
        self._make_torrent(name, multi=True)

        torrent = {
            'hash': 'HASH40CHARSAABBCCDD1122334455667788',
            'name': name,
            'is_multi': 1,
            'is_open': 0,   # torrent était arrêté
            'size_bytes': 1024**3,
        }
        _, scgi = self._run_migrate(torrent)

        methods = [s[0] for s in scgi]
        self.assertNotIn('d.resume', methods)


if __name__ == '__main__':
    unittest.main(verbosity=2)
