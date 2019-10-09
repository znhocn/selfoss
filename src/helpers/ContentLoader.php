<?php

namespace helpers;

/**
 * Helper class for loading extern items
 *
 * @copyright  Copyright (c) Tobias Zeising (http://www.aditu.de)
 * @license    GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @author     Tobias Zeising <tobias.zeising@aditu.de>
 */
class ContentLoader {
    /** @var \daos\Items database access for saving new item */
    private $itemsDao;

    /** @var \daos\Sources database access for saving source’s last update */
    private $sourceDao;

    /**
     * ctor
     */
    public function __construct() {
        $this->itemsDao = new \daos\Items();
        $this->sourceDao = new \daos\Sources();
    }

    /**
     * updates all sources
     *
     * @return void
     */
    public function update() {
        $sourcesDao = new \daos\Sources();
        foreach ($sourcesDao->getByLastUpdate() as $source) {
            $this->fetch($source);
        }
        $this->cleanup();
    }

    /**
     * updates single source
     *
     * @param $id int id of the source to update
     *
     * @throws FileNotFoundException it there is no source with the id
     *
     * @return void
     */
    public function updateSingle($id) {
        $sourcesDao = new \daos\Sources();
        $source = $sourcesDao->get($id);
        if ($source) {
            $this->fetch($source);
            $this->cleanup();
        } else {
            throw new FileNotFoundException("Unknown source: $id");
        }
    }

    /**
     * updates a given source
     * returns an error or true on success
     *
     * @param mixed $source the current source
     *
     * @return void
     */
    public function fetch($source) {
        $lastEntry = $source['lastentry'];

        // at least 20 seconds wait until next update of a given source
        $this->updateSource($source, null);
        if (time() - $source['lastupdate'] < 20) {
            return;
        }

        @set_time_limit(5000);
        @error_reporting(E_ERROR);

        // logging
        \F3::get('logger')->debug('---');
        \F3::get('logger')->debug('start fetching source "' . $source['title'] . ' (id: ' . $source['id'] . ') ');

        // get spout
        $spoutLoader = new \helpers\SpoutLoader();
        $spout = $spoutLoader->get($source['spout']);
        if ($spout === null) {
            \F3::get('logger')->error('unknown spout: ' . $source['spout']);
            $this->sourceDao->error($source['id'], 'unknown spout');

            return;
        }
        \F3::get('logger')->debug('spout successfully loaded: ' . $source['spout']);

        // receive content
        \F3::get('logger')->debug('fetch content');
        try {
            $spout->load(
                json_decode(html_entity_decode($source['params']), true)
            );
        } catch (\Exception $e) {
            \F3::get('logger')->error('error loading feed content for ' . $source['title'], ['exception' => $e]);
            $this->sourceDao->error($source['id'], date('Y-m-d H:i:s') . 'error loading feed content: ' . $e->getMessage());

            return;
        }

        // current date
        $minDate = new \DateTime();
        $minDate->sub(new \DateInterval('P' . \F3::get('items_lifetime') . 'D'));
        \F3::get('logger')->debug('minimum date: ' . $minDate->format('Y-m-d H:i:s'));

        // insert new items in database
        \F3::get('logger')->debug('start item fetching');

        $itemsInFeed = [];
        foreach ($spout as $item) {
            $itemsInFeed[] = $item->getId();
        }
        $itemsFound = $this->itemsDao->findAll($itemsInFeed, $source['id']);

        $lasticon = null;
        $itemsSeen = [];
        foreach ($spout as $item) {
            // item already in database?
            if (isset($itemsFound[$item->getId()])) {
                \F3::get('logger')->debug('item "' . $item->getTitle() . '" already in database.');
                $itemsSeen[] = $itemsFound[$item->getId()];
                continue;
            }

            // test date: continue with next if item too old
            $itemDate = new \DateTime($item->getDate());
            // if date cannot be parsed it will default to epoch. Change to current time.
            if ($itemDate->getTimestamp() == 0) {
                $itemDate = new \DateTime();
            }
            if ($itemDate < $minDate) {
                \F3::get('logger')->debug('item "' . $item->getTitle() . '" (' . $item->getDate() . ') older than ' . \F3::get('items_lifetime') . ' days');
                continue;
            }

            // date in future? Set current date
            $now = new \DateTime();
            if ($itemDate > $now) {
                $itemDate = $now;
            }

            // insert new item
            \F3::get('logger')->debug('start insertion of new item "' . $item->getTitle() . '"');

            $content = '';
            try {
                // fetch content
                $content = $item->getContent();

                // sanitize content html
                $content = $this->sanitizeContent($content);
            } catch (\Exception $e) {
                $content = 'Error: Content not fetched. Reason: ' . $e->getMessage();
                \F3::get('logger')->error('Can not fetch "' . $item->getTitle() . '"', ['exception' => $e]);
            }

            // sanitize title
            $title = $this->sanitizeField($item->getTitle());
            if (strlen(trim($title)) === 0) {
                $title = '[' . \F3::get('lang_no_title') . ']';
            }

            // Check sanitized title against filter
            if ($this->filter($source, $title, $content) === false) {
                continue;
            }

            // sanitize author
            $author = $this->sanitizeField($item->getAuthor());

            \F3::get('logger')->debug('item content sanitized');

            try {
                $icon = $item->getIcon();
            } catch (\Exception $e) {
                \F3::get('logger')->debug('icon: error', ['exception' => $e]);

                return;
            }

            $newItem = [
                'title' => $title,
                'content' => $content,
                'source' => $source['id'],
                'datetime' => $itemDate->format('Y-m-d H:i:s'),
                'uid' => $item->getId(),
                'thumbnail' => $item->getThumbnail(),
                'icon' => $icon !== null ? $icon : '',
                'link' => htmLawed($item->getLink(), ['deny_attribute' => '*', 'elements' => '-*']),
                'author' => $author
            ];

            // save thumbnail
            $newItem = $this->fetchThumbnail($item->getThumbnail(), $newItem);

            // save icon
            $newItem = $this->fetchIcon($item->getIcon(), $newItem, $lasticon);

            // insert new item
            $this->itemsDao->add($newItem);
            \F3::get('logger')->debug('item inserted');

            \F3::get('logger')->debug('Memory usage: ' . memory_get_usage());
            \F3::get('logger')->debug('Memory peak usage: ' . memory_get_peak_usage());

            $lastEntry = max($lastEntry, $itemDate->getTimestamp());
        }

        // destroy feed object (prevent memory issues)
        \F3::get('logger')->debug('destroy spout object');
        $spout->destroy();

        // remove previous errors and set last update timestamp
        $this->updateSource($source, $lastEntry);

        // mark items seen in the feed to prevent premature garbage removal
        if (count($itemsSeen) > 0) {
            $this->itemsDao->updateLastSeen($itemsSeen);
        }
    }

    /**
     * Check if a new item matches the filter
     *
     * @param string $source
     * @param string $title
     * @param string $content
     *
     * @return bool indicating filter success
     */
    protected function filter($source, $title, $content) {
        if (strlen(trim($source['filter'])) !== 0) {
            $resultTitle = @preg_match($source['filter'], $title);
            $resultContent = @preg_match($source['filter'], $content);
            if ($resultTitle === false || $resultContent === false) {
                \F3::get('logger')->error('filter error: ' . $source['filter']);

                return true; // do not filter out item
            }
            // test filter
            if ($resultTitle === 0 && $resultContent === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize content for preventing XSS attacks.
     *
     * @param $content content of the given feed
     *
     * @return mixed|string sanitized content
     */
    protected function sanitizeContent($content) {
        return htmLawed(
            $content,
            [
                'safe' => 1,
                'deny_attribute' => '* -alt -title -src -href -target',
                'keep_bad' => 0,
                'comment' => 1,
                'cdata' => 1,
                'elements' => 'div,p,ul,li,a,img,dl,dt,dd,h1,h2,h3,h4,h5,h6,ol,br,table,tr,td,blockquote,pre,ins,del,th,thead,tbody,b,i,strong,em,tt,sub,sup,s,strike,code'
            ],
            'img=width, height'
        );
    }

    /**
     * Sanitize a simple field
     *
     * @param $value content of the given field
     *
     * @return mixed|string sanitized content
     */
    protected function sanitizeField($value) {
        return htmLawed(
            $value,
            [
                'deny_attribute' => '* -href -title -target',
                'elements' => 'a,br,ins,del,b,i,strong,em,tt,sub,sup,s,code'
            ]
        );
    }

    /**
     * Fetch the thumbanil of a given item
     *
     * @param string $thumbnail the thumbnail url
     * @param array $newItem new item for saving in database
     *
     * @return array the newItem Object with thumbnail
     */
    protected function fetchThumbnail($thumbnail, array $newItem) {
        if (strlen(trim($thumbnail)) > 0) {
            $extension = 'jpg';
            $imageHelper = new \helpers\Image();
            $thumbnailAsJpg = $imageHelper->loadImage($thumbnail, $extension, 500, 500);
            if ($thumbnailAsJpg !== null) {
                $written = file_put_contents(
                    'data/thumbnails/' . md5($thumbnail) . '.' . $extension,
                    $thumbnailAsJpg
                );
                if ($written !== false) {
                    $newItem['thumbnail'] = md5($thumbnail) . '.' . $extension;
                    \F3::get('logger')->debug('Thumbnail generated: ' . $thumbnail);
                } else {
                    \F3::get('logger')->warning('Unable to store thumbnail: ' . $thumbnail . '. Please check permissions of data/thumbnails.');
                }
            } else {
                $newItem['thumbnail'] = '';
                \F3::get('logger')->error('thumbnail generation error: ' . $thumbnail);
            }
        }

        return $newItem;
    }

    /**
     * Fetch the icon of a given feed item
     *
     * @param string $icon icon given by the spout
     * @param array $newItem new item for saving in database
     * @param &string $lasticon the last fetched icon
     *
     * @return mixed newItem with icon
     */
    protected function fetchIcon($icon, array $newItem, &$lasticon) {
        if (strlen(trim($icon)) > 0) {
            $extension = 'png';
            if ($icon === $lasticon) {
                \F3::get('logger')->debug('use last icon: ' . $lasticon);
                $newItem['icon'] = md5($lasticon) . '.' . $extension;
            } else {
                $imageHelper = new \helpers\Image();
                $iconAsPng = $imageHelper->loadImage($icon, $extension, 30, null);
                if ($iconAsPng !== null) {
                    $written = file_put_contents(
                        'data/favicons/' . md5($icon) . '.' . $extension,
                        $iconAsPng
                    );
                    $lasticon = $icon;
                    if ($written !== false) {
                        $newItem['icon'] = md5($icon) . '.' . $extension;
                        \F3::get('logger')->debug('Icon generated: ' . $icon);
                    } else {
                        \F3::get('logger')->warning('Unable to store icon: ' . $icon . '. Please check permissions of data/favicons.');
                    }
                } else {
                    $newItem['icon'] = '';
                    \F3::get('logger')->error('icon generation error: ' . $icon);
                }
            }
        } else {
            \F3::get('logger')->debug('no icon for this feed');
        }

        return $newItem;
    }

    /**
     * Obtain title for given data
     *
     * @param $data
     */
    public function fetchTitle($data) {
        \F3::get('logger')->debug('Start fetching spout title');

        // get spout
        $spoutLoader = new \helpers\SpoutLoader();
        $spout = $spoutLoader->get($data['spout']);

        if ($spout === null) {
            \F3::get('logger')->error("Unknown spout '{$data['spout']}' when fetching title");

            return null;
        }

        // receive content
        try {
            @set_time_limit(5000);
            @error_reporting(E_ERROR);

            $spout->load($data);
        } catch (\Exception $e) {
            \F3::get('logger')->error('Error fetching title', ['exception' => $e]);

            return null;
        }

        $title = $spout->getSpoutTitle();
        $spout->destroy();

        return $title;
    }

    /**
     * clean up messages, thumbnails etc.
     *
     * @return void
     */
    public function cleanup() {
        // cleanup orphaned and old items
        \F3::get('logger')->debug('cleanup orphaned and old items');
        $this->itemsDao->cleanup((int) \F3::get('items_lifetime'));
        \F3::get('logger')->debug('cleanup orphaned and old items finished');

        // delete orphaned thumbnails
        \F3::get('logger')->debug('delete orphaned thumbnails');
        $this->cleanupFiles('thumbnails');
        \F3::get('logger')->debug('delete orphaned thumbnails finished');

        // delete orphaned icons
        \F3::get('logger')->debug('delete orphaned icons');
        $this->cleanupFiles('icons');
        \F3::get('logger')->debug('delete orphaned icons finished');

        // optimize database
        \F3::get('logger')->debug('optimize database');
        $database = new \daos\Database();
        $database->optimize();
        \F3::get('logger')->debug('optimize database finished');
    }

    /**
     * clean up orphaned thumbnails or icons
     *
     * @param string $type thumbnails or icons
     *
     * @return void
     */
    protected function cleanupFiles($type) {
        \F3::set('im', $this->itemsDao);
        if ($type === 'thumbnails') {
            $checker = function($file) {
                return \F3::get('im')->hasThumbnail($file);
            };
            $itemPath = 'data/thumbnails/';
        } elseif ($type === 'icons') {
            $checker = function($file) {
                return \F3::get('im')->hasIcon($file);
            };
            $itemPath = 'data/favicons/';
        }

        foreach (scandir($itemPath) as $file) {
            if (is_file($itemPath . $file) && $file !== '.htaccess') {
                $inUsage = $checker($file);
                if ($inUsage === false) {
                    unlink($itemPath . $file);
                }
            }
        }
    }

    /**
     * Update source (remove previous errors, update last update)
     *
     * @param mixed $source source object
     * @param int $lastEntry timestamp of the newest item or NULL when no items were added
     */
    protected function updateSource($source, $lastEntry) {
        // remove previous error
        if ($source['error'] !== null) {
            $this->sourceDao->error($source['id'], '');
        }
        // save last update
        $this->sourceDao->saveLastUpdate($source['id'], $lastEntry);
    }
}
