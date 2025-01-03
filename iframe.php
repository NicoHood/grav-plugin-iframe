<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Data\Data;

/**
 * Class IframePlugin
 * @package Grav\Plugin
 */
class IframePlugin extends Plugin
{
    protected $path;
    protected $parent_path;
    protected $slug;

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000], // TODO: Remove when plugin requires Grav >=1.7
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
    * Composer autoload.
    *is
    * @return ClassLoader
    */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onPageInitialized' => ['onPageInitialized', 100000],
        ]);
    }

    /**
     * Inject iframe page dynamically
     *
     * @param Event $event
     */
    public function onPageInitialized(Event $event)
    {
        // Get corresponding parent page
        // NOTE: Uri.path() is not used here by intention: https://github.com/getgrav/grav/issues/3103
        $this->path = $this->grav['route']->getRoute();
        $this->parent_path = dirname($this->path);
        $this->slug = basename($this->path);

        $page = $this->handleIframe();
        if ($page) {
            // Fix RuntimeException: Cannot override frozen service "page" issue
            // NOTE: This is kinda a workaround, but also used by the official sitemap plugin
            unset($this->grav['page']);
            $this->grav['page'] = $page;

            // Add this dynamic page to the pages array, as the form plugin requires it on a form submission.
            // NOTE: This might not be the correct way to do the initialization, but I did not find another way yet!
            // It seems correct though!
            $pages = $this->grav['pages'];
            if (!$pages->find($page->route())) {
                $pages->addPage($page);
            }
        }
    }

    /**
     * Check if uri is a valid iframe config, then return a new page to inject
     */
    public function handleIframe(): ?\Grav\Common\Page\Interfaces\PageInterface {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        /** @var Page $page */
        $parent_page = $pages->find($this->parent_path);
        if ($parent_page === NULL)
        {
            return null;
        }

        // Make sure the page is available and published
        if(!$parent_page || !$parent_page->published() || !$parent_page->isPage()){
            return null;
        }

        $header = $parent_page->header();
        if (!($header instanceof \Grav\Common\Page\Header)) {
            $header = new \Grav\Common\Page\Header((array)$header);
        }

        // Check if plugin should be activated
        $config = $this->mergeConfig($parent_page);
        if (!$config->get('active', true)) {
            return null;
        }

        // Go through all rules
        foreach ($config->get('rules') as $name => $rules) {
            $rules = new Data($rules);

            // Check if enable and disable conditions matches
            if (!$this->isIframeRuleActive($parent_page, $rules)) {
                continue;
            }

            // Load a different page, if configured
            $page = $parent_page;
            $newpage_route = $rules->get('page');
            if ($newpage_route) {
                $page = $pages->find($newpage_route);
                if(!$page) {
                    throw new \RuntimeException($this->grav['language']->translate('PLUGIN_IFRAME.PAGE_NOT_FOUND'));
                }

                // Set page to routable
                $page->routable(true);
            }

            // Add parent page as optional twig variable
            $twig_var_parent_page = $rules->get('twig_var_parent_page', 'parent_page');
            if ($twig_var_parent_page) {
                $this->grav['twig']->twig_vars[$twig_var_parent_page] = $parent_page;
            }

            // Render page with different template
            $template = $rules->get('template');
            if ($template) {
                $page->template($template);
            }

            // Render page with different title
            $title = $rules->get('title');
            if ($title) {
                $page->title($title);
            }

            // Fix route
            if ($rules->get('parent_route', false)) {
                $page->route($this->parent_path);
            }
            else {
                $page->route($this->path);
            }

            return $page;
        }

        // No valid iframe found
        return null;
    }

    /**
     * Determine if the plugin should be enabled based on the following config options:
     * slug
     * enable_on_templates
     * enable_on_header
     * enable_on_routes
     * disable_on_routes
     */
    public function isIframeRuleActive(\Grav\Common\Page\Interfaces\PageInterface $parent_page, Data $config): bool {
        // Check if the slug matches, for example restaurant/iframe -> iframe
        if ($config->get('slug') !== $this->slug) {
            return false;
        }

        // Filter page template
        $enable_on_templates = (array) $config->get('enable_on_templates');
        if (!empty($enable_on_templates)) {
            if (!in_array($parent_page->template(), $enable_on_templates, true)) {
                return false;
            }
        }

        // Check header rules
        $enable_on_header = (array) $config->get('enable_on_header');
        if (!empty($enable_on_header)) {
            $header = $parent_page->header();
            if (!($header instanceof \Grav\Common\Page\Header)) {
                $header = new \Grav\Common\Page\Header((array)$header);
            }

            // Each rule must have an exact match
            foreach ($enable_on_header as $key => $value) {
                if ($header->get($key) !== $value) {
                    return false;
                }
            }
        }

        // Filter page routes (Must be placed as last rule,
        // due to returning true instead of false)
        $disable_on_routes = (array) $config->get('disable_on_routes');
        $enable_on_routes = (array) $config->get('enable_on_routes', ['/']);

        if (!in_array($this->parent_path, $disable_on_routes)) {
            if (in_array($this->parent_path, $enable_on_routes)) {
                return true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($this->parent_path, $route)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
}
