autocompleteResponse = function(data) { console.dir(data) };

jQuery(document).ready(function () {

    jQuery('.b-autocomplete').each(function () {
        var $input = jQuery(this);
        var data = $input.data('autocomplete');

        var options = data.options || {};

        options = jQuery.extend({
            source: function (request, response) {
                autocompleteResponse = response;
                jQuery.ajax({
                    type: 'GET',
                    url: data.url,
                    async: false,
                    jsonpCallback: "autocompleteResponse",
                    contentType: "application/json",
                    dataType: 'jsonp',
                    data: {
                        q: request.term
                    }
                });
            },
            dataType: "text json",
            activeClass: 'b-autocomplete-input_active',
            resultsClass: 'b-autocomplete-results',
            parse: function (data) {
                return data;
            }
        }, options);

        $input.autocomplete(options);

        var callbacks = {};
        for (var event in data.callbacks) {
            for (var i in data.callbacks[event]) {
                eval('var f = ' + data.callbacks[event][i]);
                callbacks[event] = f;
            }
        }
        $input.autocomplete(callbacks);
    });
});
