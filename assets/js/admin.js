/**
 * Facebook Post Scheduler Admin Scripts
 */
(function($) {
    'use strict';
    
    // Image upload frame
    var file_frame;
    
    // Når dokumentet er klar
    $(document).ready(function() {
        // Initialize Facebook SDK only if fbPostSchedulerAuth exists and we're on the right page
        if (typeof fbPostSchedulerAuth !== 'undefined' && fbPostSchedulerAuth.app_id) {
            initializeFacebookSDK();
        }
        
        // Preview-opdatering for alle opslag
        initFacebookPreviews();
        
        // Datepicker-initialisering (hvis på rediger post-side)
        if ($('[id^="fb_post_date_"]').length) {
            initDateControls();
        }
        
        // Håndter tilføjelse af nye opslag
        $('#add-fb-post').on('click', function() {
            addNewPost();
        });
        
        // Håndter fjernelse af opslag
        $(document).on('click', '.fb-remove-post', function(e) {
            e.preventDefault();
            $(this).closest('.fb-post-item').remove();
            renumberPosts();
        });
        
        // Håndter billede upload
        $(document).on('click', '.fb-upload-image', function(e) {
            e.preventDefault();
            var button = $(this);
            var index = button.data('index');
            
            // Hvis media frame allerede eksisterer, genåbn det
            if (file_frame) {
                file_frame.open();
                return;
            }
            
            // Opret media frame
            file_frame = wp.media.frames.file_frame = wp.media({
                title: fbPostScheduler.selectImage,
                button: {
                    text: fbPostScheduler.useImage
                },
                multiple: false
            });
            
            // Når et billede er valgt, kør en callback
            file_frame.on('select', function() {
                var attachment = file_frame.state().get('selection').first().toJSON();
                
                // Opdater skjult felt med billed-ID
                $('#fb_post_image_id_' + index).val(attachment.id);
                
                // Vis preview af billedet
                var img = $('<img>').attr({
                    src: attachment.url,
                    alt: attachment.alt,
                    class: 'fb-post-image-preview'
                });
                
                var previewContainer = button.siblings('.fb-post-image-preview-container');
                previewContainer.html(img);
                
                // Tilføj knap til at fjerne billedet
                if (button.siblings('.fb-remove-image').length === 0) {
                    var removeButton = $('<button>').attr({
                        type: 'button',
                        class: 'button fb-remove-image',
                        'data-index': index
                    }).text(fbPostScheduler.removeImage);
                    
                    button.after(removeButton);
                }
            });
            
            // Åbn media uprloader dialog
            file_frame.open();
        });
        
        // Håndter fjernelse af billede
        $(document).on('click', '.fb-remove-image', function(e) {
            e.preventDefault();
            var button = $(this);
            var index = button.data('index');
            
            // Nulstil skjult felt
            $('#fb_post_image_id_' + index).val('');
            
            // Fjern preview
            button.siblings('.fb-post-image-preview-container').empty();
            
            // Fjern denne knap
            button.remove();
        });
        
        // Håndter AI tekstgenerering
        $(document).on('click', '.fb-generate-ai-text', function(e) {
            e.preventDefault();
            var button = $(this);
            var index = button.data('index');
            var postId = button.data('post-id');
            var spinner = button.siblings('.fb-ai-spinner');
            var textarea = $('#fb_post_text_' + index);
            
            // Vis spinner
            spinner.addClass('is-active');
            
            // Deaktiver knap under processen
            button.prop('disabled', true);
            
            // Send AJAX-anmodning
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_generate_ai_text',
                    post_id: postId,
                    nonce: fbPostScheduler.aiNonce
                },
                success: function(response) {
                    // Skjul spinner
                    spinner.removeClass('is-active');
                    
                    // Genaktiver knap
                    button.prop('disabled', false);
                    
                    if (response.success) {
                        // Indsæt den genererede tekst i tekstfeltet
                        textarea.val(response.data.text);
                        
                        // Udløs ændringshændelse for at opdatere forhåndsvisning
                        textarea.trigger('change');
                    } else {
                        // Vis fejlbesked
                        alert(response.data.message || fbPostScheduler.aiError);
                    }
                },
                error: function() {
                    // Skjul spinner
                    spinner.removeClass('is-active');
                    
                    // Genaktiver knap
                    button.prop('disabled', false);
                    
                    // Vis generisk fejl
                    alert(fbPostScheduler.ajaxError);
                }
            });
        });
    });
    
    /**
     * Initialize Facebook SDK
     */
    function initializeFacebookSDK() {
        // Check if Facebook SDK is already loaded
        if (typeof FB !== 'undefined') {
            setupFacebookEvents();
            return;
        }
        
        // Wait for Facebook SDK to load
        window.fbAsyncInit = function() {
            try {
                FB.init({
                    appId: fbPostSchedulerAuth.app_id,
                    cookie: true,
                    xfbml: false,
                    version: 'v18.0'
                });
                
                setupFacebookEvents();
            } catch (error) {
                console.error('Facebook SDK initialization error:', error);
            }
        };
    }
    
    /**
     * Setup Facebook event handlers
     */
    function setupFacebookEvents() {
        // Facebook login button
        $('#facebook-login-btn').off('click').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Logger ind...');
            
            try {
                FB.login(function(response) {
                    if (response.authResponse) {
                        FB.api('/me', { fields: 'name,id,email' }, function(user) {
                            if (user && !user.error) {
                                $.ajax({
                                    url: fbPostSchedulerAuth.ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'fb_post_scheduler_facebook_login',
                                        nonce: fbPostSchedulerAuth.nonce,
                                        access_token: response.authResponse.accessToken,
                                        user_id: user.id,
                                        user_name: user.name,
                                        user_email: user.email || ''
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            location.reload();
                                        } else {
                                            alert(response.data || fbPostSchedulerAuth.loginError);
                                            resetLoginButton($btn);
                                        }
                                    },
                                    error: function() {
                                        alert(fbPostSchedulerAuth.ajaxError);
                                        resetLoginButton($btn);
                                    }
                                });
                            } else {
                                alert(fbPostSchedulerAuth.loginError);
                                resetLoginButton($btn);
                            }
                        });
                    } else {
                        resetLoginButton($btn);
                    }
                }, { 
                    scope: 'pages_manage_posts,pages_read_engagement,publish_to_groups,email'
                });
            } catch (error) {
                console.error('Facebook login error:', error);
                alert(fbPostSchedulerAuth.loginError);
                resetLoginButton($btn);
            }
        });

        // Facebook disconnect button
        $('#facebook-disconnect-btn').off('click').on('click', function() {
            if (confirm(fbPostSchedulerAuth.disconnectConfirm)) {
                $.ajax({
                    url: fbPostSchedulerAuth.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fb_post_scheduler_facebook_disconnect',
                        nonce: fbPostSchedulerAuth.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || fbPostSchedulerAuth.ajaxError);
                        }
                    },
                    error: function() {
                        alert(fbPostSchedulerAuth.ajaxError);
                    }
                });
            }
        });
    }
    
    /**
     * Reset login button state
     */
    function resetLoginButton($btn) {
        $btn.prop('disabled', false).text('Log ind med Facebook');
    }
    
    /**
     * Initialiserer live-preview af Facebook-opslag for alle opslag
     */
    function initFacebookPreviews() {
        var $textFields = $('[id^="fb_post_text_"]');
        
        $textFields.each(function() {
            var $textField = $(this);
            var index = $textField.attr('id').replace('fb_post_text_', '');
            var $previewText = $textField.closest('.fb-post-item').find('.fb-post-preview-text');
            
            if ($previewText.length) {
                // Opdater preview ved indlæsning
                updatePreview($textField, $previewText);
                
                // Opdater preview ved ændringer
                $textField.on('input change', function() {
                    updatePreview($textField, $previewText);
                });
            }
        });
        
        // Funktion til at opdatere preview
        function updatePreview($textField, $previewText) {
            var text = $textField.val();
            
            if (text) {
                // Erstat linjeskift med <br>
                text = text.replace(/\n/g, '<br>');
                $previewText.html(text);
                $previewText.closest('.fb-post-preview').show();
            } else {
                $previewText.html('');
                $previewText.closest('.fb-post-preview').show();
            }
        }
    }
    
    /**
     * Initialiserer datokontroller for alle opslag
     */
    function initDateControls() {
        // Tjek dato for alle datoinput
        $('[id^="fb_post_date_"], [id^="fb_post_time_"]').on('change', function() {
            var id = $(this).attr('id');
            var index = id.replace('fb_post_date_', '').replace('fb_post_time_', '');
            validateDate(index);
        });
        
        // Valider alle datoer ved indlæsning
        $('[id^="fb_post_date_"]').each(function() {
            var index = $(this).attr('id').replace('fb_post_date_', '');
            validateDate(index);
        });
        
        // Funktion til at validere dato
        function validateDate(index) {
            var dateString = $('#fb_post_date_' + index).val();
            var timeString = $('#fb_post_time_' + index).val();
            
            if (!dateString || !timeString) {
                return;
            }
            
            var selectedDate = new Date(dateString + 'T' + timeString);
            var now = new Date();
            
            // Hvis datoen er i fortiden, vis en advarsel
            if (selectedDate < now) {
                // Tilføj advarsel hvis den ikke allerede findes
                var $postItem = $('#fb_post_time_' + index).closest('.fb-post-item');
                if ($postItem.find('.date-warning').length === 0) {
                    $('<div class="notice notice-warning inline date-warning"><p>Advarsel: Den valgte dato og tid er i fortiden. Facebook-opslaget vil blive forsøgt postet straks efter du gemmer.</p></div>')
                        .insertAfter($postItem.find('[id^="fb_post_time_"]').closest('p'));
                }
            } else {
                // Fjern advarsel hvis datoen nu er i fremtiden
                $('#fb_post_time_' + index).closest('.fb-post-item').find('.date-warning').remove();
            }
        }
    }
    
    /**
     * Tilføj et nyt opslag
     */
    function addNewPost() {
        // Få antallet af eksisterende opslag
        var postCount = $('.fb-post-item').length;
        
        // Få template og erstat placeholders
        var template = $('#fb-post-template').html();
        template = template.replace(/{{index}}/g, postCount);
        template = template.replace(/{{number}}/g, postCount + 1);
        
        // Tilføj nyt opslag til containeren
        $('#fb-posts-container').append(template);
        
        // Initialiser preview og datokontroller for det nye opslag
        initFacebookPreviews();
        initDateControls();
    }
    
    /**
     * Renummerér opslagene
     */
    function renumberPosts() {
        $('.fb-post-item').each(function(i) {
            // Opdater index data-attribut
            $(this).attr('data-index', i);
            
            // Opdater overskrift
            var $header = $(this).find('h3');
            var headerText = $header.text();
            $header.text(headerText.replace(/#\d+/, '#' + (i + 1)));
            
            // Opdater input IDs og names hvor nødvendigt
            $(this).find('[id^="fb_post_"]').each(function() {
                var oldId = $(this).attr('id');
                var newId = oldId.replace(/fb_post_(\w+)_\d+/, 'fb_post_$1_' + i);
                $(this).attr('id', newId);
            });
            
            $(this).find('[name^="fb_posts["]').each(function() {
                var oldName = $(this).attr('name');
                var newName = oldName.replace(/fb_posts\[\d+\]/, 'fb_posts[' + i + ']');
                $(this).attr('name', newName);
            });
        });
    }
    
})(jQuery);
