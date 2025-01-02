<?php 
/**
 * Plugin Name: @ CloudFlare Auto Purge Cache
 * Plugin URI: https://github.com/aiiddqd/cloudflare-auto-purge-cache
 * Description: Auto purge cache for CloudFlare plugin
 * Author: aiiddqd
 * Requires Plugins: cloudflare
 * Author URI: https://github.com/aiiddqd/
 * Version: 0.9
 */

namespace CloudFlareAutoPurgeCache;

Main::init();

class Main
{

    // static $route = "/";
    const API_KEY = 'cloudflare_api_key';
    const EMAIL = 'cloudflare_api_email';
    const CACHED_DOMAIN_NAME = 'cloudflare_cached_domain_name';
    public static function init()
    {
        add_action('admin_init', function () {
            if (isset($_GET['test_CloudFlareInvalidator'])) {

                echo '<pre>';
                // $val = self::get_zone_id();
                // var_dump($val);
                exit;
            }
        });

        /**
         * update after save posts
         */
        add_filter('save_post', [__CLASS__, 'prepare_invalidate_cache_after_post_save']);
        add_action('invalidate_cache_after_post_save', [__CLASS__, 'handle_invalidate_cache_after_post_save']);

        /**
         * support Admin Async Bar https://github.com/aiiddqd/aab/ 
         */
        add_filter('aab-actions', [__CLASS__, 'aab_htmx_add_action'], 10, 2);
        add_action('htmxer/cf-cache-purge', [__CLASS__, 'aab_handle_htmx_request']);
        
    }


    public static function aab_handle_htmx_request($context){
        if(current_user_can('administrator') === false){
            echo 'no permission';
            exit;
        }

        $url = $context['url'] ?? null;
        if(empty($url)){
            echo 'no url';
            exit;
        }
        self::invalidate([$url]);
        echo 'ok';
        exit;
    }

    public static function aab_htmx_add_action($actions, $context){
        
        $actions[] = sprintf('<a id="cf-cache-purge" href="%s" hx-post="/wp-json/htmxer/cf-cache-purge" hx-swap="outerHTML" hx-target="#cf-cache-purge">CachePurge</a>', '#');
        return $actions;
    }


    public static function handle_invalidate_cache_after_post_save($urls)
    {
        if (empty($urls)) {
            return false;
        }

        if (is_array($urls)) {
            self::invalidate($urls);
            return true;
        }
    }

    public static function prepare_invalidate_cache_after_post_save($post_id)
    {
        if (wp_is_post_revision($post_id) || get_post($post_id)->post_status != 'publish') {
            return false;
        }

        $urls = [
            trailingslashit(get_home_url()),
        ];

        $urls[] = trailingslashit(get_permalink($post_id));

        if (get_post_type($post_id) == 'post') {
            $urls[] = site_url('blog');
        }

        $urls = array_unique($urls);

        as_schedule_single_action(time(), 'invalidate_cache_after_post_save', [$urls], 'app', true);
    }

    public static function invalidate($urls = [])
    {
        if (empty($urls)) {
            $urls = [];
        }

        $urls[] = get_home_url();

        $urls = array_unique($urls);

        $route = sprintf('zones/%s/purge_cache', self::get_zone_id());
        $args = [
            'body' => json_encode(array('files' => $urls)),
            'method' => 'POST',
        ];
        $result = self::request($route, $args);

        return $result;
    }

    public static function get_zone_id()
    {
        $zone_id = get_transient('app_cloudflare_zone_id');
        if (empty($zone_id)) {
            $zones = self::request('zones');
            if (empty($zones['result'])) {
                return null;
            }

            $domain_name = get_option(self::CACHED_DOMAIN_NAME);
            if (empty($domain_name)) {
                return null;
            }

            foreach ($zones['result'] as $zone) {
                if ($zone['name'] === $domain_name) {
                    $zone_id = $zone['id'];
                    set_transient('app_cloudflare_zone_id', $zone_id, WEEK_IN_SECONDS);
                }
            }
        }

        return $zone_id;
    }

    public static function get_headers()
    {
        $auth_key_len = 37;

        $key = get_option(self::API_KEY);
        $headers = [
            'Content-Type' => 'application/json'
        ];
        if (strlen($key) === $auth_key_len && preg_match('/^[0-9a-f]+$/', $key)) {
            $headers['X-Auth-Email'] = get_option(self::EMAIL);
            $headers['X-Auth-Key'] = $key;
        } else {
            $headers['Authorization'] = "Bearer {$key}";
        }

        return $headers;
    }

    public static function request($path = '', $args = [])
    {
        $url_base = 'https://api.cloudflare.com/client/v4/';
        if (empty($path)) {
            $path = 'zones';
        }

        $path = trim($path, '/');
        $url = sprintf($url_base . '%s' . '/', $path);

        if (empty($args)) {
            $args = [
                'method' => 'GET'
            ];
        }

        if (empty($args['headers'])) {
            $args['headers'] = self::get_headers();
        }

        $response = wp_remote_request($url, $args);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data;
    }
}