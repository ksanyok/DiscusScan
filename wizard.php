<?php
require_once __DIR__ . '/db.php';
require_login();

header('X-Frame-Options: SAMEORIGIN');

$apiKey = (string)get_setting('openai_api_key', '');
$model  = (string)get_setting('openai_model', 'gpt-5-mini');

// --- Helpers ---
function http_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function fetch_site_info(string $url): array {
    $url = normalize_url($url);
    if ($url === '') return ['ok'=>false,'error'=>'empty url'];
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>5,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_CONNECTTIMEOUT=>7,
        CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; DiscusScanBot/1.0; +https://example.com/bot)',
        CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']
    ]);
    $html = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    $err = curl_error($ch);
    curl_close($ch);
    if ($html === false || $status >= 400) {
        app_log('error','wizard_fetch','Site fetch failed',[ 'url'=>$url, 'status'=>$status, 'err'=>$err ]);
        return ['ok'=>false,'error'=>'Не удалось получить сайт ('.$status.')'];
    }
    // Extract basic meta
    $title=''; $desc=''; $lang='';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) { $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')); }
    if (preg_match('~<meta[^>]+name=["\']description["\'][^>]*content=["\'](.*?)["\'][^>]*>~i', $html, $m)) { $desc = trim(html_entity_decode($m[1], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')); }
    if ($desc===' ' || $desc==='') {
        if (preg_match('~<meta[^>]+property=["\']og:description["\'][^>]*content=["\'](.*?)["\'][^>]*>~i', $html, $m2)) { $desc = trim(html_entity_decode($m2[1], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')); }
    }
    if (preg_match('~<html[^>]*lang=["\']([a-zA-Z-]{2,10})["\']~i', $html, $m)) { $lang = strtolower($m[1]); $lang = substr($lang,0,2); }
    $h1=''; if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) { $h1 = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')); }
    // Strip tags to get text sample
    $text = preg_replace('~<script[\s\S]*?</script>|<style[\s\S]*?</style>~i', ' ', $html);
    $text = strip_tags($text);
    $text = preg_replace('~\s+~u',' ', $text);
    $text = trim($text);
    $sample = mb_substr($text, 0, 2000);

    // Region hint from TLD
    $host = parse_url($finalUrl, PHP_URL_HOST) ?: '';
    $tld = strtolower(preg_replace('~^.*\.([a-z]{2,})$~i', '$1', $host));
    $regionHint = '';
    $tldMap = [ 'de'=>'DE','fr'=>'FR','es'=>'ES','it'=>'IT','pl'=>'PL','ua'=>'UA','ru'=>'RU','us'=>'US','uk'=>'GB','br'=>'BR','in'=>'IN','ca'=>'CA','au'=>'AU','nl'=>'NL','se'=>'SE','no'=>'NO','fi'=>'FI','dk'=>'DK' ];
    if (isset($tldMap[$tld])) $regionHint = $tldMap[$tld];

    return [
        'ok'=>true,
        'url'=>$finalUrl,
        'title'=>$title,
        'description'=>$desc,
        'h1'=>$h1,
        'sample'=>$sample,
        'lang_hint'=>$lang,
        'region_hint'=>$regionHint,
        'host'=>$host
    ];
}

function build_user_input(?string $siteUrl, ?string $what, ?string $where, array $siteInfo = []): string {
    $parts = [];
    if ($siteUrl) { $parts[] = "Сайт: " . $siteUrl; }
    if ($siteInfo && ($siteInfo['ok'] ?? false)) {
        $meta = [];
        if (!empty($siteInfo['title'])) $meta[] = 'Заголовок: '.$siteInfo['title'];
        if (!empty($siteInfo['description'])) $meta[] = 'Описание: '.$siteInfo['description'];
        if (!empty($siteInfo['h1'])) $meta[] = 'H1: '.$siteInfo['h1'];
        if (!empty($siteInfo['lang_hint'])) $meta[] = 'Язык сайта: '.$siteInfo['lang_hint'];
        if (!empty($siteInfo['region_hint'])) $meta[] = 'Регион сайта (по домену): '.$siteInfo['region_hint'];
        if ($meta) $parts[] = implode("; ", $meta);
        if (!empty($siteInfo['sample'])) {
            $parts[] = "Фрагмент контента сайта:\n" . $siteInfo['sample'];
        }
    }
    if ($what) { $parts[] = "Что ищем/рекламируем: " . $what; }
    if ($where) { $parts[] = "Где/регион/аудитория: " . $where; }
    $parts[] = "Задача: Сформировать точный промпт для поиска упоминаний/релевантного контента, без перечисления источников (форумы, соцсети и т.д.).";
    return implode("\n\n", array_filter($parts, fn($x)=>trim($x)!==''));
}

function openai_regions_langs(string $apiKey, string $model, string $context): array {
    $url = 'https://api.openai.com/v1/chat/completions';
    $headers = [ 'Content-Type: application/json', 'Authorization: Bearer '.$apiKey, 'Expect:' ];
    $system = "Ты аналитик географии и языков. Верни строго JSON с массивами 'languages' (ISO 639-1, нижний регистр) и 'regions' (ISO 3166-1 alpha-2, верхний регистр) исходя из описания.\n".
              "Если указано 'вся европа' или 'europe' — выбери 5 приоритетных кодов для Европы.\n".
              "Если указано 'весь мир' или 'worldwide' — выбери 10 приоритетных кодов по миру.\n".
              "Если указан конкретный сайт с региональным фокусом — учитывай это.\n".
              "Никаких других полей и текста. Только JSON с двумя массивами.";
    $payload = [
        'model'=>$model,
        'messages'=>[
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$context]
        ],
        'response_format'=>[
            'type'=>'json_schema',
            'json_schema'=>[
                'name'=>'geo_codes',
                'schema'=>[
                    'type'=>'object',
                    'properties'=>[
                        'languages'=>['type'=>'array','items'=>['type'=>'string']],
                        'regions'=>['type'=>'array','items'=>['type'=>'string']]
                    ],
                    'required'=>['languages','regions'],
                    'additionalProperties'=>false
                ],
                'strict'=>true
            ]
        ],
        'max_completion_tokens'=>400
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>50
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        app_log('error','wizard_geo','OpenAI geo fail',[ 'code'=>$code, 'err'=>$err, 'preview'=>mb_substr((string)$resp,0,300) ]);
        return ['languages'=>[], 'regions'=>[]];
    }
    $data = json_decode($resp,true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    if (preg_match('~```json\s*(.+?)```~is', $content, $m)) $content = $m[1];
    $parsed = json_decode(trim($content), true);
    $langs = []; $regs = [];
    if (is_array($parsed)) {
        foreach (($parsed['languages'] ?? []) as $l) { $l = strtolower(trim($l)); if (preg_match('~^[a-z]{2}$~',$l)) $langs[]=$l; }
        foreach (($parsed['regions'] ?? []) as $r) { $r = strtoupper(trim($r)); if (preg_match('~^[A-Z]{2}$~',$r)) $regs[]=$r; }
    }
    return [ 'languages'=>array_values(array_unique($langs)), 'regions'=>array_values(array_unique($regs)) ];
}

function merge_codes(array $a, array $b, int $maxLangs = 10, int $maxRegs = 10): array {
    $langs = array_values(array_unique(array_merge($a['languages'] ?? [], $b['languages'] ?? [])));
    $regs  = array_values(array_unique(array_merge($a['regions'] ?? [],  $b['regions'] ?? [])));
    if (count($langs) > $maxLangs) $langs = array_slice($langs, 0, $maxLangs);
    if (count($regs)  > $maxRegs)  $regs  = array_slice($regs,  0, $maxRegs);
    return ['languages'=>$langs,'regions'=>$regs];
}

// --- Routing ---
if (($_GET['modal'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Render modal HTML (injected into DOM)
    ?>
    <div id="smartWizardModal" class="modal" style="display: block;">
      <div class="modal-backdrop"></div>
      <div class="modal-content">
        <div class="modal-header">
          <h3>🤖 Умный мастер промптов</h3>
          <button type="button" class="modal-close" aria-label="Закрыть">&times;</button>
        </div>
        <div class="modal-body">
          <p class="muted">Укажите сайт (опционально), что ищем/рекламируем и где (регион/аудитория) в свободной форме. Мастер проанализирует сайт и сформирует промпт, языки и регионы.</p>
          <form id="wizardGenForm">
            <div class="grid-1" style="gap:12px;">
              <label>Сайт (опционально)
                <input type="text" name="site" placeholder="https://пример.ком">
              </label>
              <label>Что ищем или рекламируем
                <textarea name="what" rows="3" placeholder="Например: сервис доставки еды, бренд Acme, CRM для малого бизнеса..." required></textarea>
              </label>
              <label>Где (страны/регионы/аудитория)
                <input type="text" name="where" placeholder="Например: вся Европа, США и Канада, весь мир, СНГ, Центральная Европа...">
              </label>
            </div>
            <div class="modal-actions">
              <button type="button" class="btn btn-ghost" id="wizardCancelBtn">Отмена</button>
              <button type="submit" class="btn primary">✨ Сгенерировать</button>
            </div>
          </form>
          <div id="wizardLoading" style="display:none; text-align:center; padding:18px;">
            <div class="spinner"></div>
            <p>ИИ анализирует данные...</p>
          </div>
          <div id="wizardError" class="alert danger" style="display:none;"></div>
        </div>
      </div>
    </div>
    <style>
    .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1000; }
    .modal-backdrop { position: absolute; width: 100%; height: 100%; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
    .modal-content { position: relative; max-width: 760px; margin: 4% auto; background: var(--card); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); max-height: 92vh; display: flex; flex-direction: column; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px 16px; border-bottom: 1px solid var(--border); }
    .modal-header h3 { margin: 0; }
    .modal-close { background: none; border: none; font-size: 24px; color: var(--muted); cursor: pointer; line-height: 1; }
    .modal-close:hover { color: var(--text); }
    .modal-body { padding: 20px 24px; overflow-y: auto; }
    .spinner { width: 40px; height: 40px; border: 4px solid var(--border); border-top: 4px solid var(--pri); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
    <script>
    (function(){
      const modal = document.getElementById('smartWizardModal');
      function close(){ modal.remove(); document.body.style.overflow=''; }
      modal.querySelector('.modal-backdrop').addEventListener('click', close);
      modal.querySelector('.modal-close').addEventListener('click', close);
      document.getElementById('wizardCancelBtn').addEventListener('click', close);
      document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); }, {once:true});

      const form = document.getElementById('wizardGenForm');
      const err = document.getElementById('wizardError');
      form.addEventListener('submit', async (e)=>{
        e.preventDefault(); err.style.display='none';
        const fd = new FormData(form);
        const payload = new URLSearchParams();
        payload.set('action','generate');
        payload.set('site', (fd.get('site')||'').toString());
        payload.set('what', (fd.get('what')||'').toString());
        payload.set('where',(fd.get('where')||'').toString());
        form.style.display='none'; document.getElementById('wizardLoading').style.display='block';
        try{
          const r = await fetch('wizard.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: payload});
          const data = await r.json();
          if(!data.ok){ throw new Error(data.error||'Ошибка'); }
          // Вставляем в поля настроек
          const promptEl = document.querySelector('textarea[name=search_prompt]');
          const langEl = document.getElementById('search_languages_input');
          const regEl  = document.getElementById('search_regions_input');
          if (promptEl) promptEl.value = data.prompt || '';
          if (langEl && Array.isArray(data.languages)) langEl.value = data.languages.join(', ');
          if (regEl && Array.isArray(data.regions)) regEl.value = data.regions.join(', ');
          // Тост и закрытие
          if (typeof showToast==='function') showToast('✨ Промпт и коды вставлены. Нажмите Сохранить.','success');
          close();
        }catch(ex){
          err.textContent = 'Сбой: '+ex.message;
          err.style.display='block';
          form.style.display='block';
          document.getElementById('wizardLoading').style.display='none';
        }
      });
    })();
    </script>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'generate') {
        if ($apiKey === '') http_json(['ok'=>false,'error'=>'Укажите OpenAI API Key в настройках'], 400);
        $site  = trim($_POST['site'] ?? '');
        $what  = trim($_POST['what'] ?? '');
        $where = trim($_POST['where'] ?? '');
        if ($what === '' && $site === '') http_json(['ok'=>false,'error'=>'Укажите сайт или что искать'], 400);

        $siteInfo = ['ok'=>false];
        if ($site !== '') {
            $siteInfo = fetch_site_info($site);
        }
        $combined = build_user_input($siteInfo['url'] ?? $site, $what, $where, $siteInfo);

        // Основной промпт
        $gen = processSmartWizard($combined, $apiKey, $model, 'generate');
        if (!$gen['ok']) {
            http_json(['ok'=>false,'error'=>$gen['error'] ?? 'Ошибка генерации']);
        }
        $prompt = (string)($gen['prompt'] ?? '');
        $langs1 = $gen['languages'] ?? [];
        $regs1  = $gen['regions'] ?? [];

        // Отдельный вызов для кодов языков/регионов
        $geoCtx = $combined . "\n\nТребование: верни только коды языков и стран (массивы).";
        $geo = openai_regions_langs($apiKey, $model, $geoCtx);

        // Мержим и ограничиваем
        $merged = merge_codes(['languages'=>$langs1,'regions'=>$regs1], $geo, 10, 10);
        $languages = $merged['languages'];
        $regions   = $merged['regions'];

        // Сохраняем рекомендации (detected_*) чтобы кнопки-подсказки появились
        try {
            set_setting('detected_languages', $languages);
            set_setting('detected_regions', $regions);
        } catch (Throwable $e) {
            app_log('warning','wizard','Failed to save detected_*',['err'=>$e->getMessage()]);
        }

        http_json([
            'ok'=>true,
            'prompt'=>$prompt,
            'languages'=>$languages,
            'regions'=>$regions,
            'site_info'=>[ 'ok'=>$siteInfo['ok']??false, 'url'=>$siteInfo['url']??$site, 'host'=>$siteInfo['host']??null, 'lang_hint'=>$siteInfo['lang_hint']??null, 'region_hint'=>$siteInfo['region_hint']??null ]
        ]);
    }
    http_json(['ok'=>false,'error'=>'Unknown action'], 400);
}

// Fallback: if opened directly, redirect back to settings
header('Location: settings.php');
exit;
