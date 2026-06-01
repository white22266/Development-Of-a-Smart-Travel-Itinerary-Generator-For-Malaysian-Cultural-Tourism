// assets/itinerary_review_hotel_loader.js
// Optional nightly hotel selector for itinerary review.
// Google Places discovers hotels. SerpAPI is used only when the user clicks live pricing lookup.
(function () {
    if (window.__nightlyHotelSelectorInitialized) return;
    window.__nightlyHotelSelectorInitialized = true;

    var hotelLoadTimer = null;
    var nightlyHotelSelections = {};
    var lastNightKeys = {};

    function installHotelStyles() {
        if (document.getElementById('hotel-review-layout-style')) return;
        var style = document.createElement('style');
        style.id = 'hotel-review-layout-style';
        style.textContent = `
            .hotel-review-section{padding:22px!important;margin-bottom:20px!important;border-radius:18px!important}.hotel-review-header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:14px}.hotel-review-title h3{margin:0 0 6px!important;font-size:19px;line-height:1.2}.hotel-review-title p{margin:0;max-width:900px;color:#64748b;font-size:13px;line-height:1.5}.hotel-section-tools{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.hotel-source-pill,.night-toggle,.hotel-small-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:8px 12px;border-radius:999px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:900;white-space:nowrap;cursor:pointer}.hotel-small-btn{background:#f8fafc;color:#334155;border-color:#e2e8f0}.night-hotel-block{border:1px solid #e2e8f0;background:#fff;border-radius:16px;padding:15px;margin-top:12px}.night-hotel-block.not-selected{border-color:#e2e8f0;background:#fff}.night-hotel-block.selected{border-color:#86efac;background:#f0fdf4}.night-hotel-top{display:flex;justify-content:space-between;align-items:center;gap:14px}.night-hotel-left{min-width:0}.night-hotel-title{font-weight:950;color:#0f172a;font-size:15px}.night-hotel-sub{margin-top:3px;color:#64748b;font-size:12px;line-height:1.35}.night-hotel-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.night-selected-pill{padding:7px 10px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:900;white-space:nowrap}.night-selected-pill.selected{background:#dcfce7;color:#166534}.night-selected-pill.ready{background:#e0f2fe;color:#075985}.night-hotel-content{margin-top:14px}.night-hotel-content.collapsed{display:none}.hotel-pricing-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:12px 13px;border-radius:14px;background:#fff7ed;border:1px solid #fed7aa;margin-bottom:14px}.hotel-pricing-copy strong{display:block;margin-bottom:3px;color:#9a3412;font-size:13px;font-weight:900}.hotel-pricing-copy span{color:#9a3412;font-size:12px;line-height:1.4}.hotel-price-btn{flex:0 0 auto;border:0;border-radius:12px;padding:10px 14px;background:linear-gradient(135deg,#f59e0b,#f97316);color:#111827;box-shadow:0 8px 18px rgba(249,115,22,.18);cursor:pointer;font-size:12px;font-weight:950;white-space:nowrap}.hotel-price-btn:disabled{cursor:not-allowed;opacity:.65}.hotel-grid{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(250px,1fr))!important;gap:12px!important;align-items:stretch}.hotel-card-review{position:relative;min-height:172px;padding:16px!important;border:1px solid #e5e7eb!important;border-radius:15px!important;background:#fff!important;display:flex;flex-direction:column;gap:8px;transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease,background .15s ease}.hotel-card-review:hover{transform:translateY(-1px);border-color:#93c5fd!important;box-shadow:0 10px 24px rgba(15,23,42,.08)}.hotel-card-review.selected{border-color:#22c55e!important;background:#f0fdf4!important;box-shadow:0 10px 24px rgba(34,197,94,.12)}.hotel-name-rv{padding-right:82px;color:#0f172a;font-size:15px;line-height:1.25;font-weight:950}.hotel-meta-rv{color:#64748b;font-size:12px;line-height:1.35;min-height:33px}.hotel-price-rv{color:#4f46e5;font-size:17px;font-weight:950;letter-spacing:-.02em}.hotel-price-source-badge{display:inline-flex;width:fit-content;max-width:100%;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:900;line-height:1.2}.hotel-price-source-badge.estimate{background:#fef3c7;color:#92400e}.hotel-price-source-badge.serpapi{background:#dcfce7;color:#166534}.hotel-match-row{margin-top:auto;padding-top:6px}.hotel-match-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}.hotel-select-badge{position:absolute!important;top:12px!important;right:12px!important;display:none;padding:5px 9px;border-radius:999px;background:#22c55e;color:#052e16;font-size:11px;font-weight:950}.hotel-card-review.selected .hotel-select-badge{display:inline-flex}.hotel-empty-state{margin-top:10px;padding:12px 14px;border-radius:12px;border:1px solid rgba(148,163,184,.32);background:#f8fafc;color:#475569;font-size:12.5px;line-height:1.45}.hotel-empty-state strong{display:block;margin-bottom:4px;color:#334155;font-size:13px}@media(max-width:760px){.hotel-review-header,.night-hotel-top,.hotel-pricing-toolbar{flex-direction:column;align-items:stretch}.hotel-section-tools,.night-hotel-actions{justify-content:flex-start}.hotel-source-pill,.hotel-small-btn,.night-toggle,.hotel-price-btn{width:fit-content}}
        `;
        document.head.appendChild(style);
    }

    function getItineraryId(){ if(typeof window.ITINERARY_ID!=='undefined') return window.ITINERARY_ID; try{return new URLSearchParams(window.location.search).get('itinerary_id')||'';}catch(e){return '';} }
    function escHtml(value){return String(value==null?'':value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
    function hotelCardId(hotel,index){var raw=hotel.google_place_id||hotel.place_id||hotel.name||('hotel_'+index);return String(raw).replace(/[^a-zA-Z0-9_-]/g,'_');}
    function scoreMeta(hotel){var score=Number(hotel.score||0);var pct=Math.round(Math.max(0,Math.min(1,score))*100);if(!pct&&Number(hotel.rating||0)>0)pct=Math.round(Math.max(0,Math.min(5,Number(hotel.rating)))/5*100);if(pct>=85)return{pct:pct,label:'Excellent',color:'success',hex:'#22c55e'};if(pct>=70)return{pct:pct,label:'Good',color:'primary',hex:'#3b82f6'};if(pct>=50)return{pct:pct,label:'Fair',color:'warning',hex:'#f59e0b'};return{pct:pct||50,label:'Low',color:'danger',hex:'#ef4444'};}
    function hasSerpApiPrices(hotels){return Array.isArray(hotels)&&hotels.some(function(h){return h&&h.price_source==='serpapi_google_maps_price';});}

    function getTotalDays(){var days=Array.prototype.slice.call(document.querySelectorAll('.place-card')).map(function(c){return Number(c.dataset.day||0);}).filter(Boolean);return days.length?Math.max.apply(null,days):1;}
    function getTotalNights(){return Math.max(0,getTotalDays()-1);}

    function ensureHotelSection(){
        var target=document.querySelector('.hotel-review-section');
        if(target) return target;
        document.querySelectorAll('.card').forEach(function(card){if(target)return;var h=card.querySelector('h3');if(!h)return;var t=h.textContent.trim().toLowerCase();if(t==='select your hotel'||t==='select your hotels'||t==='optional hotel suggestions')target=card;});
        if(!target){var bar=document.querySelector('.confirm-bar');if(!bar||!bar.parentNode)return null;target=document.createElement('div');target.className='card';bar.parentNode.insertBefore(target,bar);} 
        target.classList.add('hotel-review-section');
        return target;
    }

    function getActiveFinalStopForDay(dayNo){
        var cards=Array.prototype.slice.call(document.querySelectorAll('.place-card[data-day="'+dayNo+'"]'));
        var active=cards.filter(function(card){var id=card.dataset.itemId;var s=id&&window.itemStatus?window.itemStatus[id]:card.dataset.status;return s!=='rejected'&&card.dataset.status!=='rejected';});
        if(!active.length)return null;
        active.sort(function(a,b){return Number(a.dataset.itemId||0)-Number(b.dataset.itemId||0);});
        var card=active[active.length-1];var itemId=card.dataset.itemId||'';var replacement=itemId&&window.replacementMap?window.replacementMap[itemId]:null;var titleEl=card.querySelector('.place-title');
        return{dayNo:dayNo,nightNo:dayNo,itemId:itemId,placeId:replacement&&replacement.place_id?replacement.place_id:(card.dataset.placeId||''),title:titleEl?titleEl.textContent.trim():'',card:card};
    }

    function headerHtml(){return '<div class="hotel-review-header"><div class="hotel-review-title"><h3>Optional Hotel Suggestions</h3><p>Hotel selection is optional. Each night can be expanded to view suggested hotels near that day\'s final kept stop, or skipped if the traveller books accommodation outside the system.</p></div><div class="hotel-section-tools"><button type="button" class="hotel-small-btn" id="hotel-expand-all">Expand all</button><button type="button" class="hotel-small-btn" id="hotel-collapse-all">Collapse all</button><span class="hotel-source-pill">Optional add-on</span></div></div>';}
    function priceSourceLabel(hotel){if((hotel.price_source||'')==='serpapi_google_maps_price')return hotel.price_label?'SerpAPI price: '+hotel.price_label:'SerpAPI cached price';return 'Planning estimate';}
    function selectedName(nightNo){return nightlyHotelSelections[nightNo]&&nightlyHotelSelections[nightNo].hotel?nightlyHotelSelections[nightNo].hotel.name:'';}

    function renderShell(section){
        var nights=getTotalNights();
        if(nights<=0){section.innerHTML=headerHtml()+'<div class="hotel-empty-state"><strong>No hotel night required.</strong>This itinerary has only one day, so overnight hotel suggestions are not needed.</div>';attachSectionControls();return;}
        var html=headerHtml();
        for(var night=1;night<=nights;night++){
            html+='<div class="night-hotel-block not-selected" id="night-hotel-block-'+night+'"><div class="night-hotel-top"><div class="night-hotel-left"><div class="night-hotel-title">Night '+night+' hotel suggestion</div><div class="night-hotel-sub" id="night-stop-'+night+'">Waiting for Day '+night+' final kept stop...</div></div><div class="night-hotel-actions"><div class="night-selected-pill" id="night-selected-'+night+'">Optional, not selected</div><button type="button" class="night-toggle" data-night="'+night+'" aria-expanded="false">Show hotels</button></div></div><div class="night-hotel-content collapsed" id="night-hotel-content-'+night+'"><div class="hotel-empty-state"><strong>Loading hotel suggestions...</strong></div></div></div>';
        }
        section.innerHTML=html;
        attachSectionControls();
    }

    function attachSectionControls(){
        document.querySelectorAll('.night-toggle').forEach(function(btn){btn.addEventListener('click',function(){toggleNight(btn.dataset.night);});});
        var expand=document.getElementById('hotel-expand-all');if(expand)expand.addEventListener('click',function(){setAllNights(false);});
        var collapse=document.getElementById('hotel-collapse-all');if(collapse)collapse.addEventListener('click',function(){setAllNights(true);});
    }
    function toggleNight(nightNo){var content=document.getElementById('night-hotel-content-'+nightNo);var btn=document.querySelector('.night-toggle[data-night="'+nightNo+'"]');if(!content||!btn)return;var collapsed=content.classList.toggle('collapsed');btn.textContent=collapsed?'Show hotels':'Hide hotels';btn.setAttribute('aria-expanded',collapsed?'false':'true');}
    function setAllNights(collapsed){document.querySelectorAll('.night-hotel-content').forEach(function(content){content.classList.toggle('collapsed',collapsed);});document.querySelectorAll('.night-toggle').forEach(function(btn){btn.textContent=collapsed?'Show hotels':'Hide hotels';btn.setAttribute('aria-expanded',collapsed?'false':'true');});}

    function renderNightLoading(nightNo, stop, lookupPricing){
        var block=document.getElementById('night-hotel-block-'+nightNo);var content=document.getElementById('night-hotel-content-'+nightNo);var stopEl=document.getElementById('night-stop-'+nightNo);var pill=document.getElementById('night-selected-'+nightNo);if(!block||!content)return;
        if(stopEl)stopEl.textContent=stop&&stop.title?'After Day '+nightNo+' final stop: '+stop.title:'No final kept stop for Day '+nightNo;
        if(pill){pill.textContent=lookupPricing?'Checking prices...':'Loading suggestions...';pill.className='night-selected-pill ready';}
        block.classList.add('not-selected');
        content.innerHTML='<div class="hotel-empty-state"><strong>'+(lookupPricing?'Checking live prices...':'Loading nearby hotels...')+'</strong>'+(lookupPricing?'Using SerpAPI for pricing only.':'Searching Google Places near this night\'s final stop. You can still confirm without choosing a hotel.')+'</div>';
    }

    function renderNightEmpty(nightNo,data){
        var block=document.getElementById('night-hotel-block-'+nightNo);var content=document.getElementById('night-hotel-content-'+nightNo);var pill=document.getElementById('night-selected-'+nightNo);if(!block||!content)return;block.classList.add('not-selected');
        if(pill){pill.textContent='No suggestions';pill.className='night-selected-pill';}
        content.innerHTML='<div class="hotel-empty-state"><strong>No hotel suggestions available for Night '+nightNo+'.</strong>'+escHtml((data&&data.message)||'No hotel results returned.')+' You can still confirm the itinerary and book accommodation yourself.</div>';
    }

    function renderNightHotels(nightNo,data){
        var block=document.getElementById('night-hotel-block-'+nightNo);var content=document.getElementById('night-hotel-content-'+nightNo);var stopEl=document.getElementById('night-stop-'+nightNo);var selectedPill=document.getElementById('night-selected-'+nightNo);if(!block||!content)return;
        var hotels=Array.isArray(data.hotels)?data.hotels:[];if(!hotels.length){renderNightEmpty(nightNo,data);return;}
        var lastStop=data.last_stop||{};if(stopEl)stopEl.textContent=lastStop.title?'After Day '+nightNo+' final stop: '+lastStop.title+' '+([lastStop.district,lastStop.state].filter(Boolean).join(', ')||''):'After Day '+nightNo+' final stop';
        var livePrices=hasSerpApiPrices(hotels);var selected=nightlyHotelSelections[nightNo]&&nightlyHotelSelections[nightNo].hotel?nightlyHotelSelections[nightNo].hotel.google_place_id:'';
        var html='<div class="hotel-pricing-toolbar"><div class="hotel-pricing-copy"><strong>'+(livePrices?'Live pricing available':'Prices are estimates')+'</strong><span>'+(livePrices?'Cached SerpAPI price data is shown where matched.':'Live price lookup is optional and uses SerpAPI quota only for this night.')+'</span></div><button type="button" class="hotel-price-btn" data-night="'+nightNo+'">'+(livePrices?'Refresh live prices':'Check live prices')+'</button></div><div class="hotel-grid">';
        hotels.forEach(function(hotel,index){var id=hotelCardId(hotel,index);var meta=scoreMeta(hotel);var price=Number(hotel.price_per_night||0);var rating=Number(hotel.rating||0);var distance=hotel.distance_km==null?null:Number(hotel.distance_km);var isSerp=hotel.price_source==='serpapi_google_maps_price';var isSelected=selected&&selected===(hotel.google_place_id||'');
            html+='<div class="hotel-card-review '+(isSelected?'selected':'')+'" id="hotel-card-night-'+nightNo+'-'+escHtml(id)+'" data-night="'+nightNo+'" data-card-id="'+escHtml(id)+'" data-hotel-json="'+escHtml(JSON.stringify(hotel))+'"><span class="hotel-select-badge">Selected</span><div class="hotel-name-rv">'+escHtml(hotel.name||'Unnamed hotel')+'</div><div class="hotel-meta-rv">'+escHtml(hotel.address||[hotel.district,hotel.state].filter(Boolean).join(', ')||'Address unavailable')+(rating>0?' · Google rating: '+rating.toFixed(1)+'/5':'')+(distance!==null&&!isNaN(distance)?' · '+distance.toFixed(1)+' km from final stop':'')+'</div><div class="hotel-price-rv">RM '+(price>0?Math.round(price).toLocaleString():'N/A')+' / night</div><span class="hotel-price-source-badge '+(isSerp?'serpapi':'estimate')+'">'+escHtml(priceSourceLabel(hotel))+'</span><div class="hotel-match-row"><div class="hotel-match-top"><span class="match-badge badge-'+meta.color+'">'+meta.label+'</span><span style="font-size:12px;font-weight:900;color:'+meta.hex+';">'+meta.pct+'%</span></div><div class="match-bar-track" style="height:5px;"><div class="match-bar-fill" style="width:'+meta.pct+'%;background:'+meta.hex+';"></div></div></div></div>';
        });
        html+='</div>';content.innerHTML=html;
        content.querySelectorAll('.hotel-card-review').forEach(function(card){card.addEventListener('click',function(){var hotel=JSON.parse(card.dataset.hotelJson||'{}');nightlyHotelSelections[nightNo]={hotel:hotel};content.querySelectorAll('.hotel-card-review').forEach(function(c){c.classList.remove('selected');});card.classList.add('selected');block.classList.remove('not-selected');block.classList.add('selected');if(selectedPill){selectedPill.textContent=hotel.name||'Selected';selectedPill.className='night-selected-pill selected';}updateNightlyHotelStats();});});
        var btn=content.querySelector('.hotel-price-btn');if(btn){btn.addEventListener('click',function(){btn.disabled=true;btn.textContent='Checking prices...';loadHotelsForNight(nightNo,true,true);});}
        if(selectedPill){var selectedHotel=selectedName(nightNo);selectedPill.textContent=selectedHotel||'Suggestions ready';selectedPill.className=selectedHotel?'night-selected-pill selected':'night-selected-pill ready';}block.classList.toggle('not-selected',!selectedName(nightNo));block.classList.toggle('selected',!!selectedName(nightNo));
    }

    function nightKey(nightNo,stop,pricing){if(!stop)return 'none';return[getItineraryId(),nightNo,stop.itemId||'',stop.placeId||'',pricing?'pricing':'normal'].join('|');}
    async function loadHotelsForNight(nightNo,force,lookupPricing){lookupPricing=lookupPricing===true;var stop=getActiveFinalStopForDay(nightNo);if(!stop){renderNightEmpty(nightNo,{message:'No kept stop found for Day '+nightNo+'.'});return;}var key=nightKey(nightNo,stop,lookupPricing);if(!force&&lastNightKeys[nightNo]===key)return;lastNightKeys[nightNo]=key;renderNightLoading(nightNo,stop,lookupPricing);var url='review_hotels.php?itinerary_id='+encodeURIComponent(getItineraryId())+'&item_id='+encodeURIComponent(stop.itemId||'')+'&place_id='+encodeURIComponent(stop.placeId||'')+(lookupPricing?'&lookup_pricing=1':'');try{var resp=await fetch(url,{method:'GET',headers:{Accept:'application/json'}});var text=await resp.text();var data;try{data=JSON.parse(text);}catch(e){data={status:'error',message:'Invalid hotel response from server.'};}if(data.status==='success')renderNightHotels(nightNo,data);else renderNightEmpty(nightNo,data);}catch(e){renderNightEmpty(nightNo,{message:'Network error while loading hotels.'});}}

    function loadAllNightHotels(force){var section=ensureHotelSection();if(!section)return;renderShell(section);var nights=getTotalNights();for(var night=1;night<=nights;night++)loadHotelsForNight(night,force,false);updateNightlyHotelStats();}
    function scheduleHotelReload(force){clearTimeout(hotelLoadTimer);hotelLoadTimer=setTimeout(function(){nightlyHotelSelections={};lastNightKeys={};loadAllNightHotels(force);},220);}
    function updateNightlyHotelStats(){var names=[];var total=getTotalNights();for(var night=1;night<=total;night++){if(selectedName(night))names.push('N'+night+': '+selectedName(night));}var stat=document.getElementById('stat-hotel');if(stat)stat.textContent=names.length?names.join(' | '):'None selected (optional)';}
    function currentRejectedIds(){return Object.entries(window.itemStatus||{}).filter(function(pair){return pair[1]==='rejected';}).map(function(pair){return parseInt(pair[0]);});}

    function overrideConfirmReview(){
        if(typeof window.confirmReview!=='function'||window.confirmReview.__nightlyHotelsOptional)return;
        window.confirmReview=async function(){
            var btn=document.getElementById('btn-confirm');btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Saving...';
            var payload={};Object.keys(nightlyHotelSelections).forEach(function(night){payload[night]={hotel:nightlyHotelSelections[night].hotel};});
            try{var resp=await fetch('review_confirm_nightly.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({itinerary_id:getItineraryId(),rejected_ids:currentRejectedIds().join(','),replacements_json:JSON.stringify(window.replacementMap||{}),hotel_selections_json:JSON.stringify(payload)})});var data=await resp.json();if(data.status==='success')window.location.href='itinerary_view.php?itinerary_id='+getItineraryId();else{alert('Error: '+(data.message||'Could not save review.'));btn.disabled=false;btn.innerHTML='Confirm & View Trip';}}catch(e){alert('Network error. Please try again.');btn.disabled=false;btn.innerHTML='Confirm & View Trip';}
        };
        window.confirmReview.__nightlyHotelsOptional=true;
    }

    function wrapActions(){['acceptPlace','rejectPlace','resetAll'].forEach(function(name){if(typeof window[name]==='function'&&!window[name].__nightlyHotelWrapped){var original=window[name];window[name]=function(){var result=original.apply(this,arguments);scheduleHotelReload(true);return result;};window[name].__nightlyHotelWrapped=true;}});if(typeof window.replacePlace==='function'&&!window.replacePlace.__nightlyHotelWrapped){var old=window.replacePlace;window.replacePlace=async function(){var result=await old.apply(this,arguments);scheduleHotelReload(true);return result;};window.replacePlace.__nightlyHotelWrapped=true;}overrideConfirmReview();}

    function init(){installHotelStyles();wrapActions();loadAllNightHotels(true);} 
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
