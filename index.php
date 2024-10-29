<?php
/**
 * Plugin Name: B Tiktok Feed
 * Description: Embed Tiktok feed in your website
 * Version: 1.0.16
 * Author: bPlugins
 * Author URI: http://bplugins.com
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: tiktok
 */

// ABS PATH
if (!defined('ABSPATH')) {exit;}

register_activation_hook(__FILE__, function () {
	if ( is_plugin_active('my-social-feeds/my-social-feeds.php')) {
		deactivate_plugins('my-social-feeds/my-social-feeds.php');
	}  
});

// Constant
if ('localhost' === $_SERVER['HTTP_HOST']) {
    $plugin_version = time();
} else {
    $plugin_version = '1.0.16';

}
define('TTP_PLUGIN_VERSION', $plugin_version);

// define('TTP_PLUGIN_VERSION', 'localhost' === $_SERVER['HTTP_HOST']  time() : '1.0.16');
define('TTP_DIR', plugin_dir_url(__FILE__));
define('TTP_ASSETS_DIR', plugin_dir_url(__FILE__) . 'assets/');

if (!function_exists('ttp_init')) {
    function ttp_init()
    {
        global $ttp_bs;
        require_once plugin_dir_path(__FILE__) . 'bplugins_sdk/init.php';
        $ttp_bs = new BPlugins_SDK(__FILE__);
    }
    ttp_init();
} else {
    $ttp_bs->uninstall_plugin(__FILE__);
}

// TikTok
if(!class_exists('TTPTiktok')) {

    class TTPTiktok
    {
        public function __construct()
        {
            add_action('enqueue_block_assets', [$this, 'enqueueTiktokAssets']);
            add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
            add_action('init', [$this, 'onInit']);
            add_action('admin_footer', [$this, 'load_tiktok_script'], 10);
            add_action('wp_footer', [$this, 'load_tiktok_script'], 10);
        }

        public function load_tiktok_script()
        {
            ?>
            <script async src="https://www.tiktok.com/embed.js"></script>
            <?php
        }

        public function enqueueTiktokAssets()
        {
            wp_register_style('ttp-fancyApp', TTP_ASSETS_DIR . 'css/fancyapps.min.css');

            wp_register_script('ttp-fancyApp', TTP_ASSETS_DIR . 'js/fancyapps.min.js', [], TTP_PLUGIN_VERSION);

            wp_register_script('ttp-script', TTP_DIR . 'dist/script.js', ['react', 'react-dom', 'ttp-fancyApp', 'wp-i18n'], TTP_PLUGIN_VERSION);

            wp_register_style('ttp-style', plugins_url('dist/style.css', __FILE__), ['ttp-fancyApp'], TTP_PLUGIN_VERSION); // Frontend Style

            wp_localize_script('ttp-script', 'ttpData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'tiktokAuthorized' => false !== get_transient('ttp_tiktok_authorized_data'),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);

            wp_localize_script('ttp-tiktok-player-editor-script', 'ttpPatters', [
                'patternsImagePath' => TTP_DIR . 'assets/images/patterns/',
            ]);

        }

        public function adminEnqueueScripts($hook)
        {
            if ('edit.php' === $hook) {
                wp_enqueue_style('ttpAdmin', TTP_ASSETS_DIR . 'css/admin.css', [], TTP_PLUGIN_VERSION);
                wp_enqueue_script('ttpAdmin', TTP_ASSETS_DIR . 'js/admin.js', ['wp-i18n'], TTP_PLUGIN_VERSION, true);
            }
        }

        public function onInit()
        {
            wp_register_style('ttp-tiktok-editor-style', plugins_url('dist/editor.css', __FILE__), ['wp-edit-blocks', 'ttp-style'], TTP_PLUGIN_VERSION); // Backend Style

            register_block_type(__DIR__, [
                'editor_style' => 'ttp-tiktok-editor-style',
                'render_callback' => [$this, 'render'],
            ]); // Register Block

            wp_set_script_translations('ttp-tiktok-player-editor-script', 'tiktok', plugin_dir_path(__FILE__) . 'languages'); // Translate
        }

        public function render($attributes)
        {
            extract($attributes);

            $className = $className ?? '';
            $ttpBlockClassName = 'wp-block-ttp-tiktok-player ' . $className . ' align' . $align;

            $videos = get_transient('ttp_tiktok_videos');
            $user_info = get_transient('ttp_tiktok_user_info');

            wp_enqueue_style('ttp-style');
            wp_enqueue_script('ttp-script');

            ob_start();
            ?>
            <div class='<?php echo esc_attr($ttpBlockClassName); ?>' data-data="<?php echo esc_attr(wp_json_encode(compact('videos', 'user_info'))) ?>"  id='ttpTiktok-<?php echo esc_attr($cId) ?>' data-attributes='<?php echo esc_attr(wp_json_encode($attributes)); ?>'></div>

            <?php return ob_get_clean();
        } // Render
    }
    new TTPTiktok();

    require_once plugin_dir_path(__FILE__) . '/TiktokAPI.php';
    require_once plugin_dir_path(__FILE__) . '/custom-post.php';
}