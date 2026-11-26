jQuery(function($){
'use strict';
var selectors={}, currentItems=[], frame=$('#uap50-preview-frame'), sourceUrl='';
function log(s){var b=$('#uap50-log');b.text('['+new Date().toLocaleTimeString()+'] '+s+'\n'+b.text());}
function req(action,data){data=data||{};data.action=action;data.nonce=UAP50.nonce;return $.post(UAP50.ajax,data);}
function esc(v){return $('<div>').text(v==null?'':String(v)).html();}
function preview(){
 sourceUrl=$('#uap50-url').val().trim();if(!sourceUrl){log('Спочатку введіть URL.');return;}
 $('#uap50-preview').prop('disabled',true).text('Завантаження…');
 req('uap50_preview',{url:sourceUrl}).done(function(r){
  if(!r.success){log(r.data.message||'Помилка попереднього перегляду.');return;}
  frame.attr('srcdoc',r.data.html);$('#uap50-selector-fields').empty();selectors={};log('Попередній перегляд готовий. Натисніть на потрібний елемент.');
 }).fail(function(x){log(x.responseJSON&&x.responseJSON.data?x.responseJSON.data.message:'Помилка завантаження.');})
 .always(function(){$('#uap50-preview').prop('disabled',false).text('Оновити попередній перегляд');});
}
$('#uap50-preview').on('click',preview);
window.addEventListener('message',function(ev){
 if(ev.source!==frame[0].contentWindow||!ev.data||!ev.data.uap50Selector)return;
 var d=ev.data.uap50Selector;
 var suggested=['title','description','price','image','brand','sku','color','size','material','attribute'].includes(d.suggestedField)?d.suggestedField:'description';var kind=window.prompt('Вибрано елемент <'+d.tag+'>. Вкажіть поле (тип визначено автоматично):\n title — назва\n description — опис\n price — ціна\n image — зображення\n brand — бренд\n sku — артикул\n color — колір\n size — розмір\n material — матеріал\n attribute — інша характеристика',suggested);
 if(!kind)return;kind=kind.toLowerCase().trim();
 if(!['title','description','price','image','brand','sku','color','size','material','attribute'].includes(kind)){log('Невідомий тип поля.');return;}
 selectors[kind]=d.selector;
 req('uap50_selector_extract',{url:sourceUrl,selector:d.selector}).done(function(r){
  if(!r.success){log(r.data.message||'Не вдалося перевірити селектор.');return;}
  var vals=r.data.values||[];
  var html='<div class="uap50-selector-row"><b>'+esc(kind)+'</b> <code>'+esc(d.selector)+'</code><button type="button" class="button-link uap50-remove-selector" data-kind="'+esc(kind)+'">Видалити</button><small>Знайдено: '+r.data.count+'</small><ul>';
  vals.slice(0,5).forEach(function(v){html+='<li>'+esc(v)+'</li>';});html+='</ul></div>';
  $('#uap50-selector-fields').find('[data-kind="'+kind+'"]').remove();$('#uap50-selector-fields').append(html);
 }).fail(function(x){log(x.responseJSON&&x.responseJSON.data?x.responseJSON.data.message:'Помилка перевірки CSS-селектора.');});
});
$('#uap50-selector-fields').on('click','.uap50-remove-selector',function(){delete selectors[$(this).data('kind')];$(this).closest('.uap50-selector-row').remove();});
$('#uap50-save-profile').on('click',function(){
 var name=window.prompt('Назва профілю збору:');if(!name)return;
 sourceUrl=$('#uap50-url').val().trim()||sourceUrl;
 req('uap50_profile_save',{name:name,data:{url:sourceUrl,selectors:selectors}}).done(function(r){if(r.success){log('Профіль «'+name+'» збережено.');loadProfiles();}else log(r.data.message||'Помилка збереження профілю.');});
});
function loadProfiles(){req('uap50_profile_list').done(function(r){if(!r.success)return;var s=$('#uap50-profile-list').empty().append('<option value="">Виберіть профіль</option>');$.each(r.data.profiles||{},function(id,p){s.append($('<option>').val(id).text(p.name));});});}
$('#uap50-profile-list').on('change',function(){var id=$(this).val();if(!id)return;req('uap50_profile_list').done(function(r){var p=r.data.profiles[id];if(!p)return;selectors=p.data.selectors||{};if(p.data.url)$('#uap50-url').val(p.data.url);sourceUrl=p.data.url||sourceUrl;$('#uap50-selector-fields').html('<p>Завантажено селекторів: '+Object.keys(selectors).length+'. Натисніть «Завантажити попередній перегляд» для перевірки.</p>');});});
function runQueue(resume){
 var url=$('#uap50-url').val().trim();if(!url){log('Вкажіть адресу для черги.');return;}
 $('#uap50-crawl').prop('disabled',true);$('#uap50-bar').css('width','1%');$('#uap50-percent').text('0%');$('#uap50-status').text('Запуск черги…');
 if(resume){step();}else{req('uap50_queue_start',{url:url,selectors:JSON.stringify(selectors)}).done(function(r){if(!r.success){log(r.data.message||'Чергу не запущено.');return;}step();}).fail(function(x){log(x.responseJSON&&x.responseJSON.data?x.responseJSON.data.message:'Помилка запуску черги.');$('#uap50-crawl').prop('disabled',false);});}
 function step(){
  req('uap50_queue_step',{batch:2}).done(function(r){
   if(!r.success){log(r.data.message||'Помилка черги.');$('#uap50-crawl').prop('disabled',false);return;}
   var d=r.data, pct=d.done?100:Math.min(95,Math.round(d.scanned/(d.scanned+d.remaining+1)*100));
   currentItems=d.items||[];window.UAP50Collected=currentItems;$('#uap50-bar').css('width',pct+'%');$('#uap50-percent').text(pct+'%');$('#uap50-status').text('Оброблено сторінок: '+d.scanned+'; у черзі: '+d.remaining);
   var cards='<p>Знайдено матеріалів: '+(d.items||[]).length+'</p><div class="uap50-grid">';(d.items||[]).forEach(function(it){cards+='<article class="uap50-item" data-item-id="'+esc(it.id)+'"><label><input type="checkbox" class="uap50-advanced-select" checked value="'+esc(it.id)+'"> <b>'+esc(it.title||'Без назви')+'</b></label><small>'+esc(it.source_url)+'</small>';if(it.images&&it.images.length){cards+='<div class="uap50-gallery">';it.images.forEach(function(im){cards+='<img src="'+esc(im)+'" alt="" loading="lazy">';});cards+='</div><small>Фото в галереї: '+it.images.length+'</small>';}if(it.attributes){cards+='<dl class="uap50-attributes">';Object.keys(it.attributes).forEach(function(k){var vals=Array.isArray(it.attributes[k])?it.attributes[k]:[it.attributes[k]];cards+='<dt>'+esc(k)+'</dt><dd>'+esc(vals.join(' / '))+'</dd>';});cards+='</dl>';}if(it.variations&&it.variations.length)cards+='<small>Варіантів товару: '+it.variations.length+'</small>';cards+='<details class="uap50-editor"><summary>Редагувати матеріал і галерею</summary><label>Назва<input class="uap50-edit-title" value="'+esc(it.title||'')+'"></label><label>Опис<textarea class="uap50-edit-description">'+esc(it.description||'')+'</textarea></label><label>Ціна<input class="uap50-edit-price" value="'+esc(it.price||'')+'"></label><label>Фото галереї — одне URL на рядок<textarea class="uap50-edit-images">'+esc((it.images||[]).join('\n'))+'</textarea></label><label>Атрибути — JSON<textarea class="uap50-edit-attributes">'+esc(JSON.stringify(it.attributes||{},null,2))+'</textarea></label><label>Варіації — JSON<textarea class="uap50-edit-variations">'+esc(JSON.stringify(it.variations||[],null,2))+'</textarea></label><button type="button" class="button uap50-save-item">Зберегти зміни</button></details><p>'+esc((it.description||'').replace(/<[^>]*>/g,'').slice(0,180))+'</p></article>';});cards+='</div>';$('#uap50-results').html(cards);$('#uap50-publish,#uap50-export,#uap50-export-profile-btn').prop('disabled',!(d.items||[]).length);
   if(d.done){$('#uap50-status').text('Чергу завершено');$('#uap50-crawl').prop('disabled',false);log('Черга завершена. Сторінок: '+d.scanned+'; нових матеріалів: '+d.added+'; помилок: '+d.errors+'.');(d.error_messages||[]).forEach(function(e){log('Помилка: '+e);});}
   else window.setTimeout(step,150);
  }).fail(function(x){$('#uap50-crawl').prop('disabled',false);log(x.responseJSON&&x.responseJSON.data?x.responseJSON.data.message:'Помилка кроку черги.');});
 }
}
$('#uap50-results').on('click','.uap50-save-item',function(){
 var card=$(this).closest('.uap50-item'),id=String(card.data('item-id')||card.find('input[type=checkbox]').val()),items=window.UAP50Collected||currentItems;
 var item=items.find(function(x){return String(x.id)===id;});if(!item){log('Не знайдено запис для збереження.');return;}
 try{
 item.title=card.find('.uap50-edit-title').val();
 item.description=card.find('.uap50-edit-description').val();
 item.price=card.find('.uap50-edit-price').val();
 item.images=card.find('.uap50-edit-images').val().split(/\r?\n/).map(function(x){return x.trim();}).filter(Boolean);
 item.attributes=JSON.parse(card.find('.uap50-edit-attributes').val()||'{}');
 item.variations=JSON.parse(card.find('.uap50-edit-variations').val()||'[]');
 }catch(e){log('Перевірте JSON атрибутів і варіацій: '+e.message);return;}
 req('uap50_items_update',{items:JSON.stringify(items)}).done(function(r){if(r.success){window.UAP50Collected=r.data.items;currentItems=r.data.items;log('Зміни збережено. Фото: '+item.images.length+', груп атрибутів: '+Object.keys(item.attributes).length);}else log(r.data.message||'Не вдалося зберегти зміни.');}).fail(function(x){log(x.responseJSON&&x.responseJSON.data?x.responseJSON.data.message:'Помилка збереження.');});
});
$('#uap50-crawl').off('click').on('click',function(){runQueue(false);});
$('#uap50-resume-queue').on('click',function(){runQueue(true);});
req('uap50_queue_status').done(function(r){if(r.success&&r.data.active){$('#uap50-resume-queue').show();if(r.data.url)$('#uap50-url').val(r.data.url);$('#uap50-status').text('Збережена черга: сторінок оброблено '+r.data.scanned+', залишилось '+r.data.remaining);}});
var oldPublish=$('#uap50-publish').clone();$('#uap50-publish').off('click').on('click',function(){
 if(!window.confirm('Опублікувати зібрані матеріали?'))return;
 req('uap50_publish_advanced',{post_type:$('#uap50-type').val(),post_status:$('#uap50-post-status').val(),selected:$('.uap50-advanced-select:checked, .uap50-select:checked').map(function(){return this.value;}).get()}).done(function(r){if(!r.success){log(r.data.message||'Помилка публікації.');return;}log('Публікація: створено '+r.data.count+', дублікатів пропущено '+r.data.duplicates+'.');(r.data.created||[]).forEach(function(x){log('Створено #'+x.id+' '+x.title+'; зображень: '+x.images);});}).fail(function(x){log(x.responseJSON&&x.responseJSON.data?x.responseJSON.data.message:'Помилка публікації.');});
});
$('#uap50-export-profile-btn').on('click',function(){
 var form=$('<form method="post"></form>').attr('action',UAP50.ajax);
 form.append($('<input type="hidden" name="action">').val('uap50_export_profile'));
 form.append($('<input type="hidden" name="nonce">').val(UAP50.nonce));
 form.append($('<input type="hidden" name="profile">').val($('#uap50-export-profile').val()));
 form.append($('<input type="hidden" name="delimiter">').val($('#uap50-delimiter').val()||';'));
 $('body').append(form);form.trigger('submit');form.remove();
});
loadProfiles();
});