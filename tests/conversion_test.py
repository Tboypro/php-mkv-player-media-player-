#!/usr/bin/env python3
"""Real worker + local mock CloudConvert integration; never contacts the paid service.
Requires PHP CLI (mysqli,curl), ffmpeg/ffprobe, and a disposable MariaDB database.
Uses BOOKMARK_DB_HOST/PORT/USER/PASS and PHP_BIN like library_refresh_test.py.
"""
import base64, http.client, json, os, re, shutil, socket, subprocess, tempfile, threading, time, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
env = os.environ.copy()
env.update(TEST_HOST=os.getenv('BOOKMARK_DB_HOST','127.0.0.1'), TEST_PORT=os.getenv('BOOKMARK_DB_PORT','3306'),
           TEST_USER=os.getenv('BOOKMARK_DB_USER','root'), TEST_PASS=os.getenv('BOOKMARK_DB_PASS',''),
           TEST_DB='qplayer_conversion_'+uuid.uuid4().hex[:12])
php = [PHP, '-d', 'mysqli.default_port='+env['TEST_PORT']]

def sql(query):
    program = '''mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$c=new mysqli(getenv('TEST_HOST'),getenv('TEST_USER'),getenv('TEST_PASS'),'',(int)getenv('TEST_PORT'));
$c->set_charset('utf8mb4'); $c->multi_query(stream_get_contents(STDIN));
do { if ($r=$c->store_result()) echo json_encode($r->fetch_all(MYSQLI_ASSOC)); }
while($c->more_results() && $c->next_result());'''
    return subprocess.check_output(php+['-r',program],input=query.encode(),env=env).decode()

def ffmpeg(dest, *args):
    subprocess.run(['ffmpeg','-v','error','-y',*args,str(dest)],check=True)

class Cloud(BaseHTTPRequestHandler):
    scenario='success'; polls=0; uploads=0; creates=0; payload=b''
    def log_message(self,*args): pass
    def send(self, code, body):
        data=json.dumps(body).encode(); self.send_response(code); self.send_header('Content-Length',str(len(data))); self.end_headers(); self.wfile.write(data)
    def do_POST(self):
        cls=type(self); body=self.rfile.read(int(self.headers.get('Content-Length','0')))
        if self.path=='/jobs':
            cls.creates+=1
            request=json.loads(body); assert request['tasks']['convert-file']['video_codec']=='x264'
            if cls.scenario in ('unauthorized','quota'):
                self.send(401 if cls.scenario=='unauthorized' else 402,{'message':'account error'}); return
            self.send(201,{'data':{'id':'test-job','tasks':[{'name':'import-file','result':{'form':{'url':base+'/upload','parameters':{'token':'test-upload-token'}}}}]}})
        else:
            cls.uploads+=1
            assert body.index(b'name="token"') < body.index(b'name="file"')
            if cls.scenario=='upload_disconnect':
                self.close_connection=True; self.connection.shutdown(socket.SHUT_RDWR); self.connection.close(); return
            self.send(500 if cls.scenario=='upload_error' else 201,{})
    def do_GET(self):
        cls=type(self)
        if self.path=='/jobs/test-job':
            cls.polls+=1
            if cls.scenario=='poll_forbidden': self.send(403,{'message':'no scope'}); return
            if cls.scenario=='task_error':
                self.send(200,{'data':{'status':'error','tasks':[{'name':'convert-file','status':'error','code':'INVALID_DATA','message':'Bad input https://secret.example/?signature=secret'}]}}); return
            self.send(200,{'data':{'status':'finished','tasks':[{'name':'export-file','result':{'files':[{'url':base+'/download'}]}}]}}); return
        if self.path=='/download' and cls.scenario=='redirect':
            self.send_response(302); self.send_header('Location',base+'/actual'); self.end_headers(); return
        if cls.scenario=='partial':
            self.send_response(200); self.send_header('Content-Length',str(len(cls.payload)+100)); self.end_headers(); self.wfile.write(cls.payload); self.close_connection=True; return
        data=b'not a video'*300 if cls.scenario=='invalid_output' else cls.payload
        self.send_response(200); self.send_header('Content-Length',str(len(data))); self.end_headers(); self.wfile.write(data)

server=ThreadingHTTPServer(('127.0.0.1',0),Cloud)
base=f'http://127.0.0.1:{server.server_port}'
threading.Thread(target=server.serve_forever,daemon=True).start()
name=env['TEST_DB']; created=False; web=None
try:
    sql(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4;'); created=True
    schema=(ROOT/'schema.sql').read_text()
    schema=re.sub(r'CREATE DATABASE IF NOT EXISTS q_mp4_player.*?;','',schema,flags=re.S).replace('USE q_mp4_player;','')
    sql(f'USE `{name}`; '+schema)
    with tempfile.TemporaryDirectory(prefix='qplayer-conversion-') as folder:
        root=Path(folder); app=root/'app'; shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','uploads','__pycache__'))
        config=(app/'config.php').read_text()
        values={'DB_HOST':env['TEST_HOST'],'DB_USER':env['TEST_USER'],'DB_PASS':env['TEST_PASS'],'DB_NAME':name,
                'CLOUDCONVERT_API_KEY':'mock-key','CLOUDCONVERT_API_BASE':base}
        for key,value in values.items():
            encoded=base64.b64encode(value.encode()).decode()
            config,count=re.subn(r"define\('"+key+r"',.*?\);",f"define('{key}',base64_decode('{encoded}'));",config); assert count==1
        # Worker inherits this port even though it starts without the test runner's -d flags.
        config+="\nini_set('mysqli.default_port', '"+env['TEST_PORT']+"');\n"
        (app/'config.php').write_text(config)
        for part in ['originals','videos','thumbnails']: (app/'uploads'/part).mkdir(parents=True)
        good=root/'good.mp4'; mkv=root/'good.mkv'; ac3=root/'ac3.mkv'; odd=root/'odd.mkv'; high=root/'high.mp4'; webm=root/'good.webm'
        ffmpeg(good,'-f','lavfi','-i','testsrc2=size=160x120:rate=5','-f','lavfi','-i','sine=frequency=440','-t','2','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac')
        ffmpeg(mkv,'-i',str(good),'-c','copy'); ffmpeg(ac3,'-i',str(good),'-c:v','copy','-c:a','ac3')
        ffmpeg(odd,'-f','lavfi','-i','testsrc=size=321x241:rate=5','-t','2','-c:v','ffv1')
        ffmpeg(high,'-f','lavfi','-i','testsrc=size=160x120:rate=5','-t','2','-c:v','libx264','-pix_fmt','yuv444p')
        ffmpeg(webm,'-i',str(good),'-c:v','libvpx','-c:a','libvorbis')
        bad=root/'bad.mkv'; bad.write_bytes(b'not video')
        Cloud.payload=good.read_bytes()
        count=0
        def run(source, mode='local', scenario='success', expect='ready'):
            global count
            count+=1; Cloud.scenario=scenario; Cloud.polls=Cloud.creates=Cloud.uploads=0
            src=f'test{count}_src{source.suffix}'; stored=f'test{count}'+(source.suffix if source.suffix in ['.mp4','.webm','.m4v'] else '.mp4')
            shutil.copy2(source,app/'uploads/originals'/src)
            sql(f"USE `{name}`; INSERT INTO videos(id,title,original_filename,stored_filename,source_filename,convert_mode,status) VALUES({count},'Test','test','{stored}','{src}','{mode}','processing');")
            result=subprocess.run(php+[str(app/'convert.php'),str(count)],capture_output=True,text=True,timeout=40)
            row=json.loads(sql(f'USE `{name}`; SELECT * FROM videos WHERE id={count};'))[0]
            assert row['status']==expect,(scenario,row,result.stderr,result.stdout)
            if expect=='ready':
                target=app/'uploads/videos'/row['stored_filename']; assert target.is_file()
                assert not (app/'uploads/originals'/src).exists()
                assert int(row['duration_seconds'])==2
                probe=json.loads(subprocess.check_output(['ffprobe','-v','error','-show_streams','-of','json',str(target)]))
                v=next(x for x in probe['streams'] if x['codec_type']=='video')
                if target.suffix=='.mp4': assert v['codec_name']=='h264' and v['pix_fmt']=='yuv420p'
            else: assert (app/'uploads/originals'/src).exists()
            assert not list((app/'uploads/videos').glob('*.part'))
            print('PASS',source.name,mode,scenario,flush=True); return row
        for source in [good,webm,mkv,ac3,odd,high]: run(source)
        run(bad,expect='failed')
        for scenario in ['success','redirect']:
            row=run(mkv,'cloud',scenario); assert row['actual_mode']=='cloud' and row['convert_note'] is None
        for scenario in ['unauthorized','quota','upload_disconnect','upload_error','poll_forbidden','task_error','partial','invalid_output']:
            row=run(mkv,'cloud',scenario); assert row['actual_mode']=='local' and 'online failed' in row['convert_note']
            assert 'signature=secret' not in row['convert_note']
            if scenario.startswith('upload_'): assert Cloud.polls==0, 'Failed uploads must not enter polling'
        run(good,'cloud'); assert Cloud.creates==0, 'Compatible MP4 should not use cloud credits'
        # HTTP upload integration: selected per-file mode overrides a cloud setting.
        sql(f"USE `{name}`; UPDATE settings SET setting_value='cloud' WHERE setting_key='conversion_mode';")
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        web=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','-t',str(app)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=env)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1): break
            except OSError: time.sleep(.05)
        boundary='qplayer-test-boundary'
        body=(f'--{boundary}\r\nContent-Disposition: form-data; name="conversion_mode"\r\n\r\nlocal\r\n--{boundary}\r\nContent-Disposition: form-data; name="video"; filename="upload.mkv"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+mkv.read_bytes()+f'\r\n--{boundary}--\r\n'.encode())
        conn=http.client.HTTPConnection('127.0.0.1',port); conn.request('POST','/upload.php',body,{'Content-Type':'multipart/form-data; boundary='+boundary})
        response=conn.getresponse(); data=json.loads(response.read()); assert response.status==200,data
        for _ in range(100):
            row=json.loads(sql(f"USE `{name}`; SELECT * FROM videos WHERE id={int(data['id'])};"))[0]
            if row['status']!='processing': break
            time.sleep(.1)
        assert row['status']=='ready' and row['convert_mode']=='local',row
        print('PASS HTTP upload starts worker and captures selected mode')
        conn=http.client.HTTPConnection('127.0.0.1',port); conn.request('GET','/index.php')
        response=conn.getresponse(); html=response.read().decode()
        assert response.status==200 and 'Converted locally after online failed:' in html
        print('PASS library renders retained cloud fallback diagnostics')
        print('All conversion integration tests passed.')
finally:
    if web: web.terminate(); web.wait(timeout=5)
    server.shutdown(); server.server_close()
    if created: sql(f'DROP DATABASE `{name}`;')
