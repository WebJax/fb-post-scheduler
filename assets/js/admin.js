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
        bindSavedMentionPages();
        
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
                
                var $postItem = button.closest('.fb-post-item');
                $postItem.find('.fb-post-image-preview-container').html(img);
                applyLocalPreview($postItem.find('.fb-post-preview'));
                
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
            
            var $postItem = button.closest('.fb-post-item');
            $postItem.find('.fb-post-image-preview-container').empty();
            applyLocalPreview($postItem.find('.fb-post-preview'));
            
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

        // Slet opslagsinformationer fra meta box (postet opslag)
        $(document).on('click', '.fb-clear-post-record', function(e) {
            e.preventDefault();

            if (!confirm(fbPostScheduler.clearRecordConfirm)) {
                return;
            }

            var button = $(this);
            var postId = button.data('post-id');
            var postIndex = button.data('post-index');

            button.prop('disabled', true).text('Sletter...');

            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_clear_post_record',
                    post_id: postId,
                    post_index: postIndex,
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Genindlæs siden så meta box opdateres
                        window.location.reload();
                    } else {
                        button.prop('disabled', false).text('Slet opslagsinformationer');
                        var message = response.data && response.data.message ? response.data.message : fbPostScheduler.ajaxError;
                        showNotice(message, 'error');
                    }
                },
                error: function() {
                    button.prop('disabled', false).text('Slet opslagsinformationer');
                    showNotice(fbPostScheduler.ajaxError, 'error');
                }
            });
        });

        // Slet postet opslag fra admin-oversigten
        $(document).on('click', '.fb-delete-posted-record', function(e) {
            e.preventDefault();

            if (!confirm(fbPostScheduler.clearRecordConfirm)) {
                return;
            }

            var button = $(this);
            var postId = button.data('post-id');
            var postIndex = button.data('post-index');
            var row = button.closest('tr');

            button.prop('disabled', true).text('Sletter...');

            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_clear_post_record',
                    post_id: postId,
                    post_index: postIndex,
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        row.fadeOut(300, function() {
                            $(this).remove();
                            var tbody = $('#posted-posts-table tbody');
                            if (tbody.find('tr').length === 0) {
                                tbody.html('<tr><td colspan="5">' + 'Ingen postede Facebook-opslag fundet.' + '</td></tr>');
                            }
                        });
                        if (typeof response.data.message !== 'undefined') {
                            showNotice(response.data.message, 'success');
                        }
                    } else {
                        button.prop('disabled', false).text('Slet');
                        var message = response.data && response.data.message ? response.data.message : fbPostScheduler.ajaxError;
                        showNotice(message, 'error');
                    }
                },
                error: function() {
                    button.prop('disabled', false).text('Slet');
                    showNotice(fbPostScheduler.ajaxError, 'error');
                }
            });
        });
    });
    
    /**
     * Bind token management buttons
     */
    function bindTokenManagementButtons() {
        console.log('bindTokenManagementButtons');
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
        
        // Bind group selection buttons
        bindGroupSelectionButtons();
        
        // Bind group selection buttons
        bindGroupSelectionButtons();
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
     * Escape text and render @[PAGE_ID] / @[PAGE_ID:Name] as a blue @-mention.
     */
    function formatPreviewMentions(text) {
        var escaped = $('<div>').text(text || '').html();
        escaped = escaped.replace(/\n/g, '<br>');
        escaped = escaped.replace(/@\[(\d+)(?::([^\]]+))?\]/g, function(match, id, name) {
            var label = name || id;
            return '<span class="fb-page-mention" data-page-id="' + id + '">@' + label + '</span>';
        });
        return escaped;
    }

    /**
     * @param {string} source
     * @return {string}
     */
    function getPreviewSourceLabel(source) {
        var labels = {
            selected: fbPostScheduler.previewSourceSelected,
            og: fbPostScheduler.previewSourceOg,
            featured: fbPostScheduler.previewSourceFeatured,
            facebook: fbPostScheduler.previewSourceFacebook,
            empty: fbPostScheduler.previewSourceEmpty
        };

        return labels[source] || labels.empty;
    }

    /**
     * @param {jQuery} $imageWrap
     * @param {string} imageUrl
     * @param {string} imageAlt
     */
    function setPreviewImage($imageWrap, imageUrl, imageAlt) {
        if (!$imageWrap.length) {
            return;
        }

        if (imageUrl) {
            $imageWrap.html($('<img>').attr({
                class: 'fb-post-preview-image-element',
                src: imageUrl,
                alt: imageAlt || ''
            }));
            return;
        }

        $imageWrap.html(
            $('<div>').addClass('fb-post-preview-image-placeholder').text(fbPostScheduler.previewPlaceholder)
        );
    }

    /**
     * @param {jQuery} $previewItem
     * @param {string} message
     * @param {boolean} [isSuccess]
     */
    function setPreviewError($previewItem, message, isSuccess) {
        var $error = $previewItem.find('.fb-post-preview-error');
        $error.toggleClass('is-success', !!isSuccess);
        if (message) {
            $error.text(message).prop('hidden', false);
        } else {
            $error.text('').prop('hidden', true);
        }
    }

    /**
     * @param {jQuery} $previewItem
     * @param {boolean} isBusy
     */
    function setPreviewBusy($previewItem, isBusy) {
        $previewItem.find('.fb-check-facebook-preview, .fb-refresh-facebook-cache').prop('disabled', isBusy);
        $previewItem.find('.fb-preview-spinner').toggleClass('is-active', isBusy);
        $previewItem.attr('aria-busy', isBusy ? 'true' : 'false');
    }

    /**
     * @param {jQuery} $previewItem
     * @param {{imageUrl?: string, imageAlt?: string, title?: string, description?: string, siteName?: string, source: string}} card
     */
    function renderPreviewCard($previewItem, card) {
        setPreviewImage(
            $previewItem.find('.fb-post-preview-image'),
            card.imageUrl || '',
            card.imageAlt || ''
        );

        $previewItem.find('.fb-post-preview-title').text(card.title || '');
        $previewItem.find('.fb-post-preview-description').text(card.description || '');
        if (card.siteName) {
            $previewItem.find('.fb-post-preview-website-name').text(card.siteName);
        }

        $previewItem.attr('data-source', card.source);
        $previewItem.find('.fb-post-preview-source').text(getPreviewSourceLabel(card.source));
        $previewItem.toggleClass('is-facebook', card.source === 'facebook');
        $previewItem.find('.fb-reset-facebook-preview').prop('hidden', card.source !== 'facebook');
    }

    /**
     * Forlad Facebooks cache-tilstand og vis A/B/C.
     *
     * @param {jQuery} $previewItem
     */
    function applyLocalPreview($previewItem) {
        if (!$previewItem.length) {
            return;
        }

        var $postItem = $previewItem.closest('.fb-post-item');
        var $selectedImage = $postItem.find('.fb-post-image-preview-container img');
        var selectedUrl = $selectedImage.attr('src') || '';
        var selectedAlt = $selectedImage.attr('alt') || '';
        var ogImageUrl = $previewItem.attr('data-og-image-url') || '';
        var featuredUrl = $previewItem.attr('data-featured-image-url') || '';
        var featuredAlt = $previewItem.attr('data-featured-image-alt') || '';
        var source = 'empty';
        var imageUrl = '';
        var imageAlt = '';

        if (selectedUrl) {
            source = 'selected';
            imageUrl = selectedUrl;
            imageAlt = selectedAlt;
        } else if (ogImageUrl) {
            source = 'og';
            imageUrl = ogImageUrl;
        } else if (featuredUrl) {
            source = 'featured';
            imageUrl = featuredUrl;
            imageAlt = featuredAlt;
        }

        renderPreviewCard($previewItem, {
            imageUrl: imageUrl,
            imageAlt: imageAlt,
            title: $previewItem.attr('data-og-title') || '',
            description: $previewItem.attr('data-og-description') || '',
            siteName: $previewItem.attr('data-og-site-name') || '',
            source: source
        });
        setPreviewError($previewItem, '');
        updatePreviewActor($previewItem);
        updatePreviewText($postItem);
    }

    /**
     * @param {jQuery} $previewItem
     * @param {Object} data
     */
    function applyFacebookPreview($previewItem, data) {
        renderPreviewCard($previewItem, {
            imageUrl: data.image_url || '',
            imageAlt: '',
            title: data.title || '',
            description: data.description || '',
            siteName: data.site_name || '',
            source: 'facebook'
        });
        setPreviewError($previewItem, data.refreshed ? (fbPostScheduler.facebookCacheUpdated || '') : '', !!data.refreshed);
    }

    /**
     * @param {jQuery} $postItem
     */
    function updatePreviewText($postItem) {
        var $textField = $postItem.find('textarea[id^="fb_post_text_"]');
        var $previewText = $postItem.find('.fb-post-preview-text');
        var text = $textField.val() || '';

        $previewText.html(text ? formatPreviewMentions(text) : '');
    }

    /**
     * @param {jQuery} $previewItem
     */
    function updatePreviewActor($previewItem) {
        var $postItem = $previewItem.closest('.fb-post-item');
        var $target = $postItem.find('input[name*="[target_type]"]:checked');
        if (!$target.length) {
            $target = $postItem.find('input[name*="[target_type]"]').first();
        }
        var target = $target.val() || 'page';
        var pageName = $previewItem.attr('data-page-name') || '';
        var groupName = $previewItem.attr('data-group-name') || '';
        var actorName = (target === 'group' && groupName) ? groupName : pageName;
        var dateVal = $postItem.find('input[id^="fb_post_date_"]').val() || '';
        var timeVal = $postItem.find('input[id^="fb_post_time_"]').val() || '';
        var meta = (dateVal && timeVal) ? (dateVal + ' · ' + timeVal) : '';

        $previewItem.find('.fb-post-preview-actor-name').text(actorName);
        $previewItem.find('.fb-post-preview-actor-meta').text(meta);
    }

    /**
     * @param {jQuery} $previewItem
     * @param {boolean} [forceOpen]
     */
    function togglePreview($previewItem, forceOpen) {
        var $content = $previewItem.find('.fb-post-preview-content');
        var $toggle = $previewItem.find('.fb-post-preview-toggle');
        var shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : !$previewItem.hasClass('is-open');

        $previewItem.toggleClass('is-open', shouldOpen);
        $toggle.attr('aria-expanded', shouldOpen ? 'true' : 'false');

        if (shouldOpen) {
            $content.stop(true, true).slideDown(200);
        } else {
            $content.stop(true, true).slideUp(200);
        }
    }

    /**
     * @param {jQuery} $previewItem
     * @param {boolean} refresh
     */
    async function requestFacebookPreview($previewItem, refresh) {
        var postId = $previewItem.attr('data-post-id') || '';
        if (!postId) {
            setPreviewError($previewItem, fbPostScheduler.facebookPreviewError);
            return;
        }

        setPreviewBusy($previewItem, true);
        setPreviewError($previewItem, '');

        try {
            var body = new URLSearchParams({
                action: 'fb_post_scheduler_fetch_facebook_preview',
                nonce: fbPostScheduler.nonce,
                post_id: postId,
                refresh: refresh ? '1' : '0'
            });
            var res = await fetch(fbPostScheduler.ajaxUrl, {
                method: 'POST',
                body: body
            });
            var json = await res.json();

            if (json && json.success && json.data) {
                applyFacebookPreview($previewItem, json.data);
            } else {
                var message = (json && json.data && json.data.message)
                    ? json.data.message
                    : fbPostScheduler.facebookPreviewError;
                setPreviewError($previewItem, message);
            }
        } catch (error) {
            setPreviewError($previewItem, fbPostScheduler.ajaxError || fbPostScheduler.facebookPreviewError);
        } finally {
            setPreviewBusy($previewItem, false);
        }
    }

    var previewEventsBound = false;

    /**
     * Initialiserer live-preview af Facebook-opslag for alle opslag
     */
    function initFacebookPreviews() {
        if (!previewEventsBound) {
            previewEventsBound = true;

            $(document).on('input change', 'textarea[id^="fb_post_text_"]', function() {
                updatePreviewText($(this).closest('.fb-post-item'));
            });

            $(document).on('click', '.fb-post-preview-toggle', function(e) {
                e.preventDefault();
                togglePreview($(this).closest('.fb-post-preview'));
            });

            $(document).on('keydown', '.fb-post-preview-toggle', function(e) {
                if (e.which === 13 || e.which === 32) {
                    e.preventDefault();
                    togglePreview($(this).closest('.fb-post-preview'));
                }
            });

            $(document).on('change', '.fb-post-item input[name*="[target_type]"], .fb-post-item input[id^="fb_post_date_"], .fb-post-item input[id^="fb_post_time_"]', function() {
                updatePreviewActor($(this).closest('.fb-post-item').find('.fb-post-preview'));
            });

            $(document).on('click', '.fb-check-facebook-preview', function(e) {
                e.preventDefault();
                var $previewItem = $(this).closest('.fb-post-preview');
                togglePreview($previewItem, true);
                requestFacebookPreview($previewItem, false);
            });

            $(document).on('click', '.fb-refresh-facebook-cache', function(e) {
                e.preventDefault();
                if (!window.confirm(fbPostScheduler.facebookCacheConfirm)) {
                    return;
                }
                var $previewItem = $(this).closest('.fb-post-preview');
                togglePreview($previewItem, true);
                requestFacebookPreview($previewItem, true);
            });

            $(document).on('click', '.fb-reset-facebook-preview', function(e) {
                e.preventDefault();
                applyLocalPreview($(this).closest('.fb-post-preview'));
            });
        }

        $('.fb-post-preview').each(function() {
            applyLocalPreview($(this));
        });
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

        var $newItem = $('#fb-posts-container .fb-post-item').last();
        applyLocalPreview($newItem.find('.fb-post-preview'));
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
        console.log('bindPageSelectionButtons');
        // Gem bruger access token
        $('#fb-save-user-token').click('', function(e) {
            console.log('save user token');
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
        $('#fb-load-pages').on('click', function(e) {
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
        $('#fb-select-page').on('click', function(e) {
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
    
    // Facebook Group Selection functionality
    function bindGroupSelectionButtons() {
        // Indlæs Facebook Groups
        $('#fb-load-groups').on('click', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#fb-groups-spinner');
            var resultDiv = $('#fb-groups-result');
            var dropdownContainer = $('#fb-groups-dropdown-container');
            var userToken = $('#fb-user-access-token').val();
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            if (!userToken.trim()) {
                resultDiv.html('<div class="notice notice-error inline"><p>❌ Indtast venligst dit bruger access token først i "Vælg Facebook Side" sektionen</p></div>');
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
                    action: 'fb_post_scheduler_load_groups',
                    nonce: fbPostScheduler.nonce,
                    user_access_token: userToken
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        
                        // Populer dropdown med grupper
                        var dropdown = $('#fb-groups-dropdown');
                        dropdown.empty().append('<option value="">-- Vælg en gruppe --</option>');
                        
                        if (response.data.groups && response.data.groups.length > 0) {
                            $.each(response.data.groups, function(index, group) {
                                dropdown.append('<option value="' + group.id + '" data-name="' + group.name + '">' + group.name + ' (' + group.privacy + ')</option>');
                            });
                            
                            dropdownContainer.show();
                        } else {
                            resultDiv.html('<div class="notice notice-info inline"><p>ℹ️ ' + response.data.message + '</p></div>');
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
                    button.prop('disabled', false).text('Indlæs tilgængelige grupper');
                    spinner.removeClass('is-active');
                }
            });
        });
        
        // Vælg Facebook Group
        $(document).off('click.fb-group-selection').on('click.fb-group-selection', '#fb-select-group', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var dropdown = $('#fb-groups-dropdown');
            var selectedGroupId = dropdown.val();
            var selectedGroupName = dropdown.find('option:selected').data('name');
            var resultDiv = $('#fb-group-selection-result');
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            if (!selectedGroupId) {
                resultDiv.html('<div class="notice notice-error inline"><p>❌ Vælg venligst en gruppe</p></div>');
                return;
            }
            
            // Disable button
            button.prop('disabled', true).text('Vælger...');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_select_group',
                    nonce: fbPostScheduler.nonce,
                    group_id: selectedGroupId,
                    group_name: selectedGroupName
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        
                        // Genindlæs siden efter 2 sekunder for at vise den valgte gruppe
                        setTimeout(function() {
                            window.location.reload();
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
                    button.prop('disabled', false).text('Vælg denne gruppe');
                }
            });
        });
        
        // Ryd gruppe-valg
        $(document).off('click.fb-group-selection').on('click.fb-group-selection', '#fb-clear-group', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var resultDiv = $('#fb-group-selection-result');
            
            if (typeof fbPostScheduler === 'undefined') {
                console.error('fbPostScheduler object not available');
                return;
            }
            
            if (!confirm('Er du sikker på, at du vil rydde gruppe-valget?')) {
                return;
            }
            
            // Disable button
            button.prop('disabled', true).text('Rydder...');
            resultDiv.html('');
            
            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_clear_group',
                    nonce: fbPostScheduler.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        
                        // Genindlæs siden efter 2 sekunder
                        setTimeout(function() {
                            window.location.reload();
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
                    button.prop('disabled', false).text('Ryd gruppe-valg');
                }
            });
        });
    }

    /**
     * Indstillinger: tilføj/fjern gemte sider til @[mention]-autocomplete.
     */
    function bindSavedMentionPages() {
        var $root = $('#fb-saved-mention-pages');
        if (!$root.length || typeof fbPostScheduler === 'undefined') {
            return;
        }

        function renderSavedPagesList(pages) {
            var $list = $('#fb-saved-pages-list');
            $list.empty();

            if (!pages || !pages.length) {
                $list.append(
                    $('<li>', {
                        class: 'fb-saved-pages-empty',
                        text: fbPostScheduler.savedPagesEmpty || 'Ingen gemte sider endnu.'
                    })
                );
                return;
            }

            $.each(pages, function(index, page) {
                if (!page || !page.id || !page.name) {
                    return;
                }

                var $item = $('<li>', { 'data-page-id': page.id });
                $item.append($('<span>', { class: 'fb-saved-page-name', text: page.name }));
                $item.append($('<span>', { class: 'fb-saved-page-id', text: page.id }));
                $item.append(
                    $('<button>', {
                        type: 'button',
                        class: 'button-link-delete fb-remove-saved-page',
                        'data-page-id': page.id,
                        text: fbPostScheduler.savedPageRemoveLabel || 'Fjern'
                    })
                );
                $list.append($item);
            });
        }

        function showSavedPagesMessage(type, message) {
            var className = type === 'success' ? 'notice-success' : 'notice-error';
            $('#fb-saved-pages-result').html(
                '<div class="notice ' + className + ' inline"><p>' + $('<div>').text(message).html() + '</p></div>'
            );
        }

        $('#fb-add-saved-page').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $spinner = $('#fb-saved-pages-spinner');
            var name = $.trim($('#fb-saved-page-name').val() || '');
            var id = $.trim($('#fb-saved-page-id').val() || '');

            if (!name || !id) {
                showSavedPagesMessage('error', 'Udfyld både side-navn og Page ID.');
                return;
            }

            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $('#fb-saved-pages-result').empty();

            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_add_saved_page',
                    nonce: fbPostScheduler.nonce,
                    name: name,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        showSavedPagesMessage('success', response.data.message || 'Siden er gemt.');
                        renderSavedPagesList(response.data.pages || []);
                        $('#fb-saved-page-name').val('');
                        $('#fb-saved-page-id').val('');
                    } else {
                        var message = response.data && response.data.message
                            ? response.data.message
                            : (fbPostScheduler.savedPageAddError || 'Kunne ikke tilføje siden.');
                        showSavedPagesMessage('error', message);
                    }
                },
                error: function() {
                    showSavedPagesMessage('error', fbPostScheduler.ajaxError || 'Der opstod en fejl');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });

        $(document).on('click', '.fb-remove-saved-page', function(e) {
            e.preventDefault();

            var pageId = String($(this).data('page-id') || '');
            if (!pageId) {
                return;
            }

            if (!window.confirm(fbPostScheduler.savedPageRemoveConfirm || 'Fjern denne gemte side?')) {
                return;
            }

            var $spinner = $('#fb-saved-pages-spinner');
            $spinner.addClass('is-active');
            $('#fb-saved-pages-result').empty();

            $.ajax({
                url: fbPostScheduler.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_remove_saved_page',
                    nonce: fbPostScheduler.nonce,
                    id: pageId
                },
                success: function(response) {
                    if (response.success) {
                        showSavedPagesMessage('success', response.data.message || 'Siden er fjernet.');
                        renderSavedPagesList(response.data.pages || []);
                    } else {
                        var message = response.data && response.data.message
                            ? response.data.message
                            : fbPostScheduler.ajaxError;
                        showSavedPagesMessage('error', message);
                    }
                },
                error: function() {
                    showSavedPagesMessage('error', fbPostScheduler.ajaxError || 'Der opstod en fejl');
                },
                complete: function() {
                    $spinner.removeClass('is-active');
                }
            });
        });

        $('#fb-saved-page-name, #fb-saved-page-id').on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#fb-add-saved-page').trigger('click');
            }
        });
    }
    
    // Bind page selection buttons on document ready
    $(document).ready(function() {
        // Page selection buttons er allerede bound via bindTokenManagementButtons
    });

    // Hurtigt overblik over planlagte opslag (kalender ikon i meta box)
    var fbScheduleOverviewCache = null; // global cache for alle panels

    function escHtml(str) {
        return $('<span>').text(str).html();
    }

    function renderScheduleOverview($overview, data) {
        if (data.length > 0) {
            var html = '<table><thead><tr>' +
                '<th>Dato</th><th>Tid</th><th>Titel</th>' +
                '</tr></thead><tbody>';
            $.each(data, function(i, item) {
                html += '<tr><td>' + escHtml(item.date) + '</td>' +
                    '<td>' + escHtml(item.time) + '</td>' +
                    '<td>' + escHtml(item.title) + '</td></tr>';
            });
            html += '</tbody></table>';
            $overview.html(html);
        } else {
            $overview.html('<p class="no-upcoming-posts">Ingen planlagte opslag.</p>');
        }
    }

    $(document).on('click', '.fb-toggle-schedule-overview', function() {
        var $btn = $(this);
        var $overview = $btn.closest('p').next('.fb-schedule-overview');
        var isOpen = $overview.is(':visible');

        if (isOpen) {
            $overview.slideUp(150);
            $btn.removeClass('active').attr('aria-expanded', 'false');
            return;
        }

        $btn.addClass('active').attr('aria-expanded', 'true');

        // Reuse cached data if available
        if (fbScheduleOverviewCache !== null) {
            renderScheduleOverview($overview, fbScheduleOverviewCache);
            $overview.slideDown(150);
            return;
        }

        $overview.html('<p class="no-upcoming-posts">Henter planlagte opslag&hellip;</p>').slideDown(150);

        $.ajax({
            url: fbPostScheduler.ajaxUrl,
            type: 'POST',
            data: {
                action: 'fb_post_scheduler_get_upcoming_posts',
                nonce: fbPostScheduler.nonce
            },
            success: function(response) {
                if (response.success) {
                    fbScheduleOverviewCache = response.data;
                    renderScheduleOverview($overview, response.data);
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : 'Der opstod en fejl.';
                    $overview.html('<p class="no-upcoming-posts">' + escHtml(msg) + '</p>');
                }
            },
            error: function() {
                $overview.html('<p class="no-upcoming-posts">Kunne ikke hente planlagte opslag.</p>');
            }
        });
    });

})(jQuery);
