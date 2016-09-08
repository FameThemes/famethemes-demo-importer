jQuery( document ).ready( function( $ ){

    $( '.ft-demo-import-now').on( 'click', function( e ){
        e.preventDefault();
        var btn = $( this);
        if ( btn.hasClass( 'disabled' ) ) {
            return ;
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
                       success: function( res ){
                           btn.removeClass( 'disabled' );
                           btn.removeClass( 'updating-message' );
                           doc.close();
                           btn.html( FT_IMPORT_DEMO.imported );
                       }
                   } );
               }, 1000 );

            }
        } );



    } );
} );