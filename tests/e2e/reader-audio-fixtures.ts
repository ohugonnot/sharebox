import { execFileSync, spawn, ChildProcess } from 'node:child_process';
import * as fs from 'node:fs';
import * as net from 'node:net';
import * as path from 'node:path';
import * as crypto from 'node:crypto';

export const HOSTILE_PDFS = [
  '"><script>.pdf',
  '<img src=x onerror=window.__PWNED=1>.pdf',
  "l'apostrophe d'été.pdf",
  'été à Noël & co.pdf',
];
/** Page colours of bd/a-comic.cbz (4 pages) and bd/b-comic.cbr (first 2). */
export const COMIC_COLORS: [number, number, number][] = [[220, 30, 30], [30, 200, 30], [30, 30, 220], [230, 220, 20]];
export const HOSTILE_MP3S = ['"><script>.mp3', "it's <b>bold.mp3"];

/** Minimal valid PDF, one text per page. */
export function makePdf(pages: string[], box: [number, number] = [612, 792]): Buffer {
  const [W, H] = box;
  const objs: (string | null)[] = [];
  const add = (s: string | null) => { objs.push(s); return objs.length; };
  add('<< /Type /Catalog /Pages 2 0 R >>');
  add(null);
  const font = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>');
  const kids: string[] = [];
  pages.forEach((txt) => {
    const stream = `BT /F1 64 Tf 60 400 Td (${txt}) Tj ET\n0 0 0 rg 60 200 300 80 re f`;
    const c = add(`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`);
    const p = add(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${W} ${H}] /Contents ${c} 0 R /Resources << /Font << /F1 ${font} 0 R >> >> >>`);
    kids.push(`${p} 0 R`);
  });
  objs[1] = `<< /Type /Pages /Kids [${kids.join(' ')}] /Count ${pages.length} >>`;
  let out = '%PDF-1.4\n';
  const offs: number[] = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const xref = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}

function ffmpeg(...args: string[]) {
  try { execFileSync('ffmpeg', ['-v', 'error', '-y', ...args], { stdio: 'pipe' }); } catch (e: any) { throw new Error('ffmpeg failed: ' + e.stderr?.toString()); }
}

export function createFixtures(): string {
  const dir = `/home/folken/.sharebox_e2e_${crypto.randomBytes(6).toString('hex')}`;
  fs.mkdirSync(dir, { mode: 0o755 });
  try {
    const mk = (...p: string[]) => { const d = path.join(dir, ...p); fs.mkdirSync(d, { recursive: true }); return d; };

    const album = mk('album');
    [1, 2, 3].forEach((n) => ffmpeg('-f', 'lavfi', '-i', `sine=frequency=${300 + n * 100}:duration=2`,
      '-metadata', `title=Piste ${n}`, '-metadata', `track=${n}`, path.join(album, `0${n} - piste.mp3`)));
    ffmpeg('-f', 'lavfi', '-i', 'color=c=red:s=200x200:d=1', '-frames:v', '1', path.join(album, 'cover.jpg'));
    fs.writeFileSync(path.join(album, 'x.nfo'), 'album info\n');
    fs.writeFileSync(path.join(album, '.m3u'), '01 - piste.mp3\n02 - piste.mp3\n03 - piste.mp3\n');

    const livres = mk('livres');
    fs.writeFileSync(path.join(livres, 'a-book.pdf'), makePdf(['PAGE ONE', 'PAGE TWO', 'PAGE THREE']));
    fs.writeFileSync(path.join(livres, 'b-long.pdf'), makePdf(Array.from({ length: 10 }, (_, i) => `P${i + 1}`), [595, 842]));

    const mixte = mk('mixte');
    ['un', 'deux'].forEach((n) => ffmpeg('-f', 'lavfi', '-i', 'color=c=blue:s=64x64:d=1:r=10', '-pix_fmt', 'yuv420p', '-c:v', 'libx264', path.join(mixte, `${n}.mp4`)));
    fs.writeFileSync(path.join(mixte, 'notice.pdf'), makePdf(['NOTICE']));

    ffmpeg('-f', 'lavfi', '-i', 'testsrc=s=320x240:d=3:r=15', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
      '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-c:a', 'aac', '-shortest', path.join(mk('video'), 'clip.mp4'));

    ffmpeg('-f', 'lavfi', '-i', 'sine=frequency=500:duration=2', path.join(mk('mp3seul'), 'solo.mp3'));

    const hostile = mk('hostile');
    HOSTILE_PDFS.forEach((n, i) => fs.writeFileSync(path.join(hostile, n), makePdf([`HOSTILE ${i}`])));
    const hostileAudio = mk('hostile-audio');
    HOSTILE_MP3S.forEach((n, i) => ffmpeg('-f', 'lavfi', '-i', `sine=frequency=${400 + i * 50}:duration=1`, path.join(hostileAudio, n)));

    const src = mk('.comic-src');
    const hex = (c: number[]) => '0x' + c.map((v) => v.toString(16).padStart(2, '0')).join('');
    COMIC_COLORS.forEach((c, i) => ffmpeg('-f', 'lavfi', '-i', `color=c=${hex(c)}:s=240x340`, '-frames:v', '1', '-pix_fmt', 'rgb24', path.join(src, `0${i + 1}.png`)));
    const bd = mk('bd');
    execFileSync('zip', ['-q', '-j', path.join(bd, 'a-comic.cbz'), ...[1, 2, 3, 4].map((n) => path.join(src, `0${n}.png`))]);
    execFileSync('rar', ['a', '-inul', '-ep', path.join(bd, 'b-comic.cbr'), '01.png', '02.png'], { cwd: src });
    fs.writeFileSync(path.join(mk('bd-corrupt'), 'broken.cbz'), Buffer.concat([Buffer.from('PK\x03\x04', 'latin1'), crypto.randomBytes(400)]));
    fs.rmSync(src, { recursive: true, force: true });

    fs.writeFileSync(path.join(mk('divers'), 'note.txt'), 'texte\n');
    execFileSync('chown', ['-R', 'www-data:www-data', dir]);
    fs.chmodSync(dir, 0o755);
    return dir;
  } catch (e) {
    removeFixtures(dir);
    throw e;
  }
}

export function removeFixtures(dir: string) {
  if (dir && path.basename(dir).startsWith('.sharebox_e2e_')) fs.rmSync(dir, { recursive: true, force: true });
}

// Same account as prod: download.php shares /tmp/sharebox_stream_* files with the live site.
const WWW = ['--reuid=www-data', '--regid=www-data', '--clear-groups'];

function freePort(): Promise<number> {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const p = (s.address() as net.AddressInfo).port; s.close(() => resolve(p)); });
    s.on('error', reject);
  });
}

export interface TestServer { base: string; proc: ChildProcess; stop: () => Promise<void> }

export async function startServer(dir: string): Promise<TestServer> {
  const env = { ...process.env, SB_E2E_DIR: dir, PHP_CLI_SERVER_WORKERS: '4' };
  execFileSync('setpriv', [...WWW, 'php', path.join(__dirname, 'reader-audio-seed.php')], { env, stdio: 'pipe' });
  const port = await freePort();
  // exec: no intermediate shell, so SIGTERM reaches php -S itself
  const proc = spawn('sh', ['-c', `exec setpriv ${WWW.join(' ')} php -S 127.0.0.1:${port} "${path.join(__dirname, 'reader-audio-router.php')}"`], { env, stdio: 'ignore' });
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 60; i++) {
    try { const r = await fetch(`${base}/share/favicon.svg`); if (r.status === 200) break; } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 100));
  }
  const stop = () => new Promise<void>((resolve) => {
    if (proc.exitCode !== null) return resolve();
    proc.once('exit', () => resolve());
    proc.kill('SIGTERM');
    setTimeout(() => { if (proc.exitCode === null) proc.kill('SIGKILL'); }, 2000);
  });
  return { base, proc, stop };
}

const STREAM_INFO = /^sharebox_stream_[0-9a-f]{32}\.json$/;
/** download.php drops stream-state files in /tmp: remember the existing ones to remove only ours. */
export const snapshotTmp = () => new Set(fs.readdirSync('/tmp').filter((f) => STREAM_INFO.test(f)));
export function cleanTmp(before: Set<string>) {
  for (const f of fs.readdirSync('/tmp')) if (STREAM_INFO.test(f) && !before.has(f)) fs.rmSync(`/tmp/${f}`, { force: true });
}
