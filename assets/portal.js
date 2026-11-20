(() => {
  'use strict';
  const el = id => document.getElementById(id);
  const initial = el('portal').dataset;
  const key = 'edu-sign-' + initial.uuid;
  const cache = {
    get(suffix) { try { return sessionStorage.getItem(key + suffix) || ''; } catch { return ''; } },
    set(suffix, value) { try { value ? sessionStorage.setItem(key + suffix, value) : sessionStorage.removeItem(key + suffix); } catch {} }
  };
  const fragment = location.hash.slice(1);
  let invite = fragment || cache('-invite'), session = fragment ? '' : cache('-session');
  if (fragment) {
    cache.set('-invite', fragment); cache.set('-session', '');
    history.replaceState(null, '', location.pathname + location.search);
  }
  let data, pdf, pdfBytes, page = 1, rendered = false, reached = false, format = 'pdf', generation = 0, timer;
  function message(text, error = false) {
    const box = el('notice'); box.textContent = text; box.classList.toggle('error', error); box.hidden = !text;
  }
  function stage(id) {
    ['identity','reading','signing','complete'].forEach(name => { el(name).hidden = name !== id; });
    const active = {identity:0, reading:1, signing:2, complete:2}[id];
    document.querySelectorAll('.steps li').forEach((li, i) => {
      if (i === active) li.setAttribute('aria-current','step'); else li.removeAttribute('aria-current');
    });
    window.scrollTo(0,0);
  }
  async function call(path, payload, binary = false) {
    const headers = session ? {'X-Edu-Session':session} : {};
    if (payload !== undefined) headers['Content-Type'] = 'application/json';
    const response = await fetch(initial.api.replace(/view$/,path), {method:payload === undefined ? 'GET':'POST',
      headers, credentials:'omit', cache:'no-store', body:payload === undefined ? undefined:JSON.stringify(payload)});
    if (!response.ok) {
      const problem = await response.json().catch(() => ({}));
      if (response.status === 401) { session=''; cache.set('-session',''); stage('identity'); }
      throw new Error(problem.message || 'Não foi possível concluir a operação.');
    }
    return binary ? response.arrayBuffer() : response.json();
  }
  function action(button, fn) {
    const run = async event => {
      event.preventDefault();
      if (button.dataset.busy) return;
      button.dataset.busy = '1'; button.disabled = true; message('');
      try { await fn(); } catch (err) { message(err.message || 'Falha de conexão. Tente novamente.', true); }
      finally { delete button.dataset.busy; button.disabled = false; if (button.id === 'ack-btn') check(); if (button.id === 'send-code' && timer) button.disabled = true; }
    };
    if (button.type === 'submit' && button.form) button.form.addEventListener('submit',run);
    else button.addEventListener('click',run);
  }
  function check() {
    const list = Array.from(el('clauses').querySelectorAll('input'));
    el('ack-btn').disabled = !reached || !list.length || list.some(node => !node.checked);
  }
  function progress() {
    if (!rendered || el('reading').hidden) return;
    const r = el('reader'), bottom = r.scrollTop + r.clientHeight >= r.scrollHeight - 4;
    if (bottom && (format === 'text' || (pdf && page === pdf.numPages))) reached = true;
    if (format === 'pdf') el('next').disabled = !bottom || !pdf || page >= pdf.numPages;
    el('progress').textContent = reached ? 'Fim do contrato alcançado. Confirme as declarações abaixo.' :
      'Percorra o documento até o fim para registrar a ciência.';
    check();
  }
  function accessible() {
    generation++; rendered = true; format = 'text';
    el('pages').hidden = true; el('text').hidden = false; el('pagebar').hidden = true;
    el('toggle').textContent = 'Ver páginas do PDF'; el('reader').scrollTop = 0;
    requestAnimationFrame(progress);
  }
  async function renderPage() {
    const seq = ++generation; rendered = false;
    el('next').disabled = true; el('prev').disabled = true;
    const p = await pdf.getPage(page);
    const base = p.getViewport({scale:1});
    const width = Math.max(250, el('reader').clientWidth - 16);
    const view = p.getViewport({scale:Math.min(2, window.devicePixelRatio || 1)*width/base.width});
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(view.width); canvas.height = Math.ceil(view.height);
    await p.render({canvasContext:canvas.getContext('2d'),viewport:view}).promise;
    if (seq !== generation || format !== 'pdf') return;
    el('pages').replaceChildren(canvas); el('reader').scrollTop = 0;
    el('page-number').textContent = 'Página ' + page + ' de ' + pdf.numPages;
    el('prev').disabled = page <= 1; rendered = true; progress();
  }
  async function showPdf() {
    format = 'pdf'; rendered = false;
    el('text').hidden = true; el('pages').hidden = false; el('pagebar').hidden = false;
    el('toggle').textContent = 'Usar texto acessível';
    try {
      if (!pdf) {
        const lib = await import(initial.pdfjs);
        lib.GlobalWorkerOptions.workerSrc = initial.worker;
        if (!pdfBytes) pdfBytes = await call('document/issued', undefined, true);
        pdf = await lib.getDocument({data:new Uint8Array(pdfBytes.slice(0)),isEvalSupported:false,useSystemFonts:true}).promise;
      }
      if (format === 'pdf') await renderPage();
    } catch { accessible(); message('O contrato está aberto em texto acessível. O PDF integral também está disponível para download.'); }
  }
  async function download(kind) {
    const bytes = kind === 'issued' && pdfBytes ? pdfBytes : await call('document/' + kind,undefined,true);
    const link = document.createElement('a');
    const url = URL.createObjectURL(new Blob([bytes],{type:'application/pdf'}));
    link.href=url; link.download='contrato-'+initial.uuid+'-'+(kind==='signed'?'assinado':'emitido')+'.pdf';
    document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url),60000);
  }
  function completed(when, sha) {
    stage('complete'); el('protocol').textContent=initial.uuid; el('signed-at').textContent=when; el('digest').textContent=sha;
  }
  async function open() {
    data = await call('view',{});
    if (data.status === 'signed') { completed(data.signed_at,data.signed_sha256); return; }
    el('declaration').textContent = data.declaration;
    if (data.acknowledged) { stage('signing'); return; }
    el('school').textContent=data.institution; el('title').textContent=data.title;
    el('text').innerHTML=data.html; // Frozen server-side allowlist, with escaped variable values.
    el('clauses').replaceChildren();
    Object.entries(data.clauses).forEach(([value,text]) => {
      const label=document.createElement('label'), input=document.createElement('input'), span=document.createElement('span');
      label.className='check'; input.type='checkbox'; input.value=value; input.required=true;
      input.addEventListener('change',check); span.textContent=text; label.append(input,span); el('clauses').append(label);
    });
    reached=false; stage('reading'); await showPdf();
  }
  action(el('identify').querySelector('button'),async () => {
    if (!invite) throw new Error('Abra o link completo recebido por e-mail.');
    const result=await call('identify',{invite,cpf:el('cpf').value,birth_date:el('birth-date').value});
    el('identify').reset();
    session=result.session; cache.set('-session',session); await open();
  });
  action(el('toggle'),async () => { if (format==='pdf') accessible(); else await showPdf(); });
  el('reader').addEventListener('scroll',progress,{passive:true});
  window.addEventListener('resize',progress);
  el('prev').addEventListener('click',async () => { if (rendered && page>1) { page--; try { await renderPage(); } catch { accessible(); } } });
  el('next').addEventListener('click',async () => { if (rendered && pdf && page<pdf.numPages) { page++; try { await renderPage(); } catch { accessible(); } } });
  action(el('download-issued'),()=>download('issued')); action(el('download-signed'),()=>download('signed'));
  action(el('ack-btn'),async () => {
    const clauses=Array.from(el('clauses').querySelectorAll('input:checked'), item=>item.value);
    await call('ack',{clauses,scroll_percent:reached?100:0,issued_sha256:data.issued_sha256,format}); stage('signing');
  });
  action(el('send-code'),async () => {
    const res=await call('otp',{}); message('Código enviado ao e-mail cadastrado. Confira também a pasta de spam.');
    const end=Date.now()+1000*res.resend_after, button=el('send-code');
    const tick=()=>{const remaining=Math.ceil((end-Date.now())/1000); if (remaining>0) { button.disabled=true; button.textContent='Reenviar em '+remaining+' s'; }
      else { clearInterval(timer); timer=null; button.disabled=false; button.textContent='Reenviar código por e-mail'; } };
    timer=setInterval(tick,1000); tick(); el('otp').focus();
  });
  action(el('sign-form').querySelector('button'),async () => {
    const res=await call('sign',{code:el('otp').value,confirm:el('confirm').checked,issued_sha256:data.issued_sha256});
    el('otp').value=''; cache.set('-invite',''); completed(res.signed_at,res.pdf_sha256);
  });
  if (session) open().catch(err=>message(err.message,true));
})();
