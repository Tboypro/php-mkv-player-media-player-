#!/usr/bin/env python3
"""Real PHP/MariaDB bookmark tests. Creates and drops a uniquely named test DB.
Set BOOKMARK_DB_HOST/PORT/USER/PASS; user needs CREATE/DROP DATABASE privileges.
"""
import base64
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import uuid
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which(os.environ.get('PHP_BIN', 'php'))
assert PHP, 'PHP CLI required'
env = os.environ.copy()
env.update({
    'TEST_HOST': os.getenv('BOOKMARK_DB_HOST', '127.0.0.1'),
    'TEST_PORT': os.getenv('BOOKMARK_DB_PORT', '3306'),
    'TEST_USER': os.getenv('BOOKMARK_DB_USER', 'root'),
    'TEST_PASS': os.getenv('BOOKMARK_DB_PASS', ''),
    'TEST_DB': 'qplayer_test_' + uuid.uuid4().hex[:12],
})
php = [PHP, '-d', 'mysqli.default_port=' + env['TEST_PORT']]

def sql(statement):
    code = """mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$c = new mysqli(getenv('TEST_HOST'), getenv('TEST_USER'), getenv('TEST_PASS'), '', (int)getenv('TEST_PORT'));
$c->set_charset('utf8mb4');
$c->multi_query(stream_get_contents(STDIN));
do { if ($r = $c->store_result()) { echo json_encode($r->fetch_all(MYSQLI_ASSOC)); $r->free(); } }
while ($c->more_results() && $c->next_result());"""
    return subprocess.check_output(php + ['-r', code], input=statement.encode(), env=env).decode()

name = env['TEST_DB']
created = False
server = None
try:
    sql(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4;')
    created = True
    schema = (ROOT / 'schema.sql').read_text()
    schema = re.sub(r'CREATE DATABASE IF NOT EXISTS q_mp4_player.*?;', '', schema, flags=re.S)
    schema = schema.replace('USE q_mp4_player;', '')
    sql(f'USE `{name}`; ' + schema)
    sql(f"""USE `{name}`;
INSERT INTO videos (id, title, original_filename, stored_filename, duration_seconds, status, last_position)
VALUES (1,'Test one','one.mp4','one.mp4',120,'ready',40),
(2,'Test two','two.mp4','two.mp4',120,'ready',0),
(3,'Pending','three.mp4','three.mp4',120,'processing',0);
UPDATE settings SET setting_value='cloud' WHERE setting_key='conversion_mode';""")
    with tempfile.TemporaryDirectory(prefix='qplayer-bookmarks-') as folder:
        app = Path(folder) / 'app'
        shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'uploads', '__pycache__'))
        config = (app / 'config.php').read_text()
        for key, value in [('DB_HOST', env['TEST_HOST']), ('DB_USER', env['TEST_USER']), ('DB_PASS', env['TEST_PASS']), ('DB_NAME', name)]:
            encoded = base64.b64encode(value.encode()).decode()
            config, count = re.subn(r"define\('" + key + r"',.*?\);", f"define('{key}', base64_decode('{encoded}'));", config)
            assert count == 1
        (app / 'config.php').write_text(config)
        # Exercise the upgrade route with pre-existing videos/settings and repeat it.
        sql(f'USE `{name}`; DROP TABLE video_bookmarks;')
        for _ in range(2):
            subprocess.run(php + [str(app / 'scripts/migrate_bookmarks.php')], check=True, stdout=subprocess.DEVNULL)
        assert json.loads(sql(f'USE `{name}`; SELECT last_position FROM videos WHERE id=1;'))[0]['last_position'] == '40'
        assert json.loads(sql(f"USE `{name}`; SELECT setting_value FROM settings WHERE setting_key='conversion_mode';"))[0]['setting_value'] == 'cloud'
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with (Path(folder) / 'php.log').open('w+') as log:
            server = subprocess.Popen(php + ['-S', f'127.0.0.1:{port}', '-t', str(app)], stdout=log, stderr=log)
            cookie = ''
            token = ''
            def request(path, values=None, csrf=True, method=None):
                headers = {'Cookie': cookie}
                if csrf: headers['X-CSRF-Token'] = token
                if values is not None: headers['Content-Type'] = 'application/x-www-form-urlencoded'
                conn = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
                conn.request(method or ('POST' if values is not None else 'GET'), path, urlencode(values) if values is not None else None, headers)
                response = conn.getresponse()
                body = response.read().decode()
                result = response.status, body, dict(response.getheaders())
                conn.close()
                return result
            for _ in range(100):
                try:
                    code, body, headers = request('/watch.php?id=1')
                    break
                except OSError:
                    time.sleep(.05)
            else: raise AssertionError('PHP server did not start')
            assert code == 200
            cookie = headers['Set-Cookie'].split(';')[0]
            token = re.search(r'data-token="([a-f0-9]+)"', body).group(1)
            def api(action, expected=200, video=1, **values):
                code, body, _ = request('/bookmarks.php', dict(action=action, video_id=video, **values))
                assert code == expected, (code, body)
                return json.loads(body)
            def listing(video=1):
                code, body, _ = request(f'/bookmarks.php?video_id={video}')
                assert code == 200, body
                return json.loads(body)['bookmarks']
            assert listing() == []
            first = api('create', expected=201, position=40, name='Lecture note')['id']
            early = api('create', expected=201, position=5, name='')['id']
            assert [int(r['id']) for r in listing()] == [early, first]
            assert listing(2) == []
            api('rename', id=first, name='Renamed')
            api('rename', id=first, name='Renamed') # unchanged name still succeeds
            assert listing()[1]['name'] == 'Renamed'
            api('rename', expected=404, video=2, id=first, name='Wrong video')
            api('delete', expected=404, video=2, id=first)
            for position in [-1, 121, 'nan', '3.5', '1e2', '']:
                api('create', expected=400, position=position)
            api('create', expected=400, position=2, name='x' * 121)
            api('create', expected=404, video=3, position=2)
            api('create', expected=404, video=999, position=2)
            payload = '<img src=x onerror=alert(1)>'
            api('rename', id=first, name=payload)
            assert listing()[1]['name'] == payload # rendered as text by bookmarks.js
            code, _, _ = request('/bookmarks.php', dict(action='delete', video_id=1, id=first), csrf=False)
            assert code == 403
            code, _, _ = request('/bookmarks.php?video_id=1', method='DELETE')
            assert code == 405
            # Browser tests are optional locally; run against this real isolated backend.
            if os.getenv('BOOKMARK_BROWSER_TEST') == '1':
                subprocess.run(['node', str(ROOT / 'tests/bookmarks_browser_test.js')], check=True,
                               env=dict(os.environ, BOOKMARK_TEST_URL=f'http://127.0.0.1:{port}'))
            api('delete', id=first)
            api('delete', expected=404, id=first)
            code, _, _ = request('/delete.php', {'id': 1})
            assert code == 200
            assert json.loads(sql(f'USE `{name}`; SELECT COUNT(*) AS n FROM video_bookmarks WHERE video_id=1;'))[0]['n'] == '0'
            assert json.loads(sql(f'USE `{name}`; SELECT COUNT(*) AS n FROM videos WHERE id=2;'))[0]['n'] == '1'
            sql(f'USE `{name}`; DROP TABLE video_bookmarks;')
            code, body, _ = request('/bookmarks.php?video_id=2')
            assert code == 503 and 'migrate_bookmarks.php' in body
            server.terminate()
            server.wait(timeout=10)
            server = None
    print('PASS: migration twice, data preservation, create/list/order/rename/delete, scoping, validation, CSRF, cascade, migration error.')
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    if created: sql(f'DROP DATABASE `{name}`;')
