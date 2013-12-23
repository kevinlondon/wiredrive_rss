<?php
/*****************************************************************************
 * Copyright (c) 2013 IOWA, llc dba Wiredrive
 * Author Wiredrive
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 ****************************************************************************/

namespace Feed;

/**
 * The feed manager is the primary class to handle the feed processing.  It
 * can be used to fetch an rss feed and make it accessible by php, return it
 * unaltered, or json encode the data to be used by javascript or echoed
 * out.
 */
class Manager 
{
    /**
     * Feed Url
     * The current url that is hosting the rss feed.
     * @var string
     */
    protected $feedUrl = null;

    /**
     * Is Cache
     * Flag to enable caching, this can be set on instantiation or after the
     * fact.  This defaults to being on.
     * @var bool
     */
    protected $isCache = true;

    /**
     * Cache Adapter
     * The cache adapter manages how caching is operated on.  This can be
     * set to a different mechanism prior to data fetching so long as the
     * interface is followed.
     *
     * @var CacheAdapter
     */
    protected $cacheAdapter = null;

    /**
     * Connector
     * The feed retrieval mechanism to pull down the feed.
     *
     * @var Connector
     */
    protected $connector = null;

    /**
     * Parser
     * The feed parser
     *
     * @var Parser
     */
    protected $parser = null;

    /**
     * Constructor
     * Creates a new manager object and allows you to specify a url along with
     * configuration options
     *
     * @param   array   $config
     */
    public function __construct(array $config = array())
    {
        $this->setConnector(new Connector())
             ->setParser(new Parser())
             ->setCacheAdapter(new CacheAdapter());

        if (array_key_exists('feedUrl', $config)) {
            $this->setFeedUrl($config['feedUrl']);
        }
        
        if (array_key_exists('isCache', $config)) {
            $this->setIsCache($config['isCache']);
        }

        if (array_key_exists('format', $config)) {
            $this->setFormat($config['format']);
        }

        if (array_key_exists('cacheDir', $config)) {
            $this->setCacheDir($config['cacheDir']);
        }
    }

    /**
     * @param   string      $url
     * @return  Manager
     */
    public function setFeedUrl($url)
    {
        if (empty($url)) {
            throw new \Exception('Feed urls cannot be empty');
        }
        $this->feedUrl = $url;
        return $this;
    }
    
    /**
     * @return  string
     */
    public function getFeedUrl()
    {
        return $this->feedUrl;
    }
    
    /**
     * @param   CacheAdapter  $adapter
     * @return  Manager
     */
    public function setCacheAdapter(CacheAdapter $adapter)
    {
        $this->cacheAdapter = $adapter;
        return $this;
    }
    
    /**
     * @return  CacheAdapter
     */
    public function getCacheAdapter()
    {
        return $this->cacheAdapter;
    }

    /**
     * @param   bool    $isCache
     * @return  Manager
     */
    public function setIsCache($isCache)
    {
        $this->isCache = (bool) $isCache;
        return $this;
    }

    /**
     * @return  bool
     */
    public function isCache()
    {
        return $this->isCache;
    }

    /**
     * @param   string      $cacheDir
     * @return  Manager
     */
    public function setCacheDir($cacheDir)
    {
        $cacheAdapter = $this->getCacheAdapter();
        $cacheAdapter->setCacheDir($cacheDir);
        return $this;
    }

    /**
     * @return  string
     */
    public function getCacheDir()
    {
        return $this->getCacheAdapter()
                    ->getCacheDir();
    }

    /**
     * @param   string  $format
     * @return  Manager
     */
    public function setFormat($format)
    {
        $parser = $this->getParser();
        $parser->setFormat($format);
        return $this;
    }

    /**
     * @return  string
     */
    public function getFormat()
    {
        return $this->getParser()
                    ->getFormat();
    }

    /**
     * @return  Parser
     */
    public function getParser()
    {
        return $this->parser;
    }

    /**
     * @param   Parser     $parser
     * @return  Manager
     */
    public function setParser(Parser $parser)
    {
        $this->parser = $parser;
        return $this;
    }

    /**
     * @return  Connector
     */
    public function getConnector()
    {
        return $this->connector;
    }

    /**
     * @param   Connector      $connector
     * @return  Manager
     */
    public function setConnector(Connector $connector)
    {
        $this->connector = $connector;
        return $this;
    }

    /**
     * Process 
     * Either load the feed from the cache adapter or retrieve it using the
     * connector and parse the data.
     *
     * @return mixed
     */
    public function process()
    {
        if ($this->isCache()) {
            $feedData = $this->processCache();
        } else {
            $feedData = $this->processData();
        }
        return $feedData;
    }

    /**
     * Process Cache
     * Try to retrieve the contents through the cache adapter and load it into
     * the parser.
     *
     * @return mixed
     */
    public function processCache()
    {
        $cacheAdapter = $this->getCacheAdapter();
        $url          = $this->getFeedUrl();
        $parser       = $this->getParser();

        $feedData = $cacheAdapter->getData($url);
        if (false == $feedData) {
            $feedData = $this->getContents($url);
            
            $cacheAdapter->updateCache($url, $feedData);
            
            return $parser->setContents($feedData)
                          ->process();
        } 
        else {
            /* 
             * we got a cache hit but we need to make that the data is still 
             * valid, we'll ask the cache adapter if it is stale after examining
             * the content's cache expire time.  if it is stale, we'll go
             * pull from the url again and recache
             */
            $parsedData = $parser->setContents($feedData)
                                 ->process();
            
            $timeToLive = $parser->getProperty('ttl');
            if (empty($timeToLive)) {
                return $parsedData;
            }

            /* ttl is in minutes */
            $timeOffset = $timeToLive * 60;
            if ($cacheAdapter->isStale($url, $timeOffset)) { 
                $feedData = $this->getContents($url);
                $cacheAdapter->updateCache($url, $feedData);
                $parsedData = $parser->setContents($feedData)
                                     ->process();
            }
            return $parsedData;
        }
    }

    /**
     * Process Data
     * Simple wrapper to retrieve the contents for the current set format
     * and url
     *
     * @return mixed
     */
    public function processData()
    {
        $url    = $this->getFeedUrl();
        $feedContents = $this->getContents($url);
        
        return $this->parser->setContents($feedContents)
                            ->process();
    }

    /**
     * Fetch the data using the connector
     *
     * This can be used as an independent parsing mechanism.
     *
     * @param   string      $url
     * @return  mixed
     */
    public function getContents($url)
    {
        $connector  = $this->getConnector();
        
        if (empty($url)) {
            throw new \Exception('Invalid feed url supplied');
        }

        $contents  = $connector->fetchData($url);
        if (false == $contents) {
            throw new \Exception('Failed retrieved data');
        }
        return $contents;
    }
}
