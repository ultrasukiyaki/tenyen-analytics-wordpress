(() => {
  'use strict';
  const {__} = window.wp?.i18n || {__: value => value};

  function init(root, config) {
    const page = root.querySelector('#tya-exclusions');
    if (!page || !config?.endpoint) return;
    const form = page.querySelector('[data-exclusion-form]');
    const diagnosticForm = page.querySelector('[data-diagnostic-form]');
    const list = page.querySelector('[data-exclusion-list]');
    const status = page.querySelector('[data-exclusion-status]');
    const result = page.querySelector('[data-diagnostic-result]');
    const types = Array.isArray(config.types) ? config.types : [];
    const analysisTypes = Array.isArray(config.analysisTypes) ? config.analysisTypes : [];
    let rules = [], currentPage = 1, pages = 1;

    const labels = {
      ip_exact:__('Exact IP','tenyen-analytics'), ip_cidr:__('IP CIDR','tenyen-analytics'), path_exact:__('Exact path','tenyen-analytics'), path_prefix:__('Path prefix','tenyen-analytics'),
      administrator:__('Administrator / self','tenyen-analytics'), bot:__('Bot / crawler','tenyen-analytics'), country:__('Country code','tenyen-analytics'), region:__('Region','tenyen-analytics'),
      asn:'ASN', organization:__('Organization','tenyen-analytics'), category:__('Organization category','tenyen-analytics'), browser:__('Browser','tenyen-analytics'), os:__('OS','tenyen-analytics'),
      device:__('Device','tenyen-analytics'), referrer_domain:__('Referrer domain','tenyen-analytics'), utm_source:__('UTM source','tenyen-analytics'),
      utm_medium:__('UTM medium','tenyen-analytics'), utm_campaign:__('UTM campaign','tenyen-analytics')
    };
    types.forEach(type => {
      const option = document.createElement('option'); option.value = type; option.textContent = labels[type] || type;
      form.elements.type.appendChild(option);
    });

    async function request(url, options = {}) {
      const response = await fetch(url, Object.assign({credentials:'same-origin', cache:'no-store'}, options, {
        headers:Object.assign({'Accept':'application/json', 'Content-Type':'application/json', 'X-WP-Nonce':config.nonce || ''}, options.headers || {})
      }));
      const payload = await response.json();
      if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
      return payload;
    }
    function td(row, value) { const cell = document.createElement('td'); cell.textContent = String(value ?? ''); row.appendChild(cell); return cell; }
    function render() {
      list.textContent = '';
      if (!rules.length) { const empty = document.createElement('p'); empty.textContent = __('No exclusion rules.', 'tenyen-analytics'); list.appendChild(empty); return; }
      const table = document.createElement('table'); table.className = 'widefat striped';
      const head = table.createTHead().insertRow(); ['ID',__('Type','tenyen-analytics'),__('Value','tenyen-analytics'),__('Scope / action','tenyen-analytics'),__('Status','tenyen-analytics'),__('Note','tenyen-analytics'),__('Created','tenyen-analytics'),__('Updated','tenyen-analytics'),__('Actions','tenyen-analytics')].forEach(value => { const th=document.createElement('th'); th.textContent=value; head.appendChild(th); });
      const body = table.createTBody();
      rules.forEach(rule => {
        const row = body.insertRow(); td(row, rule.rule_id); td(row, labels[rule.type] || rule.type); td(row, rule.value);
        td(row, rule.scope === 'collection' ? __('Exclude from collection','tenyen-analytics') : __('Exclude from analysis','tenyen-analytics'));
        td(row, rule.enabled ? __('Enabled','tenyen-analytics') : __('Disabled','tenyen-analytics')); td(row, rule.note); td(row, rule.created_at); td(row, rule.updated_at);
        const actions = td(row, '');
        const edit = document.createElement('button'); edit.type='button'; edit.className='button button-small'; edit.textContent=__('Edit','tenyen-analytics'); edit.addEventListener('click',()=>editRule(rule)); actions.appendChild(edit);
        actions.append(' ');
        const remove = document.createElement('button'); remove.type='button'; remove.className='button button-small'; remove.textContent=__('Delete','tenyen-analytics'); remove.addEventListener('click',()=>deleteRule(rule)); actions.appendChild(remove);
      });
      list.appendChild(table);
      if (pages > 1) { const nav=document.createElement('p'); const previous=document.createElement('button'); previous.className='button'; previous.textContent=__('Previous','tenyen-analytics'); previous.disabled=currentPage<=1; previous.addEventListener('click',()=>load(currentPage-1)); const next=document.createElement('button'); next.className='button'; next.textContent=__('Next','tenyen-analytics'); next.disabled=currentPage>=pages; next.addEventListener('click',()=>load(currentPage+1)); nav.append(previous,` ${currentPage} / ${pages} `,next); list.appendChild(nav); }
    }
    async function load(page=1) { const payload = await request(`${config.endpoint}?page=${Math.max(1,page)}&per_page=50`); rules = payload.rules || []; currentPage=Number(payload.page||1); pages=Number(payload.pages||1); render(); }
    function editRule(rule) { form.elements.rule_id.value=String(rule.rule_id); form.elements.type.value=rule.type; form.elements.value.value=rule.value; form.elements.scope.value=rule.scope; form.elements.note.value=rule.note || ''; form.elements.enabled.checked=Boolean(rule.enabled); syncScope(); form.scrollIntoView({behavior:'smooth',block:'center'}); }
    async function deleteRule(rule) { if (!window.confirm(__('Delete this exclusion rule? Historical data will not be deleted.','tenyen-analytics'))) return; try { await request(`${config.endpoint}/${rule.rule_id}`,{method:'DELETE'}); await load(); status.textContent=__('Rule deleted.','tenyen-analytics'); } catch(error) { status.textContent=error.message; } }
    function syncScope() { const analysis = form.elements.scope.value === 'analysis'; const type = form.elements.type.value; if (analysis && !analysisTypes.includes(type)) { form.elements.scope.value='collection'; status.textContent=__('This type is collection-only.','tenyen-analytics'); } const noValue=['administrator','bot'].includes(type); form.elements.value.required=!noValue; form.elements.value.disabled=noValue; if(noValue)form.elements.value.value='1'; }
    form.elements.type.addEventListener('change', syncScope); form.elements.scope.addEventListener('change', syncScope);
    form.addEventListener('reset',()=>setTimeout(()=>{form.elements.rule_id.value='0';status.textContent='';syncScope();},0));
    form.addEventListener('submit',async event => { event.preventDefault(); const id=Number(form.elements.rule_id.value||0); const payload={type:form.elements.type.value,value:form.elements.value.value,scope:form.elements.scope.value,note:form.elements.note.value,enabled:form.elements.enabled.checked}; try { await request(id?`${config.endpoint}/${id}`:config.endpoint,{method:id?'PUT':'POST',body:JSON.stringify(payload)}); form.reset(); form.elements.rule_id.value='0'; await load(); status.textContent=__('Rule saved.','tenyen-analytics'); } catch(error) { status.textContent=error.message; } });
    diagnosticForm.addEventListener('submit',async event => { event.preventDefault(); const payload=Object.fromEntries(new FormData(diagnosticForm)); try { const data=await request(config.diagnose,{method:'POST',body:JSON.stringify(payload)}); const item=data.diagnostic; result.textContent=[`${__('Action','tenyen-analytics')}: ${item.action}`,`${__('Matched rule','tenyen-analytics')}: ${item.rule_id ?? '—'}`,`${__('Precedence','tenyen-analytics')}: ${item.precedence ?? '—'}`,`${__('Reason','tenyen-analytics')}: ${item.reason}`].join('\n'); } catch(error) { result.textContent=error.message; } });
    syncScope(); load().catch(error => status.textContent=error.message);
  }
  document.addEventListener('DOMContentLoaded',()=>init(document,window.TYAExclusions||{}));
})();
