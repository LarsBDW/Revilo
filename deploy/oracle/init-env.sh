#!/bin/sh
set -eu
cd "$(dirname "$0")"
umask 077
python3 - "${1:-}" <<'PY'
import base64
import pathlib
import re
import secrets
import sys

domain = sys.argv[1].lower()
labels = domain.split('.')
if len(domain) > 253 or len(labels) < 2 or not all(re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label) for label in labels) or labels[-1].isdigit() or labels[-1] in {'test', 'invalid', 'localhost', 'local'}:
    sys.exit('Provide a public hostname, e.g. your-name.duckdns.org, without https:// or a path.')
destination = pathlib.Path('.env')
if destination.exists():
    sys.exit('Server configuration already exists; no keys or passwords were changed.')
values = pathlib.Path('.env.example').read_text()
values = values.replace('REVILO_DOMAIN=\n', 'REVILO_DOMAIN=' + domain + '\n')
values = values.replace('APP_KEY=\n', 'APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode() + '\n')
values = values.replace('DB_PASSWORD=\n', 'DB_PASSWORD=' + secrets.token_hex(32) + '\n')
values = values.replace('MYSQL_ROOT_PASSWORD=\n', 'MYSQL_ROOT_PASSWORD=' + secrets.token_hex(32) + '\n')
with destination.open('x') as output:
    output.write(values)
destination.chmod(0o600)
print('Created private server configuration. Keep a secure backup; values were not printed.')
PY
