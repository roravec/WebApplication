#!/usr/bin/env python3
"""
Assemble WebApplication + the apps from their own repositories (apps.json) — for the dev server and for deploying.

    python dev/apps.py sync              copy src/ + all apps to the dev server (~/webapplication-dev/src)
    python dev/apps.py package NAME      build a deploy package on the dev server: ~/wa-deploy/NAME/www
                                         (framework, panel, apps from their repos, vendor/, root files of apps,
                                         index.php as index_wa.php; WITHOUT config.php — it stays on the hosting;
                                         the first install used dev/make-prod-config.py)
    python dev/apps.py check             only check that every app folder from apps.json exists

Runs on Windows (Git Bash ssh) or Linux. Text files get LF line endings on the server (as tested there).
"""
import io
import json
import os
import subprocess
import sys
import tarfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'src')
HOST = 'rasto@192.168.0.11'
KEY = os.path.expanduser('~/.ssh/id_ed25519_homeserver')
TEXT = ('.php', '.css', '.js', '.html', '.htaccess', '.json', '.txt', '.md', '.svg', '.xml', '.webmanifest')
SKIP_DIRS = {'.git', 'node_modules'}


def load_apps():
    with open(os.path.join(ROOT, 'apps.json'), encoding='utf-8') as f:
        apps = json.load(f)['apps']
    for name, a in apps.items():
        a['abs'] = os.path.normpath(os.path.join(ROOT, a['path']))
        a['root_abs'] = [os.path.normpath(os.path.join(ROOT, p)) for p in a.get('root_files', [])]
    return apps


def check(apps):
    ok = True
    for name, a in apps.items():
        if not os.path.isfile(os.path.join(a['abs'], 'App.php')):
            print('MISSING app %s: %s/App.php' % (name, a['abs']))
            ok = False
        for p in a['root_abs']:
            if not os.path.isfile(p):
                print('MISSING root file of %s: %s' % (name, p))
                ok = False
        if os.path.isdir(os.path.join(SRC, 'applications', name)):
            print('WARNING: src/applications/%s exists in WebApplication too — the copy from %s wins' % (name, a['repo']))
    return ok


def add_tree(tar, base, arc, exclude):
    for dirpath, dirs, files in os.walk(base):
        rel = os.path.relpath(dirpath, base).replace(os.sep, '/')
        rel = '' if rel == '.' else rel
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS and not exclude((rel + '/' + d).lstrip('/'))]
        for fn in files:
            r = (rel + '/' + fn).lstrip('/')
            if exclude(r):
                continue
            data = open(os.path.join(dirpath, fn), 'rb').read()
            if fn.lower().endswith(TEXT) or fn == '.htaccess':
                data = data.replace(b'\r\n', b'\n')
            info = tarfile.TarInfo((arc + '/' + r).lstrip('/'))
            info.size = len(data)
            info.mode = 0o644
            tar.addfile(info, io.BytesIO(data))


def build_tar(apps, for_package):
    external = set(apps)

    def ex_src(r):
        top = r.split('/')
        if r in ('config/config.php', 'config/config.template.php') and for_package:
            return True
        if r == 'config/config.php':
            return True   # the dev server has its own config (dev/config.php)
        if len(top) >= 2 and top[0] == 'applications' and (top[1] in external or (for_package and top[1] == 'demo')):
            return True
        if top[0] == 'vendor' or (top[0] == 'var' and len(top) > 1 and top[1] in ('log', 'dompdf') and len(top) > 2):
            return True
        return False

    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode='w') as tar:
        add_tree(tar, SRC, '', ex_src)
        for name, a in apps.items():
            add_tree(tar, a['abs'], 'applications/' + name, lambda r: False)
            if for_package:
                for p in a['root_abs']:
                    data = open(p, 'rb').read().replace(b'\r\n', b'\n')
                    info = tarfile.TarInfo(os.path.basename(p))
                    info.size = len(data)
                    info.mode = 0o644
                    tar.addfile(info, io.BytesIO(data))
    return buf.getvalue()


def ssh(cmd, data=None):
    r = subprocess.run(['ssh', '-i', KEY, HOST, cmd], input=data, capture_output=True)
    sys.stdout.write(r.stdout.decode('utf-8', 'replace'))
    sys.stderr.write(r.stderr.decode('utf-8', 'replace'))
    return r.returncode


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in ('sync', 'package', 'check'):
        print(__doc__)
        return 2
    apps = load_apps()
    if not check(apps):
        return 1
    if sys.argv[1] == 'check':
        print('ok: ' + ', '.join(sorted(apps)))
        return 0
    if sys.argv[1] == 'sync':
        data = build_tar(apps, False)
        return ssh('cd ~/webapplication-dev/src && tar xf - && echo "dev server synced: src + ' + ' '.join(sorted(apps)) + '"', data)
    name = sys.argv[2] if len(sys.argv) > 2 else ''
    if not name or '/' in name or name.startswith('.'):
        print('package NAME (e.g. A-20261121)')
        return 2
    data = build_tar(apps, True)
    return ssh('set -e; P=$HOME/wa-deploy/' + name + '; rm -rf $P/www; mkdir -p $P/www; cd $P/www; tar xf -; '
               'cp -a ~/webapplication-dev/src/vendor ./vendor; mv index.php index_wa.php; mkdir -p var/log var/dompdf; '
               'echo "package $P/www: $(find . -type f | wc -l) files, $(du -sh . | cut -f1)"', data)


if __name__ == '__main__':
    sys.exit(main())
