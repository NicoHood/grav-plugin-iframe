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

        // Check if the plugin should be enabled (routes check only).
        $this->calculateEnable();

        // Enable the main events we are interested in
        if ($this->enable) {
          $this->enable([
              'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
              'onPageNotFound' => ['onPageNotFound', 1000],
          ]);
        }
    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable() {
        $path = $this->grav['uri']->path();

        $disable_on_routes = (array) $this->config->get('plugins.iframe.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.iframe.enable_on_routes');

        // Filter page routes
        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Render vertrauenssiegel iframe
     *
     * @param Event $event
     */
    public function onPageNotFound(Event $event)
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        // Get corresponding parent page
        $parent_path = dirname($uri->path());

        /** @var Page $page */
        $page = $pages->find($parent_path);
        if ($page === NULL)
        {
            return;
        }

        // Make sure the page is available and published
        if(!$page || !$page->published() || !$page->isPage()){
            return;
        }

        // Check if plugin should be activated
        $config = $this->mergeConfig($page);
        if (!$config->get('active', true)) {
            return;
        }

        // Check if the slug matches, for example restaurant/iframe -> iframe
        if($config->get('slug', 'iframe') !== $uri->basename()){
            return;
        }

        // Filter page template
        $enable_on_templates = (array) $this->config->get('plugins.iframe.enable_on_templates');
        if (!empty($enable_on_templates)) {
            if (!in_array($page->template(), $enable_on_templates, true)) {
                return;
            }
        }

        // Check header rules
        $enable_on_header = (array) $this->config->get('plugins.iframe.enable_on_header');
        if (!empty($enable_on_header)) {
            $header = new Data((array)$page->header());

            // Each rule must have an exact match
            foreach ($enable_on_header as $key => $value) {
              if ($header->get($key) !== $value) {
                  return;
              }
            }
        }

        // Render bikefitter page with iframe template
        $page->template($config->get('template', 'iframe/default'));
        $event->page = $page;

        $event->stopPropagation();
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
}
