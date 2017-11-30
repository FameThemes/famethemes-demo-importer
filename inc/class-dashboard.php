<?php
class Demo_Content_Dashboard {
    private $api_url = 'https://www.famethemes.com/wp-json/wp/v2/download/?download_type=15&per_page=100&orderby=title&order=asc';
    private $errors = array();
    // private $cache_time = 3*HOUR_IN_SECONDS;
    private $cache_time = 3600;
    private $page_slug = 'demo-contents';
    private $config_slugs = array(
        'coupon-wp' => 'wp-coupon'
    );
    private $items = array();
    private $allowed_authors = array();
    public  $tgmpa = null;
    public  $is_theme_page = false;
    function __construct()
    {

        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        if ( Demo_Contents::php_support() ) {
            add_action('admin_footer', array($this, 'preview_template'));
        }
        add_action('admin_enqueue_scripts', array($this, 'scripts'));

        $current_theme_slug = get_option( 'template' );
        $no_pro = false;
        if ( strpos( $current_theme_slug, '-pro' ) !== false ) {
            $no_pro = str_replace( '-pro', '', $current_theme_slug );
        }

        add_action( $current_theme_slug.'_demo_import_content_tab', array( $this, 'wellcome' ), 10 );
        add_action( 'theme_demo_import_content_tab', array( $this, 'wellcome' ), 10 );
        add_action( $current_theme_slug.'_demo_import_content_tab', array( $this, 'listing_themes' ), 35 );

        if ( $no_pro ) {
            add_action( $no_pro.'_demo_import_content_tab', array( $this, 'wellcome' ), 10 );
            add_action( $no_pro.'_demo_import_content_tab', array( $this, 'listing_themes' ), 35 );
        }

        // Remove no pro suffix
        add_action( 'theme_demo_import_content_tab', array( $this, 'listing_themes' ), 35 );
        add_action( 'current_screen', array( $this, 'setup_screen' ) );

    }

    function setup_screen(){
        $screen = get_current_screen();
        if ( strpos( $screen->id, 'appearance_' ) !== false ) {
            $this->is_theme_page = true;
        }
    }

    function get_tgmpa(){
        if ( empty( $this->tgmpa ) ) {
            if ( class_exists( 'TGM_Plugin_Activation' ) ) {
                $this->tgmpa = isset( $GLOBALS['tgmpa'] ) ? $GLOBALS['tgmpa'] : TGM_Plugin_Activation::get_instance();
            }
        }
        return $this->tgmpa;
    }


    function scripts( $hook = '' ){
        $load_script = false;
        if ( strpos( $hook, 'appearance_' ) === 0 ) {
            $load_script = true;
        }

        if ( ! $load_script ) {
            if ( strpos( $hook, 'demo-contents' ) !== false ) {
                $load_script = true;
            }
        }

        $load_script = apply_filters( 'demo_contents_load_scripts', $load_script, $hook );
        if ( ! $load_script ) {
            return false;
        }

        wp_enqueue_style( 'demo-contents', DEMO_CONTENT_URL . 'style.css', false );
        if ( ! Demo_Contents::php_support() ) {
            return ;
        }

        wp_enqueue_script( 'underscore');
        wp_enqueue_script( 'demo-contents', DEMO_CONTENT_URL.'assets/js/importer.js', array( 'jquery', 'underscore' ) );
        wp_enqueue_media();
        $run = isset( $_REQUEST['import_now'] ) && $_REQUEST['import_now'] == 1 ? 'run' : 'no';
        $themes = $this->setup_themes( $this->is_theme_page ?  false : true );
        $tgm_url = '';
        // Localize the javascript.
        $plugins = array();
        $this->get_tgmpa();
        if ( ! empty( $this->tgmpa ) ) {
            $tgm_url = $this->tgmpa->get_tgmpa_url();
            $plugins = $this->get_tgmpa_plugins();
        }

        $template_slug  = get_option( 'template' );
        $theme_slug     = get_option( 'stylesheet' );

        wp_localize_script( 'demo-contents', 'demo_contents_params', array(
            'tgm_plugin_nonce' 	=> array(
                'update'  	=> wp_create_nonce( 'tgmpa-update' ),
                'install' 	=> wp_create_nonce( 'tgmpa-install' ),
            ),
            'messages' 		        => array(
                'plugin_installed'    => __( '%s installed', 'demo-contents' ),
                'plugin_not_installed'    => __( '%s not installed', 'demo-contents' ),
                'plugin_not_activated'    => __( '%s not activated', 'demo-contents' ),
                'plugin_installing' => __( 'Installing %s...', 'demo-contents' ),
                'plugin_activating' => __( 'Activating %s...', 'demo-contents' ),
                'plugin_activated'  => __( '%s activated', 'demo-contents' ),
            ),
            'tgm_bulk_url' 		    => $tgm_url,
            'ajaxurl'      		    => admin_url( 'admin-ajax.php' ),
            'theme_url'      		=> admin_url( 'themes.php' ),
            'wpnonce'      		    => wp_create_nonce( 'merlin_nonce' ),
            'action_install_plugin' => 'tgmpa-bulk-activate',
            'action_active_plugin'  => 'tgmpa-bulk-activate',
            'action_update_plugin'  => 'tgmpa-bulk-update',
            'plugins'               => $plugins,
            'home'                  => home_url('/'),
            'btn_done_label'        => __( 'All Done! View Site', 'demo-contents' ),
            'failed_msg'            => __( 'Import Failed!', 'demo-contents' ),
            'import_now'            => __( 'Import Now', 'demo-contents' ),
            'importing'             => __( 'Importing...', 'demo-contents' ),
            'activate_theme'        => __( 'Activate Now', 'demo-contents' ),
            'checking_theme'        => __( 'Checking theme', 'demo-contents' ),
            'checking_resource'        => __( 'Checking resource', 'demo-contents' ),
            'confirm_leave'         => __( 'Importing demo content..., are you sure want to cancel ?', 'demo-contents' ),
            'installed_themes'      => $themes,
            'current_theme'         => $template_slug,
            'current_child_theme'   => $theme_slug,
        ) );

    }

    /**
     * Get registered TGMPA plugins
     *
     * @return    array
     */
    public function get_tgmpa_plugins() {
        $this->get_tgmpa();
        if ( empty( $this->tgmpa ) ) {
            return array();
        }
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


    function add_menu() {
        add_management_page( __( 'Demo Contents', 'demo-contents' ), __( 'Demo Contents', 'demo-contents' ), 'manage_options', $this->page_slug, array( $this, 'dashboard' ) );
    }

    function get_allowed_authors(){
        if ( empty( $this->allowed_authors ) ) {
            $this->allowed_authors  = apply_filters( 'demo_contents_allowed_authors', array(
                    'famethemes' => 'FameThemes',
                    'daisy themes' => 'Daisy Themes'
            ) );
        }
        return $this->allowed_authors;
    }

    function is_allowed_theme( $author ){
        $allowed = false;
        if ( $author ) {
            $author = strtolower( sanitize_text_field( $author ) );
            $authors = $this->get_allowed_authors();
            $allowed = isset( $authors[ $author ] ) ? true : false;
        }

        return apply_filters( 'demo_content_is_allowed_author', $allowed, $author );
    }

    function get_default_author_name(){
        return apply_filters( 'demo_content_default_author', 'FameThemes' );
    }

    function get_items( $all_demos = true ){
        return $this->setup_themes( $all_demos );
    }

    function is_installed( $theme_slug ){
        $check = wp_get_theme( $theme_slug );
        return $check->exists();
    }

    function  preview_template(){
        ?>
        <script id="tmpl-demo-contents--preview" type="text/html">
            <div id="demo-contents--preview">

                  <span type="button" class="demo-contents-collapse-sidebar button" aria-expanded="true">
                        <span class="collapse-sidebar-arrow"></span>
                        <span class="collapse-sidebar-label"><?php _e( 'Collapse', 'demo-contents' ); ?></span>
                    </span>

                <div id="demo-contents-sidebar">
                    <span class="demo-contents-close"><span class="screen-reader-text"><?php _e( 'Close', 'fdi' ); ?></span></span>

                    <div id="demo-contents-sidebar-topbar">
                        <span class="ft-theme-name">{{ data.name }}</span>
                    </div>

                    <div id="demo-contents-sidebar-content">
                        <# if ( data.demo_version ) { #>
                        <div id="demo-contents-sidebar-heading">
                            <span><?php _e( "Your're viewing demo", 'demo-contents' ); ?></span>
                            <strong class="panel-title site-title">{{ data.demo_name }}</strong>
                        </div>
                        <# } #>
                        <# if ( data.img ) { #>
                            <div class="demo-contents--theme-thumbnail"><img src="{{ data.img }}" alt=""/></div>
                        <# } #>

                        <div class="demo-contents--activate-notice">
                            <?php _e( 'This theme is inactivated. Your must activate this theme before import demo content', 'demo-contents' ); ?>
                        </div>

                        <div class="demo-contents--activate-notice resources-not-found demo-contents-hide">
                            <p class="demo-contents--msg"></p>
                            <div class="demo-contents---upload">
                                <p><button type="button" class="demo-contents--upload-xml button-secondary"><?php _e( 'Upload XML file .xml', 'demo-contents' ); ?></button></p>
                                <p><button type="button" class="demo-contents--upload-json button-secondary"><?php _e( 'Upload config file .json or .txt', 'demo-contents' ); ?></button></p>
                            </div>
                        </div>

                        <div class="demo-contents-import-progress">

                            <div class="demo-contents--step demo-contents-install-plugins demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Install Recommended Plugins', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--loading"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step demo-contents-import-users demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Users', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--waiting"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step demo-contents-import-categories demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Categories', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--completed"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step demo-contents-import-tags demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Tags', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--completed"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step demo-contents-import-taxs demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Taxonomies', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--waiting"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step  demo-contents-import-posts demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Posts & Media', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--waiting"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step demo-contents-import-theme-options demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Options', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--waiting"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step demo-contents-import-widgets demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Widgets', 'demo-contents' ); ?></div>
                                <div class="demo-contents--status demo-contents--waiting"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>

                            <div class="demo-contents--step  demo-contents-import-customize demo-contents--waiting">
                                <div class="demo-contents--step-heading"><?php _e( 'Import Customize Settings', 'demo-contents' ) ?></div>
                                <div class="demo-contents--status demo-contents--waiting"></div>
                                <div class="demo-contents--child-steps"></div>
                            </div>
                        </div>

                    </div><!-- /.demo-contents-sidebar-content -->

                    <div id="demo-contents-sidebar-footer">
                        <a href="#" " class="demo-contents--import-now button button-primary"><?php _e( 'Import Now', 'demo-contents' ); ?></a>
                    </div>

                </div>
                <div id="demo-contents-viewing">
                    <iframe src="{{ data.demoURL }}"></iframe>
                </div>
            </div>
        </script>
        <?php
    }

    function get_details_link( $theme_slug, $theme_name ) {
        $link = 'https://www.famethemes.com/themes/'.$theme_slug.'/';
        return apply_filters( 'demo_contents_get_details_link', $link, $theme_slug, $theme_name );
    }

    function setup_themes( $all_demos = true ){

        $key = 'demo_contents_get_themes_'.( (int ) $all_demos );

        $cache = wp_cache_get( $key );
        if ( false !== $cache ) {
            $this->items = $cache;
        }
        // If already setup
        if ( ! empty( $this->items) ) {
            // return $this->items;
        }

        // if have filter for list themes
        $this->items = apply_filters( 'demo_contents_get_themes', array(), $all_demos, $this );
        if ( ! empty( $this->items) ) {
            return $this->items;
        }

        $current_theme_slug = get_option( 'template' );
        $child_theme_slug    = get_option( 'stylesheet' );

        $current_active_slugs = array( $current_theme_slug );

        if ( $all_demos ) {
            $installed_themes = wp_get_themes();
        } else {
            $_activate_theme = wp_get_theme( $current_theme_slug );
            $installed_themes = array(  );
            $installed_themes[ $current_theme_slug ] = $_activate_theme;
        }

        $list_themes = array();

        // Listing installed themes
        foreach ( ( array )$installed_themes as $theme_slug => $theme ) {
            if ( ! $this->is_allowed_theme($theme->get('Author'))) {
                continue;
            }
            $is_activate = false;
            if(  $theme_slug == $current_theme_slug || $theme_slug == $child_theme_slug ) {
                $is_activate = true;
            }
            $list_themes[ $theme_slug ] = array(
                'slug'              => $theme_slug,
                'name'              => $theme->get('Name'),
                'screenshot'        => $theme->get_screenshot(),
                'author'            => $theme->get('Author'),
                'activate'          => $is_activate,
                'demo_version'      => '',
                'demo_url'          => 'https://demos.famethemes.com/'.$theme_slug.'/',
                'demo_version_name' => '',
                'is_plugin'         => false
            );
        }
        $current_theme = false;
        $child_theme = false;
        if (  isset( $list_themes[ $current_theme_slug ]  ) ) {
            $current_theme = $list_themes[ $current_theme_slug ];
            unset( $list_themes[ $current_theme_slug ] );
        }

        if ( isset(  $list_themes[ $child_theme_slug ] )  ) {
            $child_theme = $list_themes[ $child_theme_slug ];
            unset( $list_themes[ $child_theme_slug ] );
        }

        // Move current theme to top
        if ( $current_theme ) {
            $current_theme['activate'] = true;
            $list_themes = array( $current_theme_slug => $current_theme ) + $list_themes;
        }

        $support_plugins = array(
            'onepress-plus/onepress-plus.php' => array(
                'name' => 'OnePress Plus',
                'slug' => 'onepress-plus',
                'theme' => 'onepress'
            ),
            'screenr-plus/screenr-plus.php' => array(
                'name' => 'Screenr Plus',
                'slug' => 'screenr-plus',
                'theme' => 'screenr'
            )
        );

        // Check if plugin active
        foreach ( $support_plugins as $plugin => $info ) {
            if ( is_plugin_active( $plugin ) ) {
                if ( $current_theme_slug == $info['theme'] || $child_theme_slug == $info['theme'] ) {
                    if ( isset( $list_themes[ $info['theme'] ] ) ) {
                        $clone = $list_themes[ $info['theme'] ];
                        $clone['activate'] = true;
                        $clone['name'] =  $info['name'];
                        $clone['slug'] =  $info['slug'];
                        $clone['is_plugin'] = true;
                        $clone['demo_url'] = 'https://demos.famethemes.com/'.$info['slug'].'/';
                        // Move clone theme to top because it need to stay above current theme
                        $list_themes = array(  $info['slug'] => $clone ) + $list_themes;
                    }
                }
            }
        }

        // Move child theme to top
        if ( $child_theme ) {
            $child_theme['activate'] = true;
            $list_themes = array( $child_theme_slug => $child_theme ) + $list_themes;
        }

        $child_demos = array();
        foreach ( $list_themes as $slug => $theme ) {
            if ( $theme['activate'] ) {
                $sub_demos = $this->get_sub_demos( $theme['slug'] );
                if ( ! empty( $sub_demos ) ) {
                    // $list_themes = $list_themes + $sub_demos;
                    $child_demos = $child_demos + $sub_demos;
                }
            }
        }
        if( ! empty( $child_demos ) ) {
            // remove all other demo themes.
            foreach ( $list_themes as $slug => $theme ) {
                if ( ! $theme['activate'] ) {
                    unset( $list_themes[ $slug ] );
                }

            }

        }
        $list_themes = $list_themes + $child_demos;

        $this->items = $list_themes;
        wp_cache_set( $key, $this->items );

        return $this->items;
    }



    function get_sub_demos( $theme_slug ){

        $repo_name = Demo_Contents::get_github_repo();
        $key = $repo_name.'/'.$theme_slug;

        if ( $this->cache_time > 0 ) {
            $cache = get_transient($key);
            if (false !== $cache) {
                return $cache;
            }
        }


        $url = sprintf( 'https://api.github.com/repos/%1$s/contents/%2$s/demos', $repo_name, $theme_slug );
        //$url = sprintf( 'https://api.github.com/repos/%1$s/contents/%2$s', $repo_name, $theme_slug );
        // ?client_id=xxxx&client_secret=yyyy'
        $url_token = add_query_arg( array(
            'client_id' => '38954090f90b033b94e6',
            'client_secret' => '20a21793fbc87a0dc197e52393322545d22d817f',
        ), $url );
        $demos = array();

        $res = wp_remote_get( $url_token, array() );
        if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
            $res = wp_remote_get( $url, array() );
            if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
                delete_transient($key);
                return false;
            }
        }

        $body = wp_remote_retrieve_body( $res );

        $files = json_decode( $body, true );
        if ( empty( $files ) ) {
            set_transient( $key, $demos, $this->cache_time );
            return false;
        }

        $repo_username = explode('/', $repo_name );
        $repo_username = $repo_username[0];

        foreach ( $files as $file ) {
            if ( $file['type'] != 'dir' ) {
                continue;
            }
            $slug = $theme_slug.'-'.$file['name'];
            $name = $file['name'];
            $name = str_replace( '-', ' ', $name );
            $name = ucwords( $name );
            $screenshot_url = 'https://raw.githubusercontent.com/'.$repo_name.'/master/'.$file['path'].'/screenshot.png';
            $arg = array(
                'slug'              => $theme_slug,
                'name'              => ucwords( $theme_slug ),
                'screenshot'        => $screenshot_url,
                'author'            => $repo_username,
                'activate'          => false,
                'demo_version'      => $file['name'],
                'demo_url'          => 'https://demos.famethemes.com/'.$slug.'/',
                'demo_version_name' => $name,
                'is_plugin'         => false
            );

            $demos[ $slug ] = apply_filters( 'demo_contents_child_demo_args', $arg );
        }

        set_transient( $key, $demos, $this->cache_time ); // cache 2 hours

       return $demos;
    }

    function dashboard() {
        if ( ! current_user_can( 'manage_options' ) )  {
            wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
        }

        if ( Demo_Contents::php_support() ) {
            $this->setup_themes();
            $number_theme = count( $this->items );
        }

        ob_start();

        ?>
        <div class="wrap demo-contents">
            <h1 class="wp-heading-inline"><?php _e( 'Demo Contents', 'demo-contents' ); ?>
                <?php if ( Demo_Contents::php_support() ) { ?>
                <span class="title-count theme-count"><?php echo $number_theme; ?></span>
                <?php } ?>
            </h1>
            <?php
            if ( Demo_Contents::php_support() ) {
                $link_all = '?page='.$this->page_slug;
                $link_current_theme = '?page='.$this->page_slug.'&tab=current_theme';
                $link_export= '?page='.$this->page_slug.'&tab=export';
                $tab = isset( $_GET['tab'] )  ? $_GET['tab'] : '';
                 ?>
                <div class="wp-filter hide-if-no-js">
                    <div class="filter-count">
                        <span class="count theme-count"><?php echo $number_theme; ?></span>
                    </div>
                    <ul class="filter-links">
                        <li><a href="<?php echo $link_all; ?>" class="<?php echo ( ! $tab ) ? 'current' : ''; ?>"><?php _e( 'All Demos', 'demo-contents' ); ?></a></li>
                    </ul>
                </div>
                <?php $this->listing_themes( true ); ?>
            <?php } else {
                  $this->php_not_support_message();
            } ?>

        </div><!-- /.wrap -->
        <?php
    }

    function wellcome(){
        if ( Demo_Contents::php_support() ) {
            ?>
            <div class="demo-contents-import-box demo-contents-import-welcome">
                <h3><?php esc_html_e('Welcome to FameThemes Demo Importer!', 'demo-contents'); ?></h3>
                <p>
                    <?php esc_html_e('Importing demo data (post, pages, images, theme settings, ...) is the easiest way to setup your theme. It will allow you to quickly edit everything instead of creating content from scratch. When you import the data, the following things might happen:', 'demo-contents'); ?>
                </p>
                <ul>
                    <li><?php esc_html_e('No existing posts, pages, categories, images, custom post types or any other data will be deleted or modified.', 'demo-contents'); ?></li>
                    <li><?php esc_html_e('Posts, pages, images, widgets and menus will get imported.', 'demo-contents'); ?></li>
                    <li><?php esc_html_e('Click "Start Import Demo" to start import, it can take a couple of minutes.', 'demo-contents'); ?></li>
                </ul>
                <p><?php esc_html_e('Notice: If your site already has content, please make sure you backup your database and WordPress files before import demo data.', 'demo-contents'); ?></p>
            </div>
            <?php
        }
    }

    function loop_theme( $theme ){
        ?>
        <div class="theme <?php echo  (  $theme['activate'] ) ? 'demo-contents--current-theme' : ''; ?>" tabindex="0">
            <div class="theme-screenshot">
                <img src="<?php echo esc_url($theme['screenshot']); ?>" alt="">
            </div>
            <?php if ( $theme['activate'] ) { ?>
                <span class="more-details"><?php _e('Current Theme', 'demo-contents'); ?></span>
            <?php } else { ?>
                <span class="more-details"><?php _e('View Details', 'demo-contents'); ?></span>
            <?php } ?>

            <div class="theme-author"><?php sprintf(__('by %s', 'demo-contents'),$theme['author'] ); ?></div>
            <div class="theme-name"><?php echo  ( $theme['demo_version_name'] ) ? esc_html( $theme['demo_version_name']  ) : esc_html($theme['name']); ?></div>
            <div class="theme-actions">
                <a
                    data-theme-slug="<?php echo esc_attr( $theme['slug'] ); ?>"
                    data-demo-version="<?php echo esc_attr( $theme['demo_version'] ); ?>"
                    data-demo-version-name="<?php echo esc_attr( $theme['demo_version_name'] ); ?>"
                    data-name="<?php echo esc_html($theme['name']); ?>"
                    data-demo-url="<?php echo esc_attr( $theme['demo_url'] ); ?>"
                    class="demo-contents--preview-theme-btn button button-primary customize"
                    href="#"
                ><?php _e('Start Import', 'demo-contents'); ?></a>
            </div>
        </div>
        <?php
    }

    function php_not_support_message(){
        ?>
        <div class="demo-contents-notice"><?php
            printf( __( "PHP version is not support. You're using PHP version %s, please upgrade to version 5.6.20 or higher.",  'demo-contents' ), PHP_VERSION );
            ?></div>
        <?php
    }

    /**
     * Listing themes
     *
     * @param bool $all_demos true if list all demos of authors, false if listing demos for current theme only
     */
    function listing_themes( $all_demos = false ){

        if ( ! Demo_Contents::php_support() ) {
            $this->php_not_support_message();
            return ;
        }

        $this->setup_themes( $all_demos, true );
        $number_theme = count( $this->items );
        ?>
        <div class="theme-browser rendered demo-contents-themes-listing">
            <div class="themes wp-clearfix">
                <?php
                if ( $number_theme > 0 ) {
                    if ( has_action( 'demo_contents_before_themes_listing' ) ) {
                        do_action( 'demo_contents_before_themes_listing', $this );
                    } else {

                        // Listing installed themes
                        foreach (( array ) $this->items as $theme_slug => $theme ) {
                            $this->loop_theme( $theme );
                        }

                        do_action('demo_content_themes_listing');
                    } // end check if has actions
                } else {
                    ?>
                    <div class="demo-contents-no-themes">
                        <?php _e( 'No Themes Found', 'demo-contents' ); ?>
                    </div>
                    <?php
                }
                ?>
            </div><!-- /.Themes -->
        </div><!-- /.theme-browser -->
        <?php
    }
}
