<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.sppbsection
 *
 * Shortcodes:
 *   {sppb_section page=2 label="sliderrrrrr"}
 *   {sppb_section page=2 label="kakero2" debug=1}
 *   {sppb_section page=1 index=0}
 *   {sppb_section page=1 id="sppb-section-xxxx"}
 *   {sppb_section alias="home" label="Hero"}
 * Optional: itemid=381  echo_full=1  debug=1
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Http\HttpFactory;

class PlgContentSppbsection extends CMSPlugin
{
    protected $app;
    protected $db;

    /** Regex: {sppb_section key=val key="val" ...} */
    protected $pattern = '/\{sppb_section\s+([^}]+)\}/i';

    /** مموییز استاتیک نگاشت label→{index,rowId} به‌ازای هر pageId */
    protected static $rowMetaStaticCache = []; // [pageId => ['hash'=>..., 'map'=>[labelLower=>['index'=>..,'rowId'=>..]]]]

    /* =======================================================================
     * Hook
     * =======================================================================
     */
    public function onContentPrepare($context, &$article, &$params, $limitstart = 0)
    {
        $this->ensureSppbAssets();
        if (empty($article->text)) {
            return;
        }

        if (!preg_match_all($this->pattern, $article->text, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $attrs = $this->parseAttributes($m[1] ?? '');
            $replacement = $this->renderSection($attrs);
            $article->text = str_replace($m[0], $replacement, $article->text);
        }
    }

    /* =======================================================================
     * Core
     * =======================================================================
     */
    protected function renderSection(array $attrs): string
    {
        $filter = InputFilter::getInstance();

        $pageId = isset($attrs['page']) ? (int)$attrs['page'] : null;
        $alias = isset($attrs['alias']) ? $filter->clean($attrs['alias'], 'cmd') : null;
        $secId = isset($attrs['id']) ? $filter->clean($attrs['id'], 'cmd') : null;
        $index = isset($attrs['index']) ? (int)$attrs['index'] : null;
        $label = isset($attrs['label']) ? trim((string)$attrs['label']) : null;  // Admin Label
        $itemid = isset($attrs['itemid']) ? (int)$attrs['itemid'] : 0;
        $debug = isset($attrs['debug']) ? (int)$attrs['debug'] : 0;
        $echoAll = isset($attrs['echo_full']) ? (int)$attrs['echo_full'] : 0;

        if (!$pageId && $alias) {
            $pageId = $this->getPageIdByAlias($alias);
        }
        if (!$pageId) {
            return $this->wrapError('Missing "page" or resolvable "alias".');
        }

        // اگر label داده شده: از JSON متادیتا (index,rowId) را پیدا کن
        $requestedLabel = null;
        $rowIdFromLabel = null;
        if ($label !== null && $label !== '') {
            $requestedLabel = $label;
            $meta = $this->getRowMetaByAdminLabel($pageId, $label); // ['index'=>..., 'rowId'=>...]
            if ($meta) {
                $index = $meta['index'];
                $rowIdFromLabel = $meta['rowId'] ?: null;
            } else {
                return $this->wrapError('Admin Label not found on page (label="' . htmlspecialchars($label) . '").');
            }
        }

        // کلید کش خروجی
        $verSeed = (string)($this->params->get('version_seed') ?: '1');
        $cacheKey = 'sppbsection:v6.1:' . $verSeed
            . ':p=' . $pageId
            . ':sid=' . ($secId ?: '')
            . ':idx=' . ($index !== null ? $index : '')
            . ':lab=' . md5((string)$label)
            . ':it=' . $itemid
            . ':ef=' . $echoAll;

        if ($this->params->get('enable_cache', 1) && !$debug) {
            $cached = $this->getCache($cacheKey);
            if (is_string($cached)) {
                return $cached;
            }
        }

        $flow = [];
        $html = $this->getPageHtmlOnce($pageId, $itemid, $flow);

        if (!$html) {
            return $this->wrapError('PageBuilder content not found (ID=' . (int)$pageId . '). Flow=' . implode('>', $flow));
        }

        if ($echoAll) {
            $out = $debug ? $this->debugWrap($html, $flow) : $html;
            if ($this->params->get('enable_cache', 1) && !$debug) {
                $this->setCache($cacheKey, $out, 900);
            }
            return $out;
        }

        // استخراج سکشن — اولویت: rowId (از label) → secId → index
        $preferredRowId = $rowIdFromLabel ?: null;
        [$sectionHtml, $count] = $this->extractSectionSmart($html, $secId, $preferredRowId, $index, true);

        if (!$sectionHtml) {
            $msg = 'Section not found. ';
            if ($secId) $msg .= 'id="' . htmlspecialchars($secId) . '" ';
            if ($requestedLabel) $msg .= 'label="' . htmlspecialchars($requestedLabel) . '" ';
            if ($preferredRowId) $msg .= 'rowId=' . htmlspecialchars($preferredRowId) . ' ';
            if ($index !== null) $msg .= 'index=' . (int)$index . ' ';
            $msg .= 'candidates=' . (int)$count . '.';
            if ($debug) $msg .= ' flow=' . implode('>', $flow) . ' rawLen=' . strlen($html);
            return $this->wrapError($msg);
        }

        if ($debug) {
            $picked = $secId ? 'id:' . $secId
                : ($preferredRowId ? ('label:' . $requestedLabel . ' via rowId:' . $preferredRowId)
                    : ($requestedLabel ? 'label:' . $requestedLabel : 'index:' . (int)$index));
            $sectionHtml = $this->debugWrap('picked=' . $picked, $flow) . $sectionHtml;
        }

        static $sppbRootPrinted = false;
        $rootId = $sppbRootPrinted ? '' : ' id="sp-page-builder"';
        $sppbRootPrinted = true;

        $sectionHtml =
            '<div' . $rootId . ' class="sp-page-builder page-' . (int)$pageId . ' sp-page-builder-page sppb-inline-wrap" data-inline="1">' .
            '<div class="page-content">' . $sectionHtml . '</div>' .
            '</div>';




        // ✅ رپر استاندارد SPPB برای نمایش درست در مقالات (مثل صفحه اصلی Page Builder)
// ✅ رپر استاندارد SPPB برای نمایش درست در مقالات
        if (!$echoAll && $sectionHtml) {
            // توجه: اگر یک صفحه بیش از یک شورت‌کد دارد، بهتر است فقط یکی از آنها این id را داشته باشد
            $sectionHtml =
                '<div id="sp-page-builder" class="sp-page-builder page-' . (int)$pageId . ' sp-page-builder-page sppb-inline-wrap" data-inline="1">' .
                '<div class="page-content">' . $sectionHtml . '</div>' .
                '</div>';
        }


        if ($this->params->get('enable_cache', 1) && !$debug) {
            $this->setCache($cacheKey, $sectionHtml, 900);
        }
        // Force re-init of SPPB addons after insertion
        $doc = \Joomla\CMS\Factory::getApplication()->getDocument();
        $reinit = <<<JS
jQuery(function($){
  if (window.SPPB && typeof window.SPPB.init === 'function') {
      window.SPPB.init();
  }
  if (window.sppagebuilder && typeof window.sppagebuilder.init === 'function') {
      window.sppagebuilder.init();
  }
  if ($.fn.magnificPopup) {
      $('.sppb-magnific-popup, .sppb-gallery').magnificPopup({type:'image', gallery:{enabled:true}});
  }
});
JS;
        $doc->addScriptDeclaration($reinit);
        $doc->addStyleDeclaration(<<<CSS
/* کمک به چیدمان گالری و ناوبری اسلایدر در حالت شورت‌کد */
.sp-page-builder .sppb-gallery { display:flex; flex-wrap:wrap; gap:.75rem; list-style:none; padding:0; margin:0; }
.sp-page-builder .sppb-gallery > li { list-style:none; margin:0; }
.sp-page-builder .sppb-slider .sp-control-nav { display:flex; justify-content:center; }
CSS);


        return $sectionHtml;
    }

    /* =======================================================================
     * Helpers: parsing / cache / db
     * =======================================================================
     */
    protected function parseAttributes(string $attrStr): array
    {
        // key="value" | key='value' | key=value
        $attrs = [];
        $re = '/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s}]+))/';
        if (preg_match_all($re, $attrStr, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $a) {
                $key = strtolower($a[1]);
                $val = $a[2] !== '' ? $a[2] : ($a[3] !== '' ? $a[3] : $a[4]);
                $attrs[$key] = $val;
            }
        }
        return $attrs;
    }

    protected function getPageIdByAlias(?string $alias): ?int
    {
        if (!$alias) return null;
        $q = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__sppagebuilder'))
            ->where($this->db->quoteName('alias') . ' = ' . $this->db->quote($alias))
            ->where($this->db->quoteName('published') . ' = 1');
        $this->db->setQuery($q);
        $id = (int)$this->db->loadResult();
        return $id ?: null;
    }

    /* =======================================================================
     * Admin Label → Row meta (index + rowId) with caching
     * =======================================================================
     */
    protected function getRowMetaByAdminLabel(int $pageId, string $label): ?array
    {
        $needle = mb_strtolower(trim($label));
        if ($needle === '') return null;

        // 1) استاتیک مموییز
        if (isset(self::$rowMetaStaticCache[$pageId])) {
            $map = self::$rowMetaStaticCache[$pageId]['map'] ?? [];
            if (isset($map[$needle])) return $map[$needle];
        }

        // 2) خواندن JSON صفحه + کش پایدار
        $q = $this->db->getQuery(true)
            ->select($this->db->quoteName('content'))
            ->from($this->db->quoteName('#__sppagebuilder'))
            ->where($this->db->quoteName('id') . ' = ' . (int)$pageId)
            ->where($this->db->quoteName('published') . ' = 1');
        $this->db->setQuery($q);
        $json = (string)$this->db->loadResult();
        if ($json === '') return null;

        $hash = md5($json);
        $cache = Factory::getCache('plg_content_sppbsection_rows', '');
        $cache->setCaching(true);
        $cacheKey = 'rowmap:' . $pageId . ':' . $hash;

        $map = $cache->get($cacheKey);
        if (!is_array($map)) {
            $data = json_decode($json, true);
            if (!is_array($data)) return null;

            $rows = [];
            $this->collectRowsDeepFast($data, $rows);

            $map = []; // label(lower) => ['index'=>i,'rowId'=>string|null]
            foreach ($rows as $i => $rowInfo) {
                $lbl = isset($rowInfo['label']) ? mb_strtolower(trim((string)$rowInfo['label'])) : '';
                if ($lbl !== '' && !isset($map[$lbl])) {
                    $map[$lbl] = ['index' => $i, 'rowId' => $rowInfo['rowId'] ?? null];
                }
            }
            $cache->store($map, $cacheKey);
        }

        // استاتیک به‌روز
        self::$rowMetaStaticCache[$pageId] = ['hash' => $hash, 'map' => $map];

        return $map[$needle] ?? null;
    }

    protected function collectRowsDeepFast($node, array &$rows): void
    {
        if (!is_array($node)) return;

        // تشخیص سریع Row
        $looksRow = false;
        foreach (['type', 'addon_name', 'name', 'view'] as $k) {
            if (!empty($node[$k]) && is_string($node[$k]) && stripos($node[$k], 'row') !== false) {
                $looksRow = true;
                break;
            }
        }

        // برچسب و شناسه
        $label = $this->pickFirstString($node, [
            ['admin_label'], ['adminLabel'], ['label'],
            ['settings', 'admin_label'], ['settings', 'adminLabel'], ['settings', 'label'],
            ['options', 'admin_label'], ['options', 'adminLabel'], ['options', 'label'],
            ['attrs', 'admin_label'], ['attrs', 'adminLabel'], ['attrs', 'label'],
            ['style', 'admin_label'], ['styles', 'admin_label'],
        ]);
        $rowId = $this->pickFirstScalar($node, [
            ['id'], ['settings', 'id'], ['options', 'id'], ['attrs', 'id'],
        ]);

        // ساختار دارای بچه
        $hasChildren = false;
        foreach (['children', 'elements', 'items', 'content', 'columns', 'addons', 'rows', 'blocks'] as $k) {
            if (!empty($node[$k]) && is_array($node[$k])) {
                $hasChildren = true;
                break;
            }
        }

        if ($looksRow || ($label !== null && $hasChildren)) {
            $rows[] = ['label' => $label, 'rowId' => $rowId, 'node' => $node];
        }

        foreach ($node as $v) if (is_array($v)) $this->collectRowsDeepFast($v, $rows);
    }

    protected function pickFirstString(array $node, array $paths): ?string
    {
        foreach ($paths as $p) {
            $v = $this->getIn($node, $p);
            if (is_string($v) && trim($v) !== '') return $v;
        }
        return null;
    }

    protected function pickFirstScalar(array $node, array $paths): ?string
    {
        foreach ($paths as $p) {
            $v = $this->getIn($node, $p);
            if (is_scalar($v) && (string)$v !== '') return (string)$v;
        }
        return null;
    }

    protected function getIn(array $a, array $path)
    {
        $cur = $a;
        foreach ($path as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
            $cur = $cur[$p];
        }
        return $cur;
    }

    /* =======================================================================
     * HTML acquisition (single pass)
     * =======================================================================
     */
    protected function getPageHtmlOnce(int $pageId, int $itemid, array &$flow): ?string
    {
        // ترتیب: اول MVC/HTML سپس HTTP (قابل تغییر است)
        $html = $this->renderViaComponentHtml($pageId, $itemid, $flow);
        if ($html) return $html;

        return $this->renderViaHttp($pageId, $itemid, $flow);
    }

    protected function renderViaComponentHtml(int $pageId, int $itemid, array &$flow): ?string
    {
        $entry = JPATH_SITE . '/components/com_sppagebuilder/sppagebuilder.php';
        if (!is_file($entry)) return null;

        $app = Factory::getApplication();
        $input = $app->input;

        $bak = [
            'option' => $input->get('option', null, 'cmd'),
            'view' => $input->get('view', null, 'cmd'),
            'id' => $input->get('id', null, 'int'),
            'format' => $input->get('format', null, 'cmd'),
            'layout' => $input->get('layout', null, 'cmd'),
            'tmpl' => $input->get('tmpl', null, 'cmd'),
            'Itemid' => $input->get('Itemid', null, 'int'),
        ];

        $input->set('option', 'com_sppagebuilder');
        $input->set('view', 'page');
        $input->set('id', $pageId);
        $input->set('format', 'html');
        $input->set('layout', 'default');
        $input->set('tmpl', 'component');
        if ($itemid > 0) $input->set('Itemid', $itemid);

        $html = null;
        try {
            require_once $entry;
            ob_start();
            if (defined('JVERSION') && version_compare(JVERSION, '4.0', '<')) {
                // Joomla 3.x
                $controller = \JControllerLegacy::getInstance('Sppagebuilder');
                $controller->execute('display');
                $controller->redirect();
            } else {
                // Joomla 4/5
                $component = $app->bootComponent('com_sppagebuilder');
                $factory = $component->getMVCFactory();
                $view = $factory->createView('Page', 'Html', 'Site', ['layout' => 'default']);
                $model = $factory->createModel('Page', 'Site', ['ignore_request' => false]);
                if (method_exists($view, 'setModel')) $view->setModel($model, true);
                if (method_exists($model, 'setState')) {
                    $model->setState('page.id', $pageId);
                    $model->setState('id', $pageId);
                }
                $view->display();
            }
            $buf = ob_get_clean();
            $html = (is_string($buf) && trim($buf) !== '') ? $buf : null;
            if ($html) $flow[] = 'mvc-html';
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                @ob_end_clean();
            }
            $flow[] = 'mvc-ex';
            $html = null;
        } finally {
            foreach ($bak as $k => $v) {
                $input->set($k, $v);
            }
        }
        if ($html) {
            $this->ensureSppbAssets($pageId, $html);
        }

        return $html;
    }

    protected function extractPageBuilderContainer(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        foreach ([
                     '//*[@id="sp-page-builder"]',
                     '//div[contains(@class,"sp-page-builder") and contains(@class,"page-")]',
                     '//div[contains(@class,"sp-page-builder")]',
                     '//main//*[contains(@class,"sp-page-builder")]',
                 ] as $q) {
            $nodes = $xp->query($q);
            if ($nodes && $nodes->length) {
                return $dom->saveHTML($nodes->item(0));
            }
        }
        return $html;
    }

    protected function renderViaHttp(int $pageId, int $itemid, array &$flow): ?string
    {
        $root = Uri::root();
        $build = function (array $q) use ($root) {
            return $root . 'index.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        };

        $base = ['option' => 'com_sppagebuilder', 'view' => 'page', 'id' => (string)$pageId];
        $candidates = [
            $build($base + ['tmpl' => 'component']),
            $build($base),
            rtrim($root, '/') . '/index.php/component/sppagebuilder/page/' . (int)$pageId,
        ];
        if ($itemid > 0) $candidates[] = $build($base + ['Itemid' => (string)$itemid]);

        $http = HttpFactory::getHttp();
        foreach ($candidates as $url) {
            try {
                $resp = $http->get($url, ['User-Agent' => 'SPPBSection/1.6', 'Accept' => 'text/html'], 20);
                $body = (string)($resp->body ?? '');
                if (trim($body) === '') continue;

                // ⬅️ مهم: اول کل head/page را اسکن و CSS/JSها را تزریق کن
                $this->injectAssetsFromHtml($body);

                // بعد فقط کانتینر SPPB را جدا کن
                $onlyContainer = $this->extractPageBuilderContainer($body);
                if (trim($onlyContainer) !== '') {
                    $flow[] = 'http:' . $url;
                    return $onlyContainer;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }


    /* =======================================================================
     * Section extraction (single DOM pass)
     * =======================================================================
     */
    protected function extractSectionSmart(string $html, ?string $sectionId, ?string $rowId, ?int $index, bool $withCount = false)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        // container اصلی SPPB (در صورت وجود)
        $container = null;
        foreach ([
                     '//*[@id="sp-page-builder"]',
                     '//div[contains(@class,"sp-page-builder") and contains(@class,"page-")]',
                     '//div[contains(@class,"sp-page-builder")]',
                     '//main//*[contains(@class,"sp-page-builder")]',
                 ] as $q) {
            $n = $xp->query($q);
            if ($n && $n->length) {
                $container = $n->item(0);
                break;
            }
        }
        $ctx = $container ?: $dom->documentElement;
        $cxp = new \DOMXPath($ctx->ownerDocument);

        // 1) جستجو با rowId (دقیق سپس contains)
        if ($rowId) {
            $attrs = ['data-id', 'data-row-id', 'data-sppb-section-id', 'data-sppb-unique-id', 'data-unique-id', 'data-uniqueid', 'data-section-id', 'data-block-id', 'id'];
            foreach ($attrs as $a) {
                $q1 = sprintf('.//*[@%s="%s"]', $a, htmlspecialchars($rowId, ENT_QUOTES));
                $n1 = $cxp->query($q1, $ctx);
                if ($n1 && $n1->length) return [$dom->saveHTML($n1->item(0)), $n1->length];

                $q2 = sprintf('.//*[contains(@%s,"%s")]', $a, htmlspecialchars($rowId, ENT_QUOTES));
                $n2 = $cxp->query($q2, $ctx);
                if ($n2 && $n2->length) return [$dom->saveHTML($n2->item(0)), $n2->length];
            }
        }

        // 2) با sectionId صریح
        if ($sectionId) {
            foreach ([
                         sprintf('.//*[@id="%s"]', $sectionId),
                         sprintf('.//*[contains(@id,"%s")]', $sectionId),
                         sprintf('.//*[@data-sppb-section-id="%s"]', $sectionId),
                     ] as $q) {
                $n = $cxp->query($q, $ctx);
                if ($n && $n->length) return [$dom->saveHTML($n->item(0)), $n->length];
            }
        }

        // 3) کاندیدها + انتخاب بر اساس index
        $cands = $cxp->query(
            './/' . implode(' | .//', [
                'section[contains(@class,"sppb-section")]',
                'div[contains(@class,"sppb-section")]',
                '*[@data-sppb-section-id]',
                'div[contains(@class,"sppb-row")]',
            ]),
            $ctx
        );
        $count = $cands ? $cands->length : 0;
        if ($count > 0) {
            $pick = ($index !== null && $index >= 0 && $index < $count) ? $index : 0;
            return [$dom->saveHTML($cands->item($pick)), $count];
        }
        return $withCount ? [null, 0] : null;
    }

    /* =======================================================================
     * Utilities
     * =======================================================================
     */
    protected function wrapError(string $msg): string
    {
        return '<div class="alert alert-warning sppbsection-error" dir="auto">' . htmlspecialchars($msg) . '</div>';
    }

    protected function debugWrap(string $htmlOrMsg, array $flow): string
    {
        if (strip_tags($htmlOrMsg) === $htmlOrMsg) {
            return '<div class="alert alert-info" style="direction:ltr;text-align:left">DEBUG flow=' . htmlspecialchars(implode('>', $flow)) . '</div>' . $htmlOrMsg;
        }
        $info = '<div class="alert alert-info" style="direction:ltr;text-align:left">DEBUG flow=' . htmlspecialchars(implode('>', $flow)) . ', rawLen=' . strlen($htmlOrMsg) . '</div>';
        return $info . $htmlOrMsg;
    }

    protected function getCache(string $key): ?string
    {
        try {
            $cache = Factory::getCache('plg_content_sppbsection', '');
            $cache->setCaching(true);
            $data = $cache->get($key);
            return is_string($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * دارایی‌های لازم SPPB را برای کارکرد ادآن‌های پویا لود و یکبار init می‌کند.
     */
    private function ensureSppbAssets() {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $doc = \JFactory::getDocument();
        $base = JUri::root(true) . '/components/com_sppagebuilder/assets/';

        // ✅ CSS ها
        $cssFiles = [
            'css/font-awesome-6.min.css',
            'css/font-awesome-v4-shims.css',
            'css/animate.min.css',
            'css/sppagebuilder.css',
            'css/dynamic-content.css',
            'css/magnific-popup.css',
            'css/js_slider.css',
            'css/color-switcher.css'
        ];
        foreach ($cssFiles as $css) {
            $doc->addStyleSheet($base . $css);
        }

        // ✅ JS ها
        $jsFiles = [
            'js/jquery.parallax.js',
            'js/jquery.magnific-popup.min.js',
            'js/sppagebuilder.js',
            'js/js_slider.js',
            'js/dynamic-content.js'
        ];
        foreach ($jsFiles as $js) {
            $doc->addScript($base . $js);
        }

        // ✅ اطمینان از اینکه jQuery لود شده
        JHtml::_('jquery.framework');
    }


    protected function setCache(string $key, string $value, int $ttl): void
    {
        try {
            $cache = Factory::getCache('plg_content_sppbsection', '');
            $cache->setCaching(true);
            $cache->store($value, $key);
        } catch (\Throwable $e) {
        }
    }



    /**
     * CSS/JS را از HTML استخراج می‌کند؛ اما براساس basename از تکراری‌لود شدن جلوگیری می‌کند.
     */
    protected function injectAssetsFromHtml(string $html): void
    {
        if (trim($html) === '') return;

        $doc = \Joomla\CMS\Factory::getApplication()->getDocument();

        // فهرست فایل‌هایی که همین حالا در صفحه هستند (براساس basename)
        $existingCss = [];
        $existingJs = [];
        foreach ($doc->_styleSheets ?? [] as $href => $meta) {
            $base = strtolower(basename(parse_url($href, PHP_URL_PATH) ?: $href));
            $existingCss[$base] = true;
        }
        foreach ($doc->_scripts ?? [] as $src => $meta) {
            $base = strtolower(basename(parse_url($src, PHP_URL_PATH) ?: $src));
            $existingJs[$base] = true;
        }

        // فقط فایل‌هایی که واقعاً به SPPB مربوط‌اند
        $isSppbPath = function (string $url): bool {
            $u = strtolower($url);
            return (strpos($u, 'com_sppagebuilder') !== false) ||
                (strpos($u, '/sppagebuilder') !== false) ||
                (strpos($u, '/addons/') !== false);
        };

        // پارس HTML
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        // 1) لینک‌های CSS
        $links = $xp->query('//link[@rel="stylesheet" and @href]');
        if ($links) {
            foreach ($links as $lnk) {
                $href = $lnk->getAttribute('href');
                if (!$href || !$isSppbPath($href)) continue;

                $base = strtolower(basename(parse_url($href, PHP_URL_PATH) ?: $href));
                if (isset($existingCss[$base])) continue; // قبلاً لود شده

                try {
                    $doc->addStyleSheet($href);
                    $existingCss[$base] = true;
                } catch (\Throwable $e) {
                }
            }
        }

        // 2) اسکریپت‌های فایل‌دار JS
        $scripts = $xp->query('//script[@src]');
        if ($scripts) {
            foreach ($scripts as $sc) {
                $src = $sc->getAttribute('src');
                if (!$src || !$isSppbPath($src)) continue;

                $base = strtolower(basename(parse_url($src, PHP_URL_PATH) ?: $src));

                // مهم: جلوگیری از دوباره‌لود شدن فایل‌های حسّاس
                // dynamic-content.js دو بار اجرا شود، خطای currentPage می‌دهد.
                $denyDup = [
                    'dynamic-content.js',
                    'dynamic-content.min.js',
                    'js_slider.js',
                    'js_slider.min.js',
                    'jquery.magnific-popup.min.js',
                    'jquery.magnific-popup.js'
                ];
                if (isset($existingJs[$base]) || in_array($base, $denyDup, true)) continue;

                try {
                    $doc->addScript($src);
                    $existingJs[$base] = true;
                } catch (\Throwable $e) {
                }
            }
        }


    }
}