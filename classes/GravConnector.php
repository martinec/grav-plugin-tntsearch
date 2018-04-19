<?php
namespace Grav\Plugin\TNTSearch;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Page;
use Symfony\Component\Yaml\Yaml;

class GravConnector extends \PDO
{
    public function __construct()
    {

    }

    public function getAttribute($attribute)
    {
        return false;
    }

    public function query($query)
    {
        $counter = 0;
        $results = [];

        $config = Grav::instance()['config'];
        $filter = $config->get('plugins.tntsearch.filter');
        $default_process = $config->get('plugins.tntsearch.index_page_by_default');
        $gtnt = new GravTNTSearch();

        if ($filter && array_key_exists('items', $filter)) {

            if (is_string($filter['items'])) {
                $filter['items'] = Yaml::parse($filter['items']);
            }

            $page = new Page;
            $collection = $page->collection($filter, false);
        } else {
            $collection = Grav::instance()['pages']->all();
            $collection->published()->routable();
        }

        $langs = Grav::instance()['language']->getLanguages();

        if (!count($langs)) {
            // undetermined according to the ISO 639-2 standard
            // @see http://www.loc.gov/standards/iso639-2/faq.html#25
            $langs = [ 'und' ];
        }

        foreach ($collection as $page) {
            $process = $default_process;
            $header = $page->header();
            $route = $page->rawRoute();

            if (isset($header->tntsearch['process'])) {
                $process = $header->tntsearch['process'];
            }

            // Only process what's configured
            if (!$process) {
                echo("Skipped $counter $route\n");
                continue;
            }

            try {
                foreach ($langs as $lang) {
                    $translated_page = GravTNTSearch::newTranslatedPage($page->rawRoute(), $lang);
                    if($translated_page) {
                        $fields = $gtnt->indexPageData($translated_page, $lang);
                        $results[] = (array) $fields;
                        $counter++;
                        echo("Added $counter [$lang] " . $translated_page->rawRoute() . "\n");
                    }
                }
            } catch (\Exception $e) {
                echo("Skipped $counter $route\n");
                continue;
            }
        }

        return new GravResultObject($results);
    }

}
