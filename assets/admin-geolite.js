(() => {
  'use strict';
  const {__} = window.wp?.i18n || {__: value => value};
  const root = document.querySelector('#tya-geolite');
  const config = window.TYAGeoLite || {};
  if (!root || !config.status) return;
  const form = root.querySelector('[data-geolite-settings]');
  const status = root.querySelector('[data-geolite-status]');
  const message = root.querySelector('[data-geolite-message]');
  const update = root.querySelector('[data-geolite-update]');
  const selection = root.querySelector('[data-geolite-database]');

  async function request(url, options = {}) {
    const response = await fetch(url, {...options, credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json','Content-Type':'application/json','X-WP-Nonce':config.nonce||'',...(options.headers||{})}});
    const payload = await response.json().catch(()=>({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
    return payload;
  }
  const bytes=value=>{const size=Number(value||0);if(size<1024)return`${size} B`;if(size<1048576)return`${(size/1024).toFixed(1)} KiB`;return`${(size/1048576).toFixed(1)} MiB`;};
  const healthLabel=value=>({current:__('Current','tenyen-analytics'),stale:__('Stale','tenyen-analytics'),missing:__('Missing','tenyen-analytics'),unreadable:__('Unreadable','tenyen-analytics'),wrong_type:__('Wrong type','tenyen-analytics'),corrupt:__('Corrupt','tenyen-analytics')}[value]||value||'—');
  function render(payload) {
    const geo=payload.geolite||{}; const databases=geo.databases||{};
    form.elements.account_id.value=geo.account_id||'';
    form.elements.license_key.value='';
    form.elements.license_key.placeholder=geo.license_key_masked||'••••••••';
    form.elements.automatic.checked=Boolean(geo.automatic);
    form.elements.clear_license_key.checked=false;
    status.textContent='';
    const overview=document.createElement('dl'); overview.className='tya-system-list';
    [[__('Automatic updates','tenyen-analytics'),geo.automatic?__('Enabled','tenyen-analytics'):__('Disabled','tenyen-analytics')],[__('Credentials','tenyen-analytics'),geo.credentials_configured?__('Configured','tenyen-analytics'):__('Not configured','tenyen-analytics')],[__('Last run','tenyen-analytics'),geo.last_run||'—'],[__('Next run','tenyen-analytics'),geo.next_run||'—'],[__('Next retry','tenyen-analytics'),geo.next_retry||'—'],[__('Update lock','tenyen-analytics'),geo.locked?__('Active','tenyen-analytics'):__('Idle','tenyen-analytics')]].forEach(([label,value])=>{const dt=document.createElement('dt');dt.textContent=label;const dd=document.createElement('dd');dd.textContent=value;overview.append(dt,dd);});
    status.appendChild(overview);
    const table=document.createElement('table');table.className='widefat striped tya-geolite-table';const head=table.createTHead().insertRow();[__('Database','tenyen-analytics'),__('Health','tenyen-analytics'),__('File','tenyen-analytics'),__('Type','tenyen-analytics'),__('Build date','tenyen-analytics'),__('Size','tenyen-analytics'),__('Last successful update','tenyen-analytics'),__('Last attempt','tenyen-analytics'),__('Failure','tenyen-analytics')].forEach(label=>{const th=document.createElement('th');th.textContent=label;head.appendChild(th)});const body=table.createTBody();[['City',databases.city||{}],['ASN',databases.asn||{}]].forEach(([name,item])=>{const row=body.insertRow();[name,healthLabel(item.health||item.status),item.path||'—',item.database_type||'—',item.build_date||'—',bytes(item.size),item.last_success||'—',item.last_attempt||'—',item.error||'—'].forEach(value=>{const td=row.insertCell();td.textContent=value;});});status.appendChild(table);
  }
  async function load(){try{render(await request(config.status));}catch(error){status.textContent=error.message;}}
  form.addEventListener('submit',async event=>{event.preventDefault();message.textContent=__('Saving…','tenyen-analytics');try{render(await request(config.settings,{method:'POST',body:JSON.stringify({account_id:form.elements.account_id.value,license_key:form.elements.license_key.value,automatic:form.elements.automatic.checked,clear_license_key:form.elements.clear_license_key.checked})}));message.textContent=__('GeoLite2 update settings saved.','tenyen-analytics');}catch(error){message.textContent=error.message;}});
  update.addEventListener('click',async()=>{message.textContent=__('Updating GeoLite2 databases…','tenyen-analytics');update.disabled=true;try{render(await request(config.update,{method:'POST',body:JSON.stringify({database:selection.value})}));message.textContent=__('GeoLite2 update attempt finished. Review each database status.','tenyen-analytics');}catch(error){message.textContent=error.message;}finally{update.disabled=false;}});
  load();
})();
