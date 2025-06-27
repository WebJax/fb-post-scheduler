/**
 * Facebook Post Scheduler Admin Scripts
 */
(function($) {
    'use strict';
    
    // Image upload frame
    var file_frame;
    
    // Når dokumentet er klar
    $(document).ready(function() {
        // Bind token management buttons directly
        bindTokenManagementButtons();
        
        // Also try binding after a short delay in case elements load later
        setTimeout(function() {
            bindTokenManagementButtons();
        }, 500);
        
        // Try again with a longer delay for settings pages
        setTimeout(function() {
            bindTokenManagementButtons();
        }, 2000);
        
        // Fallback event delegation method
        setupFallbackEventHandlers();
        
        // Ensure meta box is positioned correctly in Gutenberg
        ensureMetaBoxPosition();
        
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
                url: fbPostScheduler.ajaxUrl,
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
        
        // Håndter slet planlagte opslag fra admin listen
        $(document).on('click', '.fb-delete-scheduled-post', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var postId = button.data('post-id');
            var postIndex = button.data('index');
            var scheduledId = button.data('scheduled-id');
            var row = button.closest('tr');
            
            if (!confirm('Er du sikker på, at du vil slette dette planlagte opslag?')) {
                return;
            }
            
            // Disable button og vis loading
            button.prop('disabled', true).text('Sletter...');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_delete_scheduled',
                    post_id: postId,
                    post_index: postIndex,
                    scheduled_id: scheduledId,
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Fjern rækken fra tabellen
                        row.fadeOut(300, function() {
                            $(this).remove();
                            
                            // Hvis der ikke er flere rækker, vis "ingen opslag" besked
                            var tbody = $('#scheduled-posts-table tbody');
                            if (tbody.find('tr').length === 0) {
                                tbody.html('<tr><td colspan="6">Ingen planlagte opslag fundet.</td></tr>');
                            }
                        });
                        
                        // Vis success besked
                        if (typeof response.data.message !== 'undefined') {
                            showNotice(response.data.message, 'success');
                        }
                    } else {
                        // Genaktiver knap
                        button.prop('disabled', false).text('Slet');
                        
                        // Vis fejl besked
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        showNotice(message, 'error');
                    }
                },
                error: function() {
                    // Genaktiver knap
                    button.prop('disabled', false).text('Slet');
                    showNotice('Der opstod en netværksfejl', 'error');
                }
            });
        });
    });
    
    /**
     * Bind token management buttons
     */
    function bindTokenManagementButtons() {
        // Facebook API test knap
        $('#fb-test-connection').off('click').on('click', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-test-spinner');
            var resultDiv = $('#fb-test-result');
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Tester...');
            spinner.addClass('is-active');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_test_api_connection',
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Test Facebook API Forbindelse');
                    spinner.removeClass('is-active');
                }
            });
        });
        
        // Facebook token udløbstjek
        $('#fb-check-token-expiry').off('click').on('click', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-test-spinner');
            var resultDiv = $('#fb-test-result');
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Tjekker...');
            spinner.addClass('is-active');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_check_token_expiry',
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var noticeClass = response.data.status === 'warning' ? 'notice-warning' : 'notice-success';
                        resultDiv.html('<div class="notice ' + noticeClass + ' inline"><p>' + response.data.message + '</p></div>');
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Tjek Token Udløb');
                    spinner.removeClass('is-active');
                }
            });
        });
        
        // Long-term token udveksling
        $('#fb-exchange-token').off('click').on('click', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-exchange-spinner');
            var resultDiv = $('#fb-exchange-result');
            var shortTermToken = $('#fb-short-term-token').val();
            
            if (!shortTermToken.trim()) {
                resultDiv.html('<div class="notice notice-error inline"><p>❌ Indtast venligst et short-term access token</p></div>');
                return;
            }
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Udveksler...');
            spinner.addClass('is-active');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_exchange_token',
                    short_term_token: shortTermToken,
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        
                        // Opdater access token feltet med det nye token
                        $('input[name="fb_post_scheduler_facebook_access_token"]').val(response.data.token_info.access_token);
                        
                        // Ryd short-term token feltet
                        $('#fb-short-term-token').val('');
                        
                        // Vis besked om at gemme indstillinger
                        setTimeout(function() {
                            resultDiv.append('<div class="notice notice-info inline" style="margin-top: 10px;"><p>💡 Husk at klikke "Gem ændringer" for at gemme det nye token permanent.</p></div>');
                        }, 1000);
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Udveksle til Long-term Token');
                    spinner.removeClass('is-active');
                }
            });
        });
        
        // Bind page selection buttons  
        bindPageSelectionButtons();
    }
    
    /**
     * Setup fallback event handlers using event delegation
     */
    function setupFallbackEventHandlers() {
        // Use document-level event delegation as fallback
        $(document).off('click.fb-token-management').on('click.fb-token-management', '#fb-test-connection', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-test-spinner');
            var resultDiv = $('#fb-test-result');
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Tester...');
            spinner.addClass('is-active');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_test_api_connection',
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Test Facebook API Forbindelse');
                    spinner.removeClass('is-active');
                }
            });
        });
        
        $(document).off('click.fb-token-management').on('click.fb-token-management', '#fb-check-token-expiry', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-test-spinner');
            var resultDiv = $('#fb-test-result');
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Tjekker...');
            spinner.addClass('is-active');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_check_token_expiry',
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var noticeClass = response.data.status === 'warning' ? 'notice-warning' : 'notice-success';
                        resultDiv.html('<div class="notice ' + noticeClass + ' inline"><p>' + response.data.message + '</p></div>');
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Tjek Token Udløb');
                    spinner.removeClass('is-active');
                }
            });
        });
        
        $(document).off('click.fb-token-management').on('click.fb-token-management', '#fb-exchange-token', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-exchange-spinner');
            var resultDiv = $('#fb-exchange-result');
            var shortTermToken = $('#fb-short-term-token').val();
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            if (!shortTermToken.trim()) {
                resultDiv.html('<div class="notice notice-error inline"><p>❌ Indtast venligst et short-term access token</p></div>');
                return;
            }
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Udveksler...');
            spinner.addClass('is-active');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_exchange_token',
                    short_term_token: shortTermToken,
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        
                        // Opdater access token feltet med det nye token
                        $('input[name="fb_post_scheduler_facebook_access_token"]').val(response.data.token_info.access_token);
                        
                        // Ryd short-term token feltet
                        $('#fb-short-term-token').val('');
                        
                        // Vis besked om at gemme indstillinger
                        setTimeout(function() {
                            resultDiv.append('<div class="notice notice-info inline" style="margin-top: 10px;"><p>💡 Husk at klikke "Gem ændringer" for at gemme det nye token permanent.</p></div>');
                        }, 1000);
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Udveksle til Long-term Token');
                    spinner.removeClass('is-active');
                }
            });
        });
    }
    
    /**
     * Ensure meta box is positioned correctly
     */
    function ensureMetaBoxPosition() {
        // Check if we're in Gutenberg editor
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/edit-post')) {
            // Wait for Gutenberg to fully load
            var attempts = 0;
            var maxAttempts = 20;
            
            function checkAndPosition() {
                attempts++;
                var metaBox = $('#fb_post_scheduler_meta_box');
                
                if (metaBox.length > 0) {
                    // Move meta box to the bottom of the normal context
                    var normalArea = $('.edit-post-meta-boxes-area__container .metabox-location-normal');
                    if (normalArea.length > 0) {
                        metaBox.appendTo(normalArea);
                    }
                } else if (attempts < maxAttempts) {
                    // Try again after a short delay
                    setTimeout(checkAndPosition, 500);
                }
            }
            
            // Start checking
            setTimeout(checkAndPosition, 1000);
        } else {
            // Classic editor - use CSS order
            setTimeout(function() {
                var metaBox = $('#fb_post_scheduler_meta_box');
                if (metaBox.length > 0) {
                    // Move to bottom of normal meta boxes
                    metaBox.parent().append(metaBox);
                }
            }, 500);
        }
    }
    
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
    
    /**
     * Viser en notice besked til brugeren
     */
    function showNotice(message, type) {
        type = type || 'info';
        
        // Fjern eksisterende notices
        $('.fb-admin-notice').remove();
        
        // Opret ny notice
        var notice = $('<div class="fb-admin-notice notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        
        // Tilføj til siden
        $('.wrap h1').after(notice);
        
        // Auto-fjern efter 5 sekunder
        setTimeout(function() {
            notice.fadeOut(function() {
                notice.remove();
            });
        }, 5000);
        
        // Håndter dismiss knap
        notice.on('click', '.notice-dismiss', function() {
            notice.remove();
        });
    }
    
    // Facebook Page Selection functionality
    function bindPageSelectionButtons() {
        // Gem bruger access token
        $(document).off('click.fb-page-selection').on('click.fb-page-selection', '#fb-save-user-token', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var userToken = $('#fb-user-access-token').val();
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            if (!userToken.trim()) {
                alert('Indtast venligst dit bruger access token');
                return;
            }
            
            // Disable button
            button.prop('disabled', true).text('Gemmer...');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_save_user_token',
                    nonce: fbPostScheduler.nonce,
                    user_token: userToken
                },
                success: function(response) {
                    if (response.success) {
                        $('#fb-pages-result').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        $('#fb-pages-result').html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    $('#fb-pages-result').html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap
                    button.prop('disabled', false).text('Gem Token');
                }
            });
        });
        
        // Indlæs Facebook Pages
        $(document).off('click.fb-page-selection').on('click.fb-page-selection', '#fb-load-pages', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-pages-spinner');
            var resultDiv = $('#fb-pages-result');
            var dropdownContainer = $('#fb-pages-dropdown-container');
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Indlæser...');
            spinner.addClass('is-active');
            resultDiv.html('');
            dropdownContainer.hide();
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_load_facebook_pages',
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        
                        // Populer dropdown med sider
                        var dropdown = $('#fb-pages-dropdown');
                        dropdown.empty().append('<option value="">-- Vælg en side --</option>');
                        
                        if (response.data.pages && response.data.pages.length > 0) {
                            $.each(response.data.pages, function(index, page) {
                                dropdown.append('<option value="' + page.id + '" data-name="' + page.name + '" data-token="' + page.access_token + '">' + page.name + ' (' + page.category + ')</option>');
                            });
                            
                            dropdownContainer.show();
                        }
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Indlæs tilgængelige sider');
                    spinner.removeClass('is-active');
                }
            });
        });
        
        // Vælg Facebook Page
        $(document).off('click.fb-page-selection').on('click.fb-page-selection', '#fb-select-page', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var dropdown = $('#fb-pages-dropdown');
            var selectedOption = dropdown.find('option:selected');
            var resultDiv = $('#fb-page-selection-result');
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            if (!selectedOption.val()) {
                alert('Vælg venligst en Facebook-side');
                return;
            }
            
            var pageId = selectedOption.val();
            var pageName = selectedOption.data('name');
            var pageAccessToken = selectedOption.data('token');
            
            // Disable button
            button.prop('disabled', true).text('Konfigurerer...');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_select_facebook_page',
                    nonce: fbPostScheduler.nonce,
                    page_id: pageId,
                    page_name: pageName,
                    page_access_token: pageAccessToken
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        
                        // Opdater Page ID feltene på siden
                        $('input[name="fb_post_scheduler_facebook_page_id"]').val(pageId);
                        
                        // Genindlæs siden efter 2 sekunder for at vise den nye side-info
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap
                    button.prop('disabled', false).text('Vælg denne side');
                }
            });
        });
        
        // Forny page token
        $(document).off('click.fb-page-selection').on('click.fb-page-selection', '#fb-renew-page-token', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-renew-spinner');
            var resultDiv = $('#fb-page-selection-result');
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            if (!confirm('Er du sikker på, at du vil forny page access token?')) {
                return;
            }
            
            // Disable button og vis spinner
            button.prop('disabled', true).text('Fornyer...');
            spinner.addClass('is-active');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_renew_page_token',
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    } else {
                        var message = response.data && response.data.message ? response.data.message : 'Der opstod en fejl';
                        resultDiv.html('<div class="notice notice-error inline"><p>❌ ' + message + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error inline"><p>❌ Der opstod en netværksfejl</p></div>');
                },
                complete: function() {
                    // Genaktiver knap og skjul spinner
                    button.prop('disabled', false).text('Forny Token');
                    spinner.removeClass('is-active');
                }
            });
        });
    }
    
    // Bind page selection buttons on document ready
    $(document).ready(function() {
        // Page selection buttons er allerede bound via bindTokenManagementButtons
    });

})(jQuery);
