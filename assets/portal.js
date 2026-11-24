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
  let data, pdf, pdfBytes, rendered = false, reached = false, format = 'pdf', generation = 0, timer;
  let receipt = '', downloaded = false, previewUrl = '';
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
      const error = new Error(problem.message || 'Não foi possível concluir a operação.');
      error.status = response.status;
      throw error;
    }
    if (!binary) return response.json();
    const bytes = await response.arrayBuffer();
    if (path === 'document/issued') {
      const digest = new Uint8Array(await crypto.subtle.digest('SHA-256',bytes));
      const hash = Array.from(digest, n => n.toString(16).padStart(2,'0')).join('');
      if (hash !== data.issued_sha256) throw new Error('O arquivo recebido diverge do contrato. Reabra o convite e tente novamente.');
      receipt = response.headers.get('X-Edu-Document-Receipt') || '';
    }
    return bytes;
  }
  function action(button, fn) {
    const run = async event => {
      event.preventDefault();
      if (button.disabled || button.dataset.busy) return;
      button.dataset.busy = '1'; button.disabled = true; message('');
      try { await fn(); } catch (err) { message(err.message || 'Falha de conexão. Tente novamente.', true); }
      finally { delete button.dataset.busy; button.disabled = false; if (button.id === 'ack-btn') check(); }
    };
    if (button.type === 'submit' && button.form) button.form.addEventListener('submit',run);
    else button.addEventListener('click',run);
  }
  function check() {
    const list = Array.from(el('clauses').querySelectorAll('input'));
    const ready = format === 'download' ? downloaded && !!receipt : reached;
    el('ack-btn').disabled = !!el('ack-btn').dataset.busy || !ready || !list.length || list.some(node => !node.checked);
  }
  function progress() {
    if (!rendered || el('reading').hidden || reached) return;
    const end = el('reading-end').getBoundingClientRect();
    if (end.top < window.innerHeight && end.bottom >= 0) {
      reached = true;
      el('progress').textContent = 'Você chegou ao fim. Marque as declarações abaixo para continuar.';
      check();
    }
  }
  function previewOriginal() {
    if (!pdfBytes || !navigator.pdfViewerEnabled || previewUrl) return;
    previewUrl = URL.createObjectURL(new Blob([pdfBytes],{type:'application/pdf'}));
    el('native-pdf').src = previewUrl;
    el('native-pdf').hidden = false;
    el('reader').hidden = false;
  }
  function accessible() {
    if (!data.html) {
      if (el('reading').hidden) return;
      generation++; rendered = false; reached = false; format = 'download'; check();
      el('pages').replaceChildren(); el('reading-end').hidden = true;
      el('reading-instruction').hidden = true;
      el('reader').setAttribute('aria-busy','false');
      el('reader').hidden = el('native-pdf').hidden;
      previewOriginal();
      el('progress').textContent = downloaded ? 'Após ler o arquivo baixado, confirme as declarações abaixo.' :
        'Baixe o PDF para leitura. Depois de ler o arquivo, marque as declarações para continuar.';
      message('O leitor de páginas está indisponível. Use a cópia em PDF para conferir o contrato.');
      return;
    }
    generation++; format = 'text';
    el('reader').setAttribute('aria-busy','false');
    el('pages').hidden = true; el('text').hidden = false;
    el('toggle').textContent = 'Ver PDF';
    readingReady();
    requestAnimationFrame(progress);
    message('O contrato está aberto em texto acessível. O PDF integral também está disponível para download.');
  }
  function readingReady() {
    el('reading-end').hidden = false;
    el('progress').textContent = 'O botão será liberado ao chegar ao fim do contrato e marcar as declarações.';
    rendered = true;
    requestAnimationFrame(progress);
  }
  async function showPdf() {
    const sequence = ++generation;
    format = 'pdf'; rendered = false; reached = false; check();
    const reader = el('reader'), pages = el('pages');
    reader.setAttribute('aria-busy','true');
    el('text').hidden = true; el('reading-end').hidden = true;
    el('reading-instruction').hidden = false;
    pages.hidden = false; pages.replaceChildren();
    el('progress').textContent = 'Carregando o contrato…';
    el('toggle').textContent = 'Usar texto acessível';
    try {
      if (!pdf) {
        if (!pdfBytes) pdfBytes = await call('document/issued', undefined, true);
        const lib = await import(initial.pdfjs);
        lib.GlobalWorkerOptions.workerSrc = initial.worker;
        pdf = await lib.getDocument({data:new Uint8Array(pdfBytes.slice(0)),isEvalSupported:false,useSystemFonts:true}).promise;
      }
      for (let number = 1; number <= pdf.numPages && sequence === generation; number++) {
        const page = await pdf.getPage(number);
        const base = page.getViewport({scale:1});
        const width = Math.max(1,pages.clientWidth);
        const density = Math.min(2,window.devicePixelRatio || 1);
        const pixelLimit = Math.min(4_000_000, 48_000_000 / pdf.numPages);
        const scale = Math.min(density * width / base.width, Math.sqrt(pixelLimit / (base.width * base.height)));
        const viewport = page.getViewport({scale});
        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-label','Página '+number+' de '+pdf.numPages);
        canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
        await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;
        if (sequence !== generation) return;
        pages.append(canvas);
        page.cleanup();
      }
      if (sequence === generation) readingReady();
    } catch {
      if (sequence === generation) accessible();
    } finally {
      if (sequence === generation) reader.setAttribute('aria-busy','false');
    }
  }
  async function download(kind) {
    const bytes = kind === 'issued' && pdfBytes ? pdfBytes : await call('document/' + kind,undefined,true);
    const link = document.createElement('a');
    const url = URL.createObjectURL(new Blob([bytes],{type:'application/pdf'}));
    link.href=url; link.download='contrato-'+initial.uuid+'-'+(kind==='signed'?'assinado':'emitido')+'.pdf';
    document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url),60000);
    if (kind === 'issued') {
      pdfBytes = bytes;
      if (!downloaded && format === 'download') {
        el('clauses').querySelectorAll('input').forEach(input => { input.checked = false; });
      }
      downloaded = true;
      if (format === 'download') {
        previewOriginal();
        el('progress').textContent = 'Após ler o arquivo baixado, confirme as declarações abaixo.';
        check();
      }
    }
  }
  function completed(when, sha) {
    stage('complete'); el('protocol').textContent=initial.uuid; el('signed-at').textContent=when; el('digest').textContent=sha;
  }
  async function open() {
    data = await call('view',{});
    if (data.status === 'signed') { completed(data.signed_at,data.signed_sha256); return; }
    el('declaration').textContent = data.declaration;
    el('code-recipient').textContent = data.code_recipient || 'seu e-mail cadastrado';
    if (data.acknowledged) { stage('signing'); return; }
    el('school').textContent=data.institution; el('title').textContent=data.title;
    el('text').innerHTML=data.html; // Frozen server-side allowlist, with escaped variable values.
    el('toggle').hidden = !data.html;
    el('clauses').replaceChildren();
    Object.entries(data.clauses).forEach(([value,text]) => {
      const label=document.createElement('label'), input=document.createElement('input'), span=document.createElement('span');
      label.className='check'; input.type='checkbox'; input.value=value; input.required=true;
      input.addEventListener('change',check); span.textContent=text; label.append(input,span); el('clauses').append(label);
    });
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    el('native-pdf').removeAttribute('src'); el('native-pdf').hidden = true; el('reader').hidden = false;
    pdf = null; pdfBytes = null; receipt = ''; previewUrl = ''; downloaded = false;
    reached = false; stage('reading'); await showPdf();
  }
  action(el('identify').querySelector('button'),async () => {
    if (!invite) throw new Error('Abra o link completo recebido por e-mail.');
    const result=await call('identify',{invite,cpf:el('cpf').value,birth_date:el('birth-date').value});
    el('identify').reset();
    session=result.session; cache.set('-session',session); await open();
  });
  action(el('toggle'),async () => { if (format==='pdf') accessible(); else await showPdf(); });
  window.addEventListener('scroll',progress,{passive:true});
  window.addEventListener('resize',progress);
  action(el('download-issued'),()=>download('issued')); action(el('download-signed'),()=>download('signed'));
  action(el('ack-btn'),async () => {
    const clauses=Array.from(el('clauses').querySelectorAll('input:checked'), item=>item.value);
    const evidence = format === 'download' ? {document_receipt:receipt} : {scroll_percent:reached?100:0};
    await call('ack',{clauses,issued_sha256:data.issued_sha256,format,...evidence});
    stage('signing');
    await requestCode();
  });
  function codeCooldown(seconds) {
    const button = el('send-code'), end = Date.now()+1000*seconds;
    const tick = () => {
      const remaining = Math.ceil((end-Date.now())/1000);
      button.disabled = remaining > 0;
      button.textContent = remaining > 0 ? 'Reenviar em '+remaining+' s' : 'Reenviar código por e-mail';
      if (remaining <= 0) { clearInterval(timer); timer = null; }
    };
    clearInterval(timer);
    timer = setInterval(tick,1000); tick();
  }
  async function requestCode() {
    const button = el('send-code');
    button.disabled = true;
    button.textContent = 'Enviando código…';
    message('Enviando o código de assinatura por e-mail…');
    try {
      const result = await call('otp',{});
      codeCooldown(result.resend_after);
      message('Código enviado. Confira a caixa de entrada e a pasta de spam.');
      el('otp').focus();
    } catch (error) {
      if (error.status === 503) codeCooldown(60);
      else {
        button.disabled = false;
        button.textContent = 'Tentar enviar o código novamente';
      }
      message(error.message || 'Falha de conexão ao enviar o código. Tente novamente.',true);
    }
  }
  el('send-code').addEventListener('click',requestCode);
  action(el('sign-form').querySelector('button'),async () => {
    const res=await call('sign',{code:el('otp').value,confirm:el('confirm').checked,issued_sha256:data.issued_sha256});
    el('otp').value=''; cache.set('-invite',''); completed(res.signed_at,res.pdf_sha256);
  });
  if (session) open().catch(err=>message(err.message,true));
})();
