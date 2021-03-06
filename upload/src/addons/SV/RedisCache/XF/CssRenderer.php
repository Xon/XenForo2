<?php
/**
 * @noinspection PhpMissingReturnTypeInspection
 */

namespace SV\RedisCache\XF;

use SV\RedisCache\RawResponseText;
use SV\RedisCache\Redis;
use XF\App;
use XF\Template\Templater;

class CssRenderer extends XFCP_CssRenderer
{
    const LESS_SHORT_CACHE_TIME     = 5 * 60;
    const TEMPLATE_SHORT_CACHE_TIME = 5 * 60;

    public function __construct(App $app, Templater $templater, \Doctrine\Common\Cache\CacheProvider $cache = null)
    {
        if ($cache === null)
        {
            $cache = \XF::app()->cache('css');
        }
        parent::__construct($app, $templater, $cache);
    }

    protected $echoUncompressedData = false;

    public function setForceRawCache(bool $value)
    {
        $this->echoUncompressedData = $value;
    }

    protected $includeCharsetInOutput = false;

    public function setIncludeCharsetInOutput(bool $value)
    {
        $this->includeCharsetInOutput = $value;
    }

    protected function svWrapOutput(string $output, bool $length): RawResponseText
    {
        return new RawResponseText($output, $length);
    }

    protected function getCredits(bool $allowSlave = false)
    {
        $cache = $this->cache;
        if (!$this->allowCached || !($cache instanceof Redis) || !($credis = $cache->getCredis($allowSlave)))
        {
            return null;
        }

        return $credis;
    }

    protected function getFinalCachedOutput(array $templates)
    {
        $credis = $this->getCredits(true);
        if (!$credis)
        {
            return parent::getFinalCachedOutput($templates);
        }
        /** @var Redis $cache */
        $cache = $this->cache;
        $key = $cache->getNamespacedId($this->getFinalCacheKey($templates) . '_gz');
        $data = $credis->hGetAll($key);
        if (empty($data))
        {
            return false;
        }

        $output = $data['o'] ?? ''; // gzencoded
        $length = $data['l'] ?? 0;

        if (!$length || !$this->includeCharsetInOutput)
        {
            $this->echoUncompressedData = false;
        }

        if ($this->echoUncompressedData)
        {
            return $this->svWrapOutput($output, $length);
        }

        // client doesn't support compression, so decompress before sending it
        $css = \strlen($output) > 0 ? @\gzdecode($output) : '';

        if (!$this->includeCharsetInOutput && \strpos($css, static::$charsetBits) === 0)
        {
            // strip out the css header bits
            $css = \substr($css, \strlen(static::$charsetBits));
        }

        return $css;
    }

    static $charsetBits = '@CHARSET "UTF-8";' . "\n\n";

    protected function cacheFinalOutput(array $templates, $output)
    {
        $credis = $this->getCredits();
        if (!$credis)
        {
            parent::cacheFinalOutput($templates, $output);

            return;
        }
        /** @var Redis $cache */
        $cache = $this->cache;

        $output = static::$charsetBits . $output;
        $len = \strlen($output);

        $key = $cache->getNamespacedId($this->getFinalCacheKey($templates) . '_gz');
        $credis->hMSet($key, [
            'o' => $len > 0 ? \gzencode($output, 9) : '',
            'l' => $len,
        ]);
        $credis->expire($key, 3600);
    }

    /** @var null|array */
    protected $cacheElements = null;

    protected function getCacheKeyElements()
    {
        if ($this->cacheElements === null)
        {
            $this->cacheElements = parent::getCacheKeyElements();
        }

        return $this->cacheElements;
    }

    protected function getComponentCacheKey($prefix, $value)
    {
        $elements = $this->getCacheKeyElements();

        return $prefix . 'Cache_' . md5(
                'text=' . $value
                . 'style=' . $elements['style_id']
                . 'modified=' . $elements['style_last_modified']
                . 'language=' . $elements['language_id']
                . $elements['modifier']
            );
    }

    public function parseLessColorFuncValue($value, $forceDebug = false)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            /** @noinspection PhpUndefinedMethodInspection */
            return parent::parseLessColorFuncValue($value, $forceDebug);
        }

        $key = $this->getComponentCacheKey('xfLessFunc', $value);
        $output = $cache->fetch($key);
        if ($output !== false)
        {
            return $output;
        }
        /** @noinspection PhpUndefinedMethodInspection */
        $output = parent::parseLessColorFuncValue($value, $forceDebug);

        $cache->save($key, $output, self::LESS_SHORT_CACHE_TIME);

        return $output;
    }

    public function parseLessColorValue($value)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            return parent::parseLessColorValue($value);
        }

        $key = $this->getComponentCacheKey('xfLessValue', $value);
        $output = $cache->fetch($key);
        if ($output !== false)
        {
            return $output;
        }

        $output = parent::parseLessColorValue($value);

        $cache->save($key, $output, self::LESS_SHORT_CACHE_TIME);

        return $output;
    }

    /**
     * @param array $templates
     * @return array
     */
    protected function getIndividualCachedTemplates(array $templates)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            return parent::getIndividualCachedTemplates($templates);
        }

        // individual css template cache causes a thundering herd of writes to xf_css_cache table

        $keys = [];
        foreach ($templates as $i => $template)
        {
            $keys[$i] = $this->getComponentCacheKey('xfCssTemplate', $template);
        }

        $results = [];
        $rawResults = $cache->fetchMultiple(\array_values($keys));
        foreach ($templates as $i => $template)
        {
            $key = $keys[$i];
            if (isset($rawResults[$key]))
            {
                $output = $rawResults[$key];
                if ($output !== false)
                {
                    $results[$template] = $rawResults[$key];
                }
            }
        }

        return $results;
    }

    /**
     * @param string $title
     * @param string $output
     */
    public function cacheTemplate($title, $output)
    {
        $cache = $this->cache;
        if (!$cache)
        {
            parent::cacheTemplate($title, $output);

            return;
        }

        $key = $this->getComponentCacheKey('xfCssTemplate', $title);
        $cache->save($key, $output, self::TEMPLATE_SHORT_CACHE_TIME);
    }
}
