<?php

namespace Defr\SiteScanner;

use DiDom\Document;
use DiDom\Element;

/**
 * Class SitemapGrabber
 * @package Defr\SiteScanner
 * @author  Dennis Fridrich <fridrich.dennis@gmail.com>
 */
class SiteMapGrabber
{
    /**
     * @var string
     */
    protected $url;

    /**
     * @var string
     */
    protected $domain;

    /**
     * @var string
     */
    protected $sitemapXml;

    /**
     * @var array
     */
    protected $pages = [];

    /**
     * @var array|WebPage[]
     */
    protected $pagesEntities = [];

    /**
     * SiteMapGrabber constructor.
     *
     * @param $url
     */
    public function __construct($url)
    {
        if (substr($url, 0, 4) != "http") {
            $url = "http://" . $url;
        }

        if (substr($url, -3) != "xml") {
            $url = $url . (substr($url, -1) == "/" ? null : "/") . "sitemap.xml";
        }

        $this->url = $url;

        $this->domain = parse_url($url)['host'];
    }

    /**
     * @return mixed
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return mixed
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @return array
     */
    public function getPages()
    {
        return $this->pages;
    }

    /**
     * @param $limit
     */
    public function limitPages($limit)
    {
        $this->pages = array_slice($this->pages, 0, $limit);
    }

    /**
     * @param $string
     */
    public function deleteUrlNotContaining($string)
    {
        $this->pages = array_filter($this->pages, function ($value) use ($string) {
            return strpos($value['loc'], $string) === false ? false : true;
        });
    }

    public function sortPagesEntities()
    {
        usort($this->pagesEntities, function (WebPage $a, WebPage $b) {
            return $a->getUrl() > $b->getUrl();
        });
    }

    /**
     * @return array|WebPage[]
     */
    public function getPagesEntities()
    {
        return $this->pagesEntities;
    }

    /**
     * @throws \Exception
     */
    public function download()
    {
        try {
            $this->sitemapXml = file_get_contents($this->url);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param bool $withoutDomain
     *
     * @return array
     */
    public function getUrls($withoutDomain = false)
    {
        $urls = [];
        foreach ($this->pages as $page) {
            $urls[] = $withoutDomain ? parse_url($page['loc'])['path'] : $page['loc'];
        }
        sort($urls);

        return $urls;
    }

    /**
     * @throws \Exception
     */
    public function getPagesSeoProperties()
    {
        if (empty($this->parsePagesFromXml())) {
            $this->parsePagesFromXml();
        }

        foreach ($this->pages as $page) {
            $this->parsePage($page);
        }
    }

    /**
     * @param $page
     */
    public function parsePage($page)
    {
        try {
            $document = new Document($page['loc'], true);

            $page = new WebPage($page['loc']);

            /** @var Element[] $title */
            $title = $document->find("title");

            if ($title[0]) {
                $page->setTitle($title[0]->text());
            }

            /** @var Element[] $title */
            $h1 = $document->find("h1");

            if (isset($h1[0])) {
                $page->setH1($h1[0]->text());
            }

            /** @var Element[] $title */
            $meta = $document->find("meta");

            foreach ($meta as $item) {
                switch ($item->getAttribute("name")) {
                    case "Keywords":
                        $page->setKeywords($item->getAttribute("content"));
                        break;
                    case "Description":
                        $page->setDescription($item->getAttribute("content"));
                        break;
                }
            }

            unset($document);
        } catch (\Exception $e) {
            $page = new WebPage($page['loc']);
            $page->setError($e->getMessage());
        }

        $this->pagesEntities[] = $page;
    }

    /**
     * @throws \Exception
     */
    public function parsePagesFromXml()
    {
        try {
            $xml = simplexml_load_string($this->sitemapXml);
            $json = json_encode($xml);
            $pages = json_decode($json, true);
            $this->pages = $pages['url'];
        } catch (\Exception $e) {
            throw  $e;
        }
    }

}