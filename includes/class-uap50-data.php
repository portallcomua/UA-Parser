<?php
if (!defined('ABSPATH')) exit;

/**
 * Universal source adapter. Source HTML is parsed independently of the CMS
 * that generated it. Destination-specific code is kept in publish_woocommerce().
 */
final class UAP50_Data {
    private static function text($value) {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8')));
    }

    private static function absolute($value, $base) {
        $value = trim((string) $value);
        if ($value === '' || preg_match('#^(data|javascript|mailto):#i', $value)) return '';
        if (strpos($value, '//') === 0) return (wp_parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $value;
        if (preg_match('#^https?://#i', $value)) return esc_url_raw($value);
        $p = wp_parse_url($base);
        if (!$p || empty($p['host'])) return '';
        $origin = (isset($p['scheme']) ? $p['scheme'] : 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . (int) $p['port'] : '');
        if (strpos($value, '/') === 0) return $origin . $value;
        return $origin . trailingslashit(dirname(isset($p['path']) ? $p['path'] : '/')) . $value;
    }

    private static function add_value(&$attrs, $name, $value) {
        $name = sanitize_text_field((string) $name);
        $value = self::text($value);
        if ($name === '' || $value === '') return;
        if (!isset($attrs[$name])) $attrs[$name] = array();
        foreach (preg_split('/\s*[|;,]\s*/u', $value) as $part) {
            $part = sanitize_text_field($part);
            if ($part !== '' && !in_array($part, $attrs[$name], true)) $attrs[$name][] = $part;
        }
    }

    private static function css_xpath($selector) {
        $selector = trim((string)$selector);
        if ($selector === '' || strpos($selector, ',') !== false || strpos($selector, ':') !== false) return '';
        $parts = preg_split('/\s+/', $selector);
        $path = '';
        foreach ($parts as $part) {
            if (!preg_match('/^([a-zA-Z][\w-]*)?(?:#([\w-]+))?((?:\.[\w-]+)*)$/', $part, $m)) return '';
            $step = '//' . (!empty($m[1]) ? $m[1] : '*');
            if (!empty($m[2])) $step .= '[@id="' . esc_attr($m[2]) . '"]';
            if (!empty($m[3])) {
                preg_match_all('/\.([\w-]+)/', $m[3], $classes);
                foreach ($classes[1] as $class) $step .= '[contains(concat(" ",normalize-space(@class)," ")," ' . esc_attr($class) . ' ")]';
            }
            $path .= $step;
        }
        return $path;
    }

    private static function json_nodes($value, &$out) {
        if (!is_array($value)) return;
        if (isset($value['@graph']) && is_array($value['@graph'])) foreach ($value['@graph'] as $v) self::json_nodes($v, $out);
        if (isset($value['@type'])) $out[] = $value;
        foreach ($value as $k => $v) if (is_array($v) && $k !== '@graph') self::json_nodes($v, $out);
    }

    private static function image_from_node($node, $base) {
        if ($node instanceof DOMAttr) $raw = $node->value;
        elseif ($node instanceof DOMElement) {
            $raw = $node->getAttribute('data-zoom-image') ?: $node->getAttribute('data-large_image') ?: $node->getAttribute('data-src') ?: $node->getAttribute('data-lazy-src') ?: $node->getAttribute('data-original') ?: $node->getAttribute('src');
            if (!$raw && $node->tagName === 'a') $raw = $node->getAttribute('href');
            if (!$raw && $node->tagName === 'source') $raw = $node->getAttribute('srcset');
        } else $raw = '';
        if (strpos($raw, ',') !== false) $raw = trim(explode(',', $raw)[0]);
        if (strpos($raw, ' ') !== false) $raw = trim(explode(' ', $raw)[0]);
        return self::absolute($raw, $base);
    }

    public static function extract($html, $url, $selectors = array()) {
        $old = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if (!$ok) return new WP_Error('uap_html', 'Не вдалося розібрати HTML.');
        $xp = new DOMXPath($dom);
        $json = array();
        foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
            $decoded = json_decode(trim($script->textContent), true);
            if (is_array($decoded)) self::json_nodes($decoded, $json);
        }
        $product = array(); $product_group = array(); $article = array();
        foreach ($json as $node) {
            $types = (array) (isset($node['@type']) ? $node['@type'] : array());
            if (!$product && in_array('Product', $types, true)) $product = $node;
            if (!$product_group && in_array('ProductGroup', $types, true)) $product_group = $node;
            if (!$article && array_intersect($types, array('Article','NewsArticle','BlogPosting'))) $article = $node;
        }
        $structured = $product ?: ($product_group ?: $article);
        $get = function($key, $queries) use ($xp, $selectors) {
            if (!empty($selectors[$key])) {
                $selector_xpath = self::css_xpath($selectors[$key]);
                if ($selector_xpath) {
                    $selected = @$xp->query($selector_xpath)->item(0);
                    if ($selected) return self::text($selected instanceof DOMAttr ? $selected->value : ($selected instanceof DOMElement && $selected->hasAttribute('content') ? $selected->getAttribute('content') : $selected->textContent));
                }
            }
            foreach ($queries as $query) {
                $n = @$xp->query($query)->item(0);
                if ($n) return self::text($n instanceof DOMAttr ? $n->value : ($n instanceof DOMElement && $n->hasAttribute('content') ? $n->getAttribute('content') : $n->textContent));
            }
            return '';
        };
        $title = $get('title', array('//meta[@property="og:title"]/@content','//*[@itemprop="name"]','//h1','//meta[@name="twitter:title"]/@content','//title'));
        if (!$title) $title = self::text(isset($structured['name']) ? $structured['name'] : (isset($structured['headline']) ? $structured['headline'] : ''));
        $description = $get('description', array('//meta[@property="og:description"]/@content','//meta[translate(@name,"DESCRIPTION","description")="description"]/@content','//*[@itemprop="description"]','//article','//main'));
        if (!$description && !empty($structured['description'])) $description = self::text($structured['description']);
        $price = $get('price', array('//*[@itemprop="price"]/@content','//*[@itemprop="price"]','//meta[@property="product:price:amount"]/@content','//*[contains(translate(@class,"PRICE","price"),"price")]'));
        $offers = isset($structured['offers']) ? $structured['offers'] : array();
        if (!$price && $offers) {
            $offer = isset($offers[0]) ? $offers[0] : $offers;
            if (is_array($offer) && isset($offer['price'])) $price = self::text($offer['price'] . (!empty($offer['priceCurrency']) ? ' ' . $offer['priceCurrency'] : ''));
        }
        $images = array();
        $add_image = function($raw) use (&$images, $url) {
            $image = self::absolute($raw, $url);
            if ($image && !in_array($image, $images, true) && count($images) < 50) $images[] = $image;
        };
        if (!empty($structured['image'])) foreach ((array) $structured['image'] as $im) {
            if (is_array($im)) $im = isset($im['url']) ? $im['url'] : (isset($im['contentUrl']) ? $im['contentUrl'] : '');
            $add_image($im);
        }
        foreach ($xp->query('//meta[@property="og:image" or @name="twitter:image"]/@content | //img[@src or @data-src or @data-lazy-src or @data-original or @data-large_image] | //picture/source[@srcset] | //a[contains(translate(@class,"GALLERY","gallery"),"gallery") or contains(translate(@rel,"IMAGE","image"),"image")][@href]') as $node) {
            $add_image(self::image_from_node($node, $url));
        }
        foreach ($xp->query('//*[@itemprop="image"]') as $node) $add_image(self::image_from_node($node, $url));
        if (!empty($selectors['image'])) {
            $sel = $selectors['image'];
            if (preg_match('/^[a-zA-Z0-9_#\.\[\]="\'\-\s>:+~*]+$/', $sel)) {
                $parts = preg_split('/\s+/', trim($sel));
                $last = end($parts);
                if (preg_match('/^[a-zA-Z][\w-]*(?:[.#][\w-]+)*$/', $last)) {
                    $tag = preg_replace('/([.#])([\w-]+)/', '[$1="$2"]', $last);
                    $tag = preg_replace('/\[\.([^=]+)="([^"]+)"\]/', '[contains(concat(" ",normalize-space(@class)," ")," $2 ")]', $tag);
                    $tag = preg_replace('/\[#([^=]+)="([^"]+)"\]/', '[@id="$2"]', $tag);
                    foreach ($xp->query('//' . $tag) as $node) $add_image(self::image_from_node($node, $url));
                }
            }
        }
        $attrs = array();
        foreach (array('sku'=>'sku','brand'=>'brand','color'=>'color','size'=>'size','material'=>'material','gtin13'=>'gtin13','mpn'=>'mpn','model'=>'model') as $prop=>$label) {
            foreach ($xp->query('//*[@itemprop="' . $prop . '"]') as $node) self::add_value($attrs, $label, $node->getAttribute('content') ?: $node->textContent);
            if (isset($structured[$prop])) {
                $v = $structured[$prop];
                if (is_array($v) && isset($v['name'])) $v = $v['name'];
                if (is_scalar($v)) self::add_value($attrs, $label, $v);
            }
        }
        if (!empty($structured['additionalProperty']) && is_array($structured['additionalProperty'])) {
            foreach ($structured['additionalProperty'] as $prop) if (is_array($prop)) self::add_value($attrs, isset($prop['name']) ? $prop['name'] : 'Характеристика', isset($prop['value']) ? $prop['value'] : '');
        }
        // Read all values from native selectors, swatches and labeled option groups.
        foreach ($xp->query('//select[@name or @id]') as $select) {
            $label = self::text($select->getAttribute('aria-label') ?: $select->getAttribute('name') ?: $select->getAttribute('id'));
            $label = preg_replace('/^(attribute_|pa_)/i', '', $label);
            if ($label === '') continue;
            foreach ($xp->query('.//option[@value and not(@disabled)]', $select) as $option) {
                $v = self::text($option->getAttribute('data-value') ?: $option->getAttribute('value') ?: $option->textContent);
                if ($v !== '' && !preg_match('/^(choose|select|оберіть|виберіть|select an option)/iu', $v)) self::add_value($attrs, $label, $v);
            }
        }
        foreach ($xp->query('//*[@data-attribute or @data-attribute_name or @data-option or @data-value or @aria-label][self::button or self::a or self::li or self::span or self::div]') as $node) {
            $label = $node->getAttribute('data-attribute') ?: $node->getAttribute('data-attribute_name');
            if (!$label) {
                $context = $node->parentNode;
                $label = $context instanceof DOMElement ? ($context->getAttribute('data-attribute') ?: $context->getAttribute('data-attribute_name') ?: $context->getAttribute('aria-label')) : '';
            }
            if (!$label) continue;
            $value = $node->getAttribute('data-value') ?: $node->getAttribute('data-option') ?: $node->getAttribute('aria-label') ?: $node->getAttribute('title') ?: $node->textContent;
            self::add_value($attrs, preg_replace('/^(attribute_|pa_)/i', '', $label), $value);
        }
        $variants = array();
        $variant_nodes = array();
        if (!empty($product_group['hasVariant'])) $variant_nodes = (array) $product_group['hasVariant'];
        if (!empty($product_group['variesBy'])) foreach ((array) $product_group['variesBy'] as $by) self::add_value($attrs, 'Варіант', $by);
        foreach ($variant_nodes as $variant) {
            if (!is_array($variant)) continue;
            $va = array();
            foreach (array('color'=>'color','size'=>'size','material'=>'material') as $k=>$label) if (!empty($variant[$k])) { self::add_value($attrs, $label, is_array($variant[$k]) && isset($variant[$k]['name']) ? $variant[$k]['name'] : $variant[$k]); $va[$label] = is_array($variant[$k]) && isset($variant[$k]['name']) ? self::text($variant[$k]['name']) : self::text($variant[$k]); }
            $vo = isset($variant['offers']) ? $variant['offers'] : array();
            if (isset($vo[0])) $vo = $vo[0];
            $variants[] = array('attributes'=>$va,'sku'=>isset($variant['sku']) ? self::text($variant['sku']) : '', 'price'=>is_array($vo) && isset($vo['price']) ? self::text($vo['price']) : '', 'image'=>isset($variant['image']) ? (is_array($variant['image']) ? reset($variant['image']) : $variant['image']) : '');
        }
        $categories = array();
        foreach ($xp->query('//*[@itemprop="category"] | //meta[@property="article:section"]/@content') as $n) {
            $v = self::text($n instanceof DOMAttr ? $n->value : $n->textContent);
            if ($v && !in_array($v, $categories, true)) $categories[] = sanitize_text_field($v);
            if (count($categories) >= 20) break;
        }
        $tags = array();
        foreach ($xp->query('//*[@rel="tag"] | //meta[@property="article:tag"]/@content') as $n) {
            $v = self::text($n instanceof DOMAttr ? $n->value : $n->textContent);
            if ($v && !in_array($v, $tags, true)) $tags[] = sanitize_text_field($v);
            if (count($tags) >= 40) break;
        }
        $canonical = $xp->query('//link[@rel="canonical"]/@href')->item(0);
        $source = $canonical ? self::absolute($canonical->nodeValue, $url) : $url;
        $links = array();
        foreach ($xp->query('//a[@href]/@href') as $linkNode) {
            $link = self::absolute($linkNode->nodeValue, $url);
            if ($link && wp_parse_url($link, PHP_URL_HOST) === wp_parse_url($url, PHP_URL_HOST) && !in_array($link, $links, true)) $links[] = esc_url_raw($link);
            if (count($links) >= 80) break;
        }
        return array(
            'id'=>wp_generate_uuid4(), 'source_url'=>esc_url_raw($source), 'title'=>sanitize_text_field($title),
            'description'=>wp_kses_post($description), 'price'=>sanitize_text_field($price),
            'images'=>array_values(array_unique($images)), 'attributes'=>$attrs, 'variations'=>$variants,
            'categories'=>$categories, 'tags'=>$tags, 'links'=>$links,
            'content_type'=>!empty($structured['@type']) ? sanitize_key(is_array($structured['@type']) ? reset($structured['@type']) : $structured['@type']) : 'page'
        );
    }

    private static function sideload($url, $post_id) {
        if (!wp_http_validate_url($url)) return 0;
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $id = media_sideload_image(esc_url_raw($url), $post_id, null, 'id');
        return is_wp_error($id) ? 0 : (int) $id;
    }

    private static function price($raw) {
        $v = preg_replace('/[^0-9,.]/u', '', (string) $raw);
        if (substr_count($v, ',') === 1 && strpos($v, '.') === false) $v = str_replace(',', '.', $v);
        else $v = str_replace(',', '', $v);
        return is_numeric($v) ? wc_format_decimal($v) : '';
    }

    public static function publish_woocommerce($item, $status) {
        if (!class_exists('WooCommerce')) return new WP_Error('uap_wc', 'WooCommerce не активований.');
        $source = esc_url_raw(isset($item['source_url']) ? $item['source_url'] : '');
        $existing = get_posts(array('post_type'=>'product','post_status'=>'any','meta_key'=>'_uap50_source_url','meta_value'=>$source,'fields'=>'ids','numberposts'=>1));
        if ($existing) return array('duplicate'=>true,'id'=>(int)$existing[0]);
        $attributes = isset($item['attributes']) && is_array($item['attributes']) ? $item['attributes'] : array();
        $variations = isset($item['variations']) && is_array($item['variations']) ? $item['variations'] : array();
        $is_variable = count($variations) > 0;
        $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
        $product->set_name(sanitize_text_field(isset($item['title']) ? $item['title'] : 'Без назви'));
        $product->set_status($status);
        $product->set_description(wp_kses_post(isset($item['description']) ? $item['description'] : ''));
        $price = self::price(isset($item['price']) ? $item['price'] : '');
        if (!$is_variable && $price !== '') $product->set_regular_price($price);
        $product->update_meta_data('_uap50_source_url', $source);
        $wc_attributes = array(); $position = 0;
        foreach ($attributes as $name=>$values) {
            if (strtolower($name) === 'sku') continue;
            $values = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$values))));
            if (!$values) continue;
            $a = new WC_Product_Attribute();
            $a->set_id(0); $a->set_name(sanitize_text_field($name)); $a->set_options($values);
            $a->set_position($position++); $a->set_visible(true); $a->set_variation($is_variable);
            $wc_attributes[] = $a;
            $product->update_meta_data('uap50_' . sanitize_key($name), implode(' | ', $values));
        }
        if ($wc_attributes) $product->set_attributes($wc_attributes);
        if (!empty($attributes['sku'][0]) && !wc_get_product_id_by_sku($attributes['sku'][0])) $product->set_sku(sanitize_text_field($attributes['sku'][0]));
        if (!empty($item['categories'])) {
            $ids = array();
            foreach ((array)$item['categories'] as $name) {
                $term = term_exists($name, 'product_cat');
                if (!$term) $term = wp_insert_term($name, 'product_cat');
                if (!is_wp_error($term)) $ids[] = (int)(is_array($term) ? $term['term_id'] : $term);
            }
            if ($ids) $product->set_category_ids(array_values(array_unique($ids)));
        }
        $id = $product->save();
        if (!$id) return new WP_Error('uap_product', 'Не вдалося створити товар.');
        if (!empty($item['tags'])) wp_set_object_terms($id, array_map('sanitize_text_field',(array)$item['tags']), 'product_tag', false);
        $gallery_ids = array();
        foreach (array_slice(array_values(array_unique((array)(isset($item['images'])?$item['images']:array()))), 0, 30) as $image_url) {
            $attachment = self::sideload($image_url, $id);
            if ($attachment) $gallery_ids[] = $attachment;
        }
        if ($gallery_ids) {
            set_post_thumbnail($id, array_shift($gallery_ids));
            if ($gallery_ids) update_post_meta($id, '_product_image_gallery', implode(',', $gallery_ids));
        }
        if ($is_variable) {
            foreach ($variations as $variant) {
                if (!is_array($variant) || empty($variant['attributes'])) continue;
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($id);
                $variation->set_status($status === 'publish' ? 'publish' : 'private');
                $vattrs = array();
                foreach ((array)$variant['attributes'] as $key=>$value) $vattrs[sanitize_title($key)] = sanitize_text_field($value);
                $variation->set_attributes($vattrs);
                $vp = self::price(isset($variant['price']) ? $variant['price'] : '');
                if ($vp !== '') $variation->set_regular_price($vp);
                if (!empty($variant['sku']) && !wc_get_product_id_by_sku($variant['sku'])) $variation->set_sku(sanitize_text_field($variant['sku']));
                if (!empty($variant['image'])) {
                    $vid = self::sideload(self::absolute($variant['image'], $source), $id);
                    if ($vid) $variation->set_image_id($vid);
                }
                $variation->save();
            }
            WC_Product_Variable::sync($id);
        }
        return array('id'=>(int)$id,'title'=>$product->get_name(),'images'=>count($gallery_ids)+(!empty($item['images'][0])?1:0),'variations'=>count($variations),'duplicate'=>false);
    }
}
