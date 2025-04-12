<?php
/*
Plugin Name: Tag Up - Auto Tags
Description: Generate and choose tags for your post using together AI and Google Trends.
Version: 0.1
Author: Nuh Furkan Erturk
*/
add_action('add_meta_boxes', 'together_add_tag_meta_box');
add_action('admin_enqueue_scripts', 'together_enqueue_scripts');
add_action('wp_ajax_together_generate_tags', 'together_ajax_generate_tags');
add_action('save_post', 'together_save_selected_tags');
add_action('admin_menu', 'together_add_settings_menu');
add_action('admin_init', 'together_register_settings');


function together_add_tag_meta_box() {
    add_meta_box(
        'together_tag_box',
        'Tag Up',
        'together_render_tag_box',
        'post',
        'side',
        'default'
    );
}

function together_render_tag_box($post) {
    wp_nonce_field('together_tags_nonce_action', 'together_tags_nonce');
    ?>
    <div id="together-tag-box">
        <button type="button" class="button" id="generate-tags-button">Generate Tags</button>
        <p id="together-loading" style="display:none;">Generating...</p>
        <div id="together-tags-container"></div>
        <input type="hidden" name="together_selected_tags" id="together-selected-tags">
    </div>
    <?php
}

function together_enqueue_scripts($hook) {
    if ($hook !== 'post.php' && $hook !== 'post-new.php') return;

    wp_enqueue_script('together-tags-js', plugin_dir_url(__FILE__) . 'together-tags.js', ['jquery'], null, true);
    wp_localize_script('together-tags-js', 'together_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('together_ajax_nonce')
    ]);
}

function together_ajax_generate_tags() {
    check_ajax_referer('together_ajax_nonce', 'nonce');

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') wp_send_json_error('Invalid post');

    $title = $post->post_title;
    $content = $post->post_content;

    $api_key = get_option('together_api_key');
    $language = get_option('together_language', 'en');
    $country = get_option('together_country', 'US');
	
    $content = preg_replace('#https?://[^\s]+#', '', $content);
		
	$content = preg_replace('#https?://[^\s]+#', '', $content);

    $ai_tags = together_call_api_for_tags($title, $content, $api_key, $language, $country);

    if (!is_array($ai_tags)) $ai_tags = [];

    // ✅ Fetch existing tag names properly
    $current_tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
	
    // ✅ Merge and deduplicate
    $merged_tags = array_unique(array_merge($current_tags, $ai_tags));
	
    // ✅ Set all tags (append mode)
    wp_set_post_tags($post_id, $merged_tags, false);

    wp_send_json_success($ai_tags);
}


function getArticleKeywords($apiKey, $userContent) {
    $url = "https://api.together.xyz/v1/chat/completions";

    $headers = [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json"
    ];

    $data = [
        "model" => "meta-llama/Llama-4-Maverick-17B-128E-Instruct-FP8",
        "messages" => [
            [
                "role" => "system",
                "content" => "You are a helpful assistant that responds **only in JSON**. Never include explanations. Generate an output like {\"keywords\": []} for wordpress article tags give no more than 15 keywords-or phrases."
            ],
            [
                "role" => "user",
                "content" => $userContent
            ]
        ],
        "temperature" => 0.5,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    if(curl_errno($ch)) {
        return 'Curl error: ' . curl_error($ch);
    }
    curl_close($ch);

    return json_decode($response, true);
}
 

function together_call_api_for_tags($title, $content, $api_key, $language, $country) {
    $message = json_encode([
        'title' => $title,
        'content' => $content,
        'language' => $language,
        'country' => $country
    ]); 

    $response = getArticleKeywords($api_key, $message);

	$json_answer = json_decode($response["choices"][0]["message"]["content"], JSON_PRETTY_PRINT);
	
    if (is_wp_error($response)) return [];

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return $json_answer['keywords'] ?? [];
}

function together_save_selected_tags($post_id) {
    if (!isset($_POST['together_tags_nonce']) || !wp_verify_nonce($_POST['together_tags_nonce'], 'together_tags_nonce_action')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (!empty($_POST['together_selected_tags'])) {
        $tags = explode(',', sanitize_text_field($_POST['together_selected_tags']));
        wp_set_post_tags($post_id, $tags, false);
    }
}

function together_add_settings_menu() {
    add_options_page(
        'Tag Up Settings',
        'Tag Up',
        'manage_options',
        'together-settings',
        'together_render_settings_page'
    );
}

function together_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Tag Up Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('together_settings_group');
            do_settings_sections('together-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function together_register_settings() {
    register_setting('together_settings_group', 'together_api_key');
    register_setting('together_settings_group', 'together_language');
    register_setting('together_settings_group', 'together_country');

    add_settings_section('together_main_section', 'API Configuration', null, 'together-settings');

    add_settings_field('together_api_key', 'together API Key', function() {
        $value = esc_attr(get_option('together_api_key', ''));
        echo "<input type='text' name='together_api_key' value='$value' style='width: 400px'>";
    }, 'together-settings', 'together_main_section');

    add_settings_field('together_language', 'Language (e.g. en, fr)', function() {
        $value = esc_attr(get_option('together_language', 'en'));
        echo "<input type='text' name='together_language' value='$value'>";
    }, 'together-settings', 'together_main_section');

    add_settings_field('together_country', 'Country (e.g. US, FR)', function() {
        $value = esc_attr(get_option('together_country', 'US'));
        echo "<input type='text' name='together_country' value='$value'>";
    }, 'together-settings', 'together_main_section');
}
?>
