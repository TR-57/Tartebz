#!/usr/bin/env python3
"""Downloads the old site's images listed in docs/media-manifest.json, byte for byte (never re-encoded).

  python3 tools/fetch-old-media.py            # only the files the site actually used (27)
  python3 tools/fetch-old-media.py --all      # every file in the media library (335)

Saves to backend/storage/app/uploads/wp/<year>/<month>/<file>. For photos WordPress shrank to "-scaled",
the full-size original is downloaded too. Each file is checked to be a real JPG/PNG/WebP/GIF/PDF.
Already-downloaded files are skipped, so it can be re-run."""
import json, os, sys, time, urllib.request, urllib.error

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEST = os.path.join(ROOT, 'backend/storage/app/uploads/wp')
MAGIC = [(b'\xff\xd8\xff', 'jpg'), (b'\x89PNG', 'png'), (b'GIF8', 'gif'), (b'%PDF', 'pdf')]


def kind(data):
    if data[:4] == b'RIFF' and data[8:12] == b'WEBP':
        return 'webp'
    for sig, k in MAGIC:
        if data.startswith(sig):
            return k
    return None


def fetch(url, path):
    if os.path.exists(path) and os.path.getsize(path) > 0:
        return 'kept', os.path.getsize(path)
    req = urllib.request.Request(url, headers={'User-Agent': 'Tartebz media import'})
    with urllib.request.urlopen(req, timeout=40) as r:
        data = r.read(30 * 1024 * 1024)
    if not kind(data):
        raise ValueError('not an image (%s)' % data[:20])
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + '.part'
    with open(tmp, 'wb') as f:
        f.write(data)
    os.replace(tmp, path)
    return 'downloaded', len(data)


def main():
    m = json.load(open(os.path.join(ROOT, 'docs/media-manifest.json')))
    files = m['files'] if '--all' in sys.argv else [a for a in m['files'] if a['usedBy']]
    ok = fail = 0
    for a in files:
        jobs = [(a['url'], a['file'])]
        if a.get('original'):
            jobs.insert(0, (a['original'], a['file'].rsplit('/', 1)[0] + '/' + a['original'].rsplit('/', 1)[1]))
        for url, rel in jobs:
            try:
                state, size = fetch(url, os.path.join(DEST, rel))
                ok += 1
                print(f'{state:10} {size/1024:8.0f} KB  {rel}')
            except (urllib.error.URLError, ValueError, OSError) as e:
                fail += 1
                print(f'FAILED                 {rel}  ({e})')
            time.sleep(0.2)
    print(f'\n{ok} saved, {fail} failed -> {DEST}')
    sys.exit(1 if fail else 0)


if __name__ == '__main__':
    main()
