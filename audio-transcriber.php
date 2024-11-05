<?php
/*
Plugin Name: Audio Transcriber
Description: A simple plugin to upload and transcribe audio files to a WordPress post.
Version: 1.0
Author: Kade
*/

// Enqueue audio-transcriber styling sheet
function audio_transcriber_enqueue_styles() {
    wp_enqueue_style('audio-transcriber-styles', plugin_dir_url(__FILE__) . 'audio-transcriber.css');
}
add_action('admin_enqueue_scripts', 'audio_transcriber_enqueue_styles');

// Enqueue media uploader script
function audio_transcriber_enqueue_media() {
    wp_enqueue_media();
}
add_action('admin_enqueue_scripts', 'audio_transcriber_enqueue_media');


// Add Audio Transcriber menu item
function audio_transcriber_add_menu_page() {
    add_menu_page(
        'Audio Transcriber',        // Page title
        'Audio Transcriber',        // Menu title
        'manage_options',           // Reserved for admin users
        'audio-transcriber',        // Menu slug
        'audio_transcriber_display_page', // Callback function
        'dashicons-media-audio',    // Icon URL
        6                           // Position
    );
}
add_action('admin_menu', 'audio_transcriber_add_menu_page');

// Display the Audio Transcriber page content
function audio_transcriber_display_page() {
    ?>
    <div class="wrap">
    <h1>Audio Transcriber</h1>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
        
    <div class="audio-file-container">
        <label for="audioFile">Select audio file:</label>
        <input type="file" name="audioFile" id="audioFile" accept="audio/*">
        <div class="media-library-container">
            <button type="button" id="selectFromMediaLibrary" class="button">Choose from Media Library</button>
            <input type="hidden" name="mediaFile" id="mediaFile">
        </div>
    </div>
     
    <div class="post-title-container">
        <label for="postTitle">Post Title:</label>
        <input type="text" name="postTitle" id="postTitle" required>
    </div>
        
    <div class="post-type-container">
        <label for="postType">Select Post Type:</label>
        <?php
        $post_types = get_post_types(array('public' => true), 'objects');
        $selected_post_type = isset($_POST['postType']) ? sanitize_text_field($_POST['postType']) : 'post'; // Default to 'post'
        echo '<select name="postType" id="postType">';
        foreach ($post_types as $post_type) {
            echo '<option value="' . esc_attr($post_type->name) . '"' . selected($selected_post_type, $post_type->name, false) . '>' . esc_html($post_type->labels->singular_name) . '</option>';
        }
        echo '</select>';
        ?>
    </div>
  
    <div class="upload-field-container">
        <input type="submit" id="upload-transcribe-button" value="Upload and Transcribe">
        <input type="hidden" name="action" value="handle_audio_upload">
    </div>
        
    <script>
    jQuery(document).ready(function($) {
        $('#selectFromMediaLibrary').on('click', function(e) {
            e.preventDefault();
            var mediaUploader = wp.media({
                title: 'Choose Audio File',
                button: {
                    text: 'Choose File'
                },
                multiple: false
            }).on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#mediaFile').val(attachment.url);
            }).open();
        });
    });
    </script>
    <?php
}

// Add a settings submenu item
function audio_transcriber_add_settings_page() {
    add_submenu_page(
        'audio-transcriber',         // Parent slug (matches the main menu slug)
        'Settings',                  // Page title
        'Settings',                  // Menu title
        'manage_options',            // Capability
        'audio-transcriber-settings',// Menu slug
        'audio_transcriber_settings_page' // Callback function
    );
}
add_action('admin_menu', 'audio_transcriber_add_settings_page');

// Update Setttings
function audio_transcriber_settings_page() {
    // Check if settings have been updated
    if (isset($_POST['audio_transcriber_api_key'])) {
        // Save the settings manually
        $api_key = sanitize_text_field($_POST['audio_transcriber_api_key']);
        update_option('audio_transcriber_api_key', $api_key);
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    // Get current settings
    $api_key = get_option('audio_transcriber_api_key', '');

    // Display the settings page content
    ?>
   <div class="wrap">
    <h1>Audio Transcriber Settings</h1>
    <form method="post" action="">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <div class="api-key-wrapper">
                        <label for="api_key">API Key:</label>
                        <input type="text" id="api_key" name="audio_transcriber_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                    </div>
                </th>
                <td>
                    
                </td>
            </tr>
        </table>
        <?php
            echo '<input type="submit" id="save-settings-button" class="button button-primary" value="Save Settings">';
            ?>
    </form>
<?php
}


// Handle file upload and create a post
function handle_audio_upload() {
    if (!empty($_FILES['audioFile']['name']) || !empty($_POST['mediaFile'])) {
        if (!empty($_FILES['audioFile']['name'])) {
            // Handle local file upload
            $uploaded_file = $_FILES['audioFile'];
            $post_title = sanitize_text_field($_POST['postTitle']);
            $post_type = sanitize_text_field($_POST['postType']); // Get selected post type

            if ($uploaded_file['error'] != UPLOAD_ERR_OK) {
                wp_die('An error occurred while uploading the file.');
            }

            $upload_overrides = array('test_form' => false);
            $movefile = wp_handle_upload($uploaded_file, $upload_overrides);

            if ($movefile && !isset($movefile['error'])) {
                $file_path = $movefile['file'];
            } else {
                wp_die($movefile['error']);
            }
        } else {
            // Handle media library file
            $file_path = download_url($_POST['mediaFile']);
            if (is_wp_error($file_path)) {
                wp_die('An error occurred while downloading the file from the media library.');
            }
        }

        // Process the file with the transcription API
        $url = 'https://api.lemonfox.ai/v1/audio/transcriptions';
        $apiKey = get_option('audio_transcriber_api_key');
        if (empty($apiKey)) {
            wp_die('API key is missing. Please set it in the Audio Transcriber Settings.');
        }
        $language = 'english';
        $responseFormat = 'text';

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $apiKey
            ),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(
                'file' => new CURLFile($file_path),
                'language' => $language,
                'response_format' => $responseFormat
            )
        ));

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            wp_die('Error: ' . curl_error($curl));
        } else {
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($http_code == 401) {
                wp_die('Invalid API key. Please check your API key and try again.');
            } elseif ($http_code != 200) {
                wp_die('An error occurred while processing the request. HTTP Status Code: ' . $http_code);
            }

            $transcription_text = sanitize_text_field($response);
            
            // Format the content as a paragraph block
            $post_content = '<!-- wp:paragraph -->' . PHP_EOL;
            $post_content .= '<p>' . $transcription_text . '</p>' . PHP_EOL;
            $post_content .= '<!-- /wp:paragraph -->';

            $new_post = array(
                'post_title'   => $post_title,
                'post_content' => $post_content,
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
                'post_type'    => $post_type, // Use selected post type
            );

            $post_id = wp_insert_post($new_post);

            if ($post_id) {
                wp_redirect(get_permalink($post_id));
                exit; // Always call exit after wp_redirect to stop the script execution
            } else {
                wp_die('Error creating post.');
            }
        }

        // Clean up temporary file if needed
        if (!empty($_POST['mediaFile'])) {
            unlink($file_path);
        }
    } else {
        wp_die('No file was uploaded.');
    }
}

add_action('admin_post_handle_audio_upload', 'handle_audio_upload');
?>

