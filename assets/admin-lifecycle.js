(() => {
  'use strict';
  const {__} = window.wp?.i18n || {__: value => value};
  const root = document.querySelector('#tya-lifecycle');
  const config = window.TYALifecycle || {};
  if (!root || !config.diagnostics) return;
  const status = root.querySelector('[data-lifecycle-status]');
  const storage = root.querySelector('[data-storage-diagnostics]');
  const cleanupResult = root.querySelector('[data-cleanup-result]');
  const retention = root.querySelector('[data-retention-form]');
  const aggregationStatus = root.querySelector('[data-aggregation-status]');
  const aggregationForm = root.querySelector('[data-aggregation-form]');

  async function request(url, options = {}) {
    const response = await fetch(url, {...options, credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json','Content-Type':'application/json','X-WP-Nonce':config.nonce||'',...(options.headers||{})}});
    const payload = await response.json().catch(()=>({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
    return payload;
  }
  const bytes = value => { const size=Number(value||0); if(size<1024)return `${size} B`; if(size<1048576)return `${(size/1024).toFixed(1)} KiB`; if(size<1073741824)return `${(size/1048576).toFixed(1)} MiB`; return `${(size/1073741824).toFixed(2)} GiB`; };
  function showDiagnostics(data) {
    const d=data.diagnostics||{}; const c=d.cleanup||{};
    storage.textContent='';
    const dl=document.createElement('dl'); dl.className='tya-system-list';
    [[__('Analytics table size','tenyen-analytics'),bytes(d.table_bytes)],[__('Database size','tenyen-analytics'),bytes(d.database_bytes)],[__('Raw events','tenyen-analytics'),Number(d.events||0).toLocaleString()],[__('Sessions','tenyen-analytics'),Number(d.sessions||0).toLocaleString()],[__('Oldest record','tenyen-analytics'),d.oldest||'—'],[__('Newest record','tenyen-analytics'),d.newest||'—'],[__('Retention','tenyen-analytics'),Number(d.retention_days)===0?__('Unlimited','tenyen-analytics'):`${d.retention_days} ${__('days','tenyen-analytics')}`],[__('Cleanup status','tenyen-analytics'),c.status||'—'],[__('Last run','tenyen-analytics'),c.last_run||'—'],[__('Next run','tenyen-analytics'),d.next_run||'—'],[__('Failure','tenyen-analytics'),c.error||'—']].forEach(([label,value])=>{const dt=document.createElement('dt');dt.textContent=label;const dd=document.createElement('dd');dd.textContent=value;dl.append(dt,dd);});
    storage.appendChild(dl);
    if(Array.isArray(d.monthly)&&d.monthly.length){const table=document.createElement('table');table.className='widefat striped';const head=table.createTHead().insertRow();[__('Month','tenyen-analytics'),__('Events','tenyen-analytics'),__('Sessions','tenyen-analytics')].forEach(x=>{const th=document.createElement('th');th.textContent=x;head.appendChild(th)});const body=table.createTBody();d.monthly.forEach(item=>{const row=body.insertRow();[item.month,Number(item.events).toLocaleString(),Number(item.sessions).toLocaleString()].forEach(x=>{const td=row.insertCell();td.textContent=x})});storage.appendChild(table);}
    const days=Number(d.retention_days||0); retention.elements.mode.value=days===0?'unlimited':([30,90,180,365].includes(days)?'preset':'custom'); retention.elements.days.value=days||90; syncRetention();
  }
  function syncRetention(){retention.elements.days.disabled=retention.elements.mode.value==='unlimited';}
  async function load(){try{showDiagnostics(await request(config.diagnostics));}catch(error){storage.textContent=error.message;}}
  function showAggregation(payload){const a=payload.aggregation||{};const s=a.state||{};const v=a.verification||{};aggregationStatus.textContent=`${__('Status','tenyen-analytics')}: ${s.status||'—'}\n${__('Aggregated days','tenyen-analytics')}: ${Number(a.days||0).toLocaleString()}\n${__('Oldest aggregate','tenyen-analytics')}: ${a.oldest||'—'}\n${__('Newest aggregate','tenyen-analytics')}: ${a.newest||'—'}\n${__('Checkpoint','tenyen-analytics')}: ${a.checkpoint||'—'}\n${__('Sample verification','tenyen-analytics')}: ${v.status||'—'} (${Number(v.mismatched||0)}/${Number(v.checked||0)})\n${__('Next run','tenyen-analytics')}: ${a.next_run||'—'}\n${__('Failure','tenyen-analytics')}: ${s.error||'—'}`;}
  async function loadAggregation(){if(!config.aggregationStatus)return;try{showAggregation(await request(config.aggregationStatus));}catch(error){aggregationStatus.textContent=error.message;}}
  retention.elements.mode.addEventListener('change',syncRetention);
  retention.addEventListener('submit',async event=>{event.preventDefault();status.textContent=__('Saving…','tenyen-analytics');try{const payload=await request(config.retention,{method:'POST',body:JSON.stringify({mode:retention.elements.mode.value,days:Number(retention.elements.days.value||0)})});status.textContent=payload.warning||__('Retention saved.','tenyen-analytics');await load();}catch(error){status.textContent=error.message;}});
  root.querySelector('[data-cleanup-preview]').addEventListener('click',async()=>{cleanupResult.textContent=__('Loading…','tenyen-analytics');try{const p=(await request(config.preview,{method:'POST',body:'{}'})).preview;const coverage=p.aggregate_coverage||{};cleanupResult.textContent=`${__('Cutoff','tenyen-analytics')}: ${p.cutoff||'—'}\n${__('Affected events','tenyen-analytics')}: ${p.events}\n${__('Affected sessions','tenyen-analytics')}: ${p.sessions}\n${__('Aggregate coverage','tenyen-analytics')}: ${coverage.complete?__('Complete','tenyen-analytics'):coverage.message||__('Incomplete','tenyen-analytics')}`;}catch(error){cleanupResult.textContent=error.message;}});
  root.querySelector('[data-cleanup-run]').addEventListener('click',async()=>{if(!confirm(__('Delete one bounded batch of expired raw events? This cannot be undone.','tenyen-analytics')))return;cleanupResult.textContent=__('Cleaning…','tenyen-analytics');try{const c=(await request(config.cleanup,{method:'POST',body:'{}'})).cleanup;cleanupResult.textContent=`${__('Status','tenyen-analytics')}: ${c.status}\n${__('Deleted total','tenyen-analytics')}: ${c.deleted_total}\n${__('Remaining','tenyen-analytics')}: ${c.remaining}`;await load();}catch(error){cleanupResult.textContent=error.message;}});
  root.querySelector('[data-export-form]').addEventListener('submit',event=>{const form=event.currentTarget;if(form.elements.ip_mode.value==='raw'&&!form.elements.confirm_raw.checked){event.preventDefault();status.textContent=__('Raw IP export requires explicit confirmation.','tenyen-analytics');}});
  aggregationForm?.addEventListener('submit',async event=>{event.preventDefault();aggregationStatus.textContent=__('Aggregating…','tenyen-analytics');try{showAggregation(await request(config.aggregationRebuild,{method:'POST',body:JSON.stringify({from:aggregationForm.elements.from.value,to:aggregationForm.elements.to.value})}));await load();}catch(error){aggregationStatus.textContent=error.message;}});
  syncRetention(); load(); loadAggregation();
})();
