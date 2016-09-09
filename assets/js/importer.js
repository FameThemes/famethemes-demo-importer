var ft_import_running = false;
window.onbeforeunload = function() {
    if ( ft_import_running ) {
        return FT_IMPORT_DEMO.confirm_leave;
    }
};



jQuery( document ).ready( function( $ ){

    $( '.ft-demo-import-now').on( 'click', function( e ){
        e.preventDefault();
        var btn = $( this);
        if ( btn.hasClass( 'disabled' ) ) {
            return ;
        }
        // Make sure user want to import
        var c = confirm( FT_IMPORT_DEMO.confirm_import );
        if ( ! c ) {
            return;
        }

        btn.addClass( 'disabled' );
        btn.addClass( 'updating-message' );

        var url =  btn.attr( 'href' );
        var frame = $( '<iframe style="display: none;"></iframe>' );
        frame.appendTo('body');
        // Thanks http://jsfiddle.net/KSXkS/1/
        try { // simply checking may throw in ie8 under ssl or mismatched protocol
            doc = frame[0].contentDocument ? frame[0].contentDocument : frame[0].document;
        } catch(err) {
            doc = frame[0].document;
        }
        doc.open();

        // Download url first
        url = btn.attr( 'data-download' );
        btn.html( FT_IMPORT_DEMO.downloading );
        var notice =  $( '.ft-ajax-notice' );
        notice.html( );

        ft_import_running = true;
        $.ajax( {
            url: url,
            success: function( res ){
               // btn.removeClass( 'disabled' );
                //btn.removeClass( 'updating-message' );
                //doc.close();

                // Import
               setTimeout( function(){
                   btn.html( FT_IMPORT_DEMO.importing );
                   url = btn.attr( 'data-import' );
                   $.ajax( {
                       url: url,
                       dataType: 'html',
                       success: function( res ){
                           //btn.removeClass( 'disabled' );
                           btn.removeClass( 'updating-message' );
                           doc.close();
                           btn.html( FT_IMPORT_DEMO.imported );
                           btn.removeClass( 'button-primary' );
                           btn.addClass( 'button-secondary' );

                           var msg = $( '<div class="ft-import-box ft-import-theme"></div>' );
                           if ( res.indexOf( 'demo_imported' ) > -1 ) {
                               msg.addClass( 'ft-import-success').html( '<p>'+FT_IMPORT_DEMO.demo_imported+'</p>' );
                           } else if ( res.indexOf( 'no_data_found' ) > -1 ){
                               msg.addClass( 'ft-import-error').html( '<p>'+FT_IMPORT_DEMO.no_data_found+'</p>' );
                           } else if ( res.indexOf( 'demo_import_failed' ) > -1 ){
                               msg.addClass( 'ft-import-error').html( '<p>'+FT_IMPORT_DEMO.demo_import_failed+'</p>' );
                           } else {
                               msg.addClass( 'ft-import-success').html( '<p>'+FT_IMPORT_DEMO.demo_imported+'</p>' );
                           }

                           notice.html( msg );

                           ft_import_running = false;

                       }
                   } );
               }, 1000 );

            }
        } );



    } );
} );