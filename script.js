
(function(){
  "use strict";
  var root=document.documentElement, body=document.body;

  // datasets
  var datasetAll=[], filteredInstallments=[], entityDataset=[], entityMap={};
  var STATUS_LABELS = { pending: 'PENDENTE', overdue: 'VENCIDO', paid: 'PAGO' };
  function statusLabel(value){
    var key = typeof value === 'string' ? value.toLowerCase() : '';
    return STATUS_LABELS[key] || String(value || '').toUpperCase();
  }
  function safeParseJSON(elId){
    var el=document.getElementById(elId);
    if(!el||!el.textContent) return null;
    try{ return JSON.parse(el.textContent); }catch(e){ return null; }
  }
  var parsedAll = safeParseJSON('dataset');
  if(parsedAll){
    if(Array.isArray(parsedAll.all)) datasetAll = parsedAll.all.slice();
    else if(Array.isArray(parsedAll)) datasetAll = parsedAll.slice();
    else if(parsedAll.all) datasetAll = [].concat(parsedAll.all);
  }
  filteredInstallments = safeParseJSON('filteredInstallments') || [];
  entityDataset = safeParseJSON('entitiesDataset') || [];

  if(!Array.isArray(datasetAll)) datasetAll=[];
  if(!Array.isArray(filteredInstallments)) filteredInstallments=[];
  if(!Array.isArray(entityDataset)) entityDataset=[];

  entityDataset.forEach(function(ent){
    if(ent && ent.name && !entityMap[ent.name]){
      entityMap[ent.name] = ent;
    }
  });
  function findEntity(name){ return entityMap[name] || null; }

  function collectEntityDetail(name){
    var ent = findEntity(name);
    var rel = (datasetAll||[]).filter(function(item){ return (item.entity||'') === name; }); // usa ALL para ver tudo
    return { entity: ent, name: name, installments: rel };
  }
  function collectCourseDetail(entityName, courseName){
    var ent = findEntity(entityName);
    var courseInfo = null;
    if(ent && Array.isArray(ent.items)){
      for(var i=0;i<ent.items.length;i++){
        var itm = ent.items[i];
        if((itm.course||'') === courseName){ courseInfo = itm; break; }
      }
    }
    var targetEntity = (entityName||'').trim().toLowerCase();
    var targetCourse = (courseName||'').trim().toLowerCase();
    var rel = (datasetAll||[]).filter(function(item){
      var entName = (item.entity||'').trim().toLowerCase();
      var courseNm = (item.course||'').trim().toLowerCase();
      return entName === targetEntity && courseNm === targetCourse;
    });
    return { entityName: entityName, courseName: courseName, course: courseInfo, installments: rel };
  }

  // === Tema persistente ===
  var themeToggleEl = document.querySelector('.theme-toggle');
  var huePickerEl   = document.getElementById('huePicker');
  var hueThumbEl    = document.getElementById('hueThumb');
  var hueValueEl    = document.getElementById('hueValue');
  var hueCurrentEl  = document.getElementById('hueNow');
  var hueInp        = document.getElementById('hue');

  function updateThemeToggle(theme){
    if(!themeToggleEl) return;
    themeToggleEl.dataset.active = (theme === 'dark') ? 'dark' : 'light';
  }

  function updateHueUI(h){
    if(!huePickerEl) return;
    var hue = Math.max(0, Math.min(360, Number(h) || 0));
    var pct = hue / 360 * 100;
    huePickerEl.style.setProperty('--pos', pct + '%');
    huePickerEl.style.setProperty('--hue', hue);
    if(hueValueEl) hueValueEl.textContent = Math.round(hue) + '°';
    if(hueThumbEl) hueThumbEl.setAttribute('aria-label', 'Matiz ' + Math.round(hue));
    if(hueCurrentEl) hueCurrentEl.style.background = 'hsl(' + hue + ' 85% 55%)';
  }

  function setTheme(t){
    if(!t) t='dark';
    if(t!=='dark' && t!=='light'){ t='dark'; try{ localStorage.setItem('julieta:theme','dark'); }catch(e){} }
    ['theme-dark','theme-light'].forEach(function(c){ root.classList.remove(c); body.classList.remove(c); });
    var cn = 'theme-'+t;
    root.classList.add(cn); body.classList.add(cn);
    root.dataset.theme = t; body.dataset.theme = t;
    try{ localStorage.setItem('julieta:theme', t); }catch(e){}
    // marca radio
    try{
      var radios=document.querySelectorAll('input[name="theme"]');
      Array.prototype.forEach.call(radios, function(r){ r.checked = (r.value===t); });
    }catch(e){}
    updateThemeToggle(t);
  }
  function setHue(h){
    var hueNum = Math.max(0, Math.min(360, Number(h)));
    if(isNaN(hueNum)) hueNum = 145;
    var hueStr = String(hueNum);
    root.style.setProperty('--accent-h', hueStr);
    try{ localStorage.setItem('julieta:hue', hueStr);}catch(e){}
    if(hueInp) hueInp.value=h;
    updateHueUI(hueStr);
  }
  try{ setTheme((localStorage.getItem('julieta:theme')||'dark')); }catch(e){ setTheme('dark'); }
  try{
    var storedHue = localStorage.getItem('julieta:hue');
    if(!storedHue || isNaN(+storedHue)) storedHue='145';
    setHue(storedHue);
  }catch(e){ setHue('145'); }

  // bind radios do tema (FIX do toggle que não mudava)
  document.addEventListener('change', function(e){
    var t = e.target;
    if(t && t.name==='theme' && (t.value==='dark' || t.value==='light')){
      setTheme(t.value);
    }
  });

  // modal simples (config)
  var modal=document.getElementById('modal');
  var openB=document.getElementById('btnConfig');
  var closeB=document.getElementById('modalClose');
  var modalTimer=null;
  var MODAL_ANIM_MS = 380;

  function openModal(){
    if(!modal) return;
    if(modalTimer){ clearTimeout(modalTimer); modalTimer=null; }
    modal.classList.remove('modal--closing');
    modal.classList.add('modal--open');
    modal.setAttribute('aria-hidden','false');
    body.classList.add('no-scroll');
  }
  function closeModal(){
    if(!modal || !modal.classList.contains('modal--open')) return;
    modal.classList.add('modal--closing');
    modal.setAttribute('aria-hidden','true');
    body.classList.remove('no-scroll');
    if(modalTimer){ clearTimeout(modalTimer); }
    modalTimer = setTimeout(function(){
      modal.classList.remove('modal--open');
      modal.classList.remove('modal--closing');
      modalTimer = null;
    }, MODAL_ANIM_MS);
  }
  if(modal && !modal.hasAttribute('aria-hidden')) modal.setAttribute('aria-hidden','true');
  if(openB) openB.addEventListener('click', openModal);
  if(closeB) closeB.addEventListener('click', closeModal);
  if(modal){ modal.addEventListener('click', function(e){ if(e.target===modal){ closeModal(); } }); }

  // hue
  if(hueInp) hueInp.addEventListener('input', function(e){ setHue(e.target.value); });
  var sw = document.querySelectorAll('.swatch[data-h]');
  for(var i=0;i<sw.length;i++){ sw[i].addEventListener('click', function(){ setHue(this.getAttribute('data-h')); }); }

  // back-to-top
  var back=document.getElementById('backtop');
  function onScroll(){ if(back) back.classList.toggle('backtop--show', window.scrollY>420); }
  window.addEventListener('scroll', onScroll); onScroll();
  if(back){ back.addEventListener('click', function(){ window.scrollTo({top:0,behavior:'smooth'}); }); }

  // === Drawer (modal com subtelas) ===
  var drawer=document.getElementById('drawer'),
      dClose=document.getElementById('drawerClose'),
      dBack=document.getElementById('drawerBack'),
      dTitle=document.getElementById('drawerTitle'),
      dBody=document.getElementById('drawerBody');
  if(dClose) dClose.addEventListener('click', closeDrawer);
  if(drawer){ drawer.addEventListener('click', function(e){ if(e.target===drawer) closeDrawer(); }); }

  var navStack = []; // pilha de telas
  var currentState = null;

  if(dBack) dBack.addEventListener('click', function(){
    var prev = navStack.pop();
    if(prev){ openDrawer(prev.title, prev.items, prev.kind, {restore:true}); }
  });

  function closeDrawer(){
    if(!drawer) return;
    if(drawer.classList.contains('drawer--open')){
      drawer.classList.remove('drawer--open');
      drawer.classList.remove('drawer--full');
      body.classList.remove('no-scroll');
      navStack = []; currentState = null;
      if(dBack) dBack.style.display='none';
    }
  }

  function openDrawer(title, items, kind, opts){
    if(!drawer || !dBody || !dTitle) return;

    // empilha estado atual se navegando para dentro
    if(opts && opts.push && currentState){ navStack.push(currentState); }
    // visibilidade do botão Voltar
    if(dBack) dBack.style.display = navStack.length ? 'inline-flex' : 'none';

    // helpers
    function parseBRL(v){
      if(typeof v==='number') return v;
      if(v==null) return 0;
      var s=String(v).replace(/\s|R\$/g,'').trim();
      if(!s) return 0;
      s=s.replace(/\./g,'').replace(',', '.');
      var n=Number(s);
      return isNaN(n)?0:n;
    }
    function safeDate(iso){
      if(!iso) return null;
      var d=new Date(iso);
      return isNaN(d.getTime())?null:d;
    }
    function formatBRL(v){ return 'R$ '+Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2}); }
    function esc(str){ str = str === null ? '' : String(str); return str.replace(/[&<>"']/g,function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]||ch; }); }
    function escAttr(str){ return esc(str).replace(/\n/g,'&#10;'); }

    // normaliza itens
    var norm = (items||[]).map(function(i){
      return {
        entity: i.entity||'-',
        course: i.course||'',
        amount: parseBRL(i.amount),
        due_date: i.due_date||null,
        status: i.status||'',
        description: i.description||i.obs||i.note||null
      };
    });

    // agregações
    var total=0, byEntity={};
    norm.forEach(function(i){
      var amount=i.amount||0; total += amount;
      var ent=byEntity[i.entity]||(byEntity[i.entity]={sum:0,overdue:0,count:0,courses:{}});
      ent.sum+=amount; ent.count++; if(i.status==='overdue') ent.overdue+=amount;

      var course=ent.courses[i.course]||(ent.courses[i.course]={sum:0,overdue:0});
      course.sum+=amount; if(i.status==='overdue') course.overdue+=amount;
    });
    var uniqueEntities = Object.keys(byEntity).length;

    dTitle.textContent = title;
    var summaryHtml =
      '<div class="drawer__section">'+
        '<header class="drawer__section-head">'+
          '<div><span class="micro">Total</span><div class="drawer__number">'+formatBRL(total)+'</div></div>'+
          '<div><span class="micro">Entidades</span><div class="drawer__number">'+uniqueEntities+'</div></div>'+
        '</header>'+
      '</div>';

    var dynamicHtml='';

    if(kind==='entity'){
      // detalhe da entidade
      var detail = items || {};
      var ent = detail.entity || null;
      var name = detail.name || title;
      var courses = ent && Array.isArray(ent.items) ? ent.items.slice() : [];
      var totalE = ent && typeof ent.total === "number" ? ent.total : courses.reduce(function(a,c){return a+(+c.value||0);},0);
      var receivedE = ent && typeof ent.received === "number" ? ent.received : courses.reduce(function(a,c){return a+(+c.received||0);},0);
      var pendingE = Math.max(0, totalE - receivedE);

      var sumHtml =
        '<div class="drawer__section">'+
          '<header class="drawer__section-head">'+
            '<div><span class="micro">Recebido</span><div class="drawer__number">'+formatBRL(receivedE)+'</div></div>'+
            '<div><span class="micro">Falta</span><div class="drawer__number">'+formatBRL(pendingE)+'</div></div>'+
            '<div><span class="micro">Total</span><div class="drawer__number">'+formatBRL(totalE)+'</div></div>'+
          '</header>'+
          '<div class="micro">'+esc(name)+'</div>'+
        '</div>';

      var courseLines = courses.map(function(c){
        var value = Number(c.value)||0, rec = Number(c.received)||0, pend = Math.max(0, value - rec);
        return '<div class="info-line js-course" data-entity="'+escAttr(name)+'" data-course="'+escAttr(c.course||'-')+'">'+
                '<div><strong>'+esc(c.course||'-')+'</strong><span>'+formatBRL(rec)+' recebidos - '+formatBRL(pend)+' a receber</span></div>'+
                '<span class="tag">'+formatBRL(value)+'</span>'+
               '</div>';
      }).join('');
      var coursesHtml = courses.length
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Cursos</h4><span class="micro">Clique para ver detalhes</span></header><div class="drawer__list">'+courseLines+'</div></div>'
        : '<div class="drawer__section"><div class="alert">Nenhum curso disponível.</div></div>';

      // timeline de parcelas da entidade
      var relInst = Array.isArray(detail.installments) ? detail.installments.slice() : [];
      relInst.sort(function(a,b){
        var da=safeDate(a.due_date), db=safeDate(b.due_date);
        if(da && db) return da - db;
        if(da && !db) return -1;
        if(!da && db) return 1;
        return (a.amount||0) - (b.amount||0);
      });
      var timeline = relInst.map(function(item){
        var d=safeDate(item.due_date); var when=d? d.toLocaleDateString("pt-BR") : "-";
        var label=[item.course||"", statusLabel(item.status)].filter(Boolean).join(" - ");
        return '<div class="info-line"><div><strong>'+when+'</strong><span>'+esc(label)+'</span></div><span class="tag">'+formatBRL(item.amount)+'</span></div>';
      }).join('');
      var timelineHtml = timeline
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Parcelas relacionadas</h4></header><div class="drawer__list">'+timeline+'</div></div>'
        : '';

      dBody.innerHTML = sumHtml + coursesHtml + timelineHtml;

    } else if(kind==='course'){
      // detalhe do curso
      var detail = items || {};
      var course = detail.course || null;
      var entityName = detail.entityName || "";
      var courseName = detail.courseName || title;
      var totalC = course && typeof course.value === "number" ? course.value : 0;
      var receivedC = course && typeof course.received === "number" ? course.received : 0;
      var pendingC = Math.max(0, totalC - receivedC);

      var sumHtml =
        '<div class="drawer__section">'+
          '<header class="drawer__section-head">'+
            '<div><span class="micro">Recebido</span><div class="drawer__number">'+formatBRL(receivedC)+'</div></div>'+
            '<div><span class="micro">Falta</span><div class="drawer__number">'+formatBRL(pendingC)+'</div></div>'+
            '<div><span class="micro">Total</span><div class="drawer__number">'+formatBRL(totalC)+'</div></div>'+
          '</header>'+
          '<div class="micro">'+esc(entityName)+' · '+esc(courseName)+'</div>'+
        '</div>';

      var relInst = Array.isArray(detail.installments) ? detail.installments.slice() : [];
      relInst.sort(function(a,b){
        var da=safeDate(a.due_date), db=safeDate(b.due_date);
        if(da && db) return da - db;
        if(da && !db) return -1;
        if(!da && db) return 1;
        return (a.amount||0) - (b.amount||0);
      });
      var lines = relInst.map(function(item){
        var d=safeDate(item.due_date); var when=d? d.toLocaleDateString("pt-BR") : "-";
        var status=statusLabel(item.status);
        return '<div class="info-line"><div><strong>'+when+'</strong><span>'+esc(status)+'</span></div><span class="tag">'+formatBRL(item.amount)+'</span></div>';
      }).join('');
      var installmentsHtml = lines
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Pagamentos e a receber</h4></header><div class="drawer__list">'+lines+'</div></div>'
        : '<div class="drawer__section"><div class="alert">Nenhuma parcela encontrada para este curso.</div></div>';

      dBody.innerHTML = sumHtml + installmentsHtml;

   } else if (kind==='overdue' || kind==='pending' || kind==='paid') {
  // visão simplificada (ordenado: mais recente no topo)
  var listItems = norm.slice().sort(function(a,b){
    var da = a.due_date ? new Date(a.due_date).getTime() : 0;
    var db = b.due_date ? new Date(b.due_date).getTime() : 0;
    // DESC: data mais recente primeiro; se empatar, maior valor primeiro
    return (db - da) || ((b.amount||0) - (a.amount||0));
  }).map(function(i){
    var subtitle = [i.entity||'', i.course||''].filter(Boolean).join(' - ');
    var when = (i.due_date ? new Date(i.due_date).toLocaleDateString('pt-BR') : '-');
    return '<div class="info-line js-course" data-entity="'+escAttr(i.entity||'-')+'" data-course="'+escAttr(i.course||'-')+'">'+
           '<div><strong>'+when+'</strong><span>'+esc(subtitle)+'</span></div>'+
           '<span class="tag">'+formatBRL(i.amount)+'</span></div>';
  }).join('');

  var html = '<div class="drawer__section">'+
               '<header class="drawer__section-head"><h4>Itens</h4></header>'+
               '<div class="drawer__list">'+(listItems || '<div class="alert">Nada aqui.</div>')+'</div>'+
             '</div>';

  dBody.innerHTML = summaryHtml + html;
}

    if(!drawer.classList.contains('drawer--open')){
      drawer.classList.add('drawer--open');
      body.classList.add('no-scroll');
    }
    drawer.classList.add('drawer--full');

    // estado atual
    currentState = { title: title, items: items, kind: kind||'pending' };
  }

  // Abridores (KPIs + entidades/curso da lista)
  function bindOpeners(){
    // Função que abre o drawer de acordo com a KPI
    function handle(kind){
      var raw = Array.isArray(datasetAll) ? datasetAll : [];
      var items = raw.filter(function(i){
        if(kind === 'pending') {
          // A Receber = pendentes + vencidos
          return (i.status === 'pending' || i.status === 'overdue');
        }
        return i.status === kind; // 'paid' ou 'overdue'
      });
      var ttl = (kind === 'paid') ? 'Recebidos' : (kind === 'overdue' ? 'Vencidos' : 'A Receber');
      openDrawer(ttl, items, kind, {push:false});
    }

    // KPIs: clique e teclado
    var kpies = document.querySelectorAll('.kpi[data-open]');
    for (var i = 0; i < kpies.length; i++) {
      (function(el){
        function trigger(){ handle(String(el.getAttribute('data-open')||'pending')); }
        el.addEventListener('click', trigger);
        el.addEventListener('keydown', function(e){
          if(e.key==='Enter' || e.key===' '){
            e.preventDefault();
            trigger();
          }
        });
      })(kpies[i]);
    }

    // entidade/curso (cards/lista)
    document.addEventListener('click', function (e) {
      var elEnt = e.target.closest('.entity-action.js-entity, .card.js-entity, .info-line.js-entity, .entity.js-entity');
      if (elEnt) {
        var name = (elEnt.getAttribute('data-entity') || '').trim() || elEnt.textContent.trim();
        var detail = collectEntityDetail(name);
        openDrawer(name, detail, 'entity', {push:true});
        return;
      }
      var elCourse = e.target.closest('.js-course');
      if (elCourse) {
        var en = elCourse.getAttribute('data-entity') || '';
        var cn = elCourse.getAttribute('data-course') || '';
        var detail = collectCourseDetail(en, cn);
        openDrawer(cn || 'Curso', detail, 'course', {push:true});
      }
    });
  }
  bindOpeners();

  // ESC fecha modal/drawer
  window.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
      if(modal && modal.classList.contains('modal--open')){ closeModal(); }
      closeDrawer();
    }
  });
})();

