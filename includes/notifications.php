<?php
/**
 * Notifikationer for Facebook Post Scheduler.
 *
 * Viser status på opslag i admin-baren og på siden Notifikationer,
 * med link til det indlæg, den side eller den begivenhed opslaget hører til.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Håndterer notifikationer, admin-baren og opsummeringsmailen.
 */
class FB_Post_Scheduler_Notifications {

    /**
     * Højst så mange notifikationer gemmes. Ældre rækker smides væk.
     */
    private const MAX_STORED = 100;

    /**
     * Antal rækker pr. side i oversigten.
     */
    private const PER_PAGE = 25;

    /**
     * Notifikationer der skal med i mailen for dette request.
     *
     * @var object[]
     */
    private $email_queue = array();

    /**
     * Indlæst liste for dette request.
     *
     * @var object[]|null
     */
    private $notifications = null;

    /**
     * Konstruktør.
     */
    public function __construct() {
        add_action('admin_bar_menu', array($this, 'add_admin_bar_notifications'), 100);
        add_action('admin_menu', array($this, 'register_admin_page'), 30);
        add_action('admin_init', array($this, 'handle_admin_actions'), 1);
        add_action('admin_init', array($this, 'check_for_new_notifications'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_fb_post_scheduler_mark_notification_read', array($this, 'mark_notification_read'));
        add_action('wp_ajax_fb_post_scheduler_mark_all_notifications_read', array($this, 'mark_all_notifications_read'));
        add_action('shutdown', array($this, 'send_summary_email'));
    }

    /**
     * Menupunktet Notifikationer med antal ulæste.
     */
    public function register_admin_page(): void {
        add_submenu_page(
            'fb-post-scheduler',
            __('Notifikationer', 'fb-post-scheduler'),
            $this->menu_title(),
            'manage_options',
            'fb-post-scheduler-logs',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Kompakt liste i admin-baren. Hele punktet linker til indholdet.
     *
     * @param WP_Admin_Bar $wp_admin_bar Admin bar.
     */
    public function add_admin_bar_notifications($wp_admin_bar): void {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $notifications = $this->get_notifications();
        $unread_count = $this->count_unread();
        $page_url = admin_url('admin.php?page=fb-post-scheduler-logs');
        $can_manage = current_user_can('manage_options');

        $wp_admin_bar->add_node(array(
            'id' => 'fb-post-scheduler-notifications',
            'title' => $this->admin_bar_parent_title($unread_count),
            'href' => $can_manage ? $page_url : '#',
            'meta' => array(
                'class' => 'fb-post-scheduler-notifications-menu',
                'title' => __('Notifikationer', 'fb-post-scheduler'),
            ),
        ));

        $unread = array();
        foreach ($notifications as $notification) {
            if (empty($notification->read)) {
                $unread[] = $notification;
            }
        }

        $shown = array_slice($unread, 0, 5);

        if ($shown === array()) {
            $wp_admin_bar->add_node(array(
                'id' => 'fb-post-scheduler-notification-none',
                'parent' => 'fb-post-scheduler-notifications',
                'title' => esc_html__('Ingen ulæste notifikationer', 'fb-post-scheduler'),
                'href' => $can_manage ? $page_url : '#',
            ));
        } else {
            foreach ($shown as $notification) {
                $view = $this->present($notification);
                $label = $this->summary_label($view);

                $wp_admin_bar->add_node(array(
                    'id' => 'fb-note-' . sanitize_html_class($view->id),
                    'parent' => 'fb-post-scheduler-notifications',
                    'title' => '<span class="fb-ab-note fb-ab-note--' . esc_attr($view->status) . '">' . esc_html($label) . '</span>',
                    'href' => $view->edit_link !== '' ? $view->edit_link : ($can_manage ? $page_url : '#'),
                ));
            }

            $hidden = $unread_count - count($shown);
            if ($hidden > 0 && $can_manage) {
                $wp_admin_bar->add_node(array(
                    'id' => 'fb-post-scheduler-notifications-more',
                    'parent' => 'fb-post-scheduler-notifications',
                    'title' => esc_html(sprintf(
                        /* translators: %d: number of additional unread notifications */
                        __('+ %d flere ulæste', 'fb-post-scheduler'),
                        $hidden
                    )),
                    'href' => $page_url,
                ));
            }
        }

        if ($can_manage) {
            $wp_admin_bar->add_node(array(
                'id' => 'fb-post-scheduler-notifications-see-all',
                'parent' => 'fb-post-scheduler-notifications',
                'title' => esc_html__('Se alle notifikationer', 'fb-post-scheduler'),
                'href' => $page_url,
                'meta' => array(
                    'class' => 'fb-notifications-see-all',
                ),
            ));
        }

        if ($unread_count > 0) {
            $wp_admin_bar->add_node(array(
                'id' => 'fb-post-scheduler-notifications-mark-all',
                'parent' => 'fb-post-scheduler-notifications',
                'title' => esc_html__('Marker alle som læst', 'fb-post-scheduler'),
                'href' => '#',
                'meta' => array(
                    'class' => 'fb-notifications-mark-all',
                ),
            ));
        }
    }

    /**
     * Scripts og styles til admin-baren og notifikationssiden.
     */
    public function enqueue_scripts(): void {
        $on_page = is_admin() && isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'fb-post-scheduler-logs';
        if (!is_admin_bar_showing() && !$on_page) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'fb-post-scheduler-notifications',
            FB_POST_SCHEDULER_URL . 'assets/css/notifications.css',
            array(),
            FB_POST_SCHEDULER_VERSION
        );

        wp_enqueue_script(
            'fb-post-scheduler-notifications-js',
            FB_POST_SCHEDULER_URL . 'assets/js/notifications.js',
            array('jquery'),
            FB_POST_SCHEDULER_VERSION,
            true
        );

        wp_localize_script(
            'fb-post-scheduler-notifications-js',
            'fbPostSchedulerNotifications',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fb-post-scheduler-notifications-nonce'),
                'ajaxError' => __('Kunne ikke opdatere notifikationerne. Prøv igen.', 'fb-post-scheduler'),
                'bulkNone' => __('Vælg mindst én notifikation.', 'fb-post-scheduler'),
                'bulkNoAction' => __('Vælg en massehandling.', 'fb-post-scheduler'),
                'bulkDeleteConfirm' => __('Slet de valgte notifikationer?', 'fb-post-scheduler'),
            )
        );
    }

    /**
     * Slet, marker som læst eller prøv et fejlet opslag igen.
     */
    public function handle_admin_actions(): void {
        if (!isset($_POST['fb_notifications_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Du har ikke tilstrækkelige rettigheder til at udføre denne handling.', 'fb-post-scheduler'));
        }

        check_admin_referer('fb_post_scheduler_manage_notifications', 'fb_notifications_nonce');

        if (isset($_POST['retry_post'])) {
            $this->handle_retry(sanitize_text_field(wp_unslash($_POST['retry_post'])));
        }

        if (isset($_POST['delete_notification'])) {
            $this->delete_ids(array(sanitize_text_field(wp_unslash($_POST['delete_notification']))));
        }

        if (isset($_POST['delete_all_notifications'])) {
            $this->store(array());
            $this->redirect_with_notice('success', __('Alle notifikationer blev slettet.', 'fb-post-scheduler'));
        }

        if (isset($_POST['mark_all_notifications'])) {
            $this->mark_read(null);
            $this->redirect_with_notice('success', __('Alle notifikationer er markeret som læst.', 'fb-post-scheduler'));
        }

        if (isset($_POST['fb_bulk_notifications'])) {
            $this->handle_bulk();
        }
    }

    /**
     * Siden Notifikationer.
     */
    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Du har ikke tilstrækkelige rettigheder til at se denne side.', 'fb-post-scheduler'));
        }

        $notifications = $this->get_notifications();
        $unread_count = $this->count_unread();
        $failed_posts = $this->get_failed_posts();
        $total = count($notifications);
        $total_pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        if ($paged < 1) {
            $paged = 1;
        }
        if ($paged > $total_pages) {
            $paged = $total_pages;
        }
        $page_items = array_slice($notifications, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p><?php esc_html_e('Her kan du se, om et Facebook-opslag blev postet eller fejlede, og åbne det indlæg, den side eller den begivenhed det hører til.', 'fb-post-scheduler'); ?></p>
            <p>
                <?php
                printf(
                    wp_kses(
                        __('Nye resultater samles i en mail. Modtageren ændres under <a href="%s">Indstillinger</a>.', 'fb-post-scheduler'),
                        array('a' => array('href' => array()))
                    ),
                    esc_url(admin_url('admin.php?page=fb-post-scheduler-settings'))
                );
                ?>
            </p>
            <?php $this->render_notice(); ?>

            <?php if ($failed_posts !== array()) : ?>
                <h2><?php esc_html_e('Fejlede opslag', 'fb-post-scheduler'); ?></h2>
                <p><?php esc_html_e('Disse opslag blev ikke postet. Ret indholdet, eller prøv at poste igen.', 'fb-post-scheduler'); ?></p>
                <form method="post" class="fb-notifications-form">
                    <?php wp_nonce_field('fb_post_scheduler_manage_notifications', 'fb_notifications_nonce'); ?>
                    <div class="fb-notifications-table-wrap">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Indhold', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Type', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Planlagt', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Fejl', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Handlinger', 'fb-post-scheduler'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($failed_posts as $failed) : ?>
                                    <tr>
                                        <td>
                                            <?php if ($failed->edit_link !== '') : ?>
                                                <a href="<?php echo esc_url($failed->edit_link); ?>"><?php echo esc_html($failed->title); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html($failed->title); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($failed->type); ?></td>
                                        <td><?php echo esc_html($this->format_time($failed->time)); ?></td>
                                        <td class="fb-notifications-message"><?php echo esc_html($failed->message); ?></td>
                                        <td>
                                            <div class="fb-notifications-actions">
                                                <?php if ($failed->edit_link !== '') : ?>
                                                    <a class="button" href="<?php echo esc_url($failed->edit_link); ?>"><?php esc_html_e('Rediger', 'fb-post-scheduler'); ?></a>
                                                <?php endif; ?>
                                                <button type="submit" class="button" name="retry_post" value="<?php echo esc_attr($failed->post_id . ':' . $failed->post_index); ?>" data-confirm="<?php echo esc_attr__('Prøv at poste opslaget til Facebook igen?', 'fb-post-scheduler'); ?>"><?php esc_html_e('Prøv igen', 'fb-post-scheduler'); ?></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php endif; ?>

            <h2><?php esc_html_e('Notifikationer', 'fb-post-scheduler'); ?></h2>
            <?php if ($page_items === array()) : ?>
                <p><?php esc_html_e('Ingen notifikationer.', 'fb-post-scheduler'); ?></p>
            <?php else : ?>
                <form method="post" class="fb-notifications-form">
                    <?php wp_nonce_field('fb_post_scheduler_manage_notifications', 'fb_notifications_nonce'); ?>
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <label for="fb-notifications-bulk-action" class="screen-reader-text"><?php esc_html_e('Vælg massehandling', 'fb-post-scheduler'); ?></label>
                            <select id="fb-notifications-bulk-action" class="fb-notifications-bulk-action" name="fb_bulk_action">
                                <option value=""><?php esc_html_e('Massehandlinger', 'fb-post-scheduler'); ?></option>
                                <option value="delete"><?php esc_html_e('Slet valgte', 'fb-post-scheduler'); ?></option>
                                <option value="read"><?php esc_html_e('Marker som læst', 'fb-post-scheduler'); ?></option>
                            </select>
                            <button type="submit" class="button action fb-notifications-bulk-apply" name="fb_bulk_notifications" value="1"><?php esc_html_e('Udfør', 'fb-post-scheduler'); ?></button>
                        </div>
                        <div class="alignright">
                            <?php if ($unread_count > 0) : ?>
                                <button type="submit" class="button" name="mark_all_notifications" value="1"><?php esc_html_e('Marker alle som læst', 'fb-post-scheduler'); ?></button>
                            <?php endif; ?>
                            <button type="submit" class="button button-link-delete" name="delete_all_notifications" value="1" data-confirm="<?php echo esc_attr__('Slet alle notifikationer? Handlingen kan ikke fortrydes.', 'fb-post-scheduler'); ?>"><?php esc_html_e('Slet alle', 'fb-post-scheduler'); ?></button>
                        </div>
                    </div>
                    <?php $this->render_pagination($total, $paged, $total_pages); ?>
                    <div class="fb-notifications-table-wrap">
                        <table class="widefat striped fb-notifications-table">
                            <thead>
                                <tr>
                                    <td class="check-column">
                                        <input id="fb-notifications-select-all" class="fb-notifications-select-all" type="checkbox" aria-label="<?php esc_attr_e('Vælg alle notifikationer', 'fb-post-scheduler'); ?>">
                                    </td>
                                    <th><?php esc_html_e('Tid', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Type', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Indhold', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Status', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Besked', 'fb-post-scheduler'); ?></th>
                                    <th><?php esc_html_e('Handlinger', 'fb-post-scheduler'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($page_items as $notification) : ?>
                                    <?php $view = $this->present($notification); ?>
                                    <tr class="<?php echo $view->read ? '' : 'fb-notifications-row--unread'; ?>">
                                        <th class="check-column" scope="row">
                                            <input type="checkbox" name="notification_ids[]" value="<?php echo esc_attr($view->id); ?>" aria-label="<?php echo esc_attr(sprintf(__('Vælg %s', 'fb-post-scheduler'), $view->title)); ?>">
                                        </th>
                                        <td><?php echo esc_html($this->format_time($view->time)); ?></td>
                                        <td><?php echo $view->type !== '' ? esc_html($view->type) : '—'; ?></td>
                                        <td>
                                            <?php if ($view->edit_link !== '') : ?>
                                                <a href="<?php echo esc_url($view->edit_link); ?>"><?php echo esc_html($view->title); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html($view->title); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($view->status === 'error') : ?>
                                                <span class="fb-log-error"><?php esc_html_e('Fejl', 'fb-post-scheduler'); ?></span>
                                            <?php else : ?>
                                                <span class="fb-log-success"><?php esc_html_e('Postet', 'fb-post-scheduler'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fb-notifications-message"><?php echo esc_html($view->message); ?></td>
                                        <td>
                                            <div class="fb-notifications-actions">
                                                <?php if ($view->edit_link !== '') : ?>
                                                    <a class="button" href="<?php echo esc_url($view->edit_link); ?>"><?php esc_html_e('Rediger', 'fb-post-scheduler'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($view->status === 'error' && $view->post_id && $view->post_index !== null) : ?>
                                                    <button type="submit" class="button" name="retry_post" value="<?php echo esc_attr($view->post_id . ':' . $view->post_index); ?>" data-confirm="<?php echo esc_attr__('Prøv at poste opslaget til Facebook igen?', 'fb-post-scheduler'); ?>"><?php esc_html_e('Prøv igen', 'fb-post-scheduler'); ?></button>
                                                <?php endif; ?>
                                                <button type="submit" class="button button-link-delete" name="delete_notification" value="<?php echo esc_attr($view->id); ?>" data-confirm="<?php echo esc_attr__('Slet denne notifikation?', 'fb-post-scheduler'); ?>"><?php esc_html_e('Slet', 'fb-post-scheduler'); ?></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php $this->render_pagination($total, $paged, $total_pages); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Marker én notifikation som læst.
     */
    public function mark_notification_read(): void {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Manglende rettigheder', 'fb-post-scheduler'));
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb-post-scheduler-notifications-nonce')) {
            wp_send_json_error(__('Ugyldig nonce', 'fb-post-scheduler'));
        }

        if (!isset($_POST['id'])) {
            wp_send_json_error(__('Manglende ID', 'fb-post-scheduler'));
        }

        $this->mark_read(array(sanitize_text_field(wp_unslash($_POST['id']))));
        wp_send_json_success();
    }

    /**
     * Marker alle notifikationer som læst.
     */
    public function mark_all_notifications_read(): void {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Manglende rettigheder', 'fb-post-scheduler'));
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb-post-scheduler-notifications-nonce')) {
            wp_send_json_error(__('Ugyldig nonce', 'fb-post-scheduler'));
        }

        $this->mark_read(null);
        wp_send_json_success();
    }

    /**
     * Gemte notifikationer, nyeste først.
     *
     * @return object[]
     */
    public function get_notifications(): array {
        if ($this->notifications !== null) {
            return $this->notifications;
        }

        $stored = get_option('fb_post_scheduler_notifications', array());
        if (!is_array($stored)) {
            $this->notifications = array();
            return $this->notifications;
        }

        $notifications = array();
        foreach ($stored as $notification) {
            if (is_array($notification)) {
                $notification = (object) $notification;
            }
            if (!is_object($notification) || !isset($notification->id)) {
                continue;
            }
            $notifications[] = $notification;
        }

        usort($notifications, static function ($a, $b): int {
            $a_time = isset($a->time) ? strtotime((string) $a->time) : 0;
            $b_time = isset($b->time) ? strtotime((string) $b->time) : 0;
            return (int) $b_time <=> (int) $a_time;
        });

        if (count($notifications) > self::MAX_STORED) {
            $notifications = array_slice($notifications, 0, self::MAX_STORED);
            $this->notifications = $notifications;
            $this->persist($notifications);
            return $this->notifications;
        }

        $this->notifications = $notifications;
        return $this->notifications;
    }

    /**
     * Antal ulæste notifikationer.
     */
    public function count_unread(): int {
        $count = 0;
        foreach ($this->get_notifications() as $notification) {
            if (empty($notification->read)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Opret en notifikation. Med email i context samles den i opsummeringsmailen.
     *
     * @param string $title   Overskrift.
     * @param string $message Besked.
     * @param string $link    Valgfrit link.
     * @param array  $context post_id, post_index, status, email.
     */
    public function add_notification($title, $message, $link = '', $context = array()): void {
        $post_id = isset($context['post_id']) ? absint($context['post_id']) : 0;
        $status = (isset($context['status']) && $context['status'] === 'success') ? 'success' : 'error';
        if (!isset($context['status']) && strpos((string) $title, 'Fejl') !== 0) {
            $status = 'success';
        }

        $notification = (object) array(
            'id' => uniqid('fb', true),
            'time' => current_time('mysql'),
            'read' => false,
            'status' => $status,
            'title' => (string) $title,
            'message' => (string) $message,
            'link' => (string) $link,
            'post_id' => $post_id,
            'post_index' => (isset($context['post_index']) && $context['post_index'] !== '' && $context['post_index'] !== null)
                ? absint($context['post_index'])
                : null,
        );

        $notifications = $this->get_notifications();
        array_unshift($notifications, $notification);
        $this->store($notifications);

        if (!empty($context['email'])) {
            $this->email_queue[] = $notification;
        }
    }

    /**
     * Notifikation for et opslag der blev postet eller fejlede.
     *
     * @param int         $post_id    WordPress post ID.
     * @param string      $status     success eller error.
     * @param string      $message    Resultat eller fejlbesked.
     * @param int|null    $post_index Indeks på det planlagte opslag.
     */
    public function notify_result($post_id, $status, $message, $post_index = null): void {
        $normalized = ($status === 'success') ? 'success' : 'error';
        $this->add_notification(
            $this->format_heading($post_id, $normalized),
            $message,
            '',
            array(
                'post_id' => absint($post_id),
                'post_index' => $post_index,
                'status' => $normalized,
                'email' => true,
            )
        );
    }

    /**
     * Send én mail med de notifikationer der er oprettet i dette request.
     */
    public function send_summary_email(): void {
        if ($this->email_queue === array()) {
            return;
        }

        $items = $this->email_queue;
        $this->email_queue = array();

        if (get_option('fb_post_scheduler_notification_email_enabled', '1') !== '1') {
            return;
        }

        $to = sanitize_email((string) get_option('fb_post_scheduler_notification_email_address', ''));
        if (!$to || !is_email($to)) {
            $to = sanitize_email((string) get_option('admin_email'));
        }
        if (!$to || !is_email($to)) {
            return;
        }

        $count = count($items);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(
            /* translators: 1: site name, 2: number of notifications */
            _n(
                '[%1$s] Facebook-opslag: %2$d ny notifikation',
                '[%1$s] Facebook-opslag: %2$d nye notifikationer',
                $count,
                'fb-post-scheduler'
            ),
            $site,
            $count
        );

        $body = '<p>' . esc_html(sprintf(
            /* translators: %s: site name */
            __('Her er en opsummering af Facebook-opslag på %s.', 'fb-post-scheduler'),
            $site
        )) . '</p><ul>';

        foreach ($items as $item) {
            $view = $this->present($item);
            $heading = $this->summary_label($view);

            $body .= '<li><strong>' . esc_html($heading) . '</strong>';
            if ($view->time !== '') {
                $body .= '<br>' . esc_html($this->format_time($view->time));
            }
            if ($view->message !== '') {
                $body .= '<br>' . esc_html($view->message);
            }
            if ($view->edit_link !== '') {
                $body .= '<br><a href="' . esc_url($view->edit_link) . '">' . esc_html__('Rediger indholdet', 'fb-post-scheduler') . '</a>';
            }
            $permalink = $view->source_id ? get_permalink($view->source_id) : '';
            if ($permalink) {
                $body .= ' · <a href="' . esc_url($permalink) . '">' . esc_html__('Vis siden', 'fb-post-scheduler') . '</a>';
            }
            $body .= '</li>';
        }

        $body .= '</ul><p><a href="' . esc_url(admin_url('admin.php?page=fb-post-scheduler-logs')) . '">' . esc_html__('Se alle notifikationer', 'fb-post-scheduler') . '</a></p>';

        $content_type = static function (): string {
            return 'text/html; charset=UTF-8';
        };
        add_filter('wp_mail_content_type', $content_type);
        $sent = wp_mail($to, $subject, $body);
        remove_filter('wp_mail_content_type', $content_type);

        if (!$sent) {
            fb_post_scheduler_log('Kunne ikke sende notifikationsmail til ' . $to);
        }
    }

    /**
     * Hent nye rækker fra logtabellen og gem dem som notifikationer.
     */
    public function check_for_new_notifications(): void {
        if (wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        global $wpdb;

        $last_check = (int) get_option('fb_post_scheduler_last_notification_check', 0);
        $now = time();
        if ($now - $last_check < 300) {
            return;
        }

        update_option('fb_post_scheduler_last_notification_check', $now);

        $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
        $this->ensure_logs_created_index($table_name);
        $date = wp_date('Y-m-d H:i:s', $last_check);
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, status, message, created_at FROM {$table_name} WHERE created_at > %s ORDER BY created_at DESC LIMIT %d",
                $date,
                self::MAX_STORED
            )
        );

        if (empty($logs)) {
            return;
        }

        $notifications = $this->get_notifications();
        $known = array();
        foreach ($notifications as $existing) {
            $known[(string) $existing->id] = true;
        }

        foreach ($logs as $log) {
            if (isset($known[(string) $log->id])) {
                continue;
            }

            $status = ($log->status === 'success') ? 'success' : 'error';
            $notifications[] = (object) array(
                'id' => (string) $log->id,
                'time' => $log->created_at,
                'read' => false,
                'status' => $status,
                'title' => $this->format_heading((int) $log->post_id, $status),
                'message' => (string) $log->message,
                'link' => '',
                'post_id' => absint($log->post_id),
                'post_index' => null,
            );
        }

        $this->store($notifications);
    }

    /**
     * Indeks på logtid, så opslag ikke scanner hele logtabellen.
     */
    private function ensure_logs_created_index(string $table_name): void {
        if (get_option('fb_post_scheduler_logs_created_index')) {
            return;
        }

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table_name} WHERE Key_name = %s", 'created_at'));
        if (!$exists) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX created_at (created_at)");
        }

        update_option('fb_post_scheduler_logs_created_index', 1, false);
    }

    /**
     * Menutitel med antal ulæste.
     */
    private function menu_title(): string {
        $label = __('Notifikationer', 'fb-post-scheduler');
        $count = $this->count_unread();
        if ($count < 1) {
            return $label;
        }

        return $label . sprintf(
            ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
            $count,
            number_format_i18n($count)
        );
    }

    /**
     * Ikon eller antal i admin-baren.
     */
    private function admin_bar_parent_title(int $unread_count): string {
        if ($unread_count < 1) {
            return '<span class="fb-notification-icon" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Notifikationer', 'fb-post-scheduler') . '</span>';
        }

        return sprintf(
            '<span class="fb-notification-count">%1$d</span><span class="screen-reader-text">%2$s</span>',
            $unread_count,
            esc_html(sprintf(
                /* translators: %d: number of unread notifications */
                _n('%d ulæst notifikation', '%d ulæste notifikationer', $unread_count, 'fb-post-scheduler'),
                $unread_count
            ))
        );
    }

    /**
     * Visningsdata med link til kildeindholdet.
     *
     * @param object $notification Gemt notifikation.
     * @return object
     */
    private function present($notification): object {
        $post_id = !empty($notification->post_id) ? absint($notification->post_id) : 0;
        if (!$post_id && !empty($notification->link) && preg_match('/[?&]post=(\d+)/', (string) $notification->link, $matches)) {
            $post_id = (int) $matches[1];
        }

        $source_id = $post_id ? fb_post_scheduler_get_source_post_id($post_id) : 0;
        $post = $source_id ? get_post($source_id) : null;
        $type = $post_id ? fb_post_scheduler_get_linked_post_type_label($post_id) : '';
        $title = ($post && $post->post_title !== '') ? $post->post_title : '';
        if ($title === '') {
            $title = !empty($notification->title) ? (string) $notification->title : __('Ukendt indhold', 'fb-post-scheduler');
        }

        $status = !empty($notification->status) ? (string) $notification->status : 'success';
        if (empty($notification->status) && strpos((string) ($notification->title ?? ''), 'Fejl') === 0) {
            $status = 'error';
        }

        $post_index = null;
        if (isset($notification->post_index) && $notification->post_index !== null && $notification->post_index !== '') {
            $post_index = absint($notification->post_index);
        }

        return (object) array(
            'id' => (string) $notification->id,
            'time' => isset($notification->time) ? (string) $notification->time : '',
            'read' => !empty($notification->read),
            'status' => $status === 'error' ? 'error' : 'success',
            'message' => isset($notification->message) ? (string) $notification->message : '',
            'title' => $title,
            'type' => ($post && $type !== '') ? $type : '',
            'post_id' => $post_id,
            'source_id' => $source_id,
            'post_index' => $post_index,
            'edit_link' => ($post && $source_id) ? admin_url('post.php?post=' . $source_id . '&action=edit') : '',
        );
    }

    /**
     * Én linje til admin-baren og mailen.
     *
     * @param object $view Presenteret notifikation.
     */
    private function summary_label($view): string {
        if ($view->edit_link === '') {
            return $view->title;
        }

        $label = $view->status === 'error'
            ? __('Fejl', 'fb-post-scheduler')
            : __('Postet', 'fb-post-scheduler');
        if ($view->type !== '') {
            $label .= ' · ' . $view->type;
        }
        if ($view->title !== '') {
            $label .= ' · ' . $view->title;
        }

        return $label;
    }

    /**
     * Overskrift med status, type og titel.
     */
    private function format_heading(int $post_id, string $status): string {
        $type = $post_id ? fb_post_scheduler_get_linked_post_type_label($post_id) : __('Indhold', 'fb-post-scheduler');
        $source_id = $post_id ? fb_post_scheduler_get_source_post_id($post_id) : 0;
        $post_title = $source_id ? get_the_title($source_id) : '';
        if ($post_title === '') {
            $post_title = $post_id ? ('#' . $post_id) : __('Ukendt', 'fb-post-scheduler');
        }

        if ($status === 'success') {
            return sprintf(__('Postet · %1$s · %2$s', 'fb-post-scheduler'), $type, $post_title);
        }

        return sprintf(__('Fejl · %1$s · %2$s', 'fb-post-scheduler'), $type, $post_title);
    }

    /**
     * Planlagte rækker med status error.
     *
     * @return object[]
     */
    private function get_failed_posts(): array {
        global $wpdb;
        $table_name = fb_post_scheduler_get_table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE status = %s ORDER BY scheduled_time DESC LIMIT 50",
                'error'
            )
        );

        if (!is_array($rows)) {
            return array();
        }

        $failed = array();
        foreach ($rows as $row) {
            $post_id = absint($row->post_id);
            $source_id = fb_post_scheduler_get_source_post_id($post_id);
            $post = $source_id ? get_post($source_id) : null;
            $title = ($post && $post->post_title !== '') ? $post->post_title : (string) $row->post_title;
            if ($title === '') {
                $title = '#' . $post_id;
            }

            $message = __('Ukendt fejl', 'fb-post-scheduler');
            $fb_posts = get_post_meta($post_id, '_fb_posts', true);
            if (is_array($fb_posts) && isset($fb_posts[$row->post_index]['error_message']) && $fb_posts[$row->post_index]['error_message'] !== '') {
                $message = (string) $fb_posts[$row->post_index]['error_message'];
            }

            $failed[] = (object) array(
                'post_id' => $post_id,
                'post_index' => absint($row->post_index),
                'title' => $title,
                'type' => fb_post_scheduler_get_linked_post_type_label($post_id),
                'time' => (string) $row->scheduled_time,
                'message' => $message,
                'edit_link' => ($post && $source_id) ? admin_url('post.php?post=' . $source_id . '&action=edit') : '',
            );
        }

        return $failed;
    }

    /**
     * Lokal tid.
     */
    private function format_time(string $mysql_time): string {
        $timestamp = strtotime($mysql_time);
        if (!$timestamp) {
            return $mysql_time;
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Gem listen, nyeste først. Ældre end de 100 nyeste fjernes.
     *
     * @param object[] $notifications Notifikationer.
     */
    private function store(array $notifications): void {
        usort($notifications, static function ($a, $b): int {
            $a_time = isset($a->time) ? strtotime((string) $a->time) : 0;
            $b_time = isset($b->time) ? strtotime((string) $b->time) : 0;
            return (int) $b_time <=> (int) $a_time;
        });

        if (count($notifications) > self::MAX_STORED) {
            $notifications = array_slice($notifications, 0, self::MAX_STORED);
        }

        $this->notifications = $notifications;
        $this->persist($notifications);
    }

    /**
     * Gem listen uden at indlæse den på hver frontend-visning.
     *
     * @param object[] $notifications Notifikationer.
     */
    private function persist(array $notifications): void {
        update_option('fb_post_scheduler_notifications', $notifications, false);
    }

    /**
     * Sidelinks til oversigten.
     */
    private function render_pagination(int $total, int $paged, int $total_pages): void {
        if ($total < 1) {
            return;
        }

        $from = (($paged - 1) * self::PER_PAGE) + 1;
        $to = min($total, $paged * self::PER_PAGE);
        ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: first row number, 2: last row number, 3: total rows */
                        __('Viser %1$d–%2$d af %3$d', 'fb-post-scheduler'),
                        $from,
                        $to,
                        $total
                    ));
                    ?>
                </span>
                <?php if ($total_pages > 1) : ?>
                    <span class="pagination-links">
                        <?php
                        echo wp_kses_post((string) paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $paged,
                            'total' => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        )));
                        ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Slet valgte notifikationer og gå tilbage til siden.
     *
     * @param string[] $ids Notifikations-id'er.
     */
    private function delete_ids(array $ids): void {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        $notifications = $this->get_notifications();
        $before = count($notifications);
        $notifications = array_values(array_filter($notifications, static function ($notification) use ($ids): bool {
            return !in_array((string) $notification->id, $ids, true);
        }));
        $this->store($notifications);
        $deleted = $before - count($notifications);

        $this->redirect_with_notice('success', sprintf(
            /* translators: %d: number of deleted notifications */
            _n('%d notifikation blev slettet.', '%d notifikationer blev slettet.', $deleted, 'fb-post-scheduler'),
            $deleted
        ));
    }

    /**
     * Fjern fejlnotifikationer for et opslag der nu er postet.
     */
    private function delete_error_notifications(int $post_id, int $post_index): void {
        $notifications = array_values(array_filter($this->get_notifications(), static function ($notification) use ($post_id, $post_index): bool {
            $same_post = isset($notification->post_id) && absint($notification->post_id) === $post_id;
            $same_index = isset($notification->post_index) && $notification->post_index !== null && absint($notification->post_index) === $post_index;
            $is_error = isset($notification->status) && $notification->status === 'error';
            return !($same_post && $same_index && $is_error);
        }));
        $this->store($notifications);
    }

    /**
     * Marker som læst. Null markerer alle.
     *
     * @param string[]|null $ids Id'er, eller null for alle.
     */
    private function mark_read(?array $ids): void {
        $notifications = $this->get_notifications();
        foreach ($notifications as $notification) {
            if ($ids === null || in_array((string) $notification->id, $ids, true)) {
                $notification->read = true;
            }
        }
        $this->store($notifications);
    }

    /**
     * Massehandlinger fra listen.
     */
    private function handle_bulk(): void {
        $action = isset($_POST['fb_bulk_action']) ? sanitize_key(wp_unslash($_POST['fb_bulk_action'])) : '';
        $ids = isset($_POST['notification_ids']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['notification_ids'])) : array();
        $ids = array_values(array_filter($ids));

        if ($action === '') {
            $this->redirect_with_notice('error', __('Vælg en massehandling.', 'fb-post-scheduler'));
        }
        if ($ids === array()) {
            $this->redirect_with_notice('error', __('Vælg mindst én notifikation.', 'fb-post-scheduler'));
        }
        if ($action === 'delete') {
            $this->delete_ids($ids);
        }
        if ($action === 'read') {
            $this->mark_read($ids);
            $this->redirect_with_notice('success', __('De valgte notifikationer er markeret som læst.', 'fb-post-scheduler'));
        }

        $this->redirect_with_notice('error', __('Ukendt handling.', 'fb-post-scheduler'));
    }

    /**
     * Post et fejlet opslag igen.
     */
    private function handle_retry(string $raw): void {
        $parts = explode(':', $raw);
        if (count($parts) !== 2) {
            $this->redirect_with_notice('error', __('Kunne ikke finde opslaget.', 'fb-post-scheduler'));
        }

        $post_id = absint($parts[0]);
        $post_index = absint($parts[1]);
        $this->delete_error_notifications($post_id, $post_index);
        $result = fb_post_scheduler_retry_failed_post($post_id, $post_index);
        if (is_wp_error($result)) {
            $this->redirect_with_notice('error', $result->get_error_message());
        }

        $this->redirect_with_notice('success', __('Opslaget blev postet til Facebook.', 'fb-post-scheduler'));
    }

    /**
     * Gem en besked og gå tilbage til notifikationssiden.
     */
    private function redirect_with_notice(string $type, string $message): void {
        set_transient('fb_ps_admin_notice_' . get_current_user_id(), array(
            'type' => $type,
            'message' => $message,
        ), MINUTE_IN_SECONDS);

        wp_safe_redirect(admin_url('admin.php?page=fb-post-scheduler-logs'));
        exit;
    }

    /**
     * Besked efter en handling.
     */
    private function render_notice(): void {
        $key = 'fb_ps_admin_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_transient($key);
        $type = (isset($notice['type']) && $notice['type'] === 'error') ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }
}

$fb_post_scheduler_notifications = new FB_Post_Scheduler_Notifications();

/**
 * Tilføj en notifikation.
 *
 * @param string $title   Overskrift.
 * @param string $message Besked.
 * @param string $link    Valgfrit link.
 * @param array  $context post_id, post_index, status, email.
 */
function fb_post_scheduler_add_notification($title, $message, $link = '', $context = array()): void {
    global $fb_post_scheduler_notifications;

    if ($fb_post_scheduler_notifications) {
        $fb_post_scheduler_notifications->add_notification($title, $message, $link, $context);
    }
}

/**
 * Notifikation når et opslag postes eller fejler. Flere i samme kørsel samles i én mail.
 *
 * @param int      $post_id    WordPress post ID.
 * @param string   $status     success eller error.
 * @param string   $message    Resultat eller fejlbesked.
 * @param int|null $post_index Indeks på det planlagte opslag.
 */
function fb_post_scheduler_notify_result($post_id, $status, $message, $post_index = null): void {
    global $fb_post_scheduler_notifications;

    if ($fb_post_scheduler_notifications) {
        $fb_post_scheduler_notifications->notify_result($post_id, $status, $message, $post_index);
    }
}
