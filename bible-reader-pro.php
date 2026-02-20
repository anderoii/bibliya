<?php
/**
 * Plugin Name: Bible Reader Pro
 * Description: Онлайн-читач Біблії з паралельними перекладами, толкуваннями, пошуком, закладками, налаштуваннями — аналог azbyka.ru/biblia + ekzeget.ru
 * Version: 2.0.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('BRP_VER','2.0.0');
define('BRP_DIR', plugin_dir_path(__FILE__));
define('BRP_URL', plugin_dir_url(__FILE__));

class BibleReaderPro {
    private static $i;
    public static function go(){ if(!self::$i) self::$i=new self(); return self::$i; }

    private function __construct(){
        register_activation_hook(__FILE__,[$this,'activate']);
        add_action('init',[$this,'rewrites']);
        add_action('wp_enqueue_scripts',[$this,'assets']);
        add_action('admin_menu',[$this,'menus']);
        add_shortcode('bible_reader',[$this,'shortcode']);
        foreach(['load_chapter','search','save_bookmark','get_bookmarks','del_bookmark',
                 'report_error','get_commentary','import_text','import_albible'] as $a){
            add_action("wp_ajax_brp_{$a}",[$this,"ajax_{$a}"]);
            add_action("wp_ajax_nopriv_brp_{$a}",[$this,"ajax_{$a}"]);
        }
    }

    /* ═══ ACTIVATION ═══ */
    public function activate(){
        global $wpdb;
        $c=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}brp_books(
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            name_ru VARCHAR(255) NOT NULL,
            name_uk VARCHAR(255) DEFAULT '',
            name_en VARCHAR(255) DEFAULT '',
            testament ENUM('OT','NT') NOT NULL,
            category VARCHAR(50) NOT NULL,
            chapters_count SMALLINT UNSIGNED NOT NULL,
            sort_order SMALLINT DEFAULT 0,
            is_canonical TINYINT(1) DEFAULT 1
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}brp_translations(
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            language VARCHAR(100) NOT NULL,
            lang_code VARCHAR(10) NOT NULL,
            dir ENUM('ltr','rtl') DEFAULT 'ltr',
            sort_order SMALLINT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}brp_verses(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            book_code VARCHAR(20) NOT NULL,
            chapter SMALLINT UNSIGNED NOT NULL,
            verse SMALLINT UNSIGNED NOT NULL,
            trans_code VARCHAR(30) NOT NULL,
            verse_text LONGTEXT NOT NULL,
            is_christ_words TINYINT(1) DEFAULT 0,
            INDEX idx_lookup(book_code,chapter,trans_code),
            FULLTEXT idx_ft(verse_text)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}brp_commentaries(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            book_code VARCHAR(20) NOT NULL,
            chapter SMALLINT UNSIGNED NOT NULL,
            verse_start SMALLINT DEFAULT NULL,
            verse_end SMALLINT DEFAULT NULL,
            author VARCHAR(255) NOT NULL,
            source VARCHAR(500) DEFAULT '',
            body LONGTEXT NOT NULL,
            INDEX idx_ref(book_code,chapter)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}brp_bookmarks(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            book_code VARCHAR(20) NOT NULL,
            chapter SMALLINT NOT NULL,
            verse_start SMALLINT DEFAULT NULL,
            verse_end SMALLINT DEFAULT NULL,
            color VARCHAR(20) DEFAULT '#FFEB3B',
            note TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user(user_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}brp_errors(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            book_code VARCHAR(20), chapter SMALLINT, verse SMALLINT DEFAULT NULL,
            trans_code VARCHAR(30), selected_text TEXT, description TEXT,
            user_id BIGINT UNSIGNED DEFAULT NULL, status VARCHAR(20) DEFAULT 'new',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c;");

        $this->seed_books();
        $this->seed_translations();
        if(!get_page_by_path('bible'))
            wp_insert_post(['post_title'=>'Біблія онлайн','post_name'=>'bible',
                'post_content'=>'[bible_reader]','post_status'=>'publish','post_type'=>'page']);
        flush_rewrite_rules();
    }

    private function seed_books(){
        global $wpdb; $t=$wpdb->prefix.'brp_books';
        if($wpdb->get_var("SELECT COUNT(*) FROM $t")>0) return;
        $d=[
            ['Mt','От Матфея','Від Матвія','Matthew','NT','gospels',28,1,1],
            ['Mk','От Марка','Від Марка','Mark','NT','gospels',16,2,1],
            ['Lk','От Луки','Від Луки','Luke','NT','gospels',24,3,1],
            ['Jn','От Иоанна','Від Іоанна','John','NT','gospels',21,4,1],
            ['Act','Деяния','Діяння','Acts','NT','acts',28,5,1],
            ['Jac','Иакова','Якова','James','NT','catholic',5,6,1],
            ['1Pet','1 Петра','1 Петра','1 Peter','NT','catholic',5,7,1],
            ['2Pet','2 Петра','2 Петра','2 Peter','NT','catholic',3,8,1],
            ['1Jn','1 Иоанна','1 Іоанна','1 John','NT','catholic',5,9,1],
            ['2Jn','2 Иоанна','2 Іоанна','2 John','NT','catholic',1,10,1],
            ['3Jn','3 Иоанна','3 Іоанна','3 John','NT','catholic',1,11,1],
            ['Juda','Иуды','Юди','Jude','NT','catholic',1,12,1],
            ['Rom','К Римлянам','До Римлян','Romans','NT','paul',16,13,1],
            ['1Cor','1 Коринфянам','1 Коринтян','1 Corinthians','NT','paul',16,14,1],
            ['2Cor','2 Коринфянам','2 Коринтян','2 Corinthians','NT','paul',13,15,1],
            ['Gal','К Галатам','До Галатів','Galatians','NT','paul',6,16,1],
            ['Eph','К Ефесянам','До Ефесян','Ephesians','NT','paul',5,17,1],
            ['Phil','К Филиппийцам','До Филипʼян','Philippians','NT','paul',4,18,1],
            ['Col','К Колоссянам','До Колосян','Colossians','NT','paul',4,19,1],
            ['1Thes','1 Фессалоникийцам','1 Солунян','1 Thessalonians','NT','paul',5,20,1],
            ['2Thes','2 Фессалоникийцам','2 Солунян','2 Thessalonians','NT','paul',3,21,1],
            ['1Tim','1 Тимофею','1 Тимофія','1 Timothy','NT','paul',6,22,1],
            ['2Tim','2 Тимофею','2 Тимофія','2 Timothy','NT','paul',4,23,1],
            ['Tit','К Титу','До Тита','Titus','NT','paul',3,24,1],
            ['Phlm','К Филимону','До Филимона','Philemon','NT','paul',1,25,1],
            ['Hebr','К Евреям','До Євреїв','Hebrews','NT','paul',13,26,1],
            ['Apok','Откровение','Одкровення','Revelation','NT','prophecy_nt',22,27,1],
            ['Gen','Бытие','Буття','Genesis','OT','pentateuch',50,28,1],
            ['Ex','Исход','Вихід','Exodus','OT','pentateuch',40,29,1],
            ['Lev','Левит','Левіт','Leviticus','OT','pentateuch',27,30,1],
            ['Num','Числа','Числа','Numbers','OT','pentateuch',36,31,1],
            ['Deut','Второзаконие','Повт. Закону','Deuteronomy','OT','pentateuch',34,32,1],
            ['Nav','Иисуса Навина','Ісуса Навіна','Joshua','OT','historical',24,33,1],
            ['Judg','Судей','Суддів','Judges','OT','historical',21,34,1],
            ['Rth','Руфь','Рут','Ruth','OT','historical',4,35,1],
            ['1Sam','1 Царств','1 Самуїлова','1 Samuel','OT','historical',31,36,1],
            ['2Sam','2 Царств','2 Самуїлова','2 Samuel','OT','historical',24,37,1],
            ['1King','3 Царств','1 Царів','1 Kings','OT','historical',22,38,1],
            ['2King','4 Царств','2 Царів','2 Kings','OT','historical',25,39,1],
            ['1Chron','1 Паралипоменон','1 Хронік','1 Chronicles','OT','historical',29,40,1],
            ['2Chron','2 Паралипоменон','2 Хронік','2 Chronicles','OT','historical',36,41,1],
            ['Ezr','Ездры','Ездри','Ezra','OT','historical',10,42,1],
            ['Nehem','Неемии','Неемії','Nehemiah','OT','historical',13,43,1],
            ['Est','Есфирь','Естер','Esther','OT','historical',10,44,1],
            ['Job','Иова','Йова','Job','OT','wisdom',42,45,1],
            ['Ps','Псалтирь','Псалтир','Psalms','OT','wisdom',150,46,1],
            ['Prov','Притчей','Приповістей','Proverbs','OT','wisdom',31,47,1],
            ['Eccl','Екклесиаст','Еклезіяст','Ecclesiastes','OT','wisdom',12,48,1],
            ['Song','Песнь Песней','Пісня Пісень','Song of Solomon','OT','wisdom',8,49,1],
            ['Is','Исаии','Ісаї','Isaiah','OT','prophets',66,50,1],
            ['Jer','Иеремии','Єремії','Jeremiah','OT','prophets',52,51,1],
            ['Lam','Плач Иеремии','Плач Єремії','Lamentations','OT','prophets',5,52,1],
            ['Ezek','Иезекииля','Єзекіїля','Ezekiel','OT','prophets',48,53,1],
            ['Dan','Даниила','Даніїла','Daniel','OT','prophets',14,54,1],
            ['Hos','Осии','Осії','Hosea','OT','prophets_minor',14,55,1],
            ['Joel','Иоиля','Йоїла','Joel','OT','prophets_minor',3,56,1],
            ['Am','Амоса','Амоса','Amos','OT','prophets_minor',9,57,1],
            ['Avd','Авдия','Авдія','Obadiah','OT','prophets_minor',1,58,1],
            ['Jona','Ионы','Йони','Jonah','OT','prophets_minor',4,59,1],
            ['Mic','Михея','Міхея','Micah','OT','prophets_minor',7,60,1],
            ['Naum','Наума','Наума','Nahum','OT','prophets_minor',3,61,1],
            ['Habak','Аввакума','Авакума','Habakkuk','OT','prophets_minor',3,62,1],
            ['Sofon','Софонии','Софонії','Zephaniah','OT','prophets_minor',3,63,1],
            ['Hag','Аггея','Огія','Haggai','OT','prophets_minor',2,64,1],
            ['Zah','Захарии','Захарії','Zechariah','OT','prophets_minor',14,65,1],
            ['Mal','Малахии','Малахії','Malachi','OT','prophets_minor',4,66,1],
            ['Tov','Товита','Товіта','Tobit','OT','historical_nc',14,67,0],
            ['Judf','Иудифь','Юдит','Judith','OT','historical_nc',16,68,0],
            ['1Mac','1 Маккавейская','1 Маккавейська','1 Maccabees','OT','historical_nc',16,69,0],
            ['2Mac','2 Маккавейская','2 Маккавейська','2 Maccabees','OT','historical_nc',15,70,0],
            ['Solom','Премудр. Соломона','Премудр. Соломона','Wisdom','OT','wisdom_nc',19,71,0],
            ['Sir','Сираха','Сираха','Sirach','OT','wisdom_nc',51,72,0],
            ['Bar','Варуха','Варуха','Baruch','OT','prophets_nc',5,73,0],
            ['pJer','Послание Иеремии','Послання Єремії','Ep. Jeremiah','OT','prophets_nc',1,74,0],
        ];
        foreach($d as $r) $wpdb->insert($t,array_combine(
            ['code','name_ru','name_uk','name_en','testament','category','chapters_count','sort_order','is_canonical'],$r));
    }

    private function seed_translations(){
        global $wpdb; $t=$wpdb->prefix.'brp_translations';
        if($wpdb->get_var("SELECT COUNT(*) FROM $t")>0) return;
        $d=[
            ['r','Синодальный','Русский','ru','ltr',1],
            ['utfcs','Церковнославянский (цс)','Церковнослав.','cu','ltr',2],
            ['cs','Церковнослав. (рус. гражд.)','Церковнослав.','cu','ltr',3],
            ['k','Українська (Огієнко)','Українська','uk','ltr',4],
            ['ua','Українська (Хоменко)','Українська','uk','ltr',5],
            ['a','English (NKJV)','English','en','ltr',6],
            ['en-kjv','English (KJV)','English','en','ltr',7],
            ['g','Greek (NT Byz)','Ελληνικά','el','ltr',8],
            ['el-r','Greek (LXX)','Ελληνικά','el','ltr',9],
            ['i','Hebrew','עברית','he','rtl',10],
            ['l','Latin (Vulgata)','Latina','la','ltr',11],
            ['de_ml','German (MLU)','Deutsch','de','ltr',12],
            ['h','French (LSG)','Français','fr','ltr',13],
            ['w','Spanish (RVR)','Español','es','ltr',14],
            ['pl','Polish','Polski','pl','ltr',15],
            ['bg','Bulgarian','Български','bg','ltr',16],
            ['it','Italian','Italiano','it','ltr',17],
            ['pt','Portuguese','Português','pt','ltr',18],
            ['chn','Chinese','中文','zh','ltr',19],
            ['jp','Japanese','日本語','ja','ltr',20],
            ['ar','Arabic (JAB)','العربية','ar','rtl',21],
        ];
        foreach($d as $r) $wpdb->insert($t,['code'=>$r[0],'name'=>$r[1],'language'=>$r[2],
            'lang_code'=>$r[3],'dir'=>$r[4],'sort_order'=>$r[5],'is_active'=>1]);
    }

    public function rewrites(){
        add_rewrite_rule('^bible/(.+)$','index.php?pagename=bible&brp_ref=$matches[1]','top');
        add_rewrite_tag('%brp_ref%','(.+)');
        // Register custom query vars for pretty URL params:
        // ?book=Juda&ch=1:1-10&lang=uk
        add_filter('query_vars', function($vars){
            $vars[] = 'book';
            $vars[] = 'ch';
            $vars[] = 'lang';
            return $vars;
        });
    }

    public function assets(){
        global $post;
        if(!is_a($post,'WP_Post')||!has_shortcode($post->post_content,'bible_reader')) return;
        // Use filemtime for cache-busting (prevents "no changes" issues after updates)
        $css_path = BRP_DIR.'assets/css/style.css';
        $js_path  = BRP_DIR.'assets/js/app.js';
        $css_ver  = file_exists($css_path) ? filemtime($css_path) : BRP_VER;
        $js_ver   = file_exists($js_path)  ? filemtime($js_path)  : BRP_VER;

        wp_enqueue_style('brp-css',BRP_URL.'assets/css/style.css',[],$css_ver);
        wp_enqueue_style('brp-popup-css', BRP_URL.'assets/css/popup.css', ['brp-css'], BRP_VER);
        wp_enqueue_script('brp-js',BRP_URL.'assets/js/app.js',['jquery'],$js_ver,true);
        wp_enqueue_script('brp-popup-js',BRP_URL.'assets/js/popup.js',['jquery','brp-js'],BRP_VER,true);
        wp_localize_script('brp-js','BRP',['ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('brp'),'url'=>BRP_URL,'logged'=>is_user_logged_in()]);

        // URL sync: support /bibliia?book=Jn&ch=10:9-16&lang=uk while legacy app uses #Book.Chapter&trans
        $inline_js = <<<'JS'
(function(){
  try{
    var url = new URL(window.location.href);
    var p = url.searchParams;
    var qBook = (p.get('book')||'').trim();
    var qCh   = (p.get('ch')||'').trim();
    var qLang = (p.get('lang')||'').trim();

    function langToTrans(lang){
      lang = (lang||'').toLowerCase();
      if(lang==='uk') return 'k';
      if(lang==='ru') return 'r';
      if(lang==='en') return 'a';
      if(lang==='cu') return 'utfcs';
      return '';
    }

    function parseCh(chRaw){
      var out = {chapter:'', vs:'', ve:''};
      if(!chRaw) return out;
      var m = chRaw.match(/^(\d+):(\d+)-(\d+)$/);
      if(m){ out.chapter=m[1]; out.vs=m[2]; out.ve=m[3]; return out; }
      m = chRaw.match(/^(\d+):(\d+)$/);
      if(m){ out.chapter=m[1]; out.vs=m[2]; out.ve=m[2]; return out; }
      m = chRaw.match(/^(\d+)$/);
      if(m){ out.chapter=m[1]; return out; }
      return out;
    }

    function parseHash(){
      // Expected: #Mt.1&r or #Jn.10&k
      var h = (window.location.hash||'').replace(/^#/,'');
      if(!h) return null;
      var parts = h.split('&');
      var left = parts[0]||'';
      var trans = parts[1]||'';
      var m = left.match(/^([A-Za-z0-9]+)\.(\d+)$/);
      if(!m) return null;
      return {book:m[1], chapter:m[2], trans:trans};
    }

    // If query params exist: convert to hash (so existing app loads correct place)
    if(qBook && qCh){
      var pc = parseCh(qCh);
      var trans = langToTrans(qLang) || (p.get('trans')||'').trim();
      if(pc.chapter){
        // Store verse range for any script that wants it
        if(pc.vs && pc.ve){
          try{ sessionStorage.setItem('brp_range', JSON.stringify({vs:pc.vs, ve:pc.ve})); }catch(_e){}
        } else {
          try{ sessionStorage.removeItem('brp_range'); }catch(_e2){}
        }
        var newHash = '#'+qBook+'.'+pc.chapter+(trans?('&'+trans):'');
        if(window.location.hash !== newHash){
          window.location.replace(url.pathname + url.search + newHash);
          return;
        }
      }
    }

    // Else if only hash exists: reflect it into query params (shareable blagovist-like URL)
    var ph = parseHash();
    if(ph && !qBook){
      p.set('book', ph.book);
      p.set('ch', ph.chapter);
      // best-effort lang from trans
      if(ph.trans==='k' || ph.trans==='ua') p.set('lang','uk');
      else if(ph.trans==='r') p.set('lang','ru');
      else if(ph.trans==='a' || ph.trans==='en-kjv') p.set('lang','en');
      else if(ph.trans==='utfcs' || ph.trans==='cs') p.set('lang','cu');
      history.replaceState({},'', url.pathname + '?' + p.toString() + window.location.hash);
    }
  }catch(e){/* ignore */}
})();
JS;
        wp_add_inline_script('brp-js', $inline_js, 'before');

        // No inline overrides — all styles handled in style.css
    }

    public function menus(){
        add_menu_page('Bible Reader','Bible Reader','manage_options','brp-dashboard',
            [$this,'admin_dashboard'],'dashicons-book-alt',30);
        add_submenu_page('brp-dashboard','Імпорт','Імпорт текстів','manage_options',
            'brp-import',[$this,'admin_import']);
        add_submenu_page('brp-dashboard','Налаштування','Налаштування','manage_options',
            'brp-settings',[$this,'admin_settings']);
        add_action('admin_init',[$this,'register_settings']);
    }

    public function register_settings(){
        register_setting('brp_settings_group','brp_resource_links',[
            'sanitize_callback'=>[$this,'sanitize_resource_links'],
            'default'=>self::default_resource_links()
        ]);
    }

    public static function default_resource_links(){
        return [
            ['label'=>'Про Біблію',             'url'=>''],
            ['label'=>'Про переклади',           'url'=>''],
            ['label'=>'Біблія за рік',           'url'=>''],
            ['label'=>'Аудіо Біблія',            'url'=>''],
            ['label'=>'Схеми та посібники',      'url'=>''],
        ];
    }

    public function sanitize_resource_links($input){
        if(!is_array($input)) return self::default_resource_links();
        $clean=[];
        foreach($input as $item){
            $clean[]=['label'=>sanitize_text_field($item['label']??''),'url'=>esc_url_raw($item['url']??'')];
        }
        return $clean;
    }

    public function admin_settings(){
        if(!current_user_can('manage_options')) return;
        $links=get_option('brp_resource_links',self::default_resource_links());
        ?>
        <div class="wrap">
        <h1>📚 Bible Reader Pro — Налаштування</h1>
        <form method="post" action="options.php">
        <?php settings_fields('brp_settings_group'); ?>
        <h2>Посилання «Ресурси» на панелі тлумачень</h2>
        <p style="color:#666">Введіть URL для кожного ресурсу. Порожні посилання не відображатимуться.</p>
        <table class="widefat" style="max-width:700px;margin-bottom:20px">
        <thead><tr><th>Назва</th><th>URL</th></tr></thead>
        <tbody>
        <?php foreach($links as $i=>$link): ?>
        <tr>
         <td><input type="text" name="brp_resource_links[<?=$i?>][label]" value="<?=esc_attr($link['label'])?>" class="regular-text" /></td>
         <td><input type="url" name="brp_resource_links[<?=$i?>][url]" value="<?=esc_attr($link['url'])?>" class="regular-text" placeholder="https://..." /></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <h2>Вигляд читалки</h2>
        <table class="form-table">
        <tr><th>Тема за замовчуванням</th><td>
         <?php $theme=get_option('brp_default_theme','default'); ?>
         <select name="brp_default_theme" onchange="this.form.submit()">
          <option value="default" <?=selected($theme,'default',false)?>>🌿 Стандартна (прозора)</option>
          <option value="dark"    <?=selected($theme,'dark',false)   ?>>🌙 Темна</option>
          <option value="sepia"   <?=selected($theme,'sepia',false)  ?>>📜 Сепія</option>
         </select>
         <p class="description">«Стандартна» — читалка успадковує фон вашої WordPress-теми</p>
        </td></tr>
        </table>
        <?php submit_button('Зберегти налаштування'); ?>
        </form>
        </div>
        <?php
    }

    public function admin_dashboard(){ include BRP_DIR.'templates/admin/dashboard.php'; }
    public function admin_import(){ include BRP_DIR.'templates/admin/import.php'; }

    /* ═══ AJAX: Load chapter ═══ */
    public function ajax_load_chapter(){
        check_ajax_referer('brp','nonce'); global $wpdb;
        $bk=sanitize_text_field($_POST['book']??'Mt');
        $ch=absint($_POST['chapter']??1);
        $tr=sanitize_text_field($_POST['trans']??'r');
        $pa=sanitize_text_field($_POST['parallel']??'');
        $vt=$wpdb->prefix.'brp_verses'; $bt=$wpdb->prefix.'brp_books';

        $verses=$wpdb->get_results($wpdb->prepare(
            "SELECT verse,verse_text,is_christ_words FROM $vt WHERE book_code=%s AND chapter=%d AND trans_code=%s ORDER BY verse",$bk,$ch,$tr));
        $pv=[];
        if($pa&&$pa!==$tr) $pv=$wpdb->get_results($wpdb->prepare(
            "SELECT verse,verse_text,is_christ_words FROM $vt WHERE book_code=%s AND chapter=%d AND trans_code=%s ORDER BY verse",$bk,$ch,$pa));
        $bi=$wpdb->get_row($wpdb->prepare("SELECT * FROM $bt WHERE code=%s",$bk));

        wp_send_json_success(['verses'=>$verses,'parallel'=>$pv,'book'=>$bi,
            'chapter'=>$ch,'total'=>$bi?(int)$bi->chapters_count:1]);
    }

    /* ═══ AJAX: Search ═══ */
    public function ajax_search(){
        check_ajax_referer('brp','nonce'); global $wpdb;
        $q=sanitize_text_field($_POST['q']??'');
        $tr=sanitize_text_field($_POST['trans']??'r');
        $bf=sanitize_text_field($_POST['book_filter']??'');
        $pg=max(1,absint($_POST['page']??1));
        $pp=20; $off=($pg-1)*$pp;
        if(mb_strlen($q)<2) wp_send_json_error(['msg'=>'Too short']);
        $vt=$wpdb->prefix.'brp_verses'; $bt=$wpdb->prefix.'brp_books';
        $w="v.trans_code=%s"; $p=[$tr];
        if($bf){$w.=" AND v.book_code=%s";$p[]=$bf;}
        $w.=" AND v.verse_text LIKE %s"; $p[]='%'.$wpdb->esc_like($q).'%';
        $cp=$p;
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $vt v JOIN $bt b ON v.book_code=b.code WHERE $w",...$cp));
        $p[]=$pp;$p[]=$off;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT v.book_code,v.chapter,v.verse,v.verse_text,b.name_uk,b.name_ru FROM $vt v JOIN $bt b ON v.book_code=b.code WHERE $w ORDER BY b.sort_order,v.chapter,v.verse LIMIT %d OFFSET %d",...$p));
        wp_send_json_success(['results'=>$rows,'total'=>$total,'page'=>$pg,'pages'=>(int)ceil($total/$pp)]);
    }

    /* ═══ AJAX: Commentary ═══ */
    public function ajax_get_commentary(){
        check_ajax_referer('brp','nonce'); global $wpdb;
        $bk=sanitize_text_field($_POST['book']??'');
        $ch=absint($_POST['chapter']??1);
        $vs=absint($_POST['verse']??0);
        $ct=$wpdb->prefix.'brp_commentaries';
        if($vs>0)
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $ct WHERE book_code=%s AND chapter=%d AND (verse_start IS NULL OR (verse_start<=%d AND (verse_end>=%d OR verse_end IS NULL)))",$bk,$ch,$vs,$vs));
        else
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $ct WHERE book_code=%s AND chapter=%d",$bk,$ch));
        wp_send_json_success(['commentaries'=>$rows]);
    }

    /* ═══ AJAX: Bookmarks ═══ */
    public function ajax_save_bookmark(){
        check_ajax_referer('brp','nonce');
        if(!is_user_logged_in()) wp_send_json_error();
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'brp_bookmarks',[
            'user_id'=>get_current_user_id(),'book_code'=>sanitize_text_field($_POST['book_code']),
            'chapter'=>absint($_POST['chapter']),'verse_start'=>absint($_POST['vs']??0)?:null,
            'verse_end'=>absint($_POST['ve']??0)?:null,'color'=>sanitize_hex_color($_POST['color']??'#FFEB3B'),
            'note'=>sanitize_textarea_field($_POST['note']??'')]);
        wp_send_json_success(['id'=>$wpdb->insert_id]);
    }

    public function ajax_get_bookmarks(){
        check_ajax_referer('brp','nonce');
        if(!is_user_logged_in()) wp_send_json_error();
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT m.*,b.name_uk FROM {$wpdb->prefix}brp_bookmarks m LEFT JOIN {$wpdb->prefix}brp_books b ON m.book_code=b.code WHERE m.user_id=%d ORDER BY m.created_at DESC",get_current_user_id()));
        wp_send_json_success(['bookmarks'=>$rows]);
    }

    public function ajax_del_bookmark(){
        check_ajax_referer('brp','nonce');
        if(!is_user_logged_in()) wp_send_json_error();
        global $wpdb;
        $wpdb->delete($wpdb->prefix.'brp_bookmarks',['id'=>absint($_POST['id']),'user_id'=>get_current_user_id()]);
        wp_send_json_success();
    }

    public function ajax_report_error(){
        check_ajax_referer('brp','nonce'); global $wpdb;
        $wpdb->insert($wpdb->prefix.'brp_errors',[
            'book_code'=>sanitize_text_field($_POST['book_code']??''),
            'chapter'=>absint($_POST['chapter']??0),'verse'=>absint($_POST['verse']??0)?:null,
            'trans_code'=>sanitize_text_field($_POST['trans']??''),
            'selected_text'=>sanitize_textarea_field($_POST['sel_text']??''),
            'description'=>sanitize_textarea_field($_POST['desc']??''),
            'user_id'=>get_current_user_id()?:null]);
        wp_send_json_success();
    }

    /* ═══ AJAX: Import (admin) ═══ */
    public function ajax_import_text(){
        check_ajax_referer('brp','nonce');
        if(!current_user_can('manage_options')) wp_send_json_error();
        global $wpdb;
        $bk=sanitize_text_field($_POST['book']??'');
        $ch=absint($_POST['chapter']??0);
        $tr=sanitize_text_field($_POST['trans']??'');
        $txt=wp_unslash($_POST['text']??'');
        if(!$bk||!$ch||!$tr||!$txt) wp_send_json_error(['msg'=>'Missing fields']);
        $t=$wpdb->prefix.'brp_verses';
        $wpdb->delete($t,['book_code'=>$bk,'chapter'=>$ch,'trans_code'=>$tr]);
        $n=0;
        foreach(preg_split('/\r?\n/',trim($txt)) as $ln){
            $ln=trim($ln); if(!$ln) continue;
            if(preg_match('/^(\d+)\s+(.+)$/',$ln,$m)){$vn=(int)$m[1];$vt=trim($m[2]);}
            else{$n++;$vn=$n;$vt=$ln;}
            $wpdb->insert($t,['book_code'=>$bk,'chapter'=>$ch,'verse'=>$vn,'trans_code'=>$tr,'verse_text'=>$vt,'is_christ_words'=>0]);
            $n=max($n,$vn);
        }
        wp_send_json_success(['count'=>$n]);
    }

    /* ═══ AJAX: Import from alBible Lite JSON ═══ */
    public function ajax_import_albible(){
        check_ajax_referer('brp','nonce');
        if(!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Access denied']);
        global $wpdb;

        $bk   = sanitize_text_field($_POST['book']   ?? '');
        $tr   = sanitize_text_field($_POST['trans']  ?? 'k'); // default: Ukrainian Ogiyenko
        $path = sanitize_text_field($_POST['path']   ?? '');  // absolute path to alBible JSON file

        if(!$bk || !$path) wp_send_json_error(['msg'=>'Missing book or path']);

        // Security: only allow reading from wp-content area
        $realpath = realpath($path);
        $wp_content = realpath(WP_CONTENT_DIR);
        if(!$realpath || strpos($realpath, $wp_content) !== 0){
            wp_send_json_error(['msg'=>'Path not allowed. File must be inside wp-content/']);
        }

        $json = file_get_contents($realpath);
        if($json === false) wp_send_json_error(['msg'=>'Cannot read file']);

        $data = json_decode($json);
        if(!$data || !is_array($data)) wp_send_json_error(['msg'=>'Invalid JSON']);

        $t = $wpdb->prefix.'brp_verses';
        // Remove existing verses for this book+translation
        $wpdb->delete($t, ['book_code'=>$bk,'trans_code'=>$tr]);

        $count = 0;
        foreach($data as $sentence){
            // alBible Lite format: {part: chapter, stix: verse_num, text: "..."}
            $chapter = (int)($sentence->part  ?? 0);
            $verse   = (int)($sentence->stix  ?? 0);
            $text    = trim($sentence->text   ?? '');

            // Handle zachalo (liturgical marker in red)
            if(!empty($sentence->zachalo)){
                $text = '<span class="brp-zachalo">[' . esc_html($sentence->zachalo) . ']</span> ' . $text;
            }

            if(!$chapter || !$verse || !$text) continue;

            $wpdb->insert($t,[
                'book_code'      => $bk,
                'chapter'        => $chapter,
                'verse'          => $verse,
                'trans_code'     => $tr,
                'verse_text'     => $text,
                'is_christ_words'=> 0,
            ]);
            $count++;
        }
        wp_send_json_success(['count'=>$count,'book'=>$bk,'trans'=>$tr]);
    }

    /* ═══ Helper: parse ?ch=1:1-10 URL param ═══
     * Returns array ['chapter'=>int, 'verse_start'=>int, 'verse_end'=>int]
     * Supports formats: 1:1-10  /  3:5  /  2
     */
    public static function parse_ch_param(string $ch): array {
        $ch = urldecode($ch);
        // Format: chapter:verseStart-verseEnd
        if(preg_match('/^(\d+):(\d+)-(\d+)$/', $ch, $m)){
            return ['chapter'=>(int)$m[1],'verse_start'=>(int)$m[2],'verse_end'=>(int)$m[3]];
        }
        // Format: chapter:verse
        if(preg_match('/^(\d+):(\d+)$/', $ch, $m)){
            return ['chapter'=>(int)$m[1],'verse_start'=>(int)$m[2],'verse_end'=>(int)$m[2]];
        }
        // Format: chapter only
        if(preg_match('/^(\d+)$/', $ch, $m)){
            return ['chapter'=>(int)$m[1],'verse_start'=>0,'verse_end'=>0];
        }
        return ['chapter'=>1,'verse_start'=>0,'verse_end'=>0];
    }

    /* ═══ SHORTCODE ═══ */
    public function shortcode(){
        global $wpdb;
        $books=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}brp_books ORDER BY sort_order");
        $trans=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}brp_translations WHERE is_active=1 ORDER BY sort_order");
        $resource_links=get_option('brp_resource_links',self::default_resource_links());
        $default_theme=get_option('brp_default_theme','default');

        // ── Read URL params: ?book=Juda&ch=1:1-10&lang=uk ──
        // Works both with get_query_var (WP) and raw $_GET fallback
        $url_book = sanitize_text_field(get_query_var('book', '') ?: ($_GET['book'] ?? ''));
        $url_ch   = sanitize_text_field(get_query_var('ch',   '') ?: ($_GET['ch']   ?? ''));
        $url_lang = sanitize_text_field(get_query_var('lang', '') ?: ($_GET['lang'] ?? ''));

        // Map lang code → translation code used in DB
        $lang_to_trans = ['uk'=>'k','ru'=>'r','en'=>'a'];
        $url_trans = $lang_to_trans[$url_lang] ?? '';

        // Parse ch param into parts
        $url_ch_data = $url_ch ? self::parse_ch_param($url_ch) : ['chapter'=>0,'verse_start'=>0,'verse_end'=>0];

        ob_start();
        include BRP_DIR.'templates/frontend/reader.php';
        return ob_get_clean();
    }
}
BibleReaderPro::go();
