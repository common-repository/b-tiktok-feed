<?php
if(!class_exists('TTPTiktokAPI')) {

    class TTPTiktokAPI
    {
        protected $user_info_endpoint = "https://open.tiktokapis.com/v2/user/info/?fields=";
        protected $user_info_fields = '';
        protected $video_list_endpoint = 'https://open.tiktokapis.com/v2/video/list/?fields=';
        protected $video_list_fields = '';
        protected $transient = [];

        public function __construct()
        {
            $this->user_info_fields = 'avatar_url,display_name,bio_description,profile_deep_link,is_verified,follower_count,following_count,likes_count,video_count';
            $this->video_list_fields = 'cover_image_url,create_time,share_url,video_description,duration,height,width,id,title,embed_link,like_count,comment_count,share_count,view_count';

            $this->transient = [
                'data' => 'ttp_tiktok_authorized_data',
                'access_token' => 'ttp_tiktok_access_token',
                'videos' => 'ttp_tiktok_videos',
                'user_info' => 'ttp_tiktok_user_info',
            ];

            $this->register();
        }

        public function register()
        {
            add_action('admin_init', [$this, 'admin_init']);
            add_action('init', [$this, 'init']);
            // ajax
            add_action('wp_ajax_ttp_tiktok_videos', [$this, 'ttp_tiktok_videos']);
            add_action('wp_ajax_nopriv_ttp_tiktok_videos', [$this, 'ttp_tiktok_videos']);

            add_action('wp_ajax_ttp_tiktok_clear', [$this, 'ttp_tiktok_clear']);
        }

        public function ttp_tiktok_videos()
        {
            if (!wp_verify_nonce(sanitize_text_field($_GET['nonce']), 'wp_rest')) {
                wp_die();
            }

            $max_count = sanitize_text_field($_GET['max_count']) ?? 20;
            $cursor = sanitize_text_field($_GET['cursor']) ?? false;
            $videoCacheTime = sanitize_text_field($_GET['videoCacheTime']) ?? false;
            $profileCacheTime = sanitize_text_field($_GET['profileCacheTime']) ?? false;
            $key = sanitize_text_field($_GET['key']) ?? false;
            $device = sanitize_text_field($_GET['device']) ?? 'mobile';

            if (isset($_GET['action'])) {
                echo wp_kses_post($this->getData($key, $max_count, $cursor, $videoCacheTime, $profileCacheTime, $device));
            }
            wp_die();
        } // ttp_tiktok_videos

        /**
         * Undocumented function
         *
         * @param string $key
         * @param integer $max_count
         * @param boolean $cursor
         * @param [type] $videoCacheTime
         * @param [type] $profileCacheTime
         * @return void
         */
        public function getData($key, $max_count, $cursor, $videoCacheTime, $profileCacheTime, $device = 'mobile')
        {
            $access_token = get_transient("ttp_tiktok_access_token");
            $version = get_option('tiktok_api_version', 'v2');

            $endpoint = $this->video_list_endpoint;

            // if ($version === 'v2') {
            //     $endpoint = ''
            // }

            if (!$access_token) {
                if (is_admin()) {
                    $access_token = $this->refetch_token();
                    if (!$access_token) {
                        return wp_json_encode(['videos' => [], 'user_info' => []]);
                    }
                    return;
                }
            }

            $videos = get_transient($key . '_ttp_tiktok_videos_' . $device);
            $user_info = get_transient('ttp_tiktok_user_info');

            if (false === $videos || $cursor) {
                $body = [
                    'max_count' => $max_count,
                ];
                if ($cursor) {
                    $body['cursor'] = $cursor;
                }
                $response = wp_remote_post($this->video_list_endpoint . $this->video_list_fields, [
                    'method' => 'POST',
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer $access_token",
                    ),
                    'body' => json_encode($body),
                ]);

                $response = json_decode(wp_remote_retrieve_body($response), true);

                $videos = [];
                if (isset($response['data']['videos']) && $response['data']['videos']) {
                    $videos = $response['data'];
                    if (!$cursor) {
                        set_transient($key . '_ttp_tiktok_videos', $response['data'], $videoCacheTime);
                    }
                }
            }

            if (false === $user_info) {
                $response = wp_remote_get($this->user_info_endpoint . $this->user_info_fields, [
                    'timeout' => 45,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer $access_token",
                    ),
                ]);
                $response = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($response['data'])) {
                    $user_info = $response['data'];
                    set_transient('ttp_tiktok_user_info', $response['data'], $profileCacheTime);
                }
            }

            $accessToken = $access_token;

            return wp_send_json(compact('videos', 'user_info', 'accessToken', 'access_token'));
        } // getData

        public function ttp_tiktok_clear()
        {
            if (!wp_verify_nonce(sanitize_text_field($_GET['nonce']), 'wp_rest')) {
                echo wp_kses_post(wp_json_encode(['invalid nonce']));
                wp_die();
            }

            $action = sanitize_text_field($_GET['action_type']) ?? 'clear_cache';
            $max_count = sanitize_text_field($_GET['max_count']) ?? 20;
            $videoCacheTime = sanitize_text_field($_GET['videoCacheTime']) ?? false;
            $profileCacheTime = sanitize_text_field($_GET['profileCacheTime']) ?? false;
            $key = sanitize_text_field($_GET['key']) ?? false;
            $device = sanitize_text_field($_GET['device']) ?? 'mobile';

            if ($action === 'clear_cache') {
                delete_transient($key . '_ttp_tiktok_videos_' . $device);
                delete_transient('ttp_tiktok_user_info');
                echo wp_kses_post($this->getData($key, $max_count, false, $videoCacheTime, $profileCacheTime));
            }

            if ($action === 'unauthorized') {

                foreach ($this->transient as $transient) {
                    delete_transient($transient);
                }
                delete_transient($key . '_ttp_tiktok_videos_' . $device);

                echo wp_kses_post(wp_json_encode(['videos' => [], 'user_info' => []]));
            }
            wp_die();
        } // ttp_tiktok_clear

        /**
         * Undocumented function
         * @return void
         */
        public function admin_init()
        {
            if (isset($_GET['data'])) {
                $data = json_decode(stripslashes(sanitize_text_field($_GET['data'])), true);
                if (isset($data['data'])) {
                    $data = $data['data'];
                }
                set_transient('ttp_tiktok_authorized_data', $data, $data['refresh_expires_in']);
                set_transient('ttp_tiktok_access_token', $data['access_token'], 60 * 60 * 20);
                // update_option('tiktok_api_version', 'v2');
            }
        }

        /**
         * Undocumented function
         *
         * @return void
         */
        public function init()
        {
            $tiktok_info = get_transient('ttp_tiktok_authorized_data');
            if (false === get_transient('ttp_tiktok_access_token') && $tiktok_info) {
                $response = wp_remote_post('https://api. bplugins.com/wp-json/tiktok/v1/refresh-token', [
                    'method' => 'POST',
                    'body' => [
                        'refresh_token' => $tiktok_info['refresh_token'],
                    ],
                ]);

                $response = json_decode(wp_remote_retrieve_body($response), true);
                if($response) {
                    set_transient('ttp_tiktok_access_token', $response['access_token'], 60 * 60 * 20);
                }
            }
        }

        public function refetch_token()
        {
            $tiktok_info = get_transient('ttp_tiktok_authorized_data');
            if (false == get_transient('ttp_tiktok_access_token') && $tiktok_info) {
                $response = wp_remote_post('https://api.bplugins.com/wp-json/tiktok/v1/refresh-token', [
                    'method' => 'POST',
                    'body' => [
                        'refresh_token' => $tiktok_info['refresh_token'],
                    ],
                ]);

                $response = json_decode(wp_remote_retrieve_body($response), true);
                set_transient('ttp_tiktok_access_token', $response['access_token'], 60 * 60 * 20);
                return $response['access_token'];
            }
            return false;
        }
    }
    new TTPTiktokAPI();
}