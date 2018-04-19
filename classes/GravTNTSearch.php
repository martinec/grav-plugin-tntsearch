<?php
namespace Grav\Plugin\TNTSearch;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Collection;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use TeamTNT\TNTSearch\TNTSearch;

class GravTNTSearch
{
    public $tnt;
    protected $options;
    protected $bool_characters = ['-', '(', ')', 'or'];

    public function __construct($options = [])
    {
        $search_type = Grav::instance()['config']->get('plugins.tntsearch.search_type');
        $lang = Grav::instance()['language']->getLanguage();
        $stemmer = Grav::instance()['config']->get('plugins.tntsearch.stemmer');
        $data_path = Grav::instance()['locator']->findResource('user://data', true) . '/tntsearch';

        if (!file_exists($data_path)) {
            mkdir($data_path);
        }

        $defaults = [
            'json' => false,
            'search_type' => $search_type,
            'langs' => $lang,
            'stemmer' => $stemmer,
            'limit' => 20,
            'as_you_type' => true,
            'snippet' => 300,
            'phrases' => true,
        ];

        $this->options = array_merge($defaults, $options);
        $this->tnt = new TNTSearch();
        $this->tnt->loadConfig([
            "storage"   => $data_path,
            "driver"    => 'sqlite',
        ]);
    }

    public function search($query) {
        $uri = Grav::instance()['uri'];
        $type = $uri->query('search_type');
        $this->tnt->selectIndex('grav.index');
        $this->tnt->asYouType = $this->options['as_you_type'];

        if (isset($this->options['fuzzy']) && $this->options['fuzzy']) {
            $this->tnt->fuzziness = true;
        }

        if ($uri->query('langs')) {
            $this->options['langs'] = $uri->query('langs');
        }

        $limit = intval($this->options['limit']);
        $type = isset($type) ? $type : $this->options['search_type'];

        $multiword = null;
        if (isset($this->options['phrases']) && $this->options['phrases']) {
            if (strlen($query) > 2) {
                if ($query[0] === "\"" && $query[strlen($query) - 1] === "\"") {
                    $multiword = substr($query, 1, strlen($query) - 2);
                    $type = 'basic';
                    $query = $multiword;
                }
            }
        }

        switch ($type) {
            case 'basic':
                $results = $this->tnt->search($query, $limit, $multiword);
                break;
            case 'boolean':
                $results = $this->tnt->searchBoolean($query, $limit);
                break;
            case 'default':
            case 'auto':
            default:
                $guess = 'search';
                foreach ($this->bool_characters as $char) {
                    if (strpos($query, $char) !== false) {
                        $guess = 'searchBoolean';
                        break;
                    }
                }

                $results = $this->tnt->$guess($query, $limit);
        }

        return $this->processResults($results, $query);
    }

    /**
     * Gets the translated version of a page based on its route and a language
     *
     * @param  string $route Route to the page.
     * @param  string $lang Optional language code or the active language if null.
     *
     * @return string  The translated Page if available|null.
     */
    public static function newTranslatedPage($route, $lang = null)
    {
        $pages = Grav::instance()['pages'];
        $page = $pages->dispatch($route);

        if (!$page) {
            return null;
        }

        if (!$lang) {
            $lang = Grav::instance()['language']->getActive();
        }

        if ($lang == 'und' || !$page->language() || $page->language() == $lang) {
            return clone $page;
        }

        $page_name_without_ext = substr($page->name(), 0, -(strlen($page->extension())));
        $translated_page_path = $page->path() . DS . $page_name_without_ext . '.' . $lang . '.md';

        if (!file_exists($translated_page_path)) {
            return null;
        }

        $translated_page  = new Page();
        $translated_page->init(new \SplFileInfo($translated_page_path), $lang . '.md');

        $the_translated_parent = $translated_page;
        $the_parent = $page->parent();

        while ($the_parent !== null) {
            $page_name_without_ext = substr($the_parent->name(), 0, -(strlen($the_parent->extension())));
            $translated_page_path = $the_parent->path() . DS . $page_name_without_ext . '.' . $lang . '.md';

            if (file_exists($translated_page_path)) {
                $aPage = new Page();
                $aPage->init(new \SplFileInfo($translated_page_path), $lang . '.md');
            } else {
                $aPage = $the_parent;
            }

            $the_translated_parent->parent($aPage);
            $the_translated_parent = $aPage;

            $the_parent = $the_parent->parent();
        }

        return $translated_page;
    }

    protected function processResults($res, $query)
    {
        $counter = 0;
        $data = new \stdClass();
        $data->execution_time = $res['execution_time'];
        $pages = Grav::instance()['pages'];

        foreach ($res['ids'] as $id) {
            if ($counter > $this->options['limit']) {
                break;
            }

            list($lang, $route) = explode(':', $id, 2);

            if (in_array($lang, explode(',', $this->options['langs']))) {
                $translated_page = GravTNTSearch::newTranslatedPage($route, $lang);
                if($translated_page) {
                    Grav::instance()->fireEvent('onTNTSearchQuery', new Event(['page' => $translated_page, 'query' => $query, 'options' => $this->options, 'fields' => $data, 'gtnt' => $this]));
                    $counter++;
                }
            }
        }

        $data->number_of_hits = $counter;

        if ($this->options['json']) {
            return json_encode($data, JSON_PRETTY_PRINT);
        } else {
            return $data;
        }
    }

    public static function getCleanContent($page)
    {
        $twig = Grav::instance()['twig'];
        $header = $page->header();

        if (isset($header->tntsearch['template'])) {
            $processed_page = $twig->processTemplate($header->tntsearch['template'] . '.html.twig', ['page' => $page]);
            $content =$processed_page;
        } else {
            $content = $page->content();
        }

        $content = preg_replace('/[ \t]+/', ' ', preg_replace('/\s*$^\s*/m', "\n", strip_tags($content)));

        return $content;
    }

    public function createIndex()
    {
        $this->tnt->setDatabaseHandle(new GravConnector);
        $indexer = $this->tnt->createIndex('grav.index');

        // Set the stemmer language if set
        if ($this->options['stemmer'] != 'default') {
            $indexer->setLanguage($this->options['stemmer']);
        }

        $indexer->run();
    }

    public function deleteIndex($page)
    {
        $this->tnt->setDatabaseHandle(new GravConnector);

        try {
            $this->tnt->selectIndex('grav.index');
        } catch (IndexNotFoundException $e) {
            return;
        }

        $indexer = $this->tnt->getIndex();

        // Delete existing if it exists
        $indexer->delete(GravTNTSearch::getPageIndexId($page->rawRoute(), $page->language()));
    }

    public function updateIndex($page)
    {
        $this->tnt->setDatabaseHandle(new GravConnector);

        try {
            $this->tnt->selectIndex('grav.index');
        } catch (IndexNotFoundException $e) {
            return;
        }

        $indexer = $this->tnt->getIndex();

        // Delete existing if it exists
        $indexer->delete(GravTNTSearch::getPageIndexId($page->rawRoute(), $page->language()));

        $filter = $config = Grav::instance()['config']->get('plugins.tntsearch.filter');
        if ($filter && array_key_exists('items', $filter)) {

            if (is_string($filter['items'])) {
                $filter['items'] = Yaml::parse($filter['items']);
            }

            $apage = new Page;
            /** @var Collection $collection */
            $collection = $apage->collection($filter, false);

            if (array_key_exists($page->path(), $collection->toArray())) {
                $fields = GravTNTSearch::indexPageData($page);
                $document = (array) $fields;

                // Insert document
                $indexer->insert($document);
            }
        }
    }
    
    public static function getPageIndexId($page, $lang)
    {
        if (!$lang) {
            $lang = Grav::instance()['language']->getActive();
        }
        return $lang . ':' .$page->rawRoute();
    }

    public function indexPageData($page, $lang)
    {
        $fields = new \stdClass();
        $fields->id = GravTNTSearch::getPageIndexId($page, $lang);
        $fields->name = $page->title();
        $fields->content = $this->getCleanContent($page);

        Grav::instance()->fireEvent('onTNTSearchIndex', new Event(['page' => $page, 'fields' => $fields]));

        return $fields;
    }

}
