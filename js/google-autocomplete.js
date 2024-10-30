var BlazeMyAccountAutocomplete = BlazeMyAccountAutocomplete || {};

BlazeMyAccountAutocomplete.method = {
    placeSearch: "",
    autocomplete: "",
    initialize: function (country) {
        this.initFormFields();

        var address = document.getElementById('Blaze_full');

        this.autocomplete = new google.maps.places.Autocomplete(
                (address),
                {
                    types: ['geocode'],
                    componentRestrictions: {
                        country: country
                    }
                });

        google.maps.event.addListener(this.autocomplete, 'place_changed', function (event) {
            BlazeMyAccountAutocomplete.method.fillInAddress()
        });

        address.addEventListener("focus", function (event) {
            BlazeMyAccountAutocomplete.method.geolocate();
        }, true);

        google.maps.event.addDomListener(address, 'keydown', function (e) {
            if (e.keyCode == 13) {
                e.preventDefault();
            }
        })

    },
    initFormFields: function ()
    {
        this.formFields =
        {
            'Blaze_street': '',
            'Blaze_city': '',
            'Blaze_state': '',
            'Blaze_postal_code': '',
        };

        this.componentForm =
        {
            'street_number': ['Blaze_street', 'short_name'],
            'route': ['Blaze_street', 'long_name'],
            'locality': ['Blaze_city', 'long_name'],
            'administrative_area_level_1': ['Blaze_state', 'short_name'],
            'postal_code': ['Blaze_postal_code', 'short_name']
        };
    },
    fillInAddress: function () {
        var place = this.autocomplete.getPlace();

        for (var field in this.formFields) {
            document.getElementById(field).value = '';
            document.getElementById(field).disabled = false;
        }

        for (var i = 0; i < place.address_components.length; i++) {
            var addressType = place.address_components[i].types[0];
            if (this.componentForm[addressType]) {
                if (addressType == 'street_number') {
                    var value = place.address_components[i][this.componentForm[addressType][1]];
                    var current = document.getElementById(this.componentForm[addressType][0]);
                    this.setStreetAddress(current, value);
                } else if (addressType == 'route') {
                    var value = place.address_components[i][this.componentForm[addressType][1]];
                    var current = document.getElementById(this.componentForm[addressType][0]);
                    this.setStreetAddress(current, value);
                } else {
                    var val = place.address_components[i][this.componentForm[addressType][1]];
                    document.getElementById(this.componentForm[addressType][0]).value = val;
                }
            }
        }
    },
    setStreetAddress: function (current, value) {
        var arr = [];
        if (current.value.length > 0) {
            arr.push(current.value);
            arr.push(value);
            current.value = arr.join(" ");
        } else {
            current.value = value;
        }
    },
    geolocate: function () {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (position) {
                var geolocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                var circle = new google.maps.Circle({
                    center: geolocation,
                    radius: position.coords.accuracy
                });
                this.autocomplete.setBounds(circle.getBounds());
            });
        }
    }

};
