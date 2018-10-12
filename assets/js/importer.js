var demo_contents_import_running = false;
var demo_contents_iframe_running = false;
window.onbeforeunload = function() {
    if ( demo_contents_import_running ) {
        return demo_contents_params.confirm_leave;
    }
};



// -------------------------------------------------------------------------------
var demo_contents_working_plugins = window.demo_contents_working_plugins || {};
var demo_contents_viewing_theme = window.demo_contents_viewing_theme || {};

(function ( $ ) {

    var demo_contents_params = demo_contents_params || window.demo_contents_params;

    if( typeof demo_contents_params.plugins.activate !== "object" ) {
        demo_contents_params.plugins.activate = {};
    }
    var $document = $( document );

    /**
     * Function that loads the Mustache template
     */
    var repeaterTemplate = _.memoize(function () {
        var compiled,
            /*
             * Underscore's default ERB-style templates are incompatible with PHP
             * when asp_tags is enabled, so WordPress uses Mustache-inspired templating syntax.
             *
             * @see track ticket #22344.
             */
            options = {
                evaluate: /<#([\s\S]+?)#>/g,
                interpolate: /\{\{\{([\s\S]+?)\}\}\}/g,
                escape: /\{\{([^\}]+?)\}\}(?!\})/g,
                variable: 'data'
            };

        return function (data, tplId ) {
            if ( typeof tplId === "undefined" ) {
                tplId = '#tmpl-demo-contents--preview';
            }
            compiled = _.template(jQuery( tplId ).html(), null, options);
            return compiled(data);
        };
    });


    String.prototype.format = function() {
        var newStr = this, i = 0;
        while (/%s/.test(newStr)) {
            newStr = newStr.replace("%s", arguments[i++]);
        }
        return newStr;
    };

    var template = repeaterTemplate();

    var ftDemoContents  = {
        plugins: {
            install: {},
            all: {},
            activate: {}
        },

        loading: function(){
            demo_contents_import_running = true;
            demo_contents_iframe_running = null;
            $( '#demo_contents_iframe_running' ).remove();
            var frame = $( '<iframe id="demo_contents_iframe_running" style="display: none;"></iframe>' );
            frame.appendTo('body');
            var doc;
            // Thanks http://jsfiddle.net/KSXkS/1/
            try { // simply checking may throw in ie8 under ssl or mismatched protocol
                doc = frame[0].contentDocument ? frame[0].contentDocument : frame[0].document;
            } catch(err) {
                doc = frame[0].document;
            }
            doc.open();
            // doc.close();
        },

        end_loading: function(){
             $( '#demo_contents_iframe_running' ).remove();
            demo_contents_import_running = false;
        },

        loading_step: function( $element ){
            $element.removeClass( 'demo-contents--waiting demo-contents--running' );
            $element.addClass( 'demo-contents--running' );
        },
        completed_step: function( $element, event_trigger ){
            $element.removeClass( 'demo-contents--running demo-contents--waiting' ).addClass( 'demo-contents--completed' );
            if ( typeof event_trigger !== "undefined" ) {
                $document.trigger( event_trigger );
            }
        },
        preparing_plugins: function( plugins ) {
            var that = this;
            if ( typeof plugins === "undefined" ) {
                plugins = demo_contents_params.plugins;
            }
            plugins = _.defaults( plugins,  {
                install: {},
                all: {},
                activate: {}
            } );

            demo_contents_working_plugins = plugins;
            that.plugins = demo_contents_working_plugins;

            var $list_install_plugins = $('.demo-contents-install-plugins');
            var n = _.size(that.plugins.all);
            if (n > 0) {
                var $child_steps = $list_install_plugins.find('.demo-contents--child-steps');
                $.each( that.plugins.all, function ($slug, plugin) {
                    var msg = plugin.name;

                    if( typeof that.plugins.install[ $slug] !== "undefined" ) {
                        msg = demo_contents_params.messages.plugin_not_installed.format( plugin.name );
                    } else {
                        if( typeof that.plugins.activate[ $slug] !== "undefined" ) {
                            msg = demo_contents_params.messages.plugin_not_activated.format( plugin.name );
                        }
                    }

                    var $item = $('<div data-slug="' + $slug + '" class="demo-contents-child-item dc-unknown-status demo-contents-plugin-' + $slug + '">'+msg+'</div>');
                    $child_steps.append($item);
                    $item.attr('data-plugin', $slug);
                });
            } else {
                $list_install_plugins.hide();
            }


            if ( demo_contents_viewing_theme.activate ) {
                $( '.demo-contents--activate-notice' ).hide();
            } else {
                $( '.demo-contents-import-progress' ).hide();
                $( '.demo-contents--activate-notice' ).show();

                var activate_theme_btn =  $( '<a href="#" class="demo-contents--activate-now button button-primary">'+demo_contents_params.activate_theme+'</a>' );
                $( '.demo-contents--import-now' ).replaceWith( activate_theme_btn );
            }

        },
        installPlugins: function() {
            var that = this;
            that.plugins = demo_contents_working_plugins;
            // Install Plugins
            var $list_install_plugins = $( '.demo-contents-install-plugins' );
            that.loading_step( $list_install_plugins );
            console.log( 'Being installing plugins....' );
            var $child_steps = $list_install_plugins.find(  '.demo-contents--child-steps' );
            var n = _.size( that.plugins.install );
            if ( n > 0 ) {

                var callback = function( current ){
                    if ( current.length ) {
                        var slug = current.attr( 'data-plugin' );
                        if ( typeof that.plugins.install[ slug ] === "undefined" ) {
                            var next = current.next();
                            callback( next );
                        } else {
                            var plugin =  that.plugins.install[ slug ];
                            var msg = demo_contents_params.messages.plugin_installing.format( plugin.name );
                            console.log( msg );
                            current.html( msg );

                            $.post( plugin.page_url, plugin.args, function (res) {
                                //console.log(plugin.name + ' Install Completed');
                                plugin.args.action = demo_contents_params.action_active_plugin;
                                that.plugins.activate[ slug ] = plugin;
                                var msg = demo_contents_params.messages.plugin_installed.format( plugin.name );
                                console.log( msg );
                                current.html( msg );
                                var next = current.next();
                                callback( next );
                            }).fail(function() {
                                demo_contents_working_plugins = that.plugins;
                                console.log( 'Plugins install failed' );
                                $document.trigger( 'demo_contents_plugins_install_completed' );
                            });
                        }
                    } else {
                        demo_contents_working_plugins = that.plugins;
                        console.log( 'Plugins install completed' );
                        $document.trigger( 'demo_contents_plugins_install_completed' );
                    }
                };

                var current = $child_steps.find( '.demo-contents-child-item' ).eq( 0 );
                callback( current );
            } else {
                demo_contents_working_plugins = that.plugins;
                console.log( 'Plugins install completed - 0' );
                //$list_install_plugins.hide();
                $document.trigger( 'demo_contents_plugins_install_completed' );
            }

            // that.completed_step( $list_install_plugins, 'demo_contents_plugins_install_completed' );

        },
        activePlugins: function(){
            var that = this;
            that.plugins = demo_contents_working_plugins;

            that.plugins.activate = $.extend({},that.plugins.activate );
            console.log( 'activePlugins', that.plugins );
            var $list_active_plugins = $( '.demo-contents-install-plugins' );
            that.loading_step( $list_active_plugins );
            var $child_steps = $list_active_plugins.find(  '.demo-contents--child-steps' );
            var n = _.size( that.plugins.activate );
            console.log( 'Being activate plugins....' );
            if (  n > 0 ) {
                var callback = function (current) {
                    if (current.length) {
                        var slug = current.attr('data-plugin');

                        if ( typeof that.plugins.activate[ slug ] === "undefined" ) {
                            var next = current.next();
                            callback( next );
                        } else {
                            var plugin = that.plugins.activate[slug];
                            var msg = demo_contents_params.messages.plugin_activating.format( plugin.name );
                            console.log( msg );
                            current.html( msg );
                            $.post(plugin.page_url, plugin.args, function (res) {

                                var msg = demo_contents_params.messages.plugin_activated.format( plugin.name );
                                console.log( msg );
                                current.html( msg );
                                var next = current.next();
                                callback(next);
                            }).fail(function() {
                                console.log( 'Plugins activate failed' );
                                that.completed_step( $list_active_plugins, 'demo_contents_plugins_active_completed' );
                            });
                        }

                    } else {
                        console.log(' Activated all plugins');
                        that.completed_step( $list_active_plugins, 'demo_contents_plugins_active_completed' );
                    }
                };

                var current = $child_steps.find( '.demo-contents-child-item' ).eq( 0 );
                callback( current );

            } else {
               // $list_active_plugins.hide();
                console.log(' Activated all plugins - 0');
                $list_active_plugins.removeClass('demo-contents--running demo-contents--waiting').addClass('demo-contents--completed');
                $document.trigger('demo_contents_plugins_active_completed');
            }

        },
        ajax: function( doing, complete_cb, fail_cb ){
            console.log( 'Being....', doing );
            $.ajax( {
                url: demo_contents_params.ajaxurl,
                data: {
                    action: 'demo_contents__import',
                    doing: doing,
                    current_theme: demo_contents_viewing_theme,
                    theme: '', // Import demo for theme ?
                    version: '' // Current demo version ?
                },
                type: 'GET',
                dataType: 'json',
                success: function( res ){
                    console.log( res );
                    if ( typeof complete_cb === 'function' ) {
                        complete_cb( res );
                    }
                    console.log( 'Completed: '+ doing, res );
                    $document.trigger( 'demo_contents_'+doing+'_completed' );
                },
                fail: function( res ){
                    if ( typeof fail_cb === 'function' ) {
                        fail_cb( res );
                    }
                    console.log( 'Failed: '+ doing, res );
                    $document.trigger( 'demo_contents_'+doing+'_failed' );
                    $document.trigger( 'demo_contents_ajax_failed', [ doing ] );
                }

            } )
        },
        import_users: function(){
            var step =  $( '.demo-contents-import-users' );
            var that = this;
            that.loading_step( step );
            this.ajax( 'import_users', function(){
                that.completed_step( step );
            } );
        },
        import_categories: function(){
            var step =  $( '.demo-contents-import-categories' );
            var that = this;
            that.loading_step( step );
            this.ajax(  'import_categories', function(){
                that.completed_step( step );
            } );
        },
        import_tags: function(){
            var step =  $( '.demo-contents-import-tags' );
            var that = this;
            that.loading_step( step );
            this.ajax(  'import_tags', function(){
                that.completed_step( step );
            } );
        },
        import_taxs: function(){
            var step =  $( '.demo-contents-import-taxs' );
            var that = this;
            that.loading_step( step );
            this.ajax(  'import_taxs', function(){
                that.completed_step( step );
            } );
        },
        import_posts: function(){
            var step =  $( '.demo-contents-import-posts' );
            var that = this;
            that.loading_step( step );
            this.ajax( 'import_posts', function(){
                that.completed_step( step );
            } );
        },

        import_theme_options: function(){
            var step =  $( '.demo-contents-import-theme-options' );
            var that = this;
            that.loading_step( step );
            this.ajax( 'import_theme_options', function(){
                that.completed_step( step );
            } );
        },

        import_widgets: function(){
            var step =  $( '.demo-contents-import-widgets' );
            var that = this;
            that.loading_step( step );
            this.ajax( 'import_widgets', function(){
                that.completed_step( step );
            } );
        },

        import_customize: function(){
            var step =  $( '.demo-contents-import-customize' );
            var that = this;
            that.loading_step( step );
            this.ajax( 'import_customize', function (){
                that.completed_step( step );
            } );
        },

        toggle_collapse: function(){
            $document .on( 'click', '.demo-contents-collapse-sidebar', function( e ){
                $( '#demo-contents--preview' ).toggleClass('ft-preview-collapse');
            } );
        },

        done: function(){
            console.log( 'All done' );
            this.end_loading();
            $( '.demo-contents--import-now' ).replaceWith( '<a href="'+demo_contents_params.home+'" class="button button-primary">'+demo_contents_params.btn_done_label+'</a>' );
        },

        failed: function(){
            console.log( 'Import failed' );
            $( '.demo-contents--import-now' ).replaceWith( '<span class="button button-secondary">'+demo_contents_params.failed_msg+'</span>' );
        },

        preview: function(){
            var that = this;
            $document .on( 'click', '.demo-contents-themes-listing .theme', function( e ){
                e.preventDefault();
                console.log( 'ok' );
                var t               = $( this );
                var btn             = $( '.demo-contents--preview-theme-btn', t );
                var theme           = btn.closest('.theme');
                var slug            = btn.attr( 'data-theme-slug' ) || '';
                var name            = btn.attr( 'data-name' ) || '';
                var demo_version    = btn.attr( 'data-demo-version' ) || '';
                var demo_name       = btn.attr( 'data-demo-version-name' ) || '';
                var demo_url        = btn.attr( 'data-demo-url' ) || '';
                var img             = $( '.theme-screenshot img', theme ).attr( 'src' );
                if ( demo_url.indexOf( 'http' ) !== 0 ) {
                    demo_url = 'https://demos.famethemes.com/'+slug+'/';
                }
                $( '#demo-contents--preview' ).remove();

                console.log( 'demo_url', demo_url );

                demo_contents_viewing_theme =  {
                    name: name,
                    slug: slug,
                    demo_version: demo_version,
                    demo_name:  demo_name,
                    demoURL: demo_url,
                    img: img,
                    activate: false
                };

                if ( typeof demo_contents_params.installed_themes[ slug ] !== "undefined" ) {
                    if ( demo_contents_params.installed_themes[ slug ].activate ) {
                        demo_contents_viewing_theme.activate = true;
                    }
                }

                var previewHtml = template( demo_contents_viewing_theme );
                $( 'body' ).append( previewHtml );
                $( 'body' ).addClass( 'demo-contents-body-viewing' );

                that.preparing_plugins();

                $document.trigger( 'demo_contents_preview_opened' );

            } );

            $document.on( 'click', '.demo-contents-close', function( e ) {
                e.preventDefault();
                if ( demo_contents_import_running ) {
                    var c = confirm( demo_contents_params.confirm_leave ) ;
                    if ( c ) {
                        demo_contents_import_running = false;
                        $( this ).closest('#demo-contents--preview').remove();
                        $( 'body' ).removeClass( 'demo-contents-body-viewing' );
                    }
                } else {
                    $( this ).closest('#demo-contents--preview').remove();
                    $( 'body' ).removeClass( 'demo-contents-body-viewing' );
                }

            } );

        },

        checking_resources: function(){
            var that = this;
            var button = $( '.demo-contents--import-now, .demo-contents--activate-now' );
            button.html( demo_contents_params.checking_resource );
            button.addClass( 'updating-message' );
            button.addClass( 'disabled' );
            that.ajax( 'checking_resources', function( res ){
                if ( res.success ) {
                    button.removeClass( 'disabled' );
                    button.removeClass( 'updating-message' );
                    if ( demo_contents_viewing_theme.activate ) {
                        button.html( demo_contents_params.import_now );
                    } else {
                        button.html( demo_contents_params.activate_theme );
                    }
                } else {
                    $( '.demo-contents--activate-notice.resources-not-found' ).show().removeClass( 'demo-contents-hide' );
                    $( '.demo-contents--activate-notice.resources-not-found .demo-contents--msg' ).addClass('not-found-data').show().html( res.data );
                    $( '.demo-contents-import-progress' ).hide();
                    var text = demo_contents_viewing_theme.activate ? demo_contents_params.import_now : demo_contents_params.activate_theme;
                    button.replaceWith( '<a href="#" class="demo-contents--no-data-btn button button-secondary disabled disable">'+text+'</a>' );
                }
            } );
        },

        init: function(){
            var that = this;

            that.preview();
            that.toggle_collapse();

            $document.on( 'demo_contents_ready', function(){
                $( '.demo-contents--activate-notice.resources-not-found ').slideUp(200).addClass( 'content-demos-hide' );
                that.loading();
                that.installPlugins();
            } );

            $document.on( 'demo_contents_plugins_install_completed', function(){
                that.activePlugins();
            } );

            $document.on( 'demo_contents_plugins_active_completed', function(){
                that.import_users();
            } );

            $document.on( 'demo_contents_import_users_completed', function(){
                that.import_categories();
            } );

            $document.on( 'demo_contents_import_categories_completed', function(){
                that.import_tags();
            } );

            $document.on( 'demo_contents_import_tags_completed', function(){
                that.import_taxs();
            } );

            $document.on( 'demo_contents_import_taxs_completed', function(){
                that.import_posts();
            } );

            $document.on( 'demo_contents_import_posts_completed', function(){
                that.import_theme_options();
            } );

            $document.on( 'demo_contents_import_theme_options_completed', function(){
                that.import_widgets();
            } );

            $document.on( 'demo_contents_import_widgets_completed', function(){
                that.import_customize();
            } );

            $document.on( 'demo_contents_import_customize_completed', function(){
                that.done();
            } );

            $document.on( 'demo_contents_ajax_failed', function(){
                that.failed();
            } );


            // Toggle Heading
            $document.on( 'click', '.demo-contents--step', function( e ){
                e.preventDefault();
                $( '.demo-contents--child-steps', $( this ) ).toggleClass( 'demo-contents--show' );
            } );

            // Import now click
            $document.on( 'click', '.demo-contents--import-now', function( e ) {
                e.preventDefault();
                if ( ! $( this ).hasClass( 'updating-message' ) ) {
                    $( this ).addClass( 'updating-message' );
                    $( this ).html( demo_contents_params.importing );
                    $document.trigger( 'demo_contents_ready' );
                }
            } );


            // Activate Theme Click
            $document.on( 'click', '.demo-contents--activate-now', function( e ) {
                e.preventDefault();
                var btn =  $( this );
                if ( ! btn.hasClass( 'updating-message' ) ) {
                    btn.addClass( 'updating-message' );
                    that.ajax( 'activate_theme', function( res ){
                        var new_btn = $( '<a href="#" class="demo-contents--checking-resource  updating-message button button-primary">' + demo_contents_params.checking_theme + '</a>' );
                        btn.replaceWith( new_btn );

                        demo_contents_params.current_theme = demo_contents_viewing_theme.slug;
                        demo_contents_params.current_child_theme =  demo_contents_viewing_theme.slug;

                        $.get( demo_contents_params.theme_url, { __checking_plugins: 1 }, function( res ){
                            console.log( 'Checking plugin completed: ', demo_contents_params.import_now );
                            $( '.demo-contents--checking-resource, .demo-contents--activate-now' ).replaceWith('<a href="#" class="demo-contents--import-now button button-primary">' + demo_contents_params.import_now + '</a>');
                            if ( res.success ) {
                                demo_contents_viewing_theme.activate = true;
                                that.preparing_plugins( res.data );
                                $( '.demo-contents--activate-notice' ).slideUp( 200 );
                                $( '.demo-contents-import-progress' ).slideDown(200);
                            }
                        } );

                    } );
                }
            } );

            $document.on( 'demo_contents_preview_opened', function(){
                //  that.loading();
                that.checking_resources();
                //$document.trigger( 'demo_contents_import_theme_options_completed' );
                //$document.trigger( 'demo_contents_import_widgets_completed' );
            } );


            // Custom upload demo file
            var Media = wp.media({
                title: wp.media.view.l10n.addMedia,
                multiple: false,
               // library:
            });

            that.uploading_file = false;

            $document.on( 'click', '.demo-contents--upload-xml', function(e){
                e.preventDefault();
                Media.open();
                that.uploading_file = 'xml';
            } );

            $document.on( 'click', '.demo-contents--upload-json', function(e){
                e.preventDefault();
                Media.open();
                that.uploading_file = 'json';
            } );

            var check_upload = function(){
                if ( typeof  demo_contents_viewing_theme.xml_id !== "undefined"
                    &&typeof  demo_contents_viewing_theme.json_id !== "undefined"
                    && demo_contents_viewing_theme.xml_id
                    && demo_contents_viewing_theme.json_id
                ) {
                    if ( demo_contents_viewing_theme.activate ) {
                        $( '.demo-contents-import-progress' ).show();
                        $( '.demo-contents--no-data-btn' ).replaceWith( '<a href="#" class="demo-contents--import-now button button-primary">' + demo_contents_params.import_now + '</a>' );
                    } else {
                        $( '.demo-contents--no-data-btn' ).replaceWith( '<a href="#" class="demo-contents--activate-now button button-primary">'+demo_contents_params.activate_theme+'</a>' );
                    }
                }
            };

            Media.on('select', function () {
                var attachment = Media.state().get('selection').first().toJSON();
                var id = attachment.id;
                var file_name = attachment.filename;
                var ext = file_name.split('.').pop();
                if (that.uploading_file == 'xml') {
                    if (ext.toLowerCase() == 'xml') {
                        demo_contents_viewing_theme.xml_id = id;
                        $('.demo-contents--upload-xml').html(file_name);
                        check_upload();
                    }
                }

                if (that.uploading_file == 'json') {
                    if (ext.toLowerCase() == 'txt' || ext.toLowerCase() == 'json') {
                        demo_contents_viewing_theme.json_id = id;
                        $('.demo-contents--upload-json').html(file_name);
                        check_upload();
                    }
                }

            });

            // END Custom upload demo file

        }
    };

    $.fn.ftDemoContent = function() {
        ftDemoContents.init();
    };


}( jQuery ));

jQuery( document ).ready( function( $ ){
    $( document ).ftDemoContent();
});



