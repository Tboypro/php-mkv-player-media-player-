#!/usr/bin/env python3
"""Exercise the real PHP streaming helper over HTTP; no database is needed."""
import http.client
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which(os.environ.get('PHP_BIN', 'php'))
if not PHP:
    raise SystemExit('PHP CLI is required. Install PHP 8.2+ or set PHP_BIN.')


def request(port, method='GET', headers=None, path='/video'):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    try:
        conn.request(method, path, headers=headers or {})
        response = conn.getresponse()
        return response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read()
    finally:
        conn.close()


with tempfile.TemporaryDirectory(prefix='qplayer-stream-test-') as directory:
    work = Path(directory)
    # More than two 1 MiB chunks, with all possible byte values.
    payload = bytes(range(256)) * 9000
    (work / 'video.bin').write_bytes(payload)
    (work / 'empty.bin').write_bytes(b'')
    shutil.copy(ROOT / 'includes/streaming.php', work / 'streaming.php')
    (work / 'router.php').write_text('''<?php
require __DIR__ . '/streaming.php';
$paths = ['/video' => 'video.bin', '/empty' => 'empty.bin', '/missing' => 'missing.bin'];
stream_video_file(__DIR__ . '/' . ($paths[$_SERVER['REQUEST_URI']] ?? 'missing.bin'), 'video/mp4');
''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (work / 'server.log').open('w+') as log:
        server = subprocess.Popen([PHP, '-d', 'output_buffering=4096', '-S', f'127.0.0.1:{port}', str(work / 'router.php')], stdout=log, stderr=log)
        try:
            for _ in range(100):
                if server.poll() is not None:
                    log.seek(0)
                    raise RuntimeError(log.read())
                try:
                    request(port, 'HEAD')
                    break
                except OSError:
                    time.sleep(0.05)
            else:
                raise RuntimeError('PHP test server did not become ready')

            size = len(payload)
            cases = [
                ('whole file', 'GET', {}, '/video', 200, payload, None, size),
                ('first byte', 'GET', {'Range': 'bytes=0-0'}, '/video', 206, payload[:1], f'bytes 0-0/{size}', 1),
                ('middle', 'GET', {'Range': 'bytes=100-299'}, '/video', 206, payload[100:300], f'bytes 100-299/{size}', 200),
                ('open end', 'GET', {'Range': f'bytes={size-10}-'}, '/video', 206, payload[-10:], f'bytes {size-10}-{size-1}/{size}', 10),
                ('suffix', 'GET', {'Range': 'bytes=-10'}, '/video', 206, payload[-10:], f'bytes {size-10}-{size-1}/{size}', 10),
                ('large suffix', 'GET', {'Range': f'bytes=-{size+1}'}, '/video', 206, payload, f'bytes 0-{size-1}/{size}', size),
                ('clamped end', 'GET', {'Range': f'bytes={size-1}-{size+100}'}, '/video', 206, payload[-1:], f'bytes {size-1}-{size-1}/{size}', 1),
                ('unsatisfiable start', 'GET', {'Range': f'bytes={size}-'}, '/video', 416, b'', f'bytes */{size}', 0),
                ('reversed', 'GET', {'Range': 'bytes=9-2'}, '/video', 416, b'', f'bytes */{size}', 0),
                ('zero suffix', 'GET', {'Range': 'bytes=-0'}, '/video', 416, b'', f'bytes */{size}', 0),
                ('overflow start', 'GET', {'Range': 'bytes=999999999999999999999999999999-'}, '/video', 416, b'', f'bytes */{size}', 0),
                ('overflow suffix', 'GET', {'Range': 'bytes=-999999999999999999999999999999'}, '/video', 206, payload, f'bytes 0-{size-1}/{size}', size),
                ('overflow end', 'GET', {'Range': f'bytes={size-1}-999999999999999999999999999999'}, '/video', 206, payload[-1:], f'bytes {size-1}-{size-1}/{size}', 1),
                ('multi-range ignored', 'GET', {'Range': 'bytes=0-1,5-6'}, '/video', 200, payload, None, size),
                ('malformed ignored', 'GET', {'Range': 'junk bytes=0-1'}, '/video', 200, payload, None, size),
                ('trailing junk ignored', 'GET', {'Range': 'bytes=0-1junk'}, '/video', 200, payload, None, size),
                ('unknown unit ignored', 'GET', {'Range': 'items=0-1'}, '/video', 200, payload, None, size),
                ('empty range ignored', 'GET', {'Range': 'bytes=-'}, '/video', 200, payload, None, size),
                ('if-range falls back', 'GET', {'Range': 'bytes=0-1', 'If-Range': '"stale"'}, '/video', 200, payload, None, size),
                ('HEAD', 'HEAD', {}, '/video', 200, b'', None, size),
                ('HEAD ignores range', 'HEAD', {'Range': 'bytes=0-1'}, '/video', 200, b'', None, size),
                ('empty file', 'GET', {}, '/empty', 200, b'', None, 0),
                ('empty file range', 'GET', {'Range': 'bytes=0-'}, '/empty', 416, b'', 'bytes */0', 0),
                ('missing file', 'GET', {}, '/missing', 404, b'', None, 0),
                ('POST rejected', 'POST', {}, '/video', 405, b'', None, 0),
            ]
            for name, method, headers, path, status, body, content_range, length in cases:
                actual_status, actual_headers, actual_body = request(port, method, headers, path)
                assert actual_status == status, (name, 'status', actual_status, status)
                assert actual_body == body, (name, 'response bytes differ')
                assert actual_headers.get('content-range') == content_range, (name, 'Content-Range', actual_headers)
                assert actual_headers.get('content-length') == str(length), (name, 'Content-Length', actual_headers)
                if status in (200, 206, 416):
                    assert actual_headers.get('accept-ranges') == 'bytes', (name, 'Accept-Ranges')
                    assert actual_headers.get('content-type') == 'video/mp4', (name, 'Content-Type')
                if status == 405:
                    assert actual_headers.get('allow') == 'GET, HEAD', (name, 'Allow')
                print('PASS:', name)
            print(f'{len(cases)} HTTP streaming tests passed.')
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
