<?php
/**
 * Created by PhpStorm.
 * User: truongsa
 * Date: 9/16/17
 * Time: 9:10 AM
 */


class  FT_Demo_Content_Progress {

    function __construct()
    {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
        add_action( 'wp_ajax_ft_demo_import', array( $this, 'ajax_import' ) );
    }

    /**
     * @see https://github.com/devinsays/edd-theme-updater/blob/master/updater/theme-updater.php
     */
    function ajax_import(){
        $demo_xml_file = FT_DEMO_CONTENT_PATH.'demos/wordpress.xml';

        if ( ! class_exists( 'Merlin_WXR_Parser' ) ) {
            require FT_DEMO_CONTENT_PATH. 'inc/merlin-wp/includes/class-merlin-xml-parser.php' ;
        }

        if ( ! class_exists( 'Merlin_Importer' ) ) {
            require FT_DEMO_CONTENT_PATH .'inc/merlin-wp/includes/class-merlin-importer.php';
        }

        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( __( "You have not permissions to import.", 'ftdi' ) );
        }
        $importer = new Merlin_Importer();
        //$importer->import( $demo_xml_file );
        $doing = isset( $_REQUEST['doing'] ) ? sanitize_text_field( $_REQUEST['doing'] ) : '';
        if ( ! $doing ) {
            wp_send_json_error( __( "No actions to do", 'ftdi' ) );
        }

        $theme      =  isset( $_REQUEST['theme'] ) ? sanitize_text_field( $_REQUEST['theme'] ) : ''; // Theme to import
        $version    =  isset( $_REQUEST['version'] ) ? sanitize_text_field( $_REQUEST['version'] ) : ''; // demo version




        $transient_key = 'ft_demo_xml_data'.$theme.$version;
        $content = get_transient( $transient_key );



        if ( ! $content ) {
            $parser = new Merlin_WXR_Parser();
            $content = $parser->parse( $demo_xml_file );
            set_transient( $transient_key, $content, DAY_IN_SECONDS );
        }
        if ( is_wp_error( $content ) ) {
            wp_send_json_success( 'no_demo_import' );
        }

        //$importer->importStart();

        switch ( $doing ) {
            case 'import_users':
                if ( ! empty( $content['users'] ) ) {
                    $importer->import_users( $content['users'] );
                }
                break;

            case 'import_categories':
                if ( ! empty( $content['categories'] ) ) {
                    $importer->importTerms( $content['categories'] );
                }
                break;
            case 'import_tags':
                if ( ! empty( $content['tags'] ) ) {
                    $importer->importTerms( $content['tags'] );
                }
                break;
            case 'import_taxs':
                if ( ! empty( $content['terms'] ) ) {
                    $importer->importTerms( $content['terms'] );
                }
                break;
            case 'import_posts':
                if ( ! empty( $content['posts'] ) ) {
                    $importer->importPosts( $content['posts'] );
                }
                $importer->remapImportedData();
                $importer->importEnd();

                break;
        }



        /*
        if ( ! empty( $content['users'] ) ) {
            $this->import_users( $content['users'] );
        }

        if ( ! empty( $content['categories'] ) ) {
            $this->importTerms( $content['categories'] );
        }

        if ( ! empty( $content['tags'] ) ) {
            $this->importTerms( $content['tags'] );
        }

        if ( ! empty( $content['terms'] ) ) {
            $this->importTerms( $content['terms'] );
        }

        if ( ! empty( $content['posts'] ) ) {
            $this->importPosts( $content['posts'] );
        }

        $this->remapImportedData();

        $this->importEnd();
        */



    }

    function init(){
        if ( isset( $_GET['step'] ) ) {
            $step = $_GET['step'];
            var_dump( $step );
            if ( method_exists( $this,  $step ) ){
                call_user_func_array( array( $this, $step ), array( ) );
            }
        }
    }

    function scripts(){
        wp_enqueue_style( 'ft-demo-content', FT_DEMO_CONTENT_URL . 'style.css', false );

        wp_enqueue_script( 'underscore');
        wp_enqueue_script( 'ft-demo-content', FT_DEMO_CONTENT_URL.'assets/js/importer.js', array( 'jquery', 'underscore' ) );

        $run = isset( $_REQUEST['import_now'] ) && $_REQUEST['import_now'] == 1 ? 'run' : 'no';
        // Localize the javascript.
        if ( class_exists( 'TGM_Plugin_Activation' ) ) {

            $this->tgmpa = isset($GLOBALS['tgmpa']) ? $GLOBALS['tgmpa'] : TGM_Plugin_Activation::get_instance();
            $plugins = $this->get_tgmpa_plugins();

            $template_slug = get_option( 'template' );
            //$theme_slug = get_option( 'stylesheet' );


            // Check first if TMGPA is included.
            wp_localize_script( 'ft-demo-content', 'ft_demo_content_params', array(
                'tgm_plugin_nonce' 	=> array(
                    'update'  	=> wp_create_nonce( 'tgmpa-update' ),
                    'install' 	=> wp_create_nonce( 'tgmpa-install' ),
                ),
                'tgm_bulk_url' 		    => $this->tgmpa->get_tgmpa_url(),
                'ajaxurl'      		    => admin_url( 'admin-ajax.php' ),
                'wpnonce'      		    => wp_create_nonce( 'merlin_nonce' ),
                'action_install_plugin' => 'tgmpa-bulk-activate',
                'action_active_plugin'  => 'tgmpa-bulk-activate',
                'action_update_plugin'  => 'tgmpa-bulk-update',
                'plugins'               => $plugins,
                'run'                   => $run,
            ) );
        } else {
            // If TMGPA is not included.
            wp_localize_script( 'ft-demo-importer', 'merlin_params', array(
                'ajaxurl'      		=> admin_url( 'admin-ajax.php' ),
                'wpnonce'      		=> wp_create_nonce( 'merlin_nonce' ),
                'plugins'      		=> wp_create_nonce( 'merlin_nonce' ),
            ) );
        }
    }

    /**
     * Get registered TGMPA plugins
     *
     * @return    array
     */
    protected function get_tgmpa_plugins() {
        $plugins  = array(
            'all'      => array(), // Meaning: all plugins which still have open actions.
            'install'  => array(),
            'update'   => array(),
            'activate' => array(),
        );

        $tgmpa_url = $this->tgmpa->get_tgmpa_url();

        foreach ( $this->tgmpa->plugins as $slug => $plugin ) {
            if ( $this->tgmpa->is_plugin_active( $slug ) && false === $this->tgmpa->does_plugin_have_update( $slug ) ) {
                continue;
            } else {
                $plugins['all'][ $slug ] = $plugin;

                $args =   array(
                    'plugin' => $slug,
                    'tgmpa-page' => $this->tgmpa->menu,
                    'plugin_status' => 'all',
                    '_wpnonce' => wp_create_nonce('bulk-plugins'),
                    'action' => '',
                    'action2' => -1,
                    //'message' => esc_html__('Installing', '@@textdomain'),
                );

                $plugin['page_url'] = $tgmpa_url;

                if ( ! $this->tgmpa->is_plugin_installed( $slug ) ) {
                    $plugins['install'][ $slug ] = $plugin;
                    $action = 'tgmpa-bulk-install';
                    $args['action'] = $action;
                    $plugins['install'][ $slug ][ 'args' ] = $args;
                } else {
                    if ( false !== $this->tgmpa->does_plugin_have_update( $slug ) ) {
                        $plugins['update'][ $slug ] = $plugin;
                        $action = 'tgmpa-bulk-update';
                        $args['action'] = $action;
                        $plugins['update'][ $slug ][ 'args' ] = $args;
                    }
                    if ( $this->tgmpa->can_plugin_activate( $slug ) ) {
                        $plugins['activate'][ $slug ] = $plugin;
                        $action = 'tgmpa-bulk-activate';
                        $args['action'] = $action;
                        $plugins['activate'][ $slug ][ 'args' ] = $args;
                    }
                }


            }
        }

        return $plugins;
    }


    function install_plugins()
    {

    }
}

new FT_Demo_Content_Progress();