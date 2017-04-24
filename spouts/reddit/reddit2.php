<?php

namespace spouts\reddit;

use helpers\WebClient;

/**
 * Spout for fetching from reddit
 *
 * @copyright  Copyright (c) Tobias Zeising (http://www.aditu.de)
 * @license    GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @author     Tobias Zeising <tobias.zeising@aditu.de>
 */
class reddit2 extends \spouts\spout {
    /** @var string name of spout */
    public $name = 'Reddit';

    /** @var string description of this source type */
    public $description = 'Get your fix from Reddit';

    /** @var array configurable parameters */
    public $params = [
        'url' => [
            'title' => 'Subreddit or multireddit url',
            'type' => 'text',
            'default' => 'r/worldnews/top',
            'required' => true,
            'validation' => ['notempty']
        ],
        'username' => [
            'title' => 'Username',
            'type' => 'text',
            'default' => '',
            'required' => false,
            'validation' => ''
        ],
        'password' => [
            'title' => 'Password',
            'type' => 'password',
            'default' => '',
            'required' => false,
            'validation' => ''
        ]
    ];

    /** @var string the readability api key */
    private $apiKey = '';

    /** @var string the reddit_session cookie */
    private $reddit_session = '';

    /** @var string the scrape urls */
    private $scrape = true;

    /** @var array|null current fetched items */
    protected $items = null;

    /** @var string favicon url */
    private $faviconUrl = '';

    /**
     * loads content for given source
     *
     * @param array  $params
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     * @throws \RuntimeException if the response body is not in JSON format
     *
     * @return void
     */
    public function load(array $params) {
        $this->apiKey = \F3::get('readability');

        if (!empty($params['password']) && !empty($params['username'])) {
            if (function_exists('apc_fetch')) {
                $this->reddit_session = apc_fetch("{$params['username']}_selfoss_reddit_session");
                if (empty($this->reddit_session)) {
                    $this->login($params);
                }
            } else {
                $this->login($params);
            }
        }

        $response = $this->sendRequest('https://www.reddit.com/' . $params['url'] . '.json');
        $json = $response->json();

        if (isset($json['error'])) {
            throw new \Exception($json['message']);
        }

        $this->items = $json['data']['children'];
    }

    //
    // Iterator Interface
    //

    /**
     * reset iterator
     *
     * @return void
     */
    public function rewind() {
        if ($this->items !== null) {
            reset($this->items);
        }
    }

    /**
     * receive current item
     *
     * @return \SimplePie_Item current item
     */
    public function current() {
        if ($this->items !== null) {
            return $this;
        }

        return false;
    }

    /**
     * receive key of current item
     *
     * @return mixed key of current item
     */
    public function key() {
        if ($this->items !== null) {
            return key($this->items);
        }

        return null;
    }

    /**
     * select next item
     *
     * @return \SimplePie_Item next item
     */
    public function next() {
        if ($this->items !== null) {
            next($this->items);
        }

        return $this;
    }

    /**
     * end reached
     *
     * @return bool false if end reached
     */
    public function valid() {
        if ($this->items !== null) {
            return current($this->items) !== false;
        }

        return false;
    }

    /**
     * returns an unique id for this item
     *
     * @return string id as hash
     */
    public function getId() {
        if ($this->items !== null && $this->valid()) {
            $id = @current($this->items)['data']['id'];
            if (strlen($id) > 255) {
                $id = md5($id);
            }

            return $id;
        }

        return null;
    }

    /**
     * returns the current title as string
     *
     * @return string title
     */
    public function getTitle() {
        if ($this->items !== null && $this->valid()) {
            return @current($this->items)['data']['title'];
        }

        return null;
    }

    /**
     * returns the current title as string
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     *
     * @return string title
     */
    public function getHtmlUrl() {
        if ($this->items !== null && $this->valid()) {
            if (preg_match('/imgur/', @current($this->items)['data']['url'])) {
                if (!preg_match('/\.(?:gif|jpg|png|svg)$/i', @current($this->items)['data']['url'])) {
                    $response = $this->sendRequest(@current($this->items)['data']['url'] . '.jpg', 'HEAD');
                    if ($response->getStatusCode() === 404) {
                        return @current($this->items)['data']['url'] . '/embed';
                    }

                    return @current($this->items)['data']['url'] . '.jpg';
                }
            }

            return @current($this->items)['data']['url'];
        }

        return null;
    }

    /**
     * returns the content of this item
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     *
     * @return string content
     */
    public function getContent() {
        if ($this->items !== null && $this->valid()) {
            $text = @current($this->items)['data']['selftext_html'];
            if (!empty($text)) {
                return $text;
            }

            if (preg_match('/\.(?:gif|jpg|png|svg)/i', $this->getHtmlUrl())) {
                return '<img src="' . $this->getHtmlUrl() . '" />';
            }

            //albums, embeds other strange thigs
            if (preg_match('/embed$/i', $this->getHtmlUrl())) {
                $response = $this->sendRequest($this->getHtmlUrl());

                return '<a href="' . $this->getHtmlUrl() . '"><img src="' . preg_replace("/s\./", '.', \helpers\Image::findFirstImageSource($response->getBody())) . '"/></a>';
            }
            if ($this->scrape) {
                if ($contentFromReadability = $this->fetchFromReadability($this->getHtmlUrl())) {
                    return $contentFromReadability;
                }
                if ($contentFromInstapaper = $this->fetchFromInstapaper($this->getHtmlUrl())) {
                    return $contentFromInstapaper;
                }
            }

            return @current($this->items)['data']['url'];
        }

        return null;
    }

    /**
     * returns the icon of this item
     *
     * @return string icon url
     */
    public function getIcon() {
        $imageHelper = $this->getImageHelper();
        $htmlUrl = $this->getHtmlUrl();
        if ($htmlUrl && $imageHelper->fetchFavicon($htmlUrl)) {
            $this->faviconUrl = $imageHelper->getFaviconUrl();
        }

        return $this->faviconUrl;
    }

    /**
     * returns the link of this item
     *
     * @return string link
     */
    public function getLink() {
        if ($this->items !== null && $this->valid()) {
            return 'https://www.reddit.com' . @current($this->items)['data']['permalink'];
        }

        return null;
    }

    /**
     * returns the thumbnail of this item
     *
     * @return string thumbnail url
     */
    public function getThumbnail() {
        if ($this->items !== null && $this->valid()) {
            return @current($this->items)['data']['thumbnail'];
        }

        return null;
    }

    /**
     * returns the date of this item
     *
     * @return string date
     */
    public function getdate() {
        if ($this->items !== null && $this->valid()) {
            $date = date('Y-m-d H:i:s', @current($this->items)['data']['created_utc']);
        }

        return $date;
    }

    /**
     * destroy the plugin (prevent memory issues)
     */
    public function destroy() {
        unset($this->items);
        $this->items = null;
    }

    /**
     * returns the xml feed url for the source
     *
     * @param array $params params for the source
     *
     * @return string url as xml
     */
    public function getXmlUrl(array $params) {
        return  'reddit://' . urlencode($params['url']);
    }

    /**
     * fetch content from readability.com
     *
     * @author oxman @github
     *
     * @param string $url
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     * @throws \RuntimeException if the response body is not in JSON format
     *
     * @return string content
     */
    private function fetchFromReadability($url) {
        if (empty($this->apiKey)) {
            return null;
        }

        $response = $this->sendRequest('https://readability.com/api/content/v1/parser?token=' . $this->apiKey . '&url=' . urlencode($url));

        $data = $response->json();
        if (!isset($data['content'])) {
            return null;
        }

        return $data['content'];
    }

    /**
     * fetch content from instapaper.com
     *
     * @author janeczku @github
     *
     * @param string $url
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     *
     * @return string content
     */
    private function fetchFromInstapaper($url) {
        $content = $this->sendRequest('https://www.instapaper.com/text?u=' . urlencode($url))->getBody();

        $dom = new \DOMDocument();
        @$dom->loadHTML($content);
        if (!$dom) {
            return null;
        }
        $xpath = new \DOMXPath($dom);
        $elements = $xpath->query("//div[@id='story']");
        $content = $dom->saveXML($elements->item(0), LIBXML_NOEMPTYTAG);

        return $content;
    }

    /**
     * @param array $params
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     * @throws \RuntimeException if the response body is not in JSON format
     * @throws \Exception if the credentials are invalid
     */
    private function login(array $params) {
        $http = WebClient::getHttpClient();
        $response = $http->post("https://ssl.reddit.com/api/login/{$params['username']}", [
            'body' => [
                'api_type' => 'json',
                'user' => $params['username'],
                'passwd' => $params['password']
            ]
        ]);
        $data = $response->json();
        if (count($data['json']['errors']) > 0) {
            $errors = '';
            foreach ($data['json']['errors'] as $error) {
                $errors .= $error[1] . PHP_EOL;
            }
            throw new \Exception($errors);
        } else {
            $this->reddit_session = $data['json']['data']['cookie'];
            if (function_exists('apc_store')) {
                apc_store("{$params['username']}_selfoss_reddit_session", $this->reddit_session, 3600);
            }
        }
    }

    /**
     * Send a HTTP request to given URL, possibly with a cookie.
     *
     * @param string $url
     * @param string $method
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     *
     * @return \GuzzleHttp\Message\Response
     */
    private function sendRequest($url, $method = 'GET') {
        $http = WebClient::getHttpClient();

        if (isset($this->reddit_session)) {
            $request = $http->createRequest($method, $url, [
                'cookies' => ['reddit_session' => $this->reddit_session]
            ]);
        } else {
            $request = $http->createRequest($method, $url);
        }

        $response = $http->send($request);

        return $response;
    }
}
