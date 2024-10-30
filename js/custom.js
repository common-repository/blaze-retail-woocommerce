jQuery(function () {

    //Import users into blaze btn event
    jQuery('#exportUser').on('click', function (e) {
        e.preventDefault();
        var conf = confirm("Are you sure you want to import the users now?");
        if (conf == true) {

            jQuery.ajax({
                type: 'POST',
                url: custom.ajax,
                data: {
                    action: 'import_users'
                },
                beforeSend: function () {
                    jQuery('#exportUser').after(' <span id="loader" style="color:red;">   Importing now… Please don’t refresh/close your window.</span>');
                },
                success: function (response) {
                    jQuery('#loader').hide();
                    jQuery('#exportUser').after(' <span id="msg" style="color:green;">  Successfully imported!</span>');
                    console.log(response)
                }
            });
        }
    });

    //Manual Import Products Btn Event
    jQuery('#importProducts').on('click', function (e) {
        e.preventDefault();
        var conf = confirm("Are you sure you want to resync products?");
        if (conf == true) {

            jQuery.ajax({
                type: 'POST',
                url: custom.ajax,
                data: {
                    action: 'import_products'
                },
                beforeSend: function () {
                    jQuery('#loaderProductSync').hide();
                    jQuery('#msgProductSync').hide();
                    jQuery('#importProducts').after(' <span id="loaderProductSync" style="color:red;">   Refreshing Products....</span>');
                },
                success: function (response) {
                    jQuery('#loaderProductSync').hide();
                    jQuery('#msgProductSync').hide();
                    console.log(response.data.message);
                    if(response.data.message == "Product Sync process already running.") {
                        jQuery('#importProducts').after(' <span id="msgProductSync" style="color:orange;">  '+response.data.message+'</span>');
                    }else if(response.data.message == "Online Store Code is not available") {
                        jQuery('#importProducts').after(' <span id="loaderProductSync" style="color:red;">  ' + response.data.message + '</span>');
                    }else {
                        jQuery('#importProducts').after(' <span id="msgProductSync" style="color:green;">  '+response.data.message+'</span>');

                    }
                }
            });
        }
    });

    //Verify Connection
    jQuery('#verifyBlazeApiKey').on('click', function (e) {
        e.preventDefault();
        jQuery.ajax({
            type: 'POST',
            url: custom.ajax,
            data: {
                action: 'verify_connection',
                blazeApiKey: document.getElementById('Blaze_api_key').value
            },
            success: function (response) {
                jQuery('#msgVerifyConnection').empty();
                if(response.data.message == "The connection wasn't successful.") {
                    jQuery('#verifyBlazeApiKey').after(' <span id="msgVerifyConnection" style="color:red;">  '+response.data.message+'</span>');
                }
                else {
                    jQuery('#verifyBlazeApiKey').after(' <span id="msgVerifyConnection" style="color:green;">  '+response.data.message+'</span>');

                }
            }
        });

    });

})