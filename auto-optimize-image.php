<?php
/**
Plugin Name: Auto optimize image
Author: Wayne
Version: 1.0.0
*/

function autoOptimizeImage_register_options_page(): void {
    add_menu_page('Auto optimize image', 'Auto optimize image', 'manage_options', 'auto-optimize-image', 'autoOptimizeImage_backendOptions', '', 4.0);
}
add_action('admin_menu', 'autoOptimizeImage_register_options_page');

function autoOptimizeImage_register_admin_resources(): void {
    wp_register_style( 'autoOptimizeImage-admin-style',  plugins_url( '/css/admin.css', __FILE__ ), array(), null) ;
    wp_enqueue_style('autoOptimizeImage-admin-style');
}
add_action( 'admin_enqueue_scripts', 'autoOptimizeImage_register_admin_resources' );

function autoOptimizeImage_backendOptions(): void {
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
                <h1><?= esc_html( __( 'Configuration' ) ); ?></h1>
                <hr>
                <form action="" method="post" class="data-options">
                    <div class="field required">
                        <label for="api_key"><?= esc_html( __( 'Api Key' ) ); ?></label><br />
                        <input id="api_key" type="text" name="api_key" value="<?= $apiKey ?>">
                    </div>
                    <div class="field actions">
                        <button type="submit" data-action="update"><?= __('Save') ?></button>
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

function autoOptimizeImage_saveOptions(): void {
    $data = [
        "status" => 'error',
        "message" => __('Have Error! Please try again')
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
        $data["message"] = __('Save Info Success');

    }
    wp_send_json_success($data);
}
add_action('wp_ajax_autoOptimizeImage_saveOptions', 'autoOptimizeImage_saveOptions');
add_action('wp_ajax_nopriv_autoOptimizeImage_saveOptions', 'autoOptimizeImage_saveOptions');

function autoOptimizeImage_getOptions(): array {
    $data = get_option('auto_optimize_image_options');
    if ($data != '') {
        $data = json_decode($data);

        return (array) $data;
    }

    return [];
}

function autoOptimizeImage_handle_upload(array $file): array {
    $responseOptimize = autoOptimizeImage_optimize_request($file);
    if ($responseOptimize) {
        $responseOptimize = (array) json_decode($responseOptimize);
        $responseOptimizeOutput = (array) $responseOptimize['output'];
        $responseDownload = autoOptimizeImage_download_request($responseOptimizeOutput['url']);
        file_put_contents($file['tmp_name'], $responseDownload);
    }

    return $file;
}
add_filter('wp_handle_upload_prefilter', 'autoOptimizeImage_handle_upload' );

function autoOptimizeImage_optimize_request(array $file): ?string {
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($file['tmp_name']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' . $file['type'],
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

function autoOptimizeImage_download_request(string $url): ?string {
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
