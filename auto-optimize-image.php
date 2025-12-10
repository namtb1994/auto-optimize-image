<?php
/**
Plugin Name: Auto optimize image
Author: Wayne
Version: 1.0.2
*/

function autoOptimizeImage_register_options_page(): void
{
    add_menu_page(__('Auto optimize image', 'autoOptimizeImage'), __('Auto optimize image', 'autoOptimizeImage'), 'manage_options', 'auto-optimize-image', 'autoOptimizeImage_backendOptions', '', 4.0);
}
add_action('admin_menu', 'autoOptimizeImage_register_options_page');

function autoOptimizeImage_register_admin_resources(): void
{
    wp_register_style( 'autoOptimizeImage-admin-style',  plugins_url( '/css/admin.css', __FILE__ ), array(), null) ;
    wp_enqueue_style('autoOptimizeImage-admin-style');
}
add_action( 'admin_enqueue_scripts', 'autoOptimizeImage_register_admin_resources' );

function autoOptimizeImage_backendOptions(): void
{
    $dataOptions = autoOptimizeImage_getOptions();
    $apiKey = null;
    if (!empty($dataOptions)) {
        $apiKey = $dataOptions['api_key'];
    }
?>
    <div class="section-info-options container">
        <div class="box-loading">
            <div class="progress"></div>
        </div>
        <div class="row">
            <div class="save-info col-6">
                <h1><?= __( 'Configuration', 'autoOptimizeImage' ); ?></h1>
                <hr>
                <form action="" method="post" class="data-options">
                    <div class="field required">
                        <label for="api_key"><?= __( 'Api Key', 'autoOptimizeImage' ); ?></label><br />
                        <input id="api_key" type="text" name="api_key" value="<?= $apiKey ?>">
                    </div>
                    <div class="field actions">
                        <button type="submit" data-action="update"><?= __('Save', 'autoOptimizeImage') ?></button>
                    </div>
                </form>
                <div class="message"></div>
            </div>
        </div>
        <hr>
        <script type="text/javascript">
            function checkFieldsRequired(form) {
                var validation = true;
                form.find('.field.required').each(function() {
                    var input = jQuery(this).find('input');
                    var select = jQuery(this).find('select');
                    if (input.length && !input.val()) {
                        input.addClass('error');
                        if (validation) {
                            validation = false;
                        }
                    } else if (select.length && !select.val()) {
                        select.addClass('error');
                        if (validation) {
                            validation = false;
                        }
                    } else {
                        input.removeClass('error');
                        select.removeClass('error');
                    }
                });

                return validation;
            }

            jQuery('form.data-options').on('submit', function(e) {
                e.preventDefault();
                var thisForm = jQuery(this);
                if (!checkFieldsRequired(thisForm)) {
                    jQuery('.section-info-options .message').remove();
                    return;
                }
                jQuery('.section-info-options div.box-loading').addClass( "loading" );
                var url = '<?= admin_url( "admin-ajax.php" ) ?>';
                data = {
                    'action': 'autoOptimizeImage_saveOptions',
                    'data': thisForm.serializeArray()
                };
                jQuery.post( url, data, function( json ) {
                    if (json.success) {
                        jQuery('.section-info-options .message').html(json.data.message);
                        jQuery('.section-info-options .message').addClass(json.data.status);
                        jQuery('.section-info-options div.box-loading').removeClass( "loading" );
                    }
                });
            });
        </script>
    </div>
<?php
}

function autoOptimizeImage_saveOptions(): void
{
    $data = [
        "status" => 'error',
        "message" => __('Have Error! Please try again', 'autoOptimizeImage')
    ];
    if ( isset($_REQUEST['data']) ) {
        $dataInfo = [];
        foreach ($_REQUEST['data'] as $item) {
            if (strpos($item['name'], '_arr') !== false) {
                $dataInfo[$item['name']][] = $item['value'];
            } else {
                $dataInfo[$item['name']] = $item['value'];
            }
        }
        $infoOption = get_option('auto_optimize_image_options');
        if ($infoOption !== false) {
            update_option( 'auto_optimize_image_options', json_encode($dataInfo), '', 'yes' );
        } else {
            add_option( 'auto_optimize_image_options', json_encode($dataInfo), '', 'yes' );
        }
        $data["status"] = 'updated';
        $data["message"] = __('Save Info Success', 'autoOptimizeImage');

    }
    wp_send_json_success($data);
}
add_action('wp_ajax_autoOptimizeImage_saveOptions', 'autoOptimizeImage_saveOptions');
add_action('wp_ajax_nopriv_autoOptimizeImage_saveOptions', 'autoOptimizeImage_saveOptions');

function autoOptimizeImage_getOptions(): array
{
    $data = get_option('auto_optimize_image_options');
    if ($data != '') {
        $data = json_decode($data);

        return (array) $data;
    }

    return [];
}

function autoOptimizeImage_handle_add_attachment(int $attachmentId): void
{
    if (wp_attachment_is_image($attachmentId)) {
        $filePath = get_attached_file($attachmentId);
        if ($filePath) {
            $fileData = [
                'type' => get_post_mime_type($attachmentId),
                'path' => $filePath,
            ];
            $responseOptimize = autoOptimizeImage_optimize_request($fileData);

            if ($responseOptimize) {
                $responseOptimize = (array) json_decode($responseOptimize);
                $responseOptimizeOutput = (array) $responseOptimize['output'];
                $responseDownload = autoOptimizeImage_download_request($responseOptimizeOutput['url']);
                file_put_contents($filePath, $responseDownload);
                update_post_meta($attachmentId, 'optimized', '1');
            }
        }
    }
}
add_action('add_attachment', 'autoOptimizeImage_handle_add_attachment' );

function autoOptimizeImage_optimize_request(array $fileData): ?string
{
    $dataOptions = autoOptimizeImage_getOptions();
    $apiKey = null;

    if (!empty($dataOptions)) {
        $apiKey = $dataOptions['api_key'];
    }

    if (!$apiKey) {
        return null;
    }

    $apiUrl = "https://api.tinify.com/shrink";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_USERPWD, "api:$apiKey");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($fileData['path']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' . $fileData['type'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 201) {
        return $response;
    } else {
        return null;
    }
}

function autoOptimizeImage_download_request(string $url): ?string
{
    $dataOptions = autoOptimizeImage_getOptions();
    $apiKey = null;

    if (!empty($dataOptions)) {
        $apiKey = $dataOptions['api_key'];
    }

    if (!$apiKey) {
        return null;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERPWD, "api:$apiKey");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        return $response;
    } else {
        return null;
    }
}

function autoOptimizeImage_add_custom_media_column(array $columns): array
{
    $columns['optimized'] = 'Optimized';

    return $columns;
}
add_filter('manage_media_columns', 'autoOptimizeImage_add_custom_media_column');

function autoOptimizeImage_display_custom_media_column(string $columnName, int $postId): void
{
    if ($columnName == 'optimized') {
        if (!wp_attachment_is_image($postId)) {
            echo __('N/A', 'autoOptimizeImage');
        } else {
            $optimized = get_post_meta($postId, 'optimized', true);
            echo $optimized ? __('Yes', 'autoOptimizeImage') : __('No', 'autoOptimizeImage');
        }
    }
}
add_action('manage_media_custom_column', 'autoOptimizeImage_display_custom_media_column', 10, 2);

function autoOptimizeImage_add_optimized_filter_to_media_library(): void
{
    $screen = get_current_screen();
    if ($screen->id == 'upload') {
        $value = isset($_GET['optimized_filter']) ? $_GET['optimized_filter'] : '';
        ?>
        <select name="optimized_filter">
            <option value=""><?= __('All', 'autoOptimizeImage'); ?></option>
            <option value="1" <?php selected($value, '1'); ?>><?= __('Images Optimized', 'autoOptimizeImage'); ?></option>
            <option value="0" <?php selected($value, '0'); ?>><?= __('Images Not Optimized', 'autoOptimizeImage'); ?></option>
        </select>
        <?php
    }
}
add_action('restrict_manage_posts', 'autoOptimizeImage_add_optimized_filter_to_media_library');

function autoOptimizeImage_filter_media_library_by_optimized(WP_Query $query): void
{
    if (
        is_admin()
        && $query->is_main_query()
        && $query->get('post_type') == 'attachment'
        && isset($_GET['optimized_filter'])
        && $_GET['optimized_filter'] !== ''
    ) {
        $metaQuery = [
            'relation' => 'OR',
            [
                'key'   => 'optimized',
                'value' => $_GET['optimized_filter'],
                'compare' => '=',
            ],
        ];

        if ($_GET['optimized_filter'] == '0') {
            $metaQuery[] = [
                'key'     => 'optimized',
                'compare' => 'NOT EXISTS',
            ];
        }

        $query->set('post_mime_type', 'image');
        $query->set('meta_query', $metaQuery);
    }
}
add_action('pre_get_posts', 'autoOptimizeImage_filter_media_library_by_optimized');

function autoOptimizeImage_register_bulk_actions(array $bulkActions): array
{
    $bulkActions['optimize'] = __('Optimize', 'autoOptimizeImage');

    return $bulkActions;
}
add_filter('bulk_actions-upload', 'autoOptimizeImage_register_bulk_actions');

function autoOptimizeImage_handle_bulk_actions(string $redirectTo, string $doaction, array $postIds): string
{
    if ($doaction === 'optimize') {
        $count = 0;
        foreach ($postIds as $postId) {
            $optimized = get_post_meta($postId, 'optimized', true);

            if ($optimized === '1' || !wp_attachment_is_image($postId)) {
                continue;
            }

            $filePath = get_attached_file($postId);
            $fileData = [
                'type' => get_post_mime_type($postId),
                'path' => $filePath,
            ];
            $responseOptimize = autoOptimizeImage_optimize_request($fileData);

            if ($responseOptimize) {
                $responseOptimize = (array) json_decode($responseOptimize);
                $responseOptimizeOutput = (array) $responseOptimize['output'];
                $responseDownload = autoOptimizeImage_download_request($responseOptimizeOutput['url']);
                file_put_contents($filePath, $responseDownload);
                $attachmentMeta = wp_get_attachment_metadata($postId);
                $attachmentMeta['filesize'] = filesize($filePath);
                wp_update_attachment_metadata($postId, $attachmentMeta);
                update_post_meta($postId, 'optimized', '1');
            }
            
            $count++;
        }

        $redirectTo = add_query_arg('bulk_optimize', $count, $redirectTo);
    }

    return $redirectTo;
}
add_filter('handle_bulk_actions-upload', 'autoOptimizeImage_handle_bulk_actions', 10, 3);

function autoOptimizeImage_bulk_action_admin_notice(): void
{
    if (!empty($_REQUEST['bulk_optimize'])) {
        $count = intval($_REQUEST['bulk_optimize']);
        printf('<div id="message" class="updated notice is-dismissible"><p>' .
            _n('Optimize for %s image.', 'Optimize for %s images.', $count, 'autoOptimizeImage') . '</p></div>', $count);
    }
}
add_action('admin_notices', 'autoOptimizeImage_bulk_action_admin_notice');
