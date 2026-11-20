#!/usr/bin/env python3
"""Crawl a site of the dev server: pages (internal links, depth N) + every CSS / JS / image they reference.
Prints pages with a bad status or PHP error text, and assets that don't load. For checking ported apps.
    python3 dev/crawl-check.py http://hbkpravotice.sk.192-168-0-11.sslip.io:8090/ [depth] [max pages]
"""
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

start = sys.argv[1]
depth = int(sys.argv[2]) if len(sys.argv) > 2 else 2
limit = int(sys.argv[3]) if len(sys.argv) > 3 else 80
host = urllib.parse.urlparse(start).netloc
PHP_ERR = re.compile(r'(<b>(Warning|Notice|Fatal error|Deprecated|Parse error)</b>|Uncaught |Stack trace:)', re.I)


def fetch(url):
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'crawl-check'}), timeout=60) as r:
            return r.status, r.read(), r.headers.get('Content-Type', '')
    except urllib.error.HTTPError as e:
        return e.code, e.read(), ''
    except Exception as e:  # noqa: BLE001 - report every failure
        return 0, str(e).encode(), ''


seen, assets, queue = set(), {}, [(start, 0)]
bad_pages = []
while queue and len(seen) < limit:
    url, d = queue.pop(0)
    if url in seen:
        continue
    seen.add(url)
    status, body, ctype = fetch(url)
    text = body.decode('utf-8', 'replace')
    if status != 200 or PHP_ERR.search(text):
        m = PHP_ERR.search(text)
        bad_pages.append('%s %s %s' % (status, url, text[max(0, m.start() - 80):m.start() + 200].replace('\n', ' ') if m else ''))
    if 'html' not in ctype:
        continue
    for attr, ref in re.findall(r'(href|src|data-slide-bg|data-parallax-img)="([^"]+)"', text):
        if ref.startswith(('mailto:', 'tel:', 'javascript:', '#', 'data:')):
            continue
        full = urllib.parse.urljoin(url, ref).split('#')[0]
        p = urllib.parse.urlparse(full)
        if p.netloc != host:
            continue
        if re.search(r'\.(css|js|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot|mp4|webmanifest|pdf|mp3|wav)(\?|$)', p.path, re.I):
            assets.setdefault(full, url)
        elif attr == 'href' and d < depth:
            queue.append((full, d + 1))

bad_assets = []
for a, page in assets.items():
    status, _, _ = fetch(a)
    if status != 200:
        bad_assets.append('%s %s (on %s)' % (status, a, page))
print('pages checked: %d, assets checked: %d' % (len(seen), len(assets)))
print('bad pages (%d):' % len(bad_pages))
for b in bad_pages[:30]:
    print('  ' + b[:400])
print('bad assets (%d):' % len(bad_assets))
for b in bad_assets[:40]:
    print('  ' + b)
