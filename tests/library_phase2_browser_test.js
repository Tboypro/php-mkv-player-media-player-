const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1440,height:1000}}), base=process.argv[2],errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 try {
  await page.goto(base+'/index.php');
  assert.equal(await page.locator('.hero-video').getAttribute('href'),'watch.php?id=27');
  await page.getByRole('textbox',{name:'Search video titles'}).fill('Video 02');
  await page.getByRole('textbox',{name:'Search video titles'}).press('Enter');
  await page.waitForURL(/q=Video/);assert.equal(await page.locator('.media-card').count(),1);
  await page.locator('#sortVideos').selectOption('title');await page.waitForURL(/sort=title/);
  assert.equal(await page.getByRole('textbox',{name:'Search video titles'}).inputValue(),'Video 02');
  await page.getByRole('link',{name:'Clear search'}).click();
  await page.goto(base+'/watch.php?id=27');
  await page.waitForFunction(()=>document.querySelector('video').readyState>=1);
  assert(await page.locator('#resumeToast').evaluate(e=>e.classList.contains('show')));
  await page.locator('#resumeYes').click();
  await page.waitForFunction(()=>document.querySelector('video').currentTime>=40);
  await page.locator('video').evaluate(async v=>{await v.play();v.pause();});
  // Sidebar toggle, native keyboard buttons and preserved saved moments.
  await page.locator('#bookmarksToggle').click();assert(await page.locator('#bookmarks').evaluate(e=>e.hidden));
  await page.locator('#bookmarksToggle').click();
  await page.locator('#bookmarkName').fill('Revision point');await page.locator('#bookmarkAdd').click();
  await page.locator('.bookmark-jump').filter({hasText:'Revision point'}).waitFor();
  assert.match(await page.locator('#bookmarkCount').innerText(),/1/);
  await page.locator('.bookmark-actions summary').click();await page.getByRole('button',{name:'Rename Revision point',exact:true}).click();
  await page.locator('.bookmark-edit input').fill('Important moment');await page.locator('.bookmark-edit button').filter({hasText:'Save'}).click();
  await page.locator('.bookmark-jump').filter({hasText:'Important moment'}).waitFor();
  await page.locator('#progressTrack').focus();const before=await page.locator('video').evaluate(v=>v.currentTime);
  await page.keyboard.press('ArrowRight');assert(Math.abs(await page.locator('video').evaluate(v=>v.currentTime)-before-5)<.2);
  await page.locator('#shortcutsBtn').focus();await page.keyboard.press('Space');
  assert(await page.locator('#shortcutsPanel').evaluate(e=>e.classList.contains('show')));
  assert(await page.locator('video').evaluate(v=>v.paused));await page.keyboard.press('Escape');
  await page.locator('#playbackSpeed').selectOption('1.5');assert.equal(await page.locator('video').evaluate(v=>v.playbackRate),1.5);
  await page.locator('#muteBtn').click();assert(await page.locator('video').evaluate(v=>v.muted));
  await page.locator('#volumeSlider').fill('0.5');assert.equal(await page.locator('video').evaluate(v=>v.volume),.5);
  // Favorite and collection dialogs must preserve the loaded player and position.
  await page.evaluate(()=>window.__testMarker='same-page');
  await page.locator('[data-action="favorite"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-action="favorite"]').getAttribute('aria-pressed')==='true');
  assert.equal(await page.evaluate(()=>window.__testMarker),'same-page');
  await page.locator('[data-action="favorite"]').click();await page.waitForFunction(()=>document.querySelector('[data-action="favorite"]').getAttribute('aria-pressed')==='false');
  const cid=await page.evaluate(async()=>{const r=await fetch('library_actions.php',{method:'POST',headers:{'X-CSRF-Token':window.__libraryToken},body:new URLSearchParams({action:'create_collection',name:'Watch test'})});return (await r.json()).collection_id;});
  await page.reload();await page.waitForFunction(()=>document.querySelector('video').readyState>=1);await page.locator('#resumeYes').click();
  await page.locator('video').evaluate(async v=>{await v.play();v.pause();});await page.evaluate(()=>window.__testMarker='same-page');
  await page.locator('[data-action="add_to_collection"]').click();await page.locator('#actionSubmit').click();
  await page.waitForFunction(()=>!document.querySelector('#actionDialog').open);assert.equal(await page.evaluate(()=>window.__testMarker),'same-page');
  await page.evaluate(async id=>{await fetch('library_actions.php',{method:'POST',headers:{'X-CSRF-Token':window.__libraryToken},body:new URLSearchParams({action:'delete_collection',collection_id:id})});},cid);
  // Actual Fullscreen API: video fills viewport, controls hide and return.
  await page.locator('#playPause').click();await page.locator('#fullscreenBtn').click();
  await page.waitForFunction(()=>document.fullscreenElement?.id==='videoShell');await page.mouse.move(100,100);
  await page.waitForFunction(()=>document.querySelector('#videoShell').classList.contains('controls-hidden'));
  await page.waitForFunction(()=>getComputedStyle(document.querySelector('.controls')).visibility==='hidden');
  assert(await page.locator('video').evaluate(v=>{const r=v.getBoundingClientRect();return Math.abs(r.width-innerWidth)<2&&Math.abs(r.height-innerHeight)<2&&getComputedStyle(v).objectFit==='contain'}));
  await page.mouse.move(200,150);await page.keyboard.press('k');await page.waitForFunction(()=>document.querySelector('video').paused);
  await page.waitForFunction(()=>getComputedStyle(document.querySelector('.controls')).visibility==='visible');
  await page.evaluate(()=>document.exitFullscreen());
  await page.waitForFunction(()=>!document.querySelector('#videoShell').classList.contains('is-fullscreen'));
  for(const width of [1440,768,390]){
   await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`watch overflow ${width}`);
   if(process.env.LIBRARY_SCREENSHOTS)await page.screenshot({path:process.env.LIBRARY_SCREENSHOTS+`/watch-${width}.png`,fullPage:true});
  }
  assert.deepEqual(errors,[]);console.log('PASS browser: scoped search/sort, resume, bookmarks, seek, shortcuts, speed/volume, uninterrupted actions, fullscreen and responsive player');
 }catch(e){console.error('Overflow nodes',await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).map(e=>[e.tagName,e.className,e.getBoundingClientRect().right])));console.error('Browser diagnostic',errors,await page.locator('video').evaluate(v=>({error:v.error?.message,ready:v.readyState,src:v.currentSrc})).catch(()=>null));throw e;}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
