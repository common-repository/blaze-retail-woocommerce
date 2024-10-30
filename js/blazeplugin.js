jQuery(function () {
   var currentUrl      = window.location.href;
   var url = new URL(currentUrl);
   var emailid= url.searchParams.get("email");
   if(emailid){
       jQuery("#reg_email").val(emailid);
   }
    
    

    jQuery(document).on( 'click', '#redeen-reward', function(ev) {
        jQuery( '.reward').hide();
        var code = jQuery('#coupon').val();
        data = {
            action: 'spyr_coupon_redeem_handler',
            coupon_code: code
        }
        jQuery.post( woocommerce_params.ajax_url, data, function( returned_data ) {
            if( returned_data.result == 'error' ) {
                jQuery( 'p.reward-result' ).html( returned_data.message );
            } else 
            {
                
                window.location.href = returned_data.href;
            }
        })
        ev.preventDefault();
    }); 
    //jQuery("tr th:contains(Discount)").parent().hide();
    jQuery(document).on('click', '.woocommerce-remove-coupon-blaze', function () {
        var couponcode = 'remove'+"-"+jQuery(this).attr("data-coupon");
        jQuery("#coupon_code").val(couponcode);
        jQuery("#coupon_code").removeAttr("required");
        jQuery('button[name=apply_coupon]').click();
        console.log(couponcode);
    })
    jQuery(document).on('click', '.woocommerce-remove-reward-blaze', function () {
        var couponcode = 'remove';
        var select = jQuery('#coupon');
        select.empty().append('<option value="Choose any reward name remove">Choose any reward name remove</option>');
        jQuery('option[value="Choose any reward name remove"]').prop('selected', true);
        jQuery( '#redeen-reward').trigger('click');
    })
    jQuery(document).on( 'click', '#select-paymnet', function(ev) {   
        ev.preventDefault();
        var paymentmethod = jQuery( '#payment_option option:selected').text();
        data = {
            action: 'payment_method_handler',
            payment_method: paymentmethod
        }
        jQuery.post( woocommerce_params.ajax_url, data, function( returned_data ) {
            if( returned_data.result == 'error' ) {
                jQuery( 'p.reward-result' ).html( returned_data.message );
            } else 
            {
                window.location.href = returned_data.href;
            }
        })
        
    })
})