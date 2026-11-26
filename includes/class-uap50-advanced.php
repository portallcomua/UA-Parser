<?php
if (!defined('ABSPATH')) exit;

/**
 * Advanced collection layer: visual selector, resumable queue, CMS export profiles.
 * Freemius is bootstrapped only when real credentials are supplied in wp-config.php.
 */
final class UAP50_Advanced {
    const NONCE = 'uap50_nonce';
    const QUEUE = 'uap50_queue_';
    const ITEMS = 'uap50_items_';

    public function __construct() {
        add_action('wp_ajax_uap50_preview', array($this, 'preview'));
        add_action('wp_ajax_uap50_selector_extract', array($this, 'selector_extract'));
        add_action('wp_ajax_uap50_queue_start', array($this, 'queue_start'));
        add_action('wp_ajax_uap50_queue_status', array($this, 'queue_status'));
        add_action('wp_ajax_uap50_queue_step', array($this, 'queue_step'));
        add_action('wp_ajax_uap50_profile_save', array($this, 'profile_save'));
        add_action('wp_ajax_uap50_profile_list', array($this, 'profile_list'));
        add_action('wp_ajax_uap50_export_profile', array($this, 'export_profile'));
        add_action('wp_ajax_uap50_publish_advanced', array($this, 'publish_advanced'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        $this->freemius_bootstrap();
    }

    public function assets($hook) {
        if ($hook !== 'toplevel_page_ua-parser') return;
        wp_enqueue_script('uap50-advanced', plugin_dir_url(dirname(__FILE__)) . 'assets/advanced.js', array('jquery', 'uap50'), '5.0.0-beta.2', true);
    }

    private function auth() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Недостатньо прав.'), 403);
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private function valid_url($url) {
        $url = esc_url_raw(trim((string)$url), array('http','https'));
        if (!$url || !wp_http_validate_url($url)) return new WP_Error('url', 'Некоректна адреса.');
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        if (!$host || $host === 'localhost' || substr($host, -6) === '.local') return new WP_Error('host', 'Локальні адреси заборонені.');
        $ip = gethostbyname($host);
        if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return new WP_Error('ip', 'Приватні IP заборонені.');
        return $url;
    }

    private function fetch($url) {
        $safe = $this->valid_url($url);
        if (is_wp_error($safe)) return $safe;
        $r = wp_safe_remote_get($safe, array('timeout'=>18,'redirection'=>0,'limit_response_size'=>2500000,'headers'=>array('User-Agent'=>'UA-Parser/5.0 (+WordPress)')));
        if (is_wp_error($r)) return $r;
        $code = (int)wp_remote_retrieve_response_code($r);
        if ($code < 200 || $code > 299) return new WP_Error('http', 'HTTP '.$code);
        return (string)wp_remote_retrieve_body($r);
    }

    private function dom($html) {
        $old = libxml_use_internal_errors(true);
        $d = new DOMDocument();
        $ok = $d->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        return $ok ? array($d, new DOMXPath($d)) : false;
    }

    private function absolute($value, $base) {
        $value = trim((string)$value);
        if (!$value || strpos($value,'data:')===0 || strpos($value,'javascript:')===0) return '';
        if (strpos($value,'//')===0) return (wp_parse_url($base,PHP_URL_SCHEME) ?: 'https').':'.$value;
        if (preg_match('#^https?://#i',$value)) return $value;
        $p = wp_parse_url($base); if (!$p || empty($p['host'])) return '';
        $origin = (isset($p['scheme'])?$p['scheme']:'https').'://'.$p['host'].(isset($p['port'])?':'.(int)$p['port']:'');
        if (strpos($value,'/')===0) return $origin.$value;
        return $origin.trailingslashit(dirname(isset($p['path'])?$p['path']:'/')).$value;
    }

    private function extract_item($html, $url, $selectors = array()) {
        $parsed = $this->dom($html); if (!$parsed) return new WP_Error('html','HTML не розібрано.');
        list($dom,$xp) = $parsed;
        $pick = function($selector, $fallback) use ($dom,$xp) {
            if ($selector && preg_match('/^[a-zA-Z0-9_#\.\[\]="\'\- >:+~*]+$/', $selector)) {
                $xpath = $this->css_to_xpath($selector);
                if ($xpath) { $n=$xp->query($xpath)->item(0); if ($n) return trim(preg_replace('/\s+/u',' ',($n instanceof DOMAttr)?$n->value:$n->textContent)); }
            }
            $n=$xp->query($fallback)->item(0); return $n ? trim(preg_replace('/\s+/u',' ',($n instanceof DOMAttr)?$n->value:$n->textContent)) : '';
        };
        $title = $pick(isset($selectors['title'])?$selectors['title']:'', '//h1 | //meta[@property="og:title"]/@content | //title');
        $description = $pick(isset($selectors['description'])?$selectors['description']:'', '//meta[@name="description"]/@content | //article | //main');
        $price = $pick(isset($selectors['price'])?$selectors['price']:'', '//*[@itemprop="price"] | //*[contains(@class,"price") or contains(@class,"Price")]');
        $images = array();
        if (!empty($selectors['image'])) { $ix=$this->css_to_xpath($selectors['image']); if ($ix) foreach ($xp->query($ix) as $im) { $v=($im instanceof DOMElement)?($im->getAttribute('src')?:$im->getAttribute('data-src')):$im->nodeValue; $u=$this->absolute($v,$url); if ($u) $images[]=$u; } }
        $queries = array('//meta[@property="og:image"]/@content','//img[@src]/@src','//img[@data-src]/@data-src','//a[contains(@class,"gallery")]/@href');
        foreach ($queries as $q) foreach ($xp->query($q) as $n) {
            $u=$this->absolute($n->nodeValue,$url); if ($u && !in_array($u,$images,true)) $images[]=$u;
            if (count($images)>=30) break 2;
        }
        $attrs = array();
        foreach ($xp->query('//*[@itemprop="brand"] | //*[@itemprop="sku"] | //*[@itemprop="color"] | //*[@itemprop="size"] | //*[@itemprop="gtin13"]') as $n) {
            $key = $n->getAttribute('itemprop'); $val = trim($n->getAttribute('content') ?: $n->textContent);
            if ($key && $val) $attrs[$key] = sanitize_text_field($val);
        }
        foreach (array('brand','sku','color','attribute') as $field) {
            if (!empty($selectors[$field])) { $sx=$this->css_to_xpath($selectors[$field]); if ($sx) { $sn=$xp->query($sx)->item(0); if ($sn) { $sv=($sn instanceof DOMAttr)?$sn->value:(($sn instanceof DOMElement && $sn->tagName==='img')?$sn->getAttribute('alt'):$sn->textContent); if (trim($sv)!=='') $attrs[$field]=sanitize_text_field(trim($sv)); } } }
        }
        $categories=array(); foreach ($xp->query('//*[@itemprop="category"] | //*[@itemprop="name" and ancestor::*[@itemtype="*BreadcrumbList"]]') as $cn) { $cv=trim($cn->textContent); if ($cv && !in_array($cv,$categories,true)) $categories[]=sanitize_text_field($cv); if (count($categories)>=8) break; }
        $canonical=$xp->query('//link[@rel="canonical"]/@href')->item(0);
        return array('id'=>wp_generate_uuid4(),'source_url'=>$canonical?$this->absolute($canonical->nodeValue,$url):$url,
            'title'=>sanitize_text_field($title),'description'=>wp_kses_post($description),'price'=>sanitize_text_field($price),
            'images'=>array_values(array_unique($images)),'attributes'=>$attrs,'categories'=>$categories);
    }

private function css_to_xpath($css) {
        $css=trim($css); if (!$css || strpos($css,',')!==false || strpos($css,':')!==false) return '';
        $parts=preg_split('/\\s+/', $css); $out=array();
        foreach ($parts as $part) {
            if (!preg_match('/^([a-zA-Z][\\w-]*)?(?:#([\\w-]+))?((?:\\.[\\w-]+)*)$/',$part,$m)) return '';
            $tag=!empty($m[1])?$m[1]:'*'; $expr='//'.$tag;
            if (!empty($m[2])) $expr.='[@id="'.esc_attr($m[2]).'"]';
            if (!empty($m[3])) { preg_match_all('/\\.([\\w-]+)/',$m[3],$classes); foreach ($classes[1] as $cl) $expr.='[contains(concat(" ",normalize-space(@class)," ")," '.$cl.' ")]'; }
            $out[]=$expr;
        }
        return implode('', $out);
    }

    public function preview() {
        $this->auth(); $url=isset($_POST['url'])?wp_unslash($_POST['url']):''; $html=$this->fetch($url);
        if (is_wp_error($html)) wp_send_json_error(array('message'=>$html->get_error_message()),400);
        $parsed=$this->dom($html); if (!$parsed) wp_send_json_error(array('message'=>'HTML не розібрано.'),400);
        list($dom,$xp)=$parsed;
        foreach ($xp->query('//script | //iframe | //object | //embed | //form') as $node) $node->parentNode->removeChild($node);
        foreach ($xp->query('//*') as $node) {
            if (!$node->hasAttributes()) continue;
            $remove=array(); foreach ($node->attributes as $a) if (stripos($a->name,'on')===0 || $a->name==='srcdoc') $remove[]=$a->name;
            foreach ($remove as $name) $node->removeAttribute($name);
        }
        $base=$dom->createElement('base'); $base->setAttribute('href',esc_url($url));
        $head=$dom->getElementsByTagName('head')->item(0); if ($head) $head->insertBefore($base,$head->firstChild);
        $scriptCode = '(function(){function path(e){var a=[];while(e&&e.nodeType===1&&e!==document.body){var s=e.tagName.toLowerCase();if(e.id){s+="#"+e.id;a.unshift(s);break;}if(e.className&&typeof e.className==="string"){var cl=e.className.trim().split(/\\s+/).filter(Boolean).slice(0,1);if(cl.length)s+="."+cl[0];}a.unshift(s);e=e.parentElement;}return a.join(" ");}document.addEventListener("click",function(ev){var e=ev.target.closest("h1,h2,h3,p,span,div,img,a,li,button");if(!e)return;ev.preventDefault();ev.stopPropagation();parent.postMessage({uap50Selector:{selector:path(e),text:(e.innerText||e.alt||e.getAttribute("content")||"").trim().slice(0,500),tag:e.tagName.toLowerCase(),image:e.tagName==="IMG"?e.src:""}},"*");},true);})();';
        $body=$dom->getElementsByTagName('body')->item(0); if ($body) { $script=$dom->createElement('script'); $script->appendChild($dom->createTextNode($scriptCode)); $body->appendChild($script); }
        wp_send_json_success(array('html'=>$dom->saveHTML(),'url'=>esc_url_raw($url)));
    }

    public function selector_extract() {
        $this->auth(); $url=isset($_POST['url'])?wp_unslash($_POST['url']):''; $selector=isset($_POST['selector'])?sanitize_text_field(wp_unslash($_POST['selector'])):'';
        $html=$this->fetch($url); if (is_wp_error($html)) wp_send_json_error(array('message'=>$html->get_error_message()),400);
        $parsed=$this->dom($html); if (!$parsed) wp_send_json_error(array('message'=>'HTML не розібрано.'),400);
        $xpath=$this->css_to_xpath($selector); if (!$xpath) wp_send_json_error(array('message'=>'Не підтримується цей CSS-селектор. Оберіть простий елемент без псевдокласів.'),400);
        $nodes=$parsed[1]->query($xpath); $values=array(); foreach ($nodes as $n) { $v=trim(preg_replace('/\s+/u',' ',$n->textContent)); if ($n instanceof DOMAttr) $v=$n->value; if ($n instanceof DOMElement && $n->tagName==='img') $v=$n->getAttribute('src'); if ($v!=='') $values[]=$v; if (count($values)>=100) break; }
        wp_send_json_success(array('selector'=>$selector,'count'=>count($values),'values'=>$values));
    }

    public function queue_start() {
        $this->auth(); $url=isset($_POST['url'])?wp_unslash($_POST['url']):''; $safe=$this->valid_url($url);
        if (is_wp_error($safe)) wp_send_json_error(array('message'=>$safe->get_error_message()),400);
        $uid=get_current_user_id(); $key=self::QUEUE.$uid;
        $selectors=isset($_POST['selectors'])?json_decode(wp_unslash($_POST['selectors']),true):array(); if (!is_array($selectors)) $selectors=array();
        set_transient($key,array('queue'=>array($safe),'seen'=>array(),'scanned'=>0,'added'=>0,'errors'=>array(),'done'=>false,'selectors'=>$selectors,'start_url'=>$safe),DAY_IN_SECONDS);
        wp_send_json_success(array('started'=>true));
    }

    public function queue_status() {
        $this->auth(); $q=get_transient(self::QUEUE.get_current_user_id());
        if (!is_array($q)) wp_send_json_success(array('active'=>false));
        wp_send_json_success(array('active'=>empty($q['done']),'scanned'=>(int)$q['scanned'],'remaining'=>count($q['queue']),'added'=>(int)$q['added'],'url'=>isset($q['start_url'])?$q['start_url']:''));
    }

    public function queue_step() {
        $this->auth(); $uid=get_current_user_id(); $key=self::QUEUE.$uid; $q=get_transient($key);
        if (!is_array($q)) wp_send_json_error(array('message'=>'Чергу не запущено.'),400);
        $limit=isset($_POST['batch'])?min(5,max(1,(int)$_POST['batch'])):1; $items=get_transient(self::ITEMS.$uid); if (!is_array($items)) $items=array();
        for ($i=0;$i<$limit && !empty($q['queue']);$i++) {
            $url=array_shift($q['queue']); if (isset($q['seen'][$url])) continue; $q['seen'][$url]=1;
            $html=$this->fetch($url); $q['scanned']++;
            if (is_wp_error($html)) { $q['errors'][]=$url.' — '.$html->get_error_message(); continue; }
            $item=$this->extract_item($html,$url,isset($q['selectors'])?$q['selectors']:array()); if (is_wp_error($item)) { $q['errors'][]=$url.' — помилка HTML'; continue; }
            $exists=false; foreach ($items as $old) if (!empty($old['source_url']) && $old['source_url']===$item['source_url']) {$exists=true;break;}
            if (!$exists && !empty($item['title'])) {$items[]=$item;$q['added']++;}
            $parsed=$this->dom($html); if ($parsed) {
                $xp=$parsed[1]; $baseHost=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
                $next=$xp->query('//link[contains(concat(" ",normalize-space(@rel)," ")," next ")]/@href')->item(0);
                if ($next) { $n=$this->absolute($next->nodeValue,$url); if ($n && strtolower((string)wp_parse_url($n,PHP_URL_HOST))===$baseHost && empty($q['seen'][$n])) $q['queue'][]=$n; }
                foreach ($xp->query('//a[@href]') as $a) {
                    $href=$this->absolute($a->getAttribute('href'),$url); if (!$href || strtolower((string)wp_parse_url($href,PHP_URL_HOST))!==$baseHost || isset($q['seen'][$href])) continue;
                    $label=strtolower(trim($a->textContent)); $path=(string)wp_parse_url($href,PHP_URL_PATH); $query=(string)wp_parse_url($href,PHP_URL_QUERY); $parentClass=$a->parentNode instanceof DOMElement ? strtolower($a->parentNode->getAttribute('class')) : '';
                    if (preg_match('/(page|pagina|страниц|next|наступ|older|›|»|page=|paged=|offset=)/iu',$label.' '.$path.' '.$query.' '.$parentClass) && count($q['seen'])+count($q['queue'])<500) $q['queue'][]=$href;
                }
            }
        }
        $q['queue']=array_values(array_unique(array_slice($q['queue'],0,500)));
        $q['done']=empty($q['queue']) || $q['scanned']>=500;
        set_transient($key,$q,DAY_IN_SECONDS); set_transient(self::ITEMS.$uid,$items,DAY_IN_SECONDS);
        wp_send_json_success(array('scanned'=>$q['scanned'],'added'=>$q['added'],'remaining'=>count($q['queue']),'done'=>$q['done'],'errors'=>count($q['errors']),'items'=>$items));
    }

    public function profile_save() {
        $this->auth(); $name=isset($_POST['name'])?sanitize_text_field(wp_unslash($_POST['name'])):''; $data=isset($_POST['data'])?json_decode(wp_unslash($_POST['data']),true):array();
        if (!$name || !is_array($data)) wp_send_json_error(array('message'=>'Вкажіть назву та параметри профілю.'),400);
        $profiles=get_option('uap50_profiles',array()); if (!is_array($profiles)) $profiles=array();
        $id=sanitize_key($name); $profiles[$id]=array('name'=>$name,'data'=>$data,'updated'=>current_time('mysql')); update_option('uap50_profiles',$profiles,false);
        wp_send_json_success(array('id'=>$id,'profiles'=>$profiles));
    }

    public function profile_list() {
        $this->auth(); $profiles=get_option('uap50_profiles',array()); wp_send_json_success(array('profiles'=>is_array($profiles)?$profiles:array()));
    }

    public function export_profile() {
        $this->auth();
        $profile=isset($_POST['profile'])?sanitize_key($_POST['profile']):'csv';
        $items=get_transient(self::ITEMS.get_current_user_id()); if (!is_array($items)) $items=array();
        $maps=array(
            'prom'=>array('Назва_позиції'=>'title','Опис'=>'description','Ціна'=>'price','Посилання_зображення'=>'images','Код_товару'=>'sku','Посилання_джерела'=>'source_url'),
            'rozetka'=>array('title_ua'=>'title','description_ua'=>'description','price'=>'price','images'=>'images','article'=>'sku','source_url'=>'source_url'),
            'opencart'=>array('product_name'=>'title','description'=>'description','price'=>'price','image'=>'images','sku'=>'sku','source_url'=>'source_url'),
            'virtuemart'=>array('product_sku'=>'sku','product_name'=>'title','product_desc'=>'description','product_price'=>'price','file_url'=>'images','source_url'=>'source_url'),
            'joomshopping'=>array('product_name'=>'title','description'=>'description','product_price'=>'price','image'=>'images','product_ean'=>'sku','source_url'=>'source_url'),
            'woocommerce'=>array('Type'=>'type','Name'=>'title','Published'=>'published','Description'=>'description','Regular price'=>'price','Images'=>'images','SKU'=>'sku','Categories'=>'categories'),
            'wordpress'=>array('post_title'=>'title','post_content'=>'description','post_status'=>'status','post_type'=>'type','featured_image'=>'images','categories'=>'categories','source_url'=>'source_url')
        );
        if (!isset($maps[$profile])) wp_send_json_error(array('message'=>'Невідомий профіль експорту.'),400);
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=ua-parser-'.$profile.'.csv');
        $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,array_keys($maps[$profile]),';');
        foreach ($items as $item) {
            $row=array();
            foreach ($maps[$profile] as $field) {
                $v='';
                if ($field==='sku') $v=isset($item['attributes']['sku'])?$item['attributes']['sku']:'';
                elseif ($field==='categories') $v=implode('|',(array)(isset($item['categories'])?$item['categories']:array()));
                elseif ($field==='images') $v=implode('|',(array)(isset($item['images'])?$item['images']:array()));
                elseif ($field==='description') $v=wp_strip_all_tags(isset($item['description'])?$item['description']:'');
                elseif ($field==='type') $v='simple';
                elseif ($field==='published') $v=0;
                elseif ($field==='status') $v='draft';
                elseif ($field==='source_url') $v=isset($item['source_url'])?$item['source_url']:'';
                elseif (isset($item[$field])) $v=$item[$field];
                $row[]=$v;
            }
            fputcsv($out,$row,';');
        }
        fclose($out); exit;
    }

    public function publish_advanced() {
        $this->auth();
        $items=get_transient(self::ITEMS.get_current_user_id()); if (!is_array($items)) $items=array();
        $selected=isset($_POST['selected'])?array_map('sanitize_text_field',(array)wp_unslash($_POST['selected'])):array(); if ($selected) $items=array_values(array_filter($items,function($it)use($selected){return in_array($it['id'],$selected,true);}));
        $type=isset($_POST['post_type'])?sanitize_key($_POST['post_type']):'product'; $status=isset($_POST['post_status'])?sanitize_key($_POST['post_status']):'draft';
        if (!in_array($type,array('product','post','page'),true)) wp_send_json_error(array('message'=>'Непідтримуваний тип.'),400); if ($type==='product' && !class_exists('WooCommerce')) wp_send_json_error(array('message'=>'Для публікації товарів потрібен WooCommerce.'),400);
        if (!in_array($status,array('draft','publish'),true)) $status='draft';
        $created=array(); $dupes=0;
        foreach ($items as $item) {
            $source=esc_url_raw(isset($item['source_url'])?$item['source_url']:''); if (!$source) continue;
            $found=get_posts(array('post_type'=>$type==='product'?'product':$type,'post_status'=>'any','meta_key'=>'_uap50_source_url','meta_value'=>$source,'fields'=>'ids','numberposts'=>1));
            if ($found) {$dupes++;continue;}
            $title=sanitize_text_field(isset($item['title'])?$item['title']:'Без назви'); $desc=wp_kses_post(isset($item['description'])?$item['description']:'');
            if ($type==='product') {
                $p=new WC_Product_Simple(); $p->set_name($title); $p->set_status($status); $p->set_description($desc);
                $raw=preg_replace('/[^0-9,.]/u','',isset($item['price'])?$item['price']:''); if (substr_count($raw,',')===1 && strpos($raw,'.')===false) $raw=str_replace(',','.',$raw); else $raw=str_replace(',','',$raw);
                if ($raw!=='' && is_numeric($raw)) $p->set_regular_price(wc_format_decimal($raw));
                $p->update_meta_data('_uap50_source_url',$source);
                if (!empty($item['categories']) && is_array($item['categories'])) { $cat_ids=array(); foreach ($item['categories'] as $cat_name) { $term=term_exists($cat_name,'product_cat'); if (!$term) $term=wp_insert_term($cat_name,'product_cat'); if (!is_wp_error($term)) $cat_ids[]=(int)(is_array($term)?$term['term_id']:$term); } if ($cat_ids) $p->set_category_ids(array_values(array_unique($cat_ids))); }
                if (!empty($item['attributes']) && is_array($item['attributes'])) {
                    $wcattrs=array(); $pos=0;
                    foreach ($item['attributes'] as $k=>$v) {
                        $k=sanitize_text_field($k); $v=sanitize_text_field($v); $p->update_meta_data('uap50_'.sanitize_key($k),$v); if ($k==='sku') continue;
                        if (class_exists('WC_Product_Attribute')) { $a=new WC_Product_Attribute(); $a->set_id(0); $a->set_name($k); $a->set_options(array($v)); $a->set_position($pos++); $a->set_visible(true); $a->set_variation(false); $wcattrs[]=$a; }
                    }
                    if ($wcattrs) $p->set_attributes($wcattrs);
                    if (!empty($item['attributes']['sku']) && !wc_get_product_id_by_sku($item['attributes']['sku'])) $p->set_sku(sanitize_text_field($item['attributes']['sku']));
                }
                $id=$p->save();
            } else $id=wp_insert_post(array('post_type'=>$type,'post_status'=>$status,'post_title'=>$title,'post_content'=>$desc,'meta_input'=>array('_uap50_source_url'=>$source)),true);
            if (is_wp_error($id) || !$id) continue;
            $gallery=array(); foreach ((array)(isset($item['images'])?$item['images']:array()) as $idx=>$img) {
                if ($idx>=15) break; if (!wp_http_validate_url($img)) continue;
                require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/image.php';
                $att=media_sideload_image(esc_url_raw($img),$id,null,'id'); if (!is_wp_error($att) && $att) $gallery[]=(int)$att;
            }
            if ($gallery) { set_post_thumbnail($id,array_shift($gallery)); if ($type==='product' && $gallery) update_post_meta($id,'_product_image_gallery',implode(',',$gallery)); }
            $created[]=array('id'=>(int)$id,'title'=>$title,'images'=>count($gallery)+($gallery?1:0));
        }
        wp_send_json_success(array('created'=>$created,'count'=>count($created),'duplicates'=>$dupes));
    }

    private function freemius_bootstrap() {
        if (!defined('UAP50_FREEMIUS_ID') || !defined('UAP50_FREEMIUS_PUBLIC_KEY')) return;
        if (!function_exists('fs_dynamic_init')) {
            $sdk=plugin_dir_path(dirname(__FILE__)).'freemius/start.php';
            if (file_exists($sdk)) require_once $sdk;
        }
        if (function_exists('fs_dynamic_init')) {
            fs_dynamic_init(array('id'=>UAP50_FREEMIUS_ID,'slug'=>'ua-parser','type'=>'plugin','public_key'=>UAP50_FREEMIUS_PUBLIC_KEY,'is_premium'=>false,'has_paid_plans'=>true,'has_premium_version'=>true));
        }
    }
}
new UAP50_Advanced();
