(()=>{
const story=document.querySelector('.selected-story'),track=story.querySelector('.selected-track'),stage=story.querySelector('.selected-stage'),video=story.querySelector('video'),chapters=[...story.querySelectorAll('.selected-chapter')];
const videoPoster=video.dataset.poster||video.getAttribute('poster'),sceneBase=story.querySelector('.scene-base'),sceneStart=sceneBase.getAttribute('src');
const reduced=matchMedia('(prefers-reduced-motion: reduce)'),mobile=matchMedia('(max-width:900px), (max-height:639px)');
let visible=false,loaded=false,failed=false,raf=0,target=0,lastChapter=-2,range=1,stickyTop=0;
const clamp=x=>Math.max(0,Math.min(1,x)),enabled=()=>!failed&&!reduced.matches&&(!mobile.matches||(innerWidth<=900&&innerHeight>=540));
// The approved clip already contains paper, dispersed drawing and construction.
// Drive its full timeline directly: no synthetic blank/plan cross-fades.
function seek(){if(enabled()&&visible&&video.readyState>=2&&!video.seeking&&Math.abs(video.currentTime-target)>1/48)video.currentTime=target}
function draw(){
 raf=0;if(!visible||!enabled())return;
 const top=track.getBoundingClientRect().top,p=clamp((stickyTop-top)/range);
 const progress=clamp((p-.04)/.90);
 const end=Number.isFinite(video.duration)?Math.max(0,video.duration-1/24):10;
 target=Math.min(end,Math.round(progress*end*24)/24);
 const show=innerWidth<=760||top<innerHeight*.35,active=show?(target<5.2?0:target<8?1:2):-1;
 if(active!==lastChapter){for(let i=0;i<chapters.length;i++){const c=chapters[i];c.classList.toggle('is-current',i===active);c.classList.toggle('is-past',i<active);c.setAttribute('aria-hidden',String(i!==active))}lastChapter=active}
 video.style.opacity=video.readyState>=2?'1':'0';seek();
}
function schedule(){if(visible&&enabled()&&!raf)raf=requestAnimationFrame(draw)}
function loadVideo(){if(visible&&!loaded&&!failed&&(enabled()||innerWidth>900)){loaded=true;video.preload='auto';video.src=video.dataset.src;video.load()}}
function update(){
 if(raf)cancelAnimationFrame(raf);raf=0;
 const enhanced=enabled();
 const backgroundOnly=innerWidth<=900;
 video.controls=!enhanced&&!failed&&!backgroundOnly;video.tabIndex=enhanced||failed||backgroundOnly?-1:0;video.setAttribute('aria-hidden',String(enhanced||failed||backgroundOnly));
 sceneBase.src=enhanced?sceneStart:videoPoster;
 if(backgroundOnly&&!enhanced)video.pause();
 if(enhanced){video.pause();video.removeAttribute('poster')}else if(videoPoster)video.setAttribute('poster',videoPoster);
 story.classList.toggle('enhanced',enhanced);
 range=Math.max(1,track.offsetHeight-stage.offsetHeight);stickyTop=parseFloat(getComputedStyle(stage).top)||0;
 if(!enhanced){for(const c of chapters){c.removeAttribute('aria-hidden');c.classList.remove('is-past');c.classList.add('is-current')}video.style.opacity=!failed&&video.readyState>=2&&!backgroundOnly?'1':'0';lastChapter=-2;loadVideo();return}
 loadVideo();if(visible)schedule();
}
video.addEventListener('seeked',seek);
video.addEventListener('loadedmetadata',schedule);
video.addEventListener('loadeddata',()=>{if(!enabled())video.style.opacity=innerWidth<=900?'0':'1';else schedule()});
video.addEventListener('error',()=>{failed=true;update()});
new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;update()},{rootMargin:'0px 0px -10% 0px'}).observe(track);
addEventListener('scroll',schedule,{passive:true});addEventListener('resize',update,{passive:true});
reduced.addEventListener('change',update);mobile.addEventListener('change',update);
if(document.fonts)document.fonts.ready.then(update);
update();
})();
(()=>{
  const cover=document.querySelector('.cover');
  const art=cover?.querySelector('.hero-art');
  if(!art)return;
  const reduced=matchMedia('(prefers-reduced-motion: reduce)');
  const small=matchMedia('(max-width:760px)');
  let inView=false,frame=0,current=0,target=0,velocity=0,travel=0,height=1,lastTime=0;
  const enabled=()=>inView&&!reduced.matches;
  function measure(){height=Math.max(1,cover.offsetHeight);travel=small.matches?Math.min(48,height*.07):Math.max(0,art.offsetHeight-height-art.offsetHeight*.12)}
  function setTarget(){
    const progress=Math.max(0,Math.min(1,-cover.getBoundingClientRect().top/(height*.7)));
    target=-travel*progress;
  }
  function write(){art.style.setProperty('--hero-art-scroll-y',current.toFixed(3)+'px')}
  function draw(time){
    frame=0;
    const delta=Math.min(64,lastTime?time-lastTime:16.67)/1000;
    lastTime=time;
    const frequency=4.2;
    const distance=current-target;
    const impulse=velocity+frequency*distance;
    const decay=Math.exp(-frequency*delta);
    current=target+(distance+impulse*delta)*decay;
    velocity=(velocity-frequency*impulse*delta)*decay;
    if(current>0){current=0;velocity=0}
    if(current<-travel){current=-travel;velocity=0}
    const resting=Math.abs(target-current)<.15&&Math.abs(velocity)<.8;
    if(resting){current=target;velocity=0}
    write();
    if(!resting)frame=requestAnimationFrame(draw);
  }
  function schedule(){
    if(!enabled())return;
    setTarget();
    if(!frame){lastTime=0;frame=requestAnimationFrame(draw)}
  }
  function update(){
    if(frame)cancelAnimationFrame(frame);
    frame=0;lastTime=0;
    measure();
    art.style.willChange=enabled()?'transform':'auto';
    if(reduced.matches){current=target=velocity=0;write()}
    else if(inView)schedule();
    else{setTarget();current=target;velocity=0;write()}
  }
  new IntersectionObserver(entries=>{inView=entries[0].isIntersecting;update()}).observe(cover);
  addEventListener('scroll',schedule,{passive:true});
  addEventListener('resize',update,{passive:true});
  reduced.addEventListener('change',update);
  small.addEventListener('change',update);
})();

(()=>{
  const header=document.querySelector('.header');
  const links=[...header.querySelectorAll('.nav a')];
  const sections=links.map(link=>document.querySelector(link.getAttribute('href')));
  let frame=0;
  function update(){
    frame=0;
    const scrolled=scrollY>24;
    let active=-1,nearest=-Infinity;
    for(let i=0;i<sections.length;i++){
      const top=sections[i]?.getBoundingClientRect().top;
      if(top<=120&&top>nearest){nearest=top;active=i}
    }
    header.classList.toggle('is-scrolled',scrolled);
    for(let i=0;i<links.length;i++){
      links[i].classList.toggle('is-current',i===active);
      if(i===active)links[i].setAttribute('aria-current','location');
      else links[i].removeAttribute('aria-current');
    }
  }
  function schedule(){if(!frame)frame=requestAnimationFrame(update)}
  addEventListener('scroll',schedule,{passive:true});
  addEventListener('resize',schedule,{passive:true});
  update();
})();

(()=>{
  const header=document.querySelector('.header');
  const nav=header.querySelector('.nav');
  const action=header.querySelector('.header-action');
  function measureAction(){
    const style=getComputedStyle(header);
    const inside=header.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight);
    const gap=parseFloat(style.columnGap)||0;
    const shift=Math.max(0,(inside-nav.offsetWidth-2*gap)/2-action.offsetWidth);
    header.style.setProperty('--header-action-shift',shift.toFixed(3)+'px');
  }
  const observer=new ResizeObserver(measureAction);
  observer.observe(header);observer.observe(nav);observer.observe(action);
  measureAction();
})();


(()=>{
 const section=document.getElementById('results');
 if(!section)return;
 const track=section.querySelector('.results-track');
 const stage=section.querySelector('.results-stage');
 const desktopItems=[section.querySelector('.delivery-1'),section.querySelector('.delivery-0'),section.querySelector('.delivery-2')];
 const mobileItems=[section.querySelector('.delivery-0'),section.querySelector('.delivery-1'),section.querySelector('.delivery-2')];
 let items=desktopItems;
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 const small=matchMedia('(max-width:900px)');
 const short=matchMedia('(max-height:639px)');
 let visible=false,frame=0,range=1,stickyTop=58;
 const clamp=value=>Math.max(0,Math.min(1,value));
 const smooth=value=>{value=clamp(value);return value*value*(3-2*value)};
 const enabled=()=>!reduced.matches&&((!small.matches&&!short.matches)||(small.matches&&innerHeight>=540));
 function draw(){
  frame=0;
  if(!enabled()||!visible)return;
  const trackTop=track.getBoundingClientRect().top;
  const progress=clamp((stickyTop-trackTop)/range);
  const approach=clamp((innerHeight-trackTop)/Math.max(1,innerHeight-stickyTop));
  const drawing=clamp(.10+.12*approach+.78*progress);
  section.style.setProperty('--object-reveal',smooth(approach/.35).toFixed(3));
  section.style.setProperty('--draw-a',smooth(drawing).toFixed(3));
  section.style.setProperty('--draw-b',smooth((drawing-.10)/.90).toFixed(3));
  section.style.setProperty('--draw-c',smooth((drawing-.20)/.80).toFixed(3));
  section.style.setProperty('--draw-d',smooth((drawing-.30)/.70).toFixed(3));
  section.style.setProperty('--draw-finish',smooth((drawing-.86)/.14).toFixed(3));
  for(let i=0;i<items.length;i++){
   const reveal=small.matches?
    (i===0?smooth(approach/.45):smooth((progress-i*.28)/.035))*(i===2?1:smooth(((i+1)*.28-progress)/.04)):
    (i===0?smooth(approach/.45):smooth((progress-(.02+i*.21))/.055));
   items[i].style.setProperty('--delivery-reveal',reveal.toFixed(3));
   const hidden=String(reveal<.01);
   if(items[i].getAttribute('aria-hidden')!==hidden)items[i].setAttribute('aria-hidden',hidden);
  }
 }
 function schedule(){if(visible&&enabled()&&!frame)frame=requestAnimationFrame(draw)}
 function update(){
  if(frame)cancelAnimationFrame(frame);
  frame=0;
  items=small.matches?mobileItems:desktopItems;
  section.classList.toggle('results-enhanced',enabled());
  if(!enabled()){
   section.style.removeProperty('--object-reveal');
   for(const property of ['--draw-a','--draw-b','--draw-c','--draw-d','--draw-finish'])section.style.removeProperty(property);
   for(let i=0;i<items.length;i++){
    items[i].style.removeProperty('--delivery-reveal');
    items[i].removeAttribute('aria-hidden');
   }
   return;
  }
  range=Math.max(1,track.offsetHeight-stage.offsetHeight);
  stickyTop=parseFloat(getComputedStyle(stage).top)||0;
  if(visible)draw();
 }
 new IntersectionObserver(entries=>{
  visible=entries[0].isIntersecting;
  section.classList.toggle('results-in-view',visible);
  update();
 },{rootMargin:'150px'}).observe(track);
 addEventListener('scroll',schedule,{passive:true});
 addEventListener('resize',update,{passive:true});
 reduced.addEventListener('change',update);
 small.addEventListener('change',update);
 short.addEventListener('change',update);
 update();
})();

(()=>{
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 if(reduced.matches)return;
 const root=document.documentElement;
 const headings=[...document.querySelectorAll('[data-type-last]')];
 const targets=[...document.querySelectorAll('.services .service,.experience-study .n-cases article,.contact-copy')];
 const risks=[...document.querySelectorAll('.risk-card')];
 const process=document.getElementById('process');
 const processHeading=document.getElementById('process-title');
 const rows=[...process.querySelectorAll('.process-row')];
 const hero=document.querySelector('.hero-title');
 const pendingReveal=new Set(),pendingType=new Set(),pendingRisk=new Set();
 let pageReady=root.classList.contains('site-ready'),heroVisible=false,processTitleVisible=false,processStarted=false,processTimer=0,heroFrame=0;
 function show(target){target.classList.add('is-visible');reveal.unobserve(target)}
 function type(heading){
  heading.classList.add('is-last-typed');typeObserver.unobserve(heading);
  if(heading===processHeading)startProcess();
 }
 function showRisk(card){card.classList.add('is-risk-revealed')}
 function startProcess(){if(!pageReady||processStarted)return;processStarted=true;if(innerWidth<=760){process.classList.add('is-process-ready');return}processTimer=setTimeout(()=>process.classList.add('is-process-ready'),650)}
 function startHero(){
  if(!pageReady||!heroVisible||hero.classList.contains('is-hero-typing'))return;
  heroFrame=requestAnimationFrame(()=>{heroFrame=0;if(heroVisible&&!reduced.matches)hero.classList.add('is-hero-typing')});
 }
 const reveal=new IntersectionObserver(entries=>{
  for(const entry of entries){
   if(entry.intersectionRatio>=.12){if(pageReady)show(entry.target);else pendingReveal.add(entry.target)}
   else pendingReveal.delete(entry.target);
  }
 },{threshold:.12,rootMargin:'0px 0px -24px 0px'});
 for(const target of targets){target.classList.add('motion-enter');reveal.observe(target)}
 const typeObserver=new IntersectionObserver(entries=>{
  for(const entry of entries){
   if(entry.intersectionRatio>=.25){if(pageReady)type(entry.target);else pendingType.add(entry.target)}
   else pendingType.delete(entry.target);
  }
 },{threshold:.25,rootMargin:'0px 0px -12% 0px'});
 for(const heading of headings){heading.classList.add('last-word-ready');typeObserver.observe(heading)}
 const riskObserver=new IntersectionObserver(entries=>{
  for(const entry of entries){
   if(entry.intersectionRatio>=.55){if(pageReady)showRisk(entry.target);else pendingRisk.add(entry.target)}
   else{pendingRisk.delete(entry.target);if(!entry.isIntersecting)entry.target.classList.remove('is-risk-revealed')}
  }
 },{threshold:[0,.55],rootMargin:'0px 0px -8% 0px'});
 const riskDelays=[.95,0,1.9]; // center, then left, then right
 for(let i=0;i<risks.length;i++){risks[i].classList.add('risk-motion-ready');risks[i].style.setProperty('--risk-delay',(riskDelays[i]||0)+'s');riskObserver.observe(risks[i])}
 const rowObserver=new IntersectionObserver(entries=>{
  for(const entry of entries){
   if(entry.intersectionRatio>=(innerWidth<=760?.1:.24))entry.target.classList.add('is-row-inview');
   else if(!entry.isIntersecting)entry.target.classList.remove('is-row-inview');
  }
 },{threshold:[0,.1,.24],rootMargin:innerWidth<=760?'0px':'0px 0px -10% 0px'});
 for(let i=0;i<rows.length;i++){rows[i].classList.add('process-motion-ready');rows[i].style.setProperty('--process-delay',(.08+i*.08)+'s');rowObserver.observe(rows[i])}
 const processTitleObserver=new IntersectionObserver(entries=>{const entry=entries[0];processTitleVisible=entry.intersectionRatio>=.25;if(processTitleVisible)startProcess()},{threshold:[0,.25],rootMargin:'0px 0px -12% 0px'});
 processTitleObserver.observe(processHeading);
 const processSectionObserver=new IntersectionObserver(entries=>{if(!entries[0].isIntersecting){clearTimeout(processTimer);processStarted=false;process.classList.remove('is-process-ready')}},{threshold:0});
 processSectionObserver.observe(process);
 hero.classList.add('hero-typing-ready');
 const heroObserver=new IntersectionObserver(entries=>{
  const entry=entries[0];
  if(entry.intersectionRatio>=.1){heroVisible=true;startHero()}
  else if(!entry.isIntersecting){heroVisible=false;if(heroFrame)cancelAnimationFrame(heroFrame);heroFrame=0;hero.classList.remove('is-hero-typing')}
 },{threshold:[0,.1],rootMargin:'-70px 0px -6% 0px'});
 heroObserver.observe(hero);
 const readyObserver=new MutationObserver(()=>{
  if(!root.classList.contains('site-ready'))return;
  readyObserver.disconnect();pageReady=true;
  for(const target of pendingReveal)show(target);pendingReveal.clear();
  for(const heading of pendingType)type(heading);pendingType.clear();
  for(const card of pendingRisk)showRisk(card);pendingRisk.clear();
  if(processTitleVisible)startProcess();
  startHero();
 });
 if(!pageReady)readyObserver.observe(root,{attributes:true,attributeFilter:['class']});
 reduced.addEventListener('change',()=>{
  if(!reduced.matches)return;
  reveal.disconnect();typeObserver.disconnect();riskObserver.disconnect();rowObserver.disconnect();processTitleObserver.disconnect();processSectionObserver.disconnect();heroObserver.disconnect();readyObserver.disconnect();
  clearTimeout(processTimer);if(heroFrame)cancelAnimationFrame(heroFrame);
  for(const target of targets)target.classList.add('is-visible');
  for(const heading of headings)heading.classList.remove('last-word-ready');
  for(const card of risks)card.classList.add('is-risk-revealed');
  for(const row of rows)row.classList.add('is-row-inview');
  process.classList.add('is-process-ready');
  hero.classList.remove('hero-typing-ready','is-hero-typing');
 });
})();

(()=>{
 const root=document.documentElement;
 // Start the cover after its first paint. Assets below the fold load independently.
 requestAnimationFrame(()=>requestAnimationFrame(()=>{
  clearTimeout(window.siteLoadFailsafe);
  root.classList.remove('site-loading');
  root.classList.add('site-ready');
  performance.mark('site-ready');
 }));
})();


(()=>{
 const form=document.getElementById('request-form');
 const home=document.getElementById('contact-form-slot');
 const popupSlot=document.getElementById('popup-form-slot');
 const dialog=document.getElementById('project-dialog');
 const close=dialog.querySelector('.project-dialog-close');
 const title=document.getElementById('project-dialog-title');
 const description=document.getElementById('project-dialog-description');
 const status=form.querySelector('.form-status');
 let opener=null,originX=0,originY=0;
 const fileInput=form.querySelector('input[type="file"]');
 const submit=form.querySelector('[type="submit"]');
 const submitText=submit.querySelector('span');
 const idleText=submitText.textContent;
 const pendingMessage='Приём заявок через форму пока готовится. Напишите на info@mega-expert.ru или в Telegram — контакты рядом с формой.';
 let connection=null,connectionAt=0,submitting=false,delivered=false;
 function message(text,error=false){
  status.hidden=false;status.textContent=text;status.classList.toggle('is-error',error);
 }
 function endpoint(){
  if(!['mega-expert.ru','www.mega-expert.ru','127.0.0.1','localhost'].includes(location.hostname))return null;
  return new URL('api/leads.php',location.href.split('#')[0]).href;
 }
 async function prepare(){
  if(connection&&Date.now()-connectionAt<7000000)return connection;
  const url=endpoint();if(!url)return null;
  connectionAt=Date.now();
  connection=fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
   .then(async response=>{
    if(!response.ok)throw Error('connection');
    const data=await response.json();
    if(typeof data.ready!=='boolean'||!data.csrf||!data.submission_id)throw Error('connection');
    connectionAt=Date.now();
    const mb=value=>Math.floor(value/1048576*10)/10;
    if(data.limits)form.querySelector('.upload-hint').textContent='До '+data.limits.files+' файлов, не более '+mb(data.limits.per_file)+' МБ каждый и '+mb(data.limits.total)+' МБ суммарно';
    return data;
   }).catch(error=>{connection=null;throw error});
  return connection;
 }
 form.addEventListener('focusin',()=>{prepare().catch(()=>{})},{once:true});
 form.addEventListener('submit',async event=>{
  event.preventDefault();
  if(submitting||!form.reportValidity())return;
  submitting=true;submit.disabled=true;submitText.textContent='Отправляем…';form.setAttribute('aria-busy','true');
  try{
   const data=await prepare();
   if(!data?.ready){message(pendingMessage);return;}
   const files=[...fileInput.files],limits=data.limits;
   if(files.length>limits.files)throw Error('Можно прикрепить не более '+limits.files+' файлов.');
   if(files.some(file=>file.size>limits.per_file)||files.reduce((sum,file)=>sum+file.size,0)>limits.total)
    throw Error('Размер вложений слишком большой. Уменьшите файлы или отправьте их на info@mega-expert.ru.');
   // A token is issued on first interaction; avoid rejecting quickly completed forms.
   const wait=2200-(Date.now()-connectionAt);
   if(wait>0)await new Promise(resolve=>setTimeout(resolve,wait));
   const payload=new FormData(form);
   payload.set('csrf',data.csrf);payload.set('submission_id',data.submission_id);
   const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);
   let response;
   try{response=await fetch(endpoint(),{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});}
   finally{clearTimeout(timer);}
   let result;try{result=await response.json();}catch{throw Error('Не удалось подтвердить приём заявки. Данные сохранены в форме — попробуйте ещё раз.');}
   if(!response.ok||result.ok!==true){
    if(response.status===403)connection=null;
    throw Error(result.message||'Не удалось принять заявку. Попробуйте ещё раз.');
   }
   delivered=true;form.reset();form.querySelector('.file-names').textContent='';
   message(result.message||'Заявка принята. Мы свяжемся с вами.');
   connection=null;
  }catch(error){
   const known=error?.message&&!['connection','Failed to fetch','Load failed'].includes(error.message)&&error.name!=='AbortError';
   message(known?error.message:'Не удалось подтвердить приём заявки. Данные остались в форме. Повторите попытку или напишите на info@mega-expert.ru.',true);
  }finally{
   submitting=false;submit.disabled=false;submitText.textContent=idleText;form.removeAttribute('aria-busy');
  }
 });
 fileInput.addEventListener('change',()=>{
  form.querySelector('.file-names').textContent=[...fileInput.files].map(file=>file.name).join(' · ');
 });
 function open(event){
  event.preventDefault();
  const trigger=event.currentTarget;
  const reference=trigger.dataset.projectOpen==='reference';
  opener=trigger;originX=scrollX;originY=scrollY;
  form.elements.reference.checked=reference;
  form.elements.request_source.value=trigger.dataset.formSource||'project';
  title.innerHTML=reference?'Получить<br>референс-лист':'Обсудить<br>проект';
  description.textContent=reference?'Оставьте контакты — направим полный референс-лист специалистов. При необходимости опишите свою задачу.':'Расскажите о задаче. Определим состав работ, стоимость и срок.';
  if(delivered&&!submitting){delivered=false;status.hidden=true;}
  prepare().catch(()=>{});
  popupSlot.append(form);
  document.documentElement.classList.add('has-project-dialog');
  dialog.showModal();
  close.focus({preventScroll:true});
  if(scrollX!==originX||scrollY!==originY)window.scrollTo({left:originX,top:originY,behavior:'instant'});
 }
 document.querySelectorAll('[data-project-open]').forEach(trigger=>trigger.addEventListener('click',open));
 close.addEventListener('click',()=>dialog.close());
 dialog.addEventListener('click',event=>{
  if(event.target!==dialog)return;
  const rect=dialog.getBoundingClientRect();
  if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close();
 });
 dialog.addEventListener('close',()=>{
  home.append(form);
  form.elements.request_source.value='contacts';
  document.documentElement.classList.remove('has-project-dialog');
  opener?.focus({preventScroll:true});
 });
})();
