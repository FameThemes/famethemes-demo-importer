<?php
/*
Plugin Name: FameTheme Demo Importer
Plugin URI: https://github.com/FameThemes/famethemes-demo-importer
Description: Demo data import tool for FameThemes's themes.
Author: famethemes
Author URI:  http://www.famethemes.com/
Version: 1.0.2
Text Domain: ftdi
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/


class FT_Demo_Content_Importer {
    public $dir;
    public $url;
    public $git_repo = 'https://raw.githubusercontent.com/FameThemes/famethemes-xml-demos/master/';
    function __construct( ){

        $this->url = trailingslashit( plugins_url('', __FILE__) );
        $this->dir = trailingslashit( plugin_dir_path( __FILE__) );

        require_once $this->dir.'inc/class-demo-content.php';
        add_action( 'wp_ajax_ft_demo_import_content', array( $this, 'ajax_import' ) );
        add_action( 'wp_ajax_ft_demo_import_download', array( $this, 'ajax_download' ) );
        add_action( 'wp_ajax_ft_demo_export', array( $this, 'ajax_export' ) );

        $template_slug = get_option( 'template' );
        $theme_slug = get_option( 'stylesheet' );
        // child theme active
        if ( $template_slug != $theme_slug ) {
            add_action( $template_slug.'_demo_import_content_tab', array( $this, 'display_import' ) );
        } else {
            add_action( $theme_slug.'_demo_import_content_tab', array( $this, 'display_import' ) );
        }

        add_action( 'customize_controls_print_footer_scripts', array( $this, 'update_customizer_keys' ) );
        if ( is_admin() ) {
            add_action( 'admin_enqueue_scripts', array( $this, 'css' ) );
        }

    }

    function update_customizer_keys(){
        $key = 'ft_demo_customizer_keys';
        $theme_slug = get_option( 'stylesheet' );
        $data = get_option( $key );
        if ( ! is_array( $data ) ) {
            $data = array();
        }

        global $wp_customize;

        $pages           = get_pages();
        $option_pages    = array();
        $option_pages[0] = esc_html__( 'Select page', 'ftdi' );
        foreach( $pages as $p ){
            $option_pages[ $p->ID ] = $p->post_title;
        }

        foreach (  $wp_customize->controls() as $k => $c )  {
            if ( $c->type == 'repeatable' ) {
                $keys = array();
                if ( property_exists( $c, 'fields' ) ){
                    foreach ( $c->fields as $field_id => $field ) {
                        if( isset( $field['options'] ) && is_array( $field['options'] ) ) {
                            $result = array_diff( $option_pages, $field['options'] );
                            if ( empty( $result ) ) { // if this option use page
                                $info = array(
                                    'key' => $field_id,
                                    'type' => 'page',
                                );
                                array_push( $keys, $info );
                            }
                        } elseif( isset( $field['data_type'] ) && in_array( $field['data_type'] , array( 'post', 'page' ) ) ) {
                            $info = array(
                                'key' => $field_id,
                                'type' => 'page',
                            );
                            array_push( $keys, $info );
                        } elseif( $field['type'] == 'media' ) {
                            $info = array(
                                'key' => $field_id,
                                'type' => 'media',
                            );
                            array_push( $keys, $info );
                        }
                    }
                }

                $data[ $theme_slug ][ $k ] = $keys;

            } else  if ( $c->type == 'media' ) { // wp media
                $data[ $theme_slug ][ $k ] = $c->type;
            }

        }
        update_option( $key, $data );
    }

    function get_recommend_plugins(){
        $recommend_plugins = get_theme_support( 'recommend-plugins' );
        if ( is_array( $recommend_plugins ) && isset( $recommend_plugins[0] ) ){
            $recommend_plugins = $recommend_plugins[0];
        } else {
            $recommend_plugins[] = array();
        }

        return $recommend_plugins;
    }

    function has_inactive_recommend_plugins( $recommend_plugins = false ){
        if ( ! $recommend_plugins ) {
            $recommend_plugins = $this->get_recommend_plugins( ) ;
        }
        $all_active = true;
        if ( ! empty( $recommend_plugins ) ) {
            foreach ( $recommend_plugins as $plugin_slug => $plugin_info ) {
                $plugin_info = wp_parse_args( $plugin_info, array(
                    'name' => '',
                    'active_filename' => '',
                ) );
                if ( $plugin_info['active_filename'] ) {
                    $active_file_name = $plugin_info['active_filename'] ;
                } else {
                    $active_file_name = $plugin_slug . '/' . $plugin_slug . '.php';
                }
                if ( ! is_plugin_active( $active_file_name ) ) {
                    $all_active = false;
                }
            }
        }

        return ( $all_active ) ? false : true;
    }

    function render_recommend_plugins( $recommend_plugins = false ){
        if ( ! $recommend_plugins ) {
            $recommend_plugins = $this->get_recommend_plugins( ) ;
        }

        if ( empty( $recommend_plugins ) ) {
            return false;
        }
        foreach ( $recommend_plugins as $plugin_slug => $plugin_info ) {
            $plugin_info = wp_parse_args( $plugin_info, array(
                'name' => '',
                'active_filename' => '',
            ) );
            $plugin_name = $plugin_info['name'];
            $status = is_dir( WP_PLUGIN_DIR . '/' . $plugin_slug );
            $button_class = 'install-now button';
            if ( $plugin_info['active_filename'] ) {
                $active_file_name = $plugin_info['active_filename'] ;
            } else {
                $active_file_name = $plugin_slug . '/' . $plugin_slug . '.php';
            }

            if ( ! is_plugin_active( $active_file_name ) ) {
                $button_txt = esc_html__( 'Install Now', 'ftdi' );
                if ( ! $status ) {
                    $install_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action' => 'install-plugin',
                                'plugin' => $plugin_slug
                            ),
                            network_admin_url( 'update.php' )
                        ),
                        'install-plugin_'.$plugin_slug
                    );

                } else {
                    $install_url = add_query_arg(array(
                        'action' => 'activate',
                        'plugin' => rawurlencode( $active_file_name ),
                        'plugin_status' => 'all',
                        'paged' => '1',
                        '_wpnonce' => wp_create_nonce('activate-plugin_' . $active_file_name ),
                    ), network_admin_url('plugins.php'));
                    $button_class = 'activate-now button-primary';
                    $button_txt = esc_html__( 'Active Now', 'ftdi' );
                }

                $detail_link = add_query_arg(
                    array(
                        'tab' => 'plugin-information',
                        'plugin' => $plugin_slug,
                        'TB_iframe' => 'true',
                        'width' => '772',
                        'height' => '349',

                    ),
                    network_admin_url( 'plugin-install.php' )
                );

                echo '<div class="rcp">';
                echo '<h4 class="rcp-name">';
                echo esc_html( $plugin_name );
                echo '</h4>';
                echo '<p class="action-btn plugin-card-'.esc_attr( $plugin_slug ).'"><a href="'.esc_url( $install_url ).'" data-slug="'.esc_attr( $plugin_slug ).'" class="'.esc_attr( $button_class ).'">'.$button_txt.'</a></p>';
                echo '<a class="plugin-detail thickbox open-plugin-details-modal" href="'.esc_url( $detail_link ).'">'.esc_html__( 'Details', 'ftdi' ).'</a>';
                echo '</div>';
            }

        }
    }


    function display_import(){
        $nonce = wp_create_nonce( 'ft_demo_import' );
        $url = admin_url('admin-ajax.php');
        $url = remove_query_arg( array( '_nonce', 'action' ) , $url );

        $current_item = apply_filters( 'ft_demo_import_current_item',  false );

        if ( ! $current_item ) {
            $current_item = get_option( 'template' );
            $current_item = untrailingslashit( $current_item );
        }

        $current_item_name = str_replace( array( '-', '_' ), ' ', $current_item );
        $current_item_name = ucwords( $current_item_name );


        $import_url = add_query_arg( array(
            '_nonce'    => $nonce,
            'action'    => 'ft_demo_import_content',
            'data'      => $current_item
        ), $url );

        $download_url = add_query_arg( array(
            '_nonce'    => $nonce,
            'action'    => 'ft_demo_import_download',
            'data'      => $current_item
        ), $url );

        $import_export_config_url = add_query_arg( array(
            '_nonce'    => $nonce,
            'action'    => 'ft_demo_export',
            'type'      => 'config',
            'data'      => $current_item
        ), $url );

        $this->js();

        $recommend_plugins = $this->get_recommend_plugins();

        ?>
        <div class="ft-import-box ft-import-welcome">
            <h3><?php esc_html_e( 'Welcome to FameThemes Demo Importer!', 'ftdi' ); ?></h3>
            <p>
                <?php esc_html_e( 'Importing demo data (post, pages, images, theme settings, ...) is the easiest way to setup your theme. It will allow you to quickly edit everything instead of creating content from scratch. When you import the data, the following things might happen:', 'ftdi' ); ?>
            </p>
            <ul>
                <li><?php esc_html_e( 'No existing posts, pages, categories, images, custom post types or any other data will be deleted or modified.', 'ftdi' ); ?></li>
                <li><?php esc_html_e( 'Posts, pages, images, widgets and menus will get imported.', 'ftdi' ); ?></li>
                <li><?php esc_html_e( 'Please click "Import Demo Data" button only once and wait, it can take a couple of minutes.', 'ftdi' ); ?></li>
            </ul>
            <p><?php esc_html_e( 'Notice: If your site already has content, please make sure you backup your database and WordPress files before import demo data.', 'ftdi' ); ?></p>
        </div>

        <?php if ( ! empty( $recommend_plugins ) && $this->has_inactive_recommend_plugins( $recommend_plugins ) ) { ?>
        <div id="plugin-filter" class="recommend-plugins">
            <h3><?php esc_html_e( 'Recommend Plugins', 'ftdi' ); ?></h3>
            <p><?php esc_html_e( 'To fully import demo content, please install and activate all recommend plugins before import.', 'ftdi' ); ?></p>
            <?php
            $this->render_recommend_plugins();
            ?>
        </div>
        <?php } ?>

        <?php if ( $this->check_data_exists( $current_item ) ){ ?>
            <div class="ft-import-box ft-import-theme">
                <p><?php printf( esc_html__( 'You are ready to import demo data for %1$s', 'ftdi' ), '<strong>'.esc_html( $current_item_name ).'</strong>' ); ?></p>
            </div>

            <div class="ft-import-action">
                <a class="button button-primary button-hero ft-demo-import-now" data-download="<?php echo esc_url( $download_url ); ?>" data-import="<?php echo esc_url( $import_url ); ?>" href="#"><?php esc_html_e( 'Import Demo Data', 'ftdi' ); ?></a>
                <?php if ( isset( $_REQUEST['export'] ) ) { ?>
                    <a class="button button-secondary ft-export-config" href="<?php echo esc_url( $import_export_config_url ); ?>"><?php esc_html_e( 'Export Config', 'ftdi' ); ?></a>
                <?php } ?>
            </div>
            <div class="ft-ajax-notice"></div>

        <?php } else { ?>
            <div class="ft-import-box ft-import-theme">
                <p><strong><?php esc_html_e( 'Notice:', 'ftdi' ); ?></strong> <?php esc_html_e( "No FameThemes's themes data detected, please make sure you are using one of our theme.", 'ftdi' ); ?></p>
            </div>
        <?php } ?>
        <?php

    }

    function js(){
        wp_enqueue_script( 'ft-demo-importer', $this->url.'assets/js/importer.js', array( 'jquery' ) );
        wp_localize_script( 'ft-demo-importer', 'FT_IMPORT_DEMO', array(
            'downloading' => esc_html__( 'Downloading...', 'ftid' ),
            'importing' => esc_html__( 'Importing...', 'ftid' ),
            'import' => esc_html__( 'Import Now', 'ftid' ),
            'import_again' => esc_html__( 'Import Again.', 'ftid' ),
            'imported' => esc_html__( 'Demo Data Imported !', 'ftid' ),
            'import_error' => esc_html__( 'Demo Data Import Error', 'ftid' ),
            'import_error_msg' => sprintf( esc_html__( 'Check your %1$s, demo data may imported.', 'ftid' ), '<a target="blank" href="'.home_url('/').'">'.esc_html__( 'front end', 'ftid' ).'</a>' ),
            'confirm_import' => esc_html__( 'Are you sure ?', 'ftid' ),
            'confirm_leave' => esc_html__( 'Importing script is running, do you want to stop it ?', 'ftid' ),
            'demo_imported' => sprintf( esc_html__( 'The demo import has finished. Please check your %1$s and make sure that everything has imported correctly. If it did, you can deactivate the FameThemes Demo Importer plugin, because it has done its job.', 'ftdi' ),
                '<a target="_blank" href="'.esc_url( home_url( '/' ) ).'">'.esc_html__( 'front page', 'ftdi' ).'</a>' ),
            'no_data_found' => esc_html__( 'No data found.', 'ftid' ),
            'demo_import_failed' => sprintf( esc_html__( 'Demo data import failed, please %1$s to get help.', 'ftdi' ), '<a target="_blank" href="https://www.famethemes.com/contact">'.esc_html__( 'contact us', 'ftdi' ).'</a>' ),

        ) );
    }

    function css( ){
        wp_enqueue_style( 'ft-demo-importer', $this->url . 'assets/css/importer.css', false );
    }

    function check_data_exists( $item_name ){
        $file =  $this->git_repo.$item_name.'/dummy-data.xml';
        $response = wp_remote_get( $file );
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 != $response_code ) {
            return false;
        }

        return true;
    }

    /**
     * Handles a side-loaded file in the same way as an uploaded file is handled by media_handle_upload().
     *
     * @since 2.6.0
     *
     * @param array  $file_array Array similar to a `$_FILES` upload array.
     * @param int    $post_id    The post ID the media is associated with.
     * @param string $desc       Optional. Description of the side-loaded file. Default null.
     * @param array  $post_data  Optional. Post data to override. Default empty array.
     * @return int|object The ID of the attachment or a WP_Error on failure.
     */
    static function media_handle_sideload( $file_array, $post_id, $desc = null, $post_data = array(), $save_attachment = true ) {
        $overrides = array(
            'test_form'=>false,
            'test_type'=>false
        );

        $time = current_time( 'mysql' );
        if ( $post = get_post( $post_id ) ) {
            if ( substr( $post->post_date, 0, 4 ) > 0 )
                $time = $post->post_date;
        }

        $file = wp_handle_sideload( $file_array, $overrides, $time );
        if ( isset($file['error']) )
            return new WP_Error( 'upload_error', $file['error'] );

        $url = $file['url'];
        $type = $file['type'];
        $file = $file['file'];
        $title = preg_replace('/\.[^.]+$/', '', basename($file));
        $content = '';

        if ( $save_attachment ) {
            if (isset($desc)) {
                $title = $desc;
            }

            // Construct the attachment array.
            $attachment = array_merge(array(
                'post_mime_type' => $type,
                'guid' => $url,
                'post_parent' => $post_id,
                'post_title' => $title,
                'post_content' => $content,
            ), $post_data);

            // This should never be set as it would then overwrite an existing attachment.
            unset($attachment['ID']);

            // Save the attachment metadata
            $id = wp_insert_attachment($attachment, $file, $post_id);

            return $id;
        } else {
            return $file;
        }
    }

    /**
     * Download image form url
     *
     * @return bool
     */
    static function download_file( $url, $name = '', $save_attachment = true ){
        if ( ! $url || empty ( $url ) ) {
            return false;
        }
        // These files need to be included as dependencies when on the front end.
        require_once (ABSPATH . 'wp-admin/includes/image.php');
        require_once (ABSPATH . 'wp-admin/includes/file.php');
        require_once (ABSPATH . 'wp-admin/includes/media.php');
        $file_array = array();
        // Download file to temp location.
        $file_array['tmp_name'] = download_url( $url );

        // If error storing temporarily, return the error.
        if ( empty( $file_array['tmp_name'] ) || is_wp_error( $file_array['tmp_name'] ) ) {
            return false;
        }

        if ( $name ) {
            $file_array['name'] = $name;
        }
        // Do the validation and storage stuff.
        $file_path_or_id = self::media_handle_sideload( $file_array, 0, null, array(), $save_attachment );

        // If error storing permanently, unlink.
        if ( is_wp_error( $file_path_or_id ) ) {
            @unlink( $file_array['tmp_name'] );
            return false;
        }

        return $file_path_or_id;
    }

    function download_dummy_files( $item_name ){
        $files = array(
            'dummy-data'    => 'xml', // file ext
            'config'        => 'json'
        );
        $downloaded_file = array();
        foreach ( $files as $k => $ext ) {
            $file = $item_name.'-'.$k.'.'.$ext;
            $file_path = self::download_file( $this->git_repo.$item_name.'/'.$k.'.'.$ext, $file, false );
            echo $file_path;
            $downloaded_file[ $k ] = $file_path;
        }

        return $downloaded_file;
    }



    function data_dir( $param ){

        $siteurl = get_option( 'siteurl' );
        $upload_path = trim( get_option( 'upload_path' ) );

        if ( empty( $upload_path ) || 'wp-content/uploads' == $upload_path ) {
            $dir = WP_CONTENT_DIR . '/uploads';
        } elseif ( 0 !== strpos( $upload_path, ABSPATH ) ) {
            // $dir is absolute, $upload_path is (maybe) relative to ABSPATH
            $dir = path_join( ABSPATH, $upload_path );
        } else {
            $dir = $upload_path;
        }

        if ( !$url = get_option( 'upload_url_path' ) ) {
            if ( empty($upload_path) || ( 'wp-content/uploads' == $upload_path ) || ( $upload_path == $dir ) )
                $url = WP_CONTENT_URL . '/uploads';
            else
                $url = trailingslashit( $siteurl ) . $upload_path;
        }


        $param['path']  = $dir . '/ft-dummy-data';
        $param['url']   = $url . '/ft-dummy-data';

        return $param;
    }


    function ajax_download(){
        $nonce = $_REQUEST['_nonce'];
        if ( ! wp_verify_nonce( $nonce, 'ft_demo_import' ) ) {
            die( 'Security check' );
        }
        add_filter('upload_dir', array( $this, 'data_dir' ), 99 );

        $item = wp_strip_all_tags( $_REQUEST['data'] );
        delete_transient( 'ft_demo_import_downloaded_'.$item );
        $import_files = $this->download_dummy_files( $item );
        set_transient( 'ft_demo_import_downloaded_'.$item, $import_files, 3 * HOUR_IN_SECONDS );

        remove_filter('upload_dir', array( $this, 'data_dir' ), 99 );

        wp_die('downloaded');
    }

    function ajax_import(){

        $nonce = $_REQUEST['_nonce'];
        if ( ! wp_verify_nonce( $nonce, 'ft_demo_import' ) ) {
            die( 'Security check' );
        }

        $item = wp_strip_all_tags( $_REQUEST['data'] );
        $import_files = get_transient( 'ft_demo_import_downloaded_'.$item );

        if ( $import_files ) {
            $import = new FT_Demo_Content( $import_files );
            $import->import();
        } else {
            echo 'no_data_found';
        }

        // Remove data files
        /*
        foreach ( $import_files as $k => $f ) {
            if ( file_exists( $f ) ) {
                @unlink( $f );
            }
        }
        */

        die();
    }


    function ajax_export(){
        $type = $_REQUEST['type'];
        ob_start();
        ob_end_clean();

        /**
         * Filters the export filename.
         *
         * @since 4.4.0
         *
         * @param string $wp_filename The name of the file for download.
         * @param string $sitename    The site name.
         * @param string $date        Today's date, formatted.
         */
        $filename = 'config.json';

        header( 'Content-Description: File Transfer' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Content-Type: application/xml; charset=' . get_option( 'blog_charset' ), true );

        switch ( $type ) {
            case 'options':
            case 'option':
            case 'config':
            case 'widget':
            case 'widgets':
            case 'theme_mod':
            case 'theme_mods':
                echo FT_Demo_Content::generate_config();
                break;
        }
        die();
    }

}

if ( is_admin() ) {
    function ft_demo_importer(){
        new FT_Demo_Content_Importer();
    }
    add_action( 'plugins_loaded', 'ft_demo_importer' );
}

/**
 * Redirect to import page
 *
 * @param $plugin
 * @param bool|false $network_wide
 */
function ft_demo_importer_plugin_activate( $plugin, $network_wide = false ) {
    if ( ! $network_wide &&  $plugin == plugin_basename( __FILE__ ) ) {
        $template_slug = get_option('template');
        $url = add_query_arg(
            array(
                'page' => 'ft_' . $template_slug,
                'tab' => 'demo-data-importer',
            ),
            admin_url('themes.php')
        );
        wp_redirect($url);
        die();
    }
}
add_action( 'activated_plugin', 'ft_demo_importer_plugin_activate', 90, 2 );

