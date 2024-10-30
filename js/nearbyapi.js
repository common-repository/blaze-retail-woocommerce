let autocomplete;
let address1Field;
let clearNearby;

function initAutocomplete() {
    address1Field = document.querySelector("#ship-address");
    clearNearby = document.querySelector('#clear-nearby');

    // Create the autocomplete object, restricting the search predictions to
    // addresses in the US and Canada.
    autocomplete = new google.maps.places.Autocomplete(address1Field, {
        componentRestrictions: { country: ["us", "ca"] },
        fields: ["address_components", "geometry"],
        types: ["address"],
    });
    address1Field.focus();
    // When the user selects an address from the drop-down, populate the
    // address fields in the form.
    autocomplete.addListener("place_changed", submitAddress);
    clearNearby.addEventListener('click', clearAddress);
    address1Field.addEventListener('input', manageClear);
    manageClear();
}

function manageClear() {
    if(address1Field.value.trim() == "") {
        clearNearby.style.display = "none";
    }
    else {
        clearNearby.style.display = "inline";
    }
}

function submitAddress() {
    const place = autocomplete.getPlace();
    
    let lat = place.geometry.location.lat();
    let long = place.geometry.location.lng();
    let address = address1Field.value;
    

    // set ajax data
    var data = {
        'action' : 'submit_nearby_api',
        'lat' : lat,
        'long' : long,
        'address' : address
    };
    
    jQuery.post( settings.url, data, function( response ) {

        console.log( response );
        location.reload();
    } );
}


function clearAddress() {
    address1Field.value = "";

    // set ajax data
    var data = {
        'action' : 'submit_nearby_api',
        'clear': true
    };
    
    jQuery.post( settings.url, data, function( response ) {
        console.log( response );
        console.log("clearing");
        location.reload();
    } );  
}
