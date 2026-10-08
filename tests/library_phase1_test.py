#!/usr/bin/env python3
"""Library HTTP regressions. Uses a disposable DB; BOOKMARK_DB_* and PHP_BIN configure it.
Set LIBRARY_BROWSER_TEST=1 and PLAYWRIGHT_MODULE to run browser checks too.
"""
import base64, http.client, json, os, re, shutil, socket, subprocess, tempfile, time, uuid
from pathlib import Path
from urllib.parse import urlencode
ROOT=Path(__file__).resolve().parents[1]
env=os.environ.copy()
env.update(TEST_HOST=os.getenv('BOOKMARK_DB_HOST','127.0.0.1'),TEST_PORT=os.getenv('BOOKMARK_DB_PORT','3306'),TEST_USER=os.getenv('BOOKMARK_DB_USER','root'),TEST_PASS=os.getenv('BOOKMARK_DB_PASS',''),TEST_DB='qplayer_library_'+uuid.uuid4().hex[:12])
php=[os.getenv('PHP_BIN','php'),'-d','mysqli.default_port='+env['TEST_PORT']]
name=env['TEST_DB'];created=False;server=None

def sql(query):
    code='''mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$c=new mysqli(getenv('TEST_HOST'),getenv('TEST_USER'),getenv('TEST_PASS'),'',(int)getenv('TEST_PORT'));
$c->set_charset('utf8mb4');$c->multi_query(stream_get_contents(STDIN));
do{if($r=$c->store_result())echo json_encode($r->fetch_all(MYSQLI_ASSOC));}while($c->more_results()&&$c->next_result());'''
    return subprocess.check_output(php+['-r',code],input=query.encode(),env=env).decode()

def rows(query):return json.loads(sql(f'USE `{name}`; '+query))
try:
    sql(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4;');created=True
    schema=(ROOT/'schema.sql').read_text();schema=re.sub(r'CREATE DATABASE IF NOT EXISTS q_mp4_player.*?;','',schema,flags=re.S).replace('USE q_mp4_player;','')
    sql(f'USE `{name}`; '+schema)
    for i in range(1,28):
        title='&lt;literal&gt;' if i==27 else f'Video {i:02d}'
        sql(f"USE `{name}`; INSERT INTO videos(id,title,original_filename,stored_filename,duration_seconds,last_position,filesize_bytes,status,convert_note,actual_mode) VALUES ({i},'{title}','test.mp4','test{i}.mp4',120,40,2048000,'ready','Converted locally after online failed: test failure','local');")
    sql(f"USE `{name}`; UPDATE videos SET title='<script>alert(1)</script>' WHERE id=26; UPDATE videos SET status='processing',convert_note='Converting safely...' WHERE id=25; UPDATE videos SET status='failed',error_message='<b>Failed input</b>' WHERE id=24;")
    with tempfile.TemporaryDirectory(prefix='qplayer-library-') as tmp:
        root=Path(tmp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','uploads','__pycache__'))
        config=(app/'config.php').read_text()
        for key,value in [('DB_HOST',env['TEST_HOST']),('DB_USER',env['TEST_USER']),('DB_PASS',env['TEST_PASS']),('DB_NAME',name)]:
            encoded=base64.b64encode(value.encode()).decode();config,count=re.subn(r"define\('"+key+r"',.*?\);",f"define('{key}',base64_decode('{encoded}'));",config);assert count==1
        config+="\nini_set('mysqli.default_port', '"+env['TEST_PORT']+"');\n";(app/'config.php').write_text(config)
        sql(f'USE `{name}`; ALTER TABLE videos DROP COLUMN is_favorite;')
        for _ in range(2):subprocess.run(php+[str(app/'scripts/migrate_favorites.php')],check=True,env=env)
        assert rows('SELECT last_position FROM videos WHERE id=27;')[0]['last_position']=='40'
        sql(f'USE `{name}`; DROP TABLE collection_videos; DROP TABLE collections;')
        for _ in range(2):subprocess.run(php+[str(app/'scripts/migrate_collections.php')],check=True,env=env)
        assert rows('SELECT last_position FROM videos WHERE id=27;')[0]['last_position']=='40'
        sql(f'USE `{name}`; ALTER TABLE videos DROP COLUMN is_completed, DROP COLUMN last_watched_at;')
        for _ in range(2):subprocess.run(php+[str(app/'scripts/migrate_watch_history.php')],check=True,env=env)
        assert rows('SELECT last_position,last_watched_at FROM videos WHERE id=27;')[0]=={'last_position':'40','last_watched_at':None}
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        log=(root/'server.log').open('w+')
        server=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','-t',str(app)],stdout=log,stderr=log,env=env)
        cookie='';token=''
        def request(path,values=None,csrf=True,method=None):
            headers={'Cookie':cookie}
            if csrf:headers['X-CSRF-Token']=token
            if values is not None:headers['Content-Type']='application/x-www-form-urlencoded'
            conn=http.client.HTTPConnection('127.0.0.1',port,timeout=10);conn.request(method or ('POST' if values is not None else 'GET'),path,urlencode(values) if values is not None else None,headers)
            r=conn.getresponse();data=r.read().decode();result=(r.status,data,dict(r.getheaders()));conn.close();return result
        for _ in range(100):
            try:status,html,headers=request('/index.php');break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Server did not start')
        assert status==200,html
        if 'Set-Cookie' in headers:cookie=headers['Set-Cookie'].split(';')[0]
        match=re.search(r'window.__libraryToken="([a-f0-9]+)"',html)
        if match:token=match.group(1)
        assert len(re.findall(r'<article class="card media-card"',html))==24
        assert 'watch.php?id=27' in html and 'watch.php?id=25' not in html
        assert '&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)</script>' not in html
        assert '&lt;b&gt;Failed input&lt;/b&gt;' in html
        assert 'Converted locally after online failed: test failure' in html
        assert 'uploadDialog' in html and 'convertModeSwitch' in html
        assert 'Continue watching' in html
        status,second,_=request('/index.php?page=2');assert status==200 and second.count('<article class="card media-card"')==3
        for page in ['-1','9999999999999999999999999','%5B%5D']:
            assert request('/index.php?page='+page)[0]==200
        assert 'assets/css/library.css' not in request('/watch.php?id=27')[1]
        print('PASS layout, pagination, safe titles/errors, conversion messages and unchanged watch page',flush=True)
        def api(values,expected=200,csrf=True):
            status,body,_=request('/library_actions.php',values,csrf=csrf);assert status==expected,(status,body);return json.loads(body)
        api(dict(action='favorite',video_id=27,value=1),403,False)
        api(dict(action='favorite',video_id=27,value=2),400)
        api(dict(action='favorite',video_id=-1,value=1),400)
        api(dict(action='favorite',video_id=99999,value=1),404)
        for _ in range(2):api(dict(action='favorite',video_id=27,value=1))
        status,favs,_=request('/index.php?view=favorites');assert status==200 and favs.count('<article class="card media-card"')==1
        assert 'watch.php?id=27' in favs
        api(dict(action='favorite',video_id=27,value=0))
        assert 'No videos here yet' in request('/index.php?view=favorites')[1]
        assert request('/library_actions.php')[0]==405
        print('PASS favorites persistence/filtering/idempotence, validation and CSRF',flush=True)
        api(dict(action='create_collection',name=''),400)
        api(dict(action='create_collection',name='x'*121),400)
        api(dict(action='create_collection',name='Private'),403,False)
        cid=api(dict(action='create_collection',name='Physics <b>notes</b>'))['collection_id']
        html=request('/index.php?view=collections')[1];assert 'Physics &lt;b&gt;notes&lt;/b&gt;' in html and '<article class="card media-card"' not in html
        assert request('/index.php?view=collections&collection=99999')[0]==404
        assert request('/index.php?view=collections&collection[]=1')[0]==400
        for _ in range(2):api(dict(action='add_to_collection',video_id=27,collection_id=cid))
        assert len(rows('SELECT * FROM collection_videos;'))==1
        html=request(f'/index.php?view=collections&collection={cid}')[1];assert html.count('<article class="card media-card"')==1 and 'watch.php?id=27' in html
        api(dict(action='rename_collection',collection_id=cid,name='Physics'))
        api(dict(action='add_to_collection',video_id=99999,collection_id=cid),404)
        api(dict(action='remove_from_collection',video_id=27,collection_id=cid))
        assert len(rows('SELECT * FROM collection_videos;'))==0 and len(rows('SELECT * FROM videos WHERE id=27;'))==1
        api(dict(action='add_to_collection',video_id=27,collection_id=cid))
        media=app/'uploads/videos/test27.mp4';media.parent.mkdir(parents=True,exist_ok=True);media.write_bytes(b'preserve-video')
        api(dict(action='delete_collection',collection_id=cid))
        assert len(rows('SELECT * FROM collection_videos;'))==0 and media.read_bytes()==b'preserve-video'
        assert len(rows('SELECT * FROM videos WHERE id=27;'))==1
        cid=api(dict(action='create_collection',name='Cascade check'))['collection_id']
        api(dict(action='add_to_collection',video_id=1,collection_id=cid))
        assert request('/delete.php',dict(id=1))[0]==200
        assert len(rows('SELECT * FROM collection_videos;'))==0 and len(rows(f'SELECT * FROM collections WHERE id={cid};'))==1
        api(dict(action='delete_collection',collection_id=cid))
        print('PASS collection CRUD, membership, CSRF, validation, cascades and preserved video file',flush=True)

        sql(f'USE `{name}`; UPDATE videos SET last_position=0, is_completed=0, last_watched_at=NULL;')
        assert 'continue-section' not in request('/index.php')[1]
        assert request('/save_progress.php')[0]==405
        assert request('/save_progress.php',dict(id=27,position=-1))[0]==400
        assert request('/save_progress.php',dict(id=27,position=0,started=0))[0]==200
        assert rows('SELECT last_watched_at FROM videos WHERE id=27;')[0]['last_watched_at'] is None
        request('/save_progress.php',dict(id=27,position=35,started=1))
        assert rows('SELECT last_watched_at FROM videos WHERE id=27;')[0]['last_watched_at'] is not None
        html=request('/index.php')[1];assert 'class="hero-video" href="watch.php?id=27"' in html
        request('/save_progress.php',dict(id=27,position=0,started=1,completed=1))
        request('/save_progress.php',dict(id=27,position=0,started=0,completed=0))
        assert rows('SELECT is_completed FROM videos WHERE id=27;')[0]['is_completed']=='1'
        assert 'continue-section' not in request('/index.php')[1]
        request('/save_progress.php',dict(id=27,position=20,started=1,completed=0))
        sql(f"USE `{name}`; UPDATE videos SET last_position=40,last_watched_at='2026-01-01 00:00:00' WHERE id=23;")
        assert 'class="hero-video" href="watch.php?id=27"' in request('/index.php')[1]
        assert 'continue-section' not in request('/index.php?view=favorites')[1]
        request('/save_progress.php',dict(id=25,position=30,started=1))
        assert rows('SELECT last_position FROM videos WHERE id=25;')[0]['last_position']=='0'
        request('/save_progress.php',dict(id=27,position=999,started=1))
        assert rows('SELECT last_position FROM videos WHERE id=27;')[0]['last_position']=='120'
        request('/save_progress.php',dict(id=27,position=40,started=1))
        print('PASS history migration, recent ordering, completion/replay, idle opening and ready-only progress',flush=True)

        def ids(html):return [int(v) for v in re.findall(r'<article class="card media-card" data-id="(\d+)"',html)]
        assert ids(request('/index.php?q=Video%2002')[1])==[2]
        assert ids(request('/index.php?q=%25')[1])==[]
        assert ids(request('/index.php?q=%27%20OR%201%3D1--')[1])==[]
        assert '&lt;script&gt;' in request('/index.php?q=%3Cscript%3E')[1]
        assert request('/index.php?q='+'x'*501)[0]==400
        assert request('/index.php?q[]=x&sort[]=x')[0]==200
        assert ids(request('/index.php?q=Video&sort=title')[1])==list(range(2,26))
        assert ids(request('/index.php?sort=watched')[1])[0]==27
        api(dict(action='favorite',video_id=2,value=1))
        assert ids(request('/index.php?view=favorites&q=Video&sort=title')[1])==[2]
        api(dict(action='favorite',video_id=2,value=0))
        cid=api(dict(action='create_collection',name='Search scope'))['collection_id']
        api(dict(action='add_to_collection',video_id=3,collection_id=cid))
        assert ids(request(f'/index.php?collection={cid}&q=Video&sort=title')[1])==[3]
        api(dict(action='delete_collection',collection_id=cid))
        html=request('/index.php?q=Video&sort=title')[1]
        # 24 matching titles after the deletion fixture: add one to exercise filtered pagination.
        sql(f"USE `{name}`; INSERT INTO videos(title,original_filename,stored_filename,status) VALUES ('Video z','z.mp4','z.mp4','failed');")
        html=request('/index.php?q=Video&sort=title')[1];assert 'q=Video' in html and 'sort=title' in html and 'page=2' in html
        assert len(ids(request('/index.php?q=Video&sort=title&page=2')[1]))==1
        sql(f"USE `{name}`; DELETE FROM videos WHERE title='Video z';")
        assert 'continue-section' not in request('/index.php?q=Video')[1]
        print('PASS scoped search, literal metacharacters, allowlisted sorting and filtered pagination',flush=True)

        if os.getenv('LIBRARY_BROWSER_TEST')=='1':
            clip=root/'test.mp4'
            subprocess.run(['ffmpeg','-v','error','-y','-f','lavfi','-i','testsrc2=size=320x180:rate=5','-t','2','-c:v','libx264','-pix_fmt','yuv420p',str(clip)],check=True)
            thumbs=app/'uploads/thumbnails';thumbs.mkdir(parents=True,exist_ok=True)
            for i,color in enumerate(['#192f41','#36352d','#3a2131','#1c3d36'],1):
                subprocess.run(['ffmpeg','-v','error','-y','-f','lavfi','-i',f'color=c={color}:s=640x360','-frames:v','1',str(thumbs/f'fixture{i}.jpg')],check=True)
            sql(f"USE `{name}`; UPDATE videos SET thumbnail=CONCAT('fixture',MOD(id,4)+1,'.jpg');")
            subprocess.run(['node',str(app/'tests/library_phase1_browser_test.js'),f'http://127.0.0.1:{port}',str(clip)],check=True,env=env)
        log.seek(0);logs=log.read();assert 'Fatal error' not in logs and 'Warning:' not in logs,logs
        print('Library phase tests passed.',flush=True)
finally:
    if server:server.terminate();server.wait(timeout=5)
    if created:sql(f'DROP DATABASE `{name}`;')
