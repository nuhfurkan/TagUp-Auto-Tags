jQuery(document).ready(function ($) {
    $('#generate-tags-button').on('click', function () {
        const postID = $('#post_ID').val();
        $('#together-loading').show();
        $('#together-tags-container').html('');

        $.ajax({
            url: together_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'together_generate_tags',
                nonce: together_ajax.nonce,
                post_id: postID
            },
            success: function (response) {
                $('#together-loading').hide();
                if (response.success && response.data.length > 0) {
                    const tags = response.data;
                    const container = $('#together-tags-container');
                    console.log(response);
                    tags.forEach(tag => {
                        const checkbox = `<label><input type="checkbox" class="together-tag" value="${tag}"> ${tag}</label><br>`;
                        container.append(checkbox);
                    });
                } else {
                    $('#together-tags-container').html('<p>No tags found.</p>');
                }
            },
            error: function () {
                $('#together-loading').hide();
                alert('Error generating tags.');
            }
        });
    });

    $('#post').on('submit', function () {
        const selected = [];
        $('.together-tag:checked').each(function () {
            selected.push($(this).val());
        });
        $('#together-selected-tags').val(selected.join(','));
    });
});
