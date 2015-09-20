$('#shorten').click(function() {
    var url = encodeURIComponent($('#url').val());
    $.getJSON(
        'shorten?url=' + url,
        function(data) {
            if (data.status == 1) {
                $('#url').val(data.s_url);
            } else {
                alert(data.msg);
            }
        }
    )
});

$('#expand').click(function() {
    var s_url = $('#url').val();
    $.getJSON(
        'expand?s_url=' + s_url,
        function(data) {
            if (data.status == 1) {
                $('#url').val(data.url);
            } else {
                alert(data.msg);
            }
        }
    )
});
