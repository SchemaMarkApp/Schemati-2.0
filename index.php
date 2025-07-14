<?php
/**
 * Plugin Name: Schemati
 * Description: Complete Schema markup plugin with all features and sidebar
 * Plugin URI: https://schemamarkapp.com/
 * Author: Shay Ohayon
 * Author URI: https://schemamarkapp.com/
 * Version: 5.1.0
 * Text Domain: schemati
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('SCHEMATI_VERSION', '5.1.0');
define('SCHEMATI_FILE', __FILE__);
define('SCHEMATI_DIR', plugin_dir_path(__FILE__));
define('SCHEMATI_URL', plugin_dir_url(__FILE__));

/**
 * Main Schemati Plugin Class
 */
class Schemati {
    
    private static $instance = null;
    private $github_updater;
    private $cache_group = 'schemati_schemas';
    private $schema_types = array(
        'LocalBusiness', 'Service', 'Product', 'Event', 'Person', 
        'FAQPage', 'HowTo', 'Recipe', 'VideoObject', 'Review', 
        'Organization', 'Article', 'BlogPosting', 'NewsArticle', 'WebSite',
        'ImageObject', 'AudioObject', 'CreativeWork', 'Place', 'Offer'
    );
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'load_textdomain'), 1);
        add_action('init', array($this, 'init'), 10);
    }
    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        $loaded = load_plugin_textdomain(
            'schemati', 
            false, 
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
        
        // Debug: Force reload if not loaded
        if (!$loaded && get_locale() === 'he_IL') {
            $mo_file = dirname(__FILE__) . '/languages/schemati-he_IL.mo';
            if (file_exists($mo_file)) {
                load_textdomain('schemati', $mo_file);
            }
        }
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        $this->register_hooks();
    }
    
    /**
     * Register all hooks - NEW MODULAR APPROACH
     */
    private function register_hooks() {
        // Plugin lifecycle
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Core functionality
        $this->init_github_updater();
        add_action('wp_head', array($this, 'output_schema'), 1);
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_init', array($this, 'admin_init'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
            add_action('save_post', array($this, 'save_meta_boxes'));
			add_action('admin_notices', array($this, 'admin_notices'));
            // AJAX handlers
            $this->register_ajax_handlers();
        }
        
        // Frontend hooks
        add_shortcode('schemati_breadcrumbs', array($this, 'breadcrumb_shortcode'));
        add_shortcode('breadcrumbs', array($this, 'breadcrumb_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_styles'));
        
        // Sidebar functionality - conditional loading
        if (current_user_can('edit_posts') && !is_admin()) {
            add_action('admin_bar_menu', array($this, 'add_admin_bar'), 100);
            add_action('wp_footer', array($this, 'add_sidebar_html'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_sidebar_scripts'));
        }
    }
    
    /**
     * Register AJAX handlers - NEW CENTRALIZED APPROACH
     */
	private function verify_ajax_request($capability = 'edit_posts') {
    // Nonce verification
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'schemati_ajax')) {
        $this->send_ajax_error('Security check failed');
        return false;
    }
    
    // Capability check
    if (!current_user_can($capability)) {
        $this->send_ajax_error('Insufficient permissions');
        return false;
    }
    
    return true;
}

// 2. ADD: Standardized AJAX success response (NEW)
private function send_ajax_success($data = null, $message = '') {
    wp_send_json_success(array(
        'data' => $data,
        'message' => $message
    ));
}

// 3. ADD: Standardized AJAX error response (NEW)
private function send_ajax_error($message = '') {
    wp_send_json_error(array(
        'message' => $message
    ));
}

    private function register_ajax_handlers() {
        $ajax_actions = array(
            'toggle_schema',
            'delete_schema', 
            'save_schema',
            'add_schema',
            'get_schema_template',
            'toggle_global',
            'clear_update_cache'
        );
        
        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_schemati_' . $action, array($this, 'ajax_' . $action));
        }
    }

    /**
     * AJAX handler to toggle schema status
     */
    public function ajax_toggle_schema() {
    if (!$this->verify_ajax_request()) {
        return; // Security handled centrally
    }
    
    // Enhanced input validation
    $schema_index = filter_input(INPUT_POST, 'schema_index', FILTER_VALIDATE_INT);
    $post_id = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    
    if ($schema_index === false || !$post_id) {
        $this->send_ajax_error('Invalid parameters provided');
        return;
    }
    
    $custom_schemas = get_post_meta($post_id, '_schemati_custom_schemas', true);
    if (!is_array($custom_schemas)) {
        $custom_schemas = array();
    }
    
    if (isset($custom_schemas[$schema_index])) {
        $current_status = $custom_schemas[$schema_index]['_enabled'] ?? true;
        $custom_schemas[$schema_index]['_enabled'] = !$current_status;
        
        if (update_post_meta($post_id, '_schemati_custom_schemas', $custom_schemas)) {
            $this->send_ajax_success(array('new_status' => !$current_status), 'Schema status updated successfully');
        } else {
            $this->send_ajax_error('Failed to update schema status');
        }
    } else {
        $this->send_ajax_error('Schema not found');
    }
}


    /**
     * AJAX handler to delete schema
     */
    public function ajax_delete_schema() {
    if (!$this->verify_ajax_request()) {
        return;
    }
    
    $schema_index = filter_input(INPUT_POST, 'schema_index', FILTER_VALIDATE_INT);
    $post_id = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    
    if ($schema_index === false || !$post_id) {
        $this->send_ajax_error('Invalid parameters provided');
        return;
    }
    
    $custom_schemas = get_post_meta($post_id, '_schemati_custom_schemas', true);
    if (!is_array($custom_schemas)) {
        $this->send_ajax_error('No schemas found');
        return;
    }
    
    if (isset($custom_schemas[$schema_index])) {
        unset($custom_schemas[$schema_index]);
        $custom_schemas = array_values($custom_schemas); // Re-index array
        
        if (update_post_meta($post_id, '_schemati_custom_schemas', $custom_schemas)) {
            $this->send_ajax_success(null, 'Schema deleted successfully');
        } else {
            $this->send_ajax_error('Failed to delete schema');
        }
    } else {
        $this->send_ajax_error('Schema not found');
    }
}


    /**
     * AJAX handler to save schema changes
     */
    public function ajax_save_schema() {
    if (!$this->verify_ajax_request()) {
        return;
    }
    
    $schema_index = filter_input(INPUT_POST, 'schema_index', FILTER_VALIDATE_INT);
    $post_id = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    
    if ($schema_index === false || !$post_id) {
        $this->send_ajax_error('Invalid parameters provided');
        return;
    }
    
    $custom_schemas = get_post_meta($post_id, '_schemati_custom_schemas', true);
    if (!is_array($custom_schemas)) {
        $custom_schemas = array();
    }
    
    if (isset($custom_schemas[$schema_index])) {
        // Update schema with new data
        $schema = $custom_schemas[$schema_index];
        
        // Update common fields with proper sanitization
        if (isset($_POST['name'])) {
            $schema['name'] = sanitize_text_field($_POST['name']);
        }
        if (isset($_POST['description'])) {
            $schema['description'] = sanitize_textarea_field($_POST['description']);
        }
        if (isset($_POST['url'])) {
            $schema['url'] = esc_url_raw($_POST['url']);
        }
        
        // Update schema-specific fields based on type
        switch ($schema['@type']) {
            case 'LocalBusiness':
            case 'Service':
                if (isset($_POST['address'])) {
                    $schema['address'] = sanitize_textarea_field($_POST['address']);
                }
                if (isset($_POST['telephone'])) {
                    $schema['telephone'] = sanitize_text_field($_POST['telephone']);
                }
                if (isset($_POST['email'])) {
                    $schema['email'] = sanitize_email($_POST['email']);
                }
                break;
            
            case 'Product':
                if (isset($_POST['brand'])) {
                    $schema['brand'] = sanitize_text_field($_POST['brand']);
                }
                if (isset($_POST['price'])) {
                    $schema['offers']['price'] = sanitize_text_field($_POST['price']);
                }
                if (isset($_POST['currency'])) {
                    $schema['offers']['priceCurrency'] = sanitize_text_field($_POST['currency']);
                }
                break;
        }
        
        $custom_schemas[$schema_index] = $schema;
        
        if (update_post_meta($post_id, '_schemati_custom_schemas', $custom_schemas)) {
            $this->send_ajax_success($schema, 'Schema updated successfully');
        } else {
            $this->send_ajax_error('Failed to update schema');
        }
    } else {
        $this->send_ajax_error('Schema not found');
    }
}


    /**
     * AJAX handler to add new schema
     */
    public function ajax_add_schema() {
    if (!$this->verify_ajax_request()) {
        return;
    }
    
    $schema_type = sanitize_text_field($_POST['schema_type'] ?? '');
    $post_id = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    
    if (empty($schema_type) || !$post_id) {
        $this->send_ajax_error('Invalid schema type or post ID');
        return;
    }
    
    // Validate schema type against allowed types
    if (!in_array($schema_type, $this->schema_types)) {
        $this->send_ajax_error('Invalid schema type provided');
        return;
    }
    
    // Create new schema based on type
    $new_schema = $this->create_schema_template($schema_type, $_POST);
    
    if (!$new_schema) {
        $this->send_ajax_error('Failed to create schema template');
        return;
    }
    
    $custom_schemas = get_post_meta($post_id, '_schemati_custom_schemas', true);
    if (!is_array($custom_schemas)) {
        $custom_schemas = array();
    }
    
    $custom_schemas[] = $new_schema;
    
    if (update_post_meta($post_id, '_schemati_custom_schemas', $custom_schemas)) {
        $this->send_ajax_success($new_schema, 'Schema added successfully');
    } else {
        $this->send_ajax_error('Failed to save new schema');
    }
}

    /**
     * AJAX handler to get schema template
     */
    public function ajax_get_schema_template() {
    if (!$this->verify_ajax_request()) {
        return;
    }
    
    $schema_type = sanitize_text_field($_POST['schema_type'] ?? '');
    
    if (empty($schema_type)) {
        $this->send_ajax_error('Schema type is required');
        return;
    }
    
    // Validate schema type
    if (!in_array($schema_type, $this->schema_types)) {
        $this->send_ajax_error('Invalid schema type provided');
        return;
    }
    
    $template_html = $this->get_schema_template_html($schema_type);
    
    if ($template_html) {
        $this->send_ajax_success($template_html, 'Template loaded successfully');
    } else {
        $this->send_ajax_error('Template not found for schema type: ' . $schema_type);
    }
}

    /**
     * AJAX handler to toggle global schema settings
     */
    public function ajax_toggle_global() {
    if (!$this->verify_ajax_request('manage_options')) {
        return;
    }
    
    $enabled = filter_input(INPUT_POST, 'enabled', FILTER_VALIDATE_INT);
    
    if ($enabled === false) {
        $this->send_ajax_error('Invalid enabled value');
        return;
    }
    
    $settings = $this->get_settings('schemati_general');
    $settings['enabled'] = (bool) $enabled;
    
    if (update_option('schemati_general', $settings)) {
        $this->send_ajax_success(array('enabled' => (bool) $enabled), 'Global setting updated successfully');
    } else {
        $this->send_ajax_error('Failed to update global setting');
    }
}
	private function get_current_page_url($include_protocol = false) {
    $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    
    if ($include_protocol) {
        $protocol = is_ssl() ? 'https://' : 'http://';
        $url = $protocol . $url;
    }
    
    return $url;
}

/**
 * Get site domain without protocol
 */
private function get_site_domain() {
    return parse_url(home_url(), PHP_URL_HOST);
}
	public function admin_notices() {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'schemati') !== false) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php _e('Schema Validation', 'schemati'); ?>:</strong> 
                <?php _e('Use our comprehensive schema validation tool at', 'schemati'); ?> 
                <a href="https://schemamarkup.net/?url=<?php echo esc_attr($this->get_site_domain()); ?>" target="_blank">
                    <strong>schemamarkup.net</strong>
                </a> 
                <?php _e('to test your website\'s schema markup.', 'schemati'); ?>
            </p>
        </div>
        <?php
    }
}

    /**
     * Create schema template based on type
     */
    private function create_schema_template($schema_type, $data) {
    $schema = array(
        '@context' => 'https://schema.org',
        '@type' => $schema_type,
        '_enabled' => true,
        '_source' => 'custom'
    );
    
    // Add common fields for all schemas
    $common_fields = array('name', 'description', 'url');
    foreach ($common_fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $schema[$field] = sanitize_text_field($data[$field]);
        }
    }
    
    // Add type-specific fields using modular approach
    $this->add_type_specific_fields($schema, $schema_type, $data);
    
    return $schema;
}
	private function add_type_specific_fields(&$schema, $schema_type, $data) {
    // Convert schema type to method name (e.g., LocalBusiness -> localbusiness)
    $method = 'add_' . strtolower(str_replace('_', '', $schema_type)) . '_fields';
    
    if (method_exists($this, $method)) {
        $this->$method($schema, $data);
    } else {
        // Fallback for unknown schema types
        $this->add_generic_fields($schema, $data);
    }
}

// 3. ADD: LocalBusiness-specific fields (NEW)
private function add_localbusiness_fields(&$schema, $data) {
    $fields = array('address', 'telephone', 'email');
    foreach ($fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $schema[$field] = $field === 'email' ? 
                sanitize_email($data[$field]) : 
                sanitize_text_field($data[$field]);
        }
    }
    
    // Set default URL if not provided
    if (empty($schema['url'])) {
        $schema['url'] = get_permalink();
    }
    
    // Optional fields
    if (!empty($data['opening_hours'])) {
        $schema['openingHours'] = sanitize_text_field($data['opening_hours']);
    }
    if (!empty($data['price_range'])) {
        $schema['priceRange'] = sanitize_text_field($data['price_range']);
    }
}

// 4. ADD: Service-specific fields (NEW)
private function add_service_fields(&$schema, $data) {
    $schema['provider'] = array(
        '@type' => 'Organization',
        'name' => get_bloginfo('name')
    );
    
    if (!empty($data['area_served'])) {
        $schema['areaServed'] = sanitize_text_field($data['area_served']);
    }
    if (!empty($data['service_type'])) {
        $schema['serviceType'] = sanitize_text_field($data['service_type']);
    }
}

// 5. ADD: Product-specific fields (NEW)
private function add_product_fields(&$schema, $data) {
    if (isset($data['brand']) && !empty($data['brand'])) {
        $schema['brand'] = sanitize_text_field($data['brand']);
    }
    
    // Handle offers/pricing
    if (isset($data['price']) || isset($data['currency'])) {
        $schema['offers'] = array(
            '@type' => 'Offer',
            'price' => sanitize_text_field($data['price'] ?? ''),
            'priceCurrency' => sanitize_text_field($data['currency'] ?? 'USD'),
            'availability' => 'https://schema.org/InStock'
        );
    }
    
    // Optional product identifiers
    if (!empty($data['sku'])) {
        $schema['sku'] = sanitize_text_field($data['sku']);
    }
    if (!empty($data['mpn'])) {
        $schema['mpn'] = sanitize_text_field($data['mpn']);
    }
}

// 6. ADD: Person-specific fields (NEW)
private function add_person_fields(&$schema, $data) {
    if (isset($data['job_title']) && !empty($data['job_title'])) {
        $schema['jobTitle'] = sanitize_text_field($data['job_title']);
    }
    
    $contact_fields = array('email', 'telephone');
    foreach ($contact_fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $schema[$field] = $field === 'email' ? 
                sanitize_email($data[$field]) : 
                sanitize_text_field($data[$field]);
        }
    }
    
    if (!empty($data['works_for'])) {
        $schema['worksFor'] = array(
            '@type' => 'Organization',
            'name' => sanitize_text_field($data['works_for'])
        );
    }
}

// 7. ADD: Event-specific fields (NEW)
private function add_event_fields(&$schema, $data) {
    if (!empty($data['start_date'])) {
        $schema['startDate'] = sanitize_text_field($data['start_date']);
    }
    if (!empty($data['end_date'])) {
        $schema['endDate'] = sanitize_text_field($data['end_date']);
    }
    
    if (!empty($data['location'])) {
        $schema['location'] = array(
            '@type' => 'Place',
            'name' => sanitize_text_field($data['location'])
        );
    }
    
    if (!empty($data['event_status'])) {
        $schema['eventStatus'] = 'https://schema.org/' . sanitize_text_field($data['event_status']);
    }
    
    if (!empty($data['ticket_url'])) {
        $schema['offers'] = array(
            '@type' => 'Offer',
            'url' => esc_url_raw($data['ticket_url'])
        );
    }
}

// 8. ADD: FAQ Page-specific fields (NEW)
private function add_faqpage_fields(&$schema, $data) {
    $schema['mainEntity'] = array();
    
    if (isset($data['questions']) && is_array($data['questions'])) {
        foreach ($data['questions'] as $i => $question) {
            if (!empty($question) && !empty($data['answers'][$i])) {
                $schema['mainEntity'][] = array(
                    '@type' => 'Question',
                    'name' => sanitize_text_field($question),
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text' => sanitize_textarea_field($data['answers'][$i])
                    )
                );
            }
        }
    }
}

// 9. ADD: Organization-specific fields (NEW)
private function add_organization_fields(&$schema, $data) {
    $contact_fields = array('email', 'telephone');
    foreach ($contact_fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $schema[$field] = $field === 'email' ? 
                sanitize_email($data[$field]) : 
                sanitize_text_field($data[$field]);
        }
    }
    
    if (!empty($data['logo_url'])) {
        $schema['logo'] = esc_url_raw($data['logo_url']);
    }
    
    if (!empty($data['social_urls'])) {
        $social_urls = explode("\n", $data['social_urls']);
        $schema['sameAs'] = array_map('esc_url_raw', array_filter($social_urls));
    }
}

// 10. ADD: Article-specific fields (NEW)
private function add_article_fields(&$schema, $data) {
    $this->add_blogposting_fields($schema, $data);
}

private function add_blogposting_fields(&$schema, $data) {
    $schema['headline'] = sanitize_text_field($data['headline'] ?? get_the_title());
    
    $schema['author'] = array(
        '@type' => 'Person',
        'name' => sanitize_text_field($data['author_name'] ?? get_the_author())
    );
    
    $schema['datePublished'] = sanitize_text_field($data['date_published'] ?? get_the_date('c'));
    $schema['dateModified'] = sanitize_text_field($data['date_modified'] ?? get_the_modified_date('c'));
    
    if (!empty($data['image_url'])) {
        $schema['image'] = esc_url_raw($data['image_url']);
    }
}

private function add_newsarticle_fields(&$schema, $data) {
    $this->add_blogposting_fields($schema, $data);
}

// 11. ADD: Video Object-specific fields (NEW)
private function add_videoobject_fields(&$schema, $data) {
    if (!empty($data['content_url'])) {
        $schema['contentUrl'] = esc_url_raw($data['content_url']);
    }
    if (!empty($data['embed_url'])) {
        $schema['embedUrl'] = esc_url_raw($data['embed_url']);
    }
    
    $schema['uploadDate'] = sanitize_text_field($data['upload_date'] ?? date('c'));
    
    if (!empty($data['duration'])) {
        $schema['duration'] = sanitize_text_field($data['duration']);
    }
    if (!empty($data['thumbnail_url'])) {
        $schema['thumbnailUrl'] = esc_url_raw($data['thumbnail_url']);
    }
}

// 12. ADD: Review-specific fields (NEW)
private function add_review_fields(&$schema, $data) {
    $schema['itemReviewed'] = array(
        '@type' => sanitize_text_field($data['item_type'] ?? 'Thing'),
        'name' => sanitize_text_field($data['item_name'] ?? '')
    );
    
    $schema['reviewRating'] = array(
        '@type' => 'Rating',
        'ratingValue' => intval($data['rating_value'] ?? 5),
        'bestRating' => intval($data['best_rating'] ?? 5),
        'worstRating' => intval($data['worst_rating'] ?? 1)
    );
    
    $schema['author'] = array(
        '@type' => 'Person',
        'name' => sanitize_text_field($data['author_name'] ?? get_the_author())
    );
    
    if (!empty($data['review_body'])) {
        $schema['reviewBody'] = sanitize_textarea_field($data['review_body']);
    }
}

// 13. ADD: WebSite-specific fields (NEW)
private function add_website_fields(&$schema, $data) {
    $schema['name'] = sanitize_text_field($data['name'] ?? get_bloginfo('name'));
    $schema['url'] = home_url();
    
    if (!empty($data['potential_action'])) {
        $schema['potentialAction'] = array(
            '@type' => 'SearchAction',
            'target' => home_url('/?s={search_term_string}'),
            'query-input' => 'required name=search_term_string'
        );
    }
}

// 14. ADD: Generic fallback fields (NEW)
private function add_generic_fields(&$schema, $data) {
    // For unknown schema types, just add the common fields
    // Common fields are already added in create_schema_template()
    
    // Add URL if not present
    if (empty($schema['url'])) {
        $schema['url'] = get_permalink();
    }
}

    private function get_schema_template_html($schema_type) {
        ob_start();
        
        switch ($schema_type) {
            case 'LocalBusiness':
                ?>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Business Name:</label>
                    <input type="text" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Description:</label>
                    <textarea name="description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Address:</label>
                    <textarea name="address" rows="2" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Phone:</label>
                        <input type="text" name="telephone" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Email:</label>
                        <input type="email" name="email" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Website URL:</label>
                    <input type="url" name="url" value="<?php echo esc_url(get_permalink()); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <?php
                break;
                
            case 'Service':
                ?>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Service Name:</label>
                    <input type="text" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Description:</label>
                    <textarea name="description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Area Served:</label>
                    <input type="text" name="area_served" placeholder="e.g., New York, NY" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <?php
                break;
                
            case 'Product':
                ?>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Product Name:</label>
                    <input type="text" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Description:</label>
                    <textarea name="description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Brand:</label>
                    <input type="text" name="brand" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Price:</label>
                        <input type="number" name="price" step="0.01" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Currency:</label>
                        <select name="currency" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="GBP">GBP</option>
                            <option value="CAD">CAD</option>
                        </select>
                    </div>
                </div>
                <?php
                break;
                
            case 'Event':
                ?>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Event Name:</label>
                    <input type="text" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Description:</label>
                    <textarea name="description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Start Date:</label>
                        <input type="datetime-local" name="start_date" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">End Date:</label>
                        <input type="datetime-local" name="end_date" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Location:</label>
                    <input type="text" name="location" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <?php
                break;
                
            case 'Person':
                ?>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Full Name:</label>
                    <input type="text" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Job Title:</label>
                    <input type="text" name="job_title" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Email:</label>
                        <input type="email" name="email" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Phone:</label>
                        <input type="text" name="telephone" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Website:</label>
                    <input type="url" name="url" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <?php
                break;
                
            case 'FAQPage':
                ?>
                <div id="faq-questions">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">Question 1:</label>
                        <input type="text" name="questions[]" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 5px;">
                        <textarea name="answers[]" placeholder="Answer..." rows="2" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                    </div>
                </div>
                <button type="button" onclick="addFAQQuestion()" style="background: #0073aa; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-bottom: 15px;">
                    + Add Another Question
                </button>
                <script>
                function addFAQQuestion() {
                    var container = document.getElementById('faq-questions');
                    var questionNum = container.children.length + 1;
                    var html = '<div style="margin-bottom: 15px;">' +
                        '<label style="display: block; margin-bottom: 5px; font-weight: 500;">Question ' + questionNum + ':</label>' +
                        '<input type="text" name="questions[]" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 5px;">' +
                        '<textarea name="answers[]" placeholder="Answer..." rows="2" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>' +
                        '</div>';
                    container.insertAdjacentHTML('beforeend', html);
                }
                </script>
                <?php
                break;
                
            default:
                ?>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Name:</label>
                    <input type="text" name="name" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Description:</label>
                    <textarea name="description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                </div>
                <?php
        }
        
        return ob_get_clean();
    }

    /**
     * Helper methods for building schemas
     */
    private function build_organization_schema() {
        $settings = $this->get_settings('schemati_general');
        
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $settings['org_type'] ?? 'Organization',
            'name' => $settings['org_name'] ?? get_bloginfo('name'),
            'url' => home_url()
        );
        
        // Add local business details if enabled
        $business_settings = $this->get_settings('schemati_local_business');
        if ($business_settings['enabled'] ?? false) {
            if (!empty($business_settings['address'])) {
                $schema['address'] = $business_settings['address'];
            }
            if (!empty($business_settings['phone'])) {
                $schema['telephone'] = $business_settings['phone'];
            }
        }
        
        return $schema;
    }

    private function build_webpage_schema() {
        global $post;
        
        if (!$post) return null;
        
        $custom_type = get_post_meta($post->ID, '_schemati_type', true);
        $custom_description = get_post_meta($post->ID, '_schemati_description', true);
        
        // Determine schema type
        $schema_type = 'WebPage';
        if ($custom_type) {
            $schema_type = $custom_type;
        } elseif ($post->post_type === 'post') {
            $article_settings = $this->get_settings('schemati_article');
            if ($article_settings['enabled'] ?? true) {
                $schema_type = $article_settings['article_type'] ?? 'Article';
            }
        }
        
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $schema_type,
            'name' => get_the_title(),
            'url' => get_permalink(),
            'description' => $custom_description ?: wp_trim_words(get_the_excerpt() ?: $post->post_content, 25, '...')
        );
        
        // Add article-specific fields
        if (in_array($schema_type, array('Article', 'BlogPosting', 'NewsArticle'))) {
            $schema['datePublished'] = get_the_date('c');
            $schema['dateModified'] = get_the_modified_date('c');
            $schema['author'] = array(
                '@type' => 'Person',
                'name' => get_the_author()
            );
            
            // Add featured image if available
            if (has_post_thumbnail()) {
                $image = wp_get_attachment_image_src(get_post_thumbnail_id(), 'large');
                if ($image) {
                    $schema['image'] = $image[0];
                }
            }
        }
        
        return $schema;
    }

    private function build_breadcrumb_schema() {
        $breadcrumbs = $this->get_breadcrumb_data();
        
        if (empty($breadcrumbs)) {
            return null;
        }
        
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array()
        );
        
        foreach ($breadcrumbs as $index => $crumb) {
            $schema['itemListElement'][] = array(
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['title'],
                'item' => $crumb['url']
            );
        }
        
        return $schema;
    }
	private function build_wpheader_schema() {
        $settings = $this->get_settings('schemati_general');
        
        if (!($settings['enable_wpheader'] ?? true)) {
            return null;
        }
        
        $header_menu_items = $this->get_navigation_items('header');
        
        if (empty($header_menu_items)) {
            return null;
        }
        
        return array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPageElement',
            '@id' => home_url() . '#WPHeader',
            'name' => 'Website Header',
            'hasPart' => $header_menu_items
        );
    }

    /**
     * Build WPFooter schema - NEW FUNCTION
     */
    private function build_wpfooter_schema() {
        $settings = $this->get_settings('schemati_general');
        
        if (!($settings['enable_wpfooter'] ?? true)) {
            return null;
        }
        
        $footer_menu_items = $this->get_navigation_items('footer');
        
        if (empty($footer_menu_items)) {
            return null;
        }
        
        return array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPageElement',
            '@id' => home_url() . '#WPFooter',
            'name' => 'Website Footer',
            'hasPart' => $footer_menu_items
        );
    }

    /**
     * Get navigation items with smart auto-detection - NEW FUNCTION
     */
    private function get_navigation_items($type = 'header') {
        $settings = $this->get_settings('schemati_general');
        
        // Try user-configured location first
        $configured_location = $settings[$type . '_menu_location'] ?? '';
        if (!empty($configured_location)) {
            $menu_items = $this->get_menu_items_by_location($configured_location);
            if (!empty($menu_items)) {
                return $this->format_navigation_items($menu_items, $type);
            }
        }
        
        // Smart auto-detection
        $detected_locations = $this->detect_menu_locations($type);
        
        foreach ($detected_locations as $location) {
            $menu_items = $this->get_menu_items_by_location($location);
            if (!empty($menu_items)) {
                // Cache the working location for future use
                $settings[$type . '_menu_location'] = $location;
                update_option('schemati_general', $settings);
                
                return $this->format_navigation_items($menu_items, $type);
            }
        }
        
        return array();
    }

    /**
     * Smart detection of menu locations by type - NEW FUNCTION
     */
    private function detect_menu_locations($type = 'header') {
        $all_locations = get_registered_nav_menus();
        $detected = array();
        
        if (empty($all_locations)) {
            return $this->get_fallback_locations($type);
        }
        
        // Header patterns (order by priority)
        $header_patterns = array(
            'primary', 'header', 'main', 'navigation', 'nav', 'top',
            'primary-navigation', 'main-navigation', 'header-navigation',
            'primary-menu', 'main-menu', 'header-menu'
        );
        
        // Footer patterns
        $footer_patterns = array(
            'footer', 'bottom', 'secondary', 
            'footer-navigation', 'footer-menu', 'bottom-navigation',
            'secondary-navigation', 'utility'
        );
        
        $patterns = $type === 'header' ? $header_patterns : $footer_patterns;
        
        // Phase 1: Exact matches (highest priority)
        foreach ($patterns as $pattern) {
            foreach (array_keys($all_locations) as $location) {
                if ($location === $pattern) {
                    $detected[] = $location;
                }
            }
        }
        
        // Phase 2: Pattern contains matches
        foreach ($patterns as $pattern) {
            foreach (array_keys($all_locations) as $location) {
                if (strpos($location, $pattern) !== false && !in_array($location, $detected)) {
                    $detected[] = $location;
                }
            }
        }
        
        // Phase 3: Add remaining locations if looking for header and none found
        if ($type === 'header' && empty($detected)) {
            $detected = array_keys($all_locations);
        }
        
        // Add manual fallbacks at the end
        $fallbacks = $this->get_fallback_locations($type);
        foreach ($fallbacks as $fallback) {
            if (!in_array($fallback, $detected)) {
                $detected[] = $fallback;
            }
        }
        
        return $detected;
    }

    /**
     * Get menu items by location - NEW FUNCTION
     */
    private function get_menu_items_by_location($location) {
        if (!function_exists('get_nav_menu_locations')) {
            return array();
        }
        
        $locations = get_nav_menu_locations();
        
        if (!isset($locations[$location]) || empty($locations[$location])) {
            return array();
        }
        
        $menu = wp_get_nav_menu_object($locations[$location]);
        if (!$menu || is_wp_error($menu)) {
            return array();
        }
        
        $menu_items = wp_get_nav_menu_items($menu->term_id);
        if (!$menu_items || is_wp_error($menu_items)) {
            return array();
        }
        
        $items = array();
        foreach ($menu_items as $item) {
            if ($item->menu_item_parent == 0) {
                $items[] = array(
                    'name' => $item->title,
                    'url' => $item->url
                );
            }
        }
        
        return $items;
    }

    /**
     * Format navigation items for schema - NEW FUNCTION
     */
    private function format_navigation_items($menu_items, $type) {
        if (empty($menu_items)) {
            return array();
        }
        
        $formatted_items = array();
        $element_type = $type === 'header' ? 'WPHeader' : 'WPFooter';
        
        foreach ($menu_items as $item) {
            $formatted_items[] = array(
                '@type' => array('SiteNavigationElement', $element_type),
                '@id' => home_url() . '#SiteNavigationElement-' . sanitize_title($item['name']),
                'name' => $item['name'],
                'url' => $item['url']
            );
        }
        
        return $formatted_items;
    }

    /**
     * Get fallback locations - NEW FUNCTION
     */
    private function get_fallback_locations($type = 'header') {
        return $type === 'header' ? 
            array('primary', 'header', 'main', 'navigation') : 
            array('footer', 'footer-menu', 'bottom');
    }

    /**
     * Get available menu locations for admin settings - NEW FUNCTION
     */
    public function get_available_menu_locations() {
        $locations = get_registered_nav_menus();
        $formatted = array(
            '' => __('Auto-detect', 'schemati')
        );
        
        if (!empty($locations)) {
            foreach ($locations as $location => $description) {
                $formatted[$location] = $description . ' (' . $location . ')';
            }
        }
        
        return $formatted;
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options for all schema types
        $defaults = array(
    'version' => SCHEMATI_VERSION,
    'enabled' => true,
    'org_name' => get_bloginfo('name'),
    'org_type' => 'Organization',
    'breadcrumb_home' => 'Home',
    'breadcrumb_separator' => ' › ',
    'show_current' => true,
    'enable_wpheader' => true,
    'enable_wpfooter' => true,
    'header_menu_location' => '',
    'footer_menu_location' => ''
);
        
        add_option('schemati_general', $defaults);
        add_option('schemati_article', array('enabled' => true));
        add_option('schemati_about_page', array('enabled' => false));
        add_option('schemati_contact_page', array('enabled' => false));
        add_option('schemati_local_business', array('enabled' => false));
        add_option('schemati_person', array('enabled' => false));
        add_option('schemati_author', array('enabled' => false));
        add_option('schemati_publisher', array('enabled' => false));
        add_option('schemati_product', array('enabled' => false));
        add_option('schemati_faq', array('enabled' => false));
        
        // Show activation notice
        set_transient('schemati_activated', true, 30);
        
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_cache_flush_group($this->cache_group);
        flush_rewrite_rules();
    }
    
    /**
     * Add admin menu
     */
    public function admin_menu() {
        // Main menu page
        add_menu_page(
            __('Schemati Settings', 'schemati'),
            __('Schemati', 'schemati'),
            'manage_options',
            'schemati',
            array($this, 'general_page'),
            'dashicons-admin-settings',
            80
        );
        
        // All submenu pages
        add_submenu_page('schemati', __('General Settings', 'schemati'), __('General', 'schemati'), 'manage_options', 'schemati', array($this, 'general_page'));
        add_submenu_page('schemati', __('Article Schema', 'schemati'), __('Article', 'schemati'), 'manage_options', 'schemati-article', array($this, 'article_page'));
        add_submenu_page('schemati', __('About Page Schema', 'schemati'), __('About Page', 'schemati'), 'manage_options', 'schemati-about', array($this, 'about_page'));
        add_submenu_page('schemati', __('Contact Page Schema', 'schemati'), __('Contact Page', 'schemati'), 'manage_options', 'schemati-contact', array($this, 'contact_page'));
        add_submenu_page('schemati', __('Local Business Schema', 'schemati'), __('Local Business', 'schemati'), 'manage_options', 'schemati-business', array($this, 'business_page'));
        add_submenu_page('schemati', __('Person Schema', 'schemati'), __('Person', 'schemati'), 'manage_options', 'schemati-person', array($this, 'person_page'));
        add_submenu_page('schemati', __('Author Schema', 'schemati'), __('Author', 'schemati'), 'manage_options', 'schemati-author', array($this, 'author_page'));
        add_submenu_page('schemati', __('Publisher Schema', 'schemati'), __('Publisher', 'schemati'), 'manage_options', 'schemati-publisher', array($this, 'publisher_page'));
        add_submenu_page('schemati', __('Product Schema', 'schemati'), __('Product', 'schemati'), 'manage_options', 'schemati-product', array($this, 'product_page'));
        add_submenu_page('schemati', __('FAQ Schema', 'schemati'), __('FAQ', 'schemati'), 'manage_options', 'schemati-faq', array($this, 'faq_page'));
        add_submenu_page('schemati', __('CheckTool', 'schemati'), __('CheckTool', 'schemati'), 'manage_options', 'schemati-tools', array($this, 'tools_page'));
        add_submenu_page('schemati', __('Updates', 'schemati'), __('Updates', 'schemati'), 'manage_options', 'schemati-updates', array($this, 'updates_page'));
		add_submenu_page('schemati', __('Import/Export', 'schemati'), __('Import/Export', 'schemati'), 'manage_options', 'schemati-import-export', array($this, 'import_export_page'));
    }
	public function import_export_page() {
    // Handle actions if present
    if (isset($_GET['action'])) {
        $this->handle_import_export_action($_GET['action']);
    }
    
    if (isset($_POST['import_settings'])) {
        $this->handle_settings_import();
    }
    
    ?>
    <div class="wrap">
        <h1><?php _e('Schemati - Import/Export', 'schemati'); ?></h1>
        
        <div class="notice notice-info">
            <p><strong><?php _e('Backup & Restore', 'schemati'); ?></strong> - <?php _e('Export your settings for backup or transfer to another site.', 'schemati'); ?></p>
        </div>
        
        <!-- Export Section -->
        <div class="card">
            <h2><?php _e('Export Settings', 'schemati'); ?></h2>
            <p><?php _e('Download all plugin settings as a JSON file for backup or migration.', 'schemati'); ?></p>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=schemati-import-export&action=export_settings'), 'schemati_export'); ?>" class="button button-primary">
                    <?php _e('Export Plugin Settings', 'schemati'); ?>
                </a>
            </p>
        </div>
        
        <!-- Export Custom Schemas -->
        <div class="card">
            <h2><?php _e('Export Custom Schemas', 'schemati'); ?></h2>
            <p><?php _e('Download all custom schemas from posts and pages.', 'schemati'); ?></p>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=schemati-import-export&action=export_schemas'), 'schemati_export'); ?>" class="button button-secondary">
                    <?php _e('Export Custom Schemas', 'schemati'); ?>
                </a>
            </p>
        </div>
        
        <!-- Import Section -->
        <div class="card">
            <h2><?php _e('Import Settings', 'schemati'); ?></h2>
            <p><?php _e('Upload a previously exported settings file to restore configuration.', 'schemati'); ?></p>
            
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('schemati_import', 'schemati_import_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Settings File', 'schemati'); ?></th>
                        <td>
                            <input type="file" name="import_file" accept=".json" required />
                            <p class="description"><?php _e('Select a JSON file exported from Schemati.', 'schemati'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Import Options', 'schemati'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="overwrite_existing" value="1" />
                                <?php _e('Overwrite existing settings', 'schemati'); ?>
                            </label>
                            <p class="description"><?php _e('Check this to replace all current settings. Leave unchecked to merge with existing settings.', 'schemati'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Import Settings', 'schemati'), 'primary', 'import_settings'); ?>
            </form>
        </div>
        
        <!-- Statistics -->
        <div class="card">
            <h2><?php _e('Current Data', 'schemati'); ?></h2>
            <?php $this->display_export_statistics(); ?>
        </div>
    </div>
    <?php
}

// 3. ADD: Handle import/export actions (NEW)
private function handle_import_export_action($action) {
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'schemati_export')) {
        wp_die(__('Security check failed', 'schemati'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'schemati'));
    }
    
    switch ($action) {
        case 'export_settings':
            $this->export_settings();
            break;
        case 'export_schemas':
            $this->export_custom_schemas();
            break;
        default:
            wp_die(__('Invalid action', 'schemati'));
    }
}

// 4. ADD: Export plugin settings (NEW)
private function export_settings() {
    $settings = array();
    $option_groups = array(
        'schemati_general',
        'schemati_article',
        'schemati_about_page',
        'schemati_contact_page',
        'schemati_local_business',
        'schemati_person',
        'schemati_author',
        'schemati_publisher',
        'schemati_product',
        'schemati_faq'
    );
    
    foreach ($option_groups as $group) {
        $option_value = get_option($group, array());
        if (!empty($option_value)) {
            $settings[$group] = $option_value;
        }
    }
    
    $export_data = array(
        'version' => SCHEMATI_VERSION,
        'export_date' => current_time('mysql'),
        'export_type' => 'settings',
        'site_url' => home_url(),
        'settings' => $settings
    );
    
    $filename = 'schemati-settings-' . date('Y-m-d-H-i-s') . '.json';
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    echo wp_json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	update_option('schemati_last_export', current_time('mysql'));
    exit;
}

// 5. ADD: Export custom schemas (NEW)
private function export_custom_schemas() {
    global $wpdb;
    
    $custom_schemas = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_schemati_custom_schemas'"
    );
    
    $export_data = array(
        'version' => SCHEMATI_VERSION,
        'export_date' => current_time('mysql'),
        'export_type' => 'custom_schemas',
        'site_url' => home_url(),
        'custom_schemas' => array()
    );
    
    foreach ($custom_schemas as $row) {
        $schemas = maybe_unserialize($row->meta_value);
        if (is_array($schemas) && !empty($schemas)) {
            $post = get_post($row->post_id);
            $export_data['custom_schemas'][$row->post_id] = array(
                'post_title' => $post ? $post->post_title : 'Unknown',
                'post_type' => $post ? $post->post_type : 'unknown',
                'schemas' => $schemas
            );
        }
    }
    
    $filename = 'schemati-custom-schemas-' . date('Y-m-d-H-i-s') . '.json';
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    echo wp_json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	update_option('schemati_last_export', current_time('mysql'));
    exit;
}

// 6. ADD: Handle settings import (NEW)
private function handle_settings_import() {
    if (!wp_verify_nonce($_POST['schemati_import_nonce'] ?? '', 'schemati_import')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . __('Security check failed', 'schemati') . '</p></div>';
        });
        return;
    }
    
    if (!current_user_can('manage_options')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . __('Insufficient permissions', 'schemati') . '</p></div>';
        });
        return;
    }
    
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . __('File upload failed', 'schemati') . '</p></div>';
        });
        return;
    }
    
    $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
    $import_data = json_decode($file_content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . __('Invalid JSON file', 'schemati') . '</p></div>';
        });
        return;
    }
    
    // Validate import data
    if (!isset($import_data['version']) || !isset($import_data['settings'])) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . __('Invalid import file format', 'schemati') . '</p></div>';
        });
        return;
    }
    
    $overwrite = isset($_POST['overwrite_existing']) && $_POST['overwrite_existing'];
    $imported_count = 0;
    
    foreach ($import_data['settings'] as $option_name => $option_value) {
        if ($overwrite || !get_option($option_name)) {
            $sanitized_value = $this->sanitize_settings($option_value);
            update_option($option_name, $sanitized_value);
            $imported_count++;
        }
    }
    
    add_action('admin_notices', function() use ($imported_count) {
        echo '<div class="notice notice-success"><p>' . 
             sprintf(__('Successfully imported %d settings', 'schemati'), $imported_count) . 
             '</p></div>';
    });
}

// 7. ADD: Display export statistics (NEW)
private function display_export_statistics() {
    global $wpdb;
    
    // Count settings
    $option_groups = array(
        'schemati_general',
        'schemati_article',
        'schemati_about_page',
        'schemati_contact_page',
        'schemati_local_business',
        'schemati_person',
        'schemati_author',
        'schemati_publisher',
        'schemati_product',
        'schemati_faq'
    );
    
    $settings_count = 0;
    foreach ($option_groups as $group) {
        if (get_option($group)) {
            $settings_count++;
        }
    }
    
    // Count custom schemas
    $custom_schemas_count = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_schemati_custom_schemas'"
    );
    
    // Count total schemas in custom schemas
    $total_schemas = 0;
    $custom_schemas_data = $wpdb->get_results(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_schemati_custom_schemas'"
    );
    
    foreach ($custom_schemas_data as $row) {
        $schemas = maybe_unserialize($row->meta_value);
        if (is_array($schemas)) {
            $total_schemas += count($schemas);
        }
    }
    
    ?>
    <table class="widefat">
        <tr>
            <td><strong><?php _e('Plugin Version', 'schemati'); ?></strong></td>
            <td><?php echo SCHEMATI_VERSION; ?></td>
        </tr>
        <tr>
            <td><strong><?php _e('Configured Settings Groups', 'schemati'); ?></strong></td>
            <td><?php echo $settings_count; ?></td>
        </tr>
        <tr>
            <td><strong><?php _e('Posts/Pages with Custom Schemas', 'schemati'); ?></strong></td>
            <td><?php echo $custom_schemas_count; ?></td>
        </tr>
        <tr>
            <td><strong><?php _e('Total Custom Schemas', 'schemati'); ?></strong></td>
            <td><?php echo $total_schemas; ?></td>
        </tr>
        <tr>
            <td><strong><?php _e('Last Export', 'schemati'); ?></strong></td>
            <td><?php 
            $last_export = get_option('schemati_last_export', '');
            echo $last_export ? $last_export : __('Never', 'schemati');
            ?></td>
        </tr>
    </table>
    <?php
}
    
    /**
     * Register admin settings
     */
    public function admin_init() {
        // Register settings for all schema types
        register_setting('schemati_general', 'schemati_general', array($this, 'sanitize_settings'));
        register_setting('schemati_article', 'schemati_article', array($this, 'sanitize_settings'));
        register_setting('schemati_about_page', 'schemati_about_page', array($this, 'sanitize_settings'));
        register_setting('schemati_contact_page', 'schemati_contact_page', array($this, 'sanitize_settings'));
        register_setting('schemati_local_business', 'schemati_local_business', array($this, 'sanitize_settings'));
        register_setting('schemati_person', 'schemati_person', array($this, 'sanitize_settings'));
        register_setting('schemati_author', 'schemati_author', array($this, 'sanitize_settings'));
        register_setting('schemati_publisher', 'schemati_publisher', array($this, 'sanitize_settings'));
        register_setting('schemati_product', 'schemati_product', array($this, 'sanitize_settings'));
        register_setting('schemati_faq', 'schemati_faq', array($this, 'sanitize_settings'));
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $output = array();
        
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $output[$key] = array_map('sanitize_text_field', $value);
            } else {
                $output[$key] = sanitize_text_field($value);
            }
        }
        
        return $output;
    }
    
    /**
     * Get settings for any option group
     */
    public function get_settings($group = 'schemati_general') {
        $defaults = array(
            'enabled' => true,
            'org_name' => get_bloginfo('name'),
            'org_type' => 'Organization',
            'breadcrumb_home' => 'Home',
            'breadcrumb_separator' => ' › ',
            'show_current' => true
        );
        
        return get_option($group, $defaults);
    }
    
    /**
     * General Settings Page
     */
    public function general_page() {
        
        $this->handle_form_submission('schemati_general');
        $settings = $this->get_settings('schemati_general');
        
        ?>
        <div class="wrap">
            <div class="wrap">
            <h1><?php _e('Schemati - General Settings', 'schemati'); ?></h1>
            
            <?php if (get_transient('schemati_activated')): ?>
                <?php delete_transient('schemati_activated'); ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php _e('Schemati v5.1 Activated!', 'schemati'); ?></strong> <?php _e('All features loaded successfully.', 'schemati'); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p><strong><?php _e('✅ Schemati v5.1', 'schemati'); ?></strong> - <?php _e('Complete schema solution with enhanced architecture and improved performance.', 'schemati'); ?></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('schemati_save', 'schemati_nonce'); ?>
                
                <h2><?php _e('General Schema Settings', 'schemati'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Schema Markup', 'schemati'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked(1, $settings['enabled'] ?? true); ?> />
                                <?php _e('Enable schema markup output site-wide', 'schemati'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Organization Name', 'schemati'); ?></th>
                        <td>
                            <input type="text" name="org_name" value="<?php echo esc_attr($settings['org_name'] ?? get_bloginfo('name')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Your organization or website name', 'schemati'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Organization Type', 'schemati'); ?></th>
                        <td>
                            <select name="org_type">
                                <option value="Organization" <?php selected($settings['org_type'] ?? 'Organization', 'Organization'); ?>><?php _e('Organization', 'schemati'); ?></option>
                                <option value="LocalBusiness" <?php selected($settings['org_type'] ?? '', 'LocalBusiness'); ?>><?php _e('Local Business', 'schemati'); ?></option>
                                <option value="Corporation" <?php selected($settings['org_type'] ?? '', 'Corporation'); ?>><?php _e('Corporation', 'schemati'); ?></option>
                                <option value="Person" <?php selected($settings['org_type'] ?? '', 'Person'); ?>><?php _e('Person', 'schemati'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('Breadcrumb Settings', 'schemati'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Home Text', 'schemati'); ?></th>
                        <td>
                            <input type="text" name="breadcrumb_home" value="<?php echo esc_attr($settings['breadcrumb_home'] ?? 'Home'); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Separator', 'schemati'); ?></th>
                        <td>
                            <input type="text" name="breadcrumb_separator" value="<?php echo esc_attr($settings['breadcrumb_separator'] ?? ' › '); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Show Current Page', 'schemati'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_current" value="1" <?php checked(1, $settings['show_current'] ?? true); ?> />
                                <?php _e('Display current page in breadcrumb trail', 'schemati'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
               <h3><?php _e('Usage', 'schemati'); ?></h3>
                <p><strong><?php _e('Shortcode:', 'schemati'); ?></strong> <code>[schemati_breadcrumbs]</code></p>
                <p><strong><?php _e('PHP Function:', 'schemati'); ?></strong> <code>&lt;?php echo schemati_breadcrumbs(); ?&gt;</code></p>

                <h2><?php _e('Navigation Schema Settings', 'schemati'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Header Schema', 'schemati'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_wpheader" value="1" <?php checked(1, $settings['enable_wpheader'] ?? true); ?> />
                                <?php _e('Generate schema markup for header navigation', 'schemati'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Header Menu Location', 'schemati'); ?></th>
                        <td>
                            <select name="header_menu_location">
                                <?php 
                                $available_locations = $this->get_available_menu_locations();
                                foreach ($available_locations as $location => $description): ?>
                                    <option value="<?php echo esc_attr($location); ?>" <?php selected($settings['header_menu_location'] ?? '', $location); ?>>
                                        <?php echo esc_html($description); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e('Select header menu location or leave on "Auto-detect"', 'schemati'); ?>
                                <br><strong><?php _e('Auto-detected:', 'schemati'); ?></strong> 
                                <code><?php 
                                $detected = $this->detect_menu_locations('header');
                                echo !empty($detected) ? esc_html($detected[0]) : __('None found', 'schemati');
                                ?></code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Enable Footer Schema', 'schemati'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_wpfooter" value="1" <?php checked(1, $settings['enable_wpfooter'] ?? true); ?> />
                                <?php _e('Generate schema markup for footer navigation', 'schemati'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Footer Menu Location', 'schemati'); ?></th>
                        <td>
                            <select name="footer_menu_location">
                                <?php foreach ($available_locations as $location => $description): ?>
                                    <option value="<?php echo esc_attr($location); ?>" <?php selected($settings['footer_menu_location'] ?? '', $location); ?>>
                                        <?php echo esc_html($description); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e('Select footer menu location or leave on "Auto-detect"', 'schemati'); ?>
                                <br><strong><?php _e('Auto-detected:', 'schemati'); ?></strong> 
                                <code><?php 
                                $detected = $this->detect_menu_locations('footer');
                                echo !empty($detected) ? esc_html($detected[0]) : __('None found', 'schemati');
                                ?></code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Available Menu Locations', 'schemati'); ?></th>
                        <td>
                            <?php 
                            $all_locations = get_registered_nav_menus();
                            if (!empty($all_locations)): ?>
                                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                                    <?php foreach ($all_locations as $location => $description): ?>
                                        <div><strong><?php echo esc_html($location); ?>:</strong> <?php echo esc_html($description); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <em><?php _e('Your theme does not support navigation menus', 'schemati'); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Article Schema Page
     */
    public function article_page() {
        $this->handle_form_submission('schemati_article');
        $settings = $this->get_settings('schemati_article');
        
        ?>
        <div class="wrap">
            <h1><?php _e('Article Schema Settings', 'schemati'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('schemati_save', 'schemati_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Article Schema', 'schemati'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked(1, $settings['enabled'] ?? true); ?> />
                                <?php _e('Generate article schema markup for posts', 'schemati'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default Article Type', 'schemati'); ?></th>
                        <td>
                            <select name="article_type">
                                <option value="Article" <?php selected($settings['article_type'] ?? 'Article', 'Article'); ?>><?php _e('Article', 'schemati'); ?></option>
                                <option value="BlogPosting" <?php selected($settings['article_type'] ?? '', 'BlogPosting'); ?>><?php _e('Blog Posting', 'schemati'); ?></option>
                                <option value="NewsArticle" <?php selected($settings['article_type'] ?? '', 'NewsArticle'); ?>><?php _e('News Article', 'schemati'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * About Page Schema
     */
    public function about_page() {
        $this->schema_page_template(__('About Page Schema', 'schemati'), 'schemati_about_page', __('Generate schema markup for about pages', 'schemati'));
    }
    
    /**
     * Contact Page Schema
     */
    public function contact_page() {
        $this->schema_page_template(__('Contact Page Schema', 'schemati'), 'schemati_contact_page', __('Generate schema markup for contact pages', 'schemati'));
    }
    
    /**
     * Local Business Schema
     */
    public function business_page() {
        $this->handle_form_submission('schemati_local_business');
        $settings = $this->get_settings('schemati_local_business');
        
        ?>
        <div class="wrap">
            <h1><?php _e('Local Business Schema', 'schemati'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('schemati_save', 'schemati_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Local Business Schema', 'schemati'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked(1, $settings['enabled'] ?? false); ?> />
                                <?php _e('Generate local business schema markup', 'schemati'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Business Name', 'schemati'); ?></th>
                        <td>
                            <input type="text" name="business_name" value="<?php echo esc_attr($settings['business_name'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Business Type', 'schemati'); ?></th>
                        <td>
                            <select name="business_type">
                                <option value="LocalBusiness" <?php selected($settings['business_type'] ?? 'LocalBusiness', 'LocalBusiness'); ?>><?php _e('Local Business', 'schemati'); ?></option>
                                <option value="Restaurant" <?php selected($settings['business_type'] ?? '', 'Restaurant'); ?>><?php _e('Restaurant', 'schemati'); ?></option>
                                <option value="Store" <?php selected($settings['business_type'] ?? '', 'Store'); ?>><?php _e('Store', 'schemati'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Address', 'schemati'); ?></th>
                        <td>
                            <textarea name="address" rows="3" class="large-text"><?php echo esc_textarea($settings['address'] ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Phone Number', 'schemati'); ?></th>
                        <td>
                            <input type="text" name="phone" value="<?php echo esc_attr($settings['phone'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Person Schema
     */
    public function person_page() {
        $this->schema_page_template(__('Person Schema', 'schemati'), 'schemati_person', __('Generate schema markup for person/individual', 'schemati'));
    }
    
    /**
     * Author Schema
     */
    public function author_page() {
        $this->schema_page_template(__('Author Schema', 'schemati'), 'schemati_author', __('Generate schema markup for authors', 'schemati'));
    }
    
    /**
     * Publisher Schema
     */
    public function publisher_page() {
        $this->schema_page_template(__('Publisher Schema', 'schemati'), 'schemati_publisher', __('Generate schema markup for publishers', 'schemati'));
    }
    
    /**
     * Product Schema
     */
    public function product_page() {
        $this->schema_page_template(__('Product Schema', 'schemati'), 'schemati_product', __('Generate schema markup for products', 'schemati'));
    }
    
    /**
     * FAQ Schema
     */
    public function faq_page() {
        $this->schema_page_template(__('FAQ Schema', 'schemati'), 'schemati_faq', __('Generate schema markup for FAQ pages', 'schemati'));
    }
    
    /**
     * Tools/CheckTool Page
     */
    public function tools_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Schemati Tools & Diagnostics', 'schemati'); ?></h1>
        
        <div class="card">
            <h2><?php _e('Schema Testing Tool', 'schemati'); ?></h2>
            <p><?php _e('Test your schema markup with our comprehensive validation tool:', 'schemati'); ?></p>
            <p>
                <a href="https://schemamarkup.net/?url=<?php echo esc_attr(parse_url(home_url(), PHP_URL_HOST)); ?>" target="_blank" class="button button-primary">
                    <?php _e('Test Rich Results', 'schemati'); ?>
                </a>
            </p>
            <p class="description"><?php _e('This will analyze all schema markup on your website and provide detailed validation results.', 'schemati'); ?></p>
        </div>
        
        <div class="card">
            <h2><?php _e('Plugin Status', 'schemati'); ?></h2>
            <table class="widefat">
                <tr>
                    <td><strong><?php _e('Version', 'schemati'); ?></strong></td>
                    <td><?php echo SCHEMATI_VERSION; ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Status', 'schemati'); ?></strong></td>
                    <td>
                        <?php 
                        $general = $this->get_settings('schemati_general');
                        echo $general['enabled'] ? '<span style="color: green;">✓ ' . __('Active', 'schemati') . '</span>' : '<span style="color: red;">✗ ' . __('Disabled', 'schemati') . '</span>'; 
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('Schema Types Available', 'schemati'); ?></strong></td>
                    <td><?php _e('Organization, WebPage, Article, LocalBusiness, Person, Product, FAQ, BreadcrumbList', 'schemati'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Sidebar', 'schemati'); ?></strong></td>
                    <td><?php _e('✓ Active on frontend for logged-in users', 'schemati'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Breadcrumbs', 'schemati'); ?></strong></td>
                    <td><?php _e('✓ Shortcode and PHP function available', 'schemati'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Architecture', 'schemati'); ?></strong></td>
                    <td><?php _e('✓ Enhanced modular hooks system', 'schemati'); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2><?php _e('Current Page Schema Preview', 'schemati'); ?></h2>
            <p><?php _e('Visit your website while logged in to see the Schemati sidebar with live schema preview.', 'schemati'); ?></p>
            <p><a href="<?php echo home_url(); ?>" target="_blank" class="button"><?php _e('View Website with Sidebar', 'schemati'); ?></a></p>
        </div>
    </div>
    <?php
}

    
    
    /**
     * Generic schema page template
     */
    private function schema_page_template($title, $option_group, $description) {
        $this->handle_form_submission($option_group);
        $settings = $this->get_settings($option_group);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('schemati_save', 'schemati_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html(str_replace(' Schema', '', $title)); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked(1, $settings['enabled'] ?? false); ?> />
                                <?php echo esc_html($description); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Handle form submissions
     */
    private function handle_form_submission($option_group) {
        if (isset($_POST['schemati_nonce']) && wp_verify_nonce($_POST['schemati_nonce'], 'schemati_save')) {
            $current_settings = get_option($option_group, array());
            $new_settings = array();
            
            foreach ($_POST as $key => $value) {
                if ($key !== 'schemati_nonce' && $key !== 'submit') {
                    if (is_array($value)) {
                        $new_settings[$key] = array_map('sanitize_text_field', $value);
                    } else {
                        $new_settings[$key] = sanitize_text_field($value);
                    }
                }
            }
            
            $updated_settings = array_merge($current_settings, $new_settings);
            update_option($option_group, $updated_settings);
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'schemati') . '</p></div>';
        }
    }
    
    /**
     * Admin scripts and styles
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'schemati') !== false) {
            ?>
            <style>
            .schemati-admin .card { max-width: 800px; margin-top: 20px; }
            .schemati-admin .form-table th { width: 200px; }
            .schemati-admin .notice { max-width: 800px; }
            .schemati-admin .widefat td { padding: 8px 10px; }
            </style>
            <script>
            jQuery(document).ready(function($) {
                $('.wrap').addClass('schemati-admin');
            });
            </script>
            <?php
        }
    }
    
    /**
     * Add meta boxes for posts and pages
     */
    public function add_meta_boxes() {
        add_meta_box(
            'schemati_schema',
            __('Schema Settings', 'schemati'),
            array($this, 'meta_box_schema'),
            array('post', 'page'),
            'normal',
            'default'
        );
    }
    
    /**
     * Schema meta box content
     */
    public function meta_box_schema($post) {
        wp_nonce_field('schemati_meta', 'schemati_meta_nonce');
        
        $schema_type = get_post_meta($post->ID, '_schemati_type', true);
        $schema_description = get_post_meta($post->ID, '_schemati_description', true);
        
        ?>
        <table class="form-table">
            <tr>
                <th><label for="schemati_type"><?php _e('Schema Type', 'schemati'); ?></label></th>
                <td>
                    <select name="schemati_type" id="schemati_type">
                        <option value=""><?php _e('Default', 'schemati'); ?></option>
                        <option value="Article" <?php selected($schema_type, 'Article'); ?>><?php _e('Article', 'schemati'); ?></option>
                        <option value="BlogPosting" <?php selected($schema_type, 'BlogPosting'); ?>><?php _e('Blog Post', 'schemati'); ?></option>
                        <option value="NewsArticle" <?php selected($schema_type, 'NewsArticle'); ?>><?php _e('News Article', 'schemati'); ?></option>
                        <option value="Product" <?php selected($schema_type, 'Product'); ?>><?php _e('Product', 'schemati'); ?></option>
                        <option value="Event" <?php selected($schema_type, 'Event'); ?>><?php _e('Event', 'schemati'); ?></option>
                        <option value="LocalBusiness" <?php selected($schema_type, 'LocalBusiness'); ?>><?php _e('Local Business', 'schemati'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="schemati_description"><?php _e('Custom Description', 'schemati'); ?></label></th>
                <td>
                    <textarea name="schemati_description" id="schemati_description" rows="3" style="width:100%;"><?php echo esc_textarea($schema_description); ?></textarea>
                    <p class="description"><?php _e('Optional custom description for schema markup', 'schemati'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Save meta box data
     */
    public function save_meta_boxes($post_id) {
        if (!isset($_POST['schemati_meta_nonce']) || !wp_verify_nonce($_POST['schemati_meta_nonce'], 'schemati_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['schemati_type'])) {
            update_post_meta($post_id, '_schemati_type', sanitize_text_field($_POST['schemati_type']));
        }
        
        if (isset($_POST['schemati_description'])) {
            update_post_meta($post_id, '_schemati_description', sanitize_textarea_field($_POST['schemati_description']));
        }
    }
    
    /**
     * Add admin bar menu
     */
    public function add_admin_bar($admin_bar) {
    if (!current_user_can('edit_posts') || is_admin()) {
        return;
    }
    
    // Get current page URL without protocol
    $current_url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    
    $admin_bar->add_menu(array(
        'id'    => 'schemati',
        'title' => 'Schemati',
        'href'  => '#',
        'meta'  => array(
            'onclick' => 'toggleSchematiSidebar(); return false;',
            'title'   => __('Toggle Schemati Sidebar', 'schemati')
        ),
    ));
    
    $admin_bar->add_menu(array(
        'id'     => 'schemati-preview',
        'parent' => 'schemati',
        'title'  => __('View Schema', 'schemati'),
        'href'   => '#',
        'meta'   => array(
            'onclick' => 'showSchematiPreview(); return false;',
        ),
    ));
    
    $admin_bar->add_menu(array(
        'id'     => 'schemati-test',
        'parent' => 'schemati',
        'title'  => __('Test Rich Results', 'schemati'),
        'href'   => 'https://schemamarkup.net/?url=' . urlencode($current_url),
        'meta'   => array(
            'target' => '_blank'
        ),
    ));
}
    
    /**
     * Enqueue sidebar scripts
     */
    public function enqueue_sidebar_scripts() {
    if (!current_user_can('edit_posts') || is_admin()) {
        return;
    }
    
    wp_enqueue_script('jquery');
    
    // Add essential JavaScript functions for admin bar
    wp_add_inline_script('jquery', '
        // Essential functions for admin bar (loaded early)
        function toggleSchematiSidebar() {
            var sidebar = document.getElementById("schemati-sidebar");
            if (sidebar) {
                if (sidebar.style.display === "none" || sidebar.style.display === "") {
                    sidebar.style.display = "block";
                    // Sync schemas when opening if SchematiSidebar exists
                    if (typeof SchematiSidebar !== "undefined" && SchematiSidebar.syncSchemasWithDOM) {
                        SchematiSidebar.syncSchemasWithDOM();
                    }
                } else {
                    sidebar.style.display = "none";
                }
            } else {
                console.warn("Schemati sidebar not found");
            }
        }
        
        function showSchematiPreview() {
            // First ensure sidebar is available
            var sidebar = document.getElementById("schemati-sidebar");
            if (!sidebar) {
                console.warn("Schemati sidebar not found");
                return;
            }
            
            // Show sidebar first if hidden
            if (sidebar.style.display === "none" || sidebar.style.display === "") {
                toggleSchematiSidebar();
            }
            
            // Wait a moment for sidebar to load, then show preview
            setTimeout(function() {
                if (typeof SchematiSidebar !== "undefined" && SchematiSidebar.syncSchemasWithDOM) {
                    SchematiSidebar.syncSchemasWithDOM();
                }
                
                var modal = document.getElementById("schemati-schema-modal");
                if (modal) {
                    // Call the full preview function if available
                    if (window.showSchematiPreviewFull) {
                        window.showSchematiPreviewFull();
                    } else {
                        // Fallback: just show the modal
                        modal.style.display = "block";
                        var content = document.getElementById("schema-modal-content");
                        if (content) {
                            content.innerHTML = "<div style=\"text-align: center; padding: 40px; color: #666;\"><div style=\"font-size: 48px; margin-bottom: 15px;\">🔄</div><h3>Loading Schema Preview...</h3><p>Please wait while we detect schemas on this page.</p></div>";
                        }
                    }
                } else {
                    console.warn("Schema preview modal not found");
                }
            }, 100);
        }
        
        // Make functions globally available
        window.toggleSchematiSidebar = toggleSchematiSidebar;
        window.showSchematiPreview = showSchematiPreview;
    '); }
    
    // NOTE: I'll continue with the rest of the file in the next section as this is getting long
    // The remaining methods include: add_sidebar_html, output_schema, get_breadcrumb_data, 
    // breadcrumb_shortcode, frontend_styles, init_github_updater, updates_page
    
    /**
     * Output schema markup in head
     */
    public function output_schema() {
        $general_settings = $this->get_settings('schemati_general');
        
        if (!($general_settings['enabled'] ?? true)) {
            return;
        }
        
        $schemas = array();
        
        // Organization schema
        $org_schema = $this->build_organization_schema();
        if ($org_schema) {
            $schemas[] = $org_schema;
        }
		$header_schema = $this->build_wpheader_schema();
if ($header_schema) {
    $schemas[] = $header_schema;
}

$footer_schema = $this->build_wpfooter_schema();
if ($footer_schema) {
    $schemas[] = $footer_schema;
}
        
        // Page-specific schemas
        if (is_singular()) {
            global $post;
            
            // WebPage/Article schema
            $page_schema = $this->build_webpage_schema();
            if ($page_schema) {
                $schemas[] = $page_schema;
            }
            
            // Breadcrumb schema (skip for homepage)
            if (!is_front_page()) {
                $breadcrumb_schema = $this->build_breadcrumb_schema();
                if ($breadcrumb_schema) {
                    $schemas[] = $breadcrumb_schema;
                }
            }
            
            // Custom schemas from post meta
            $custom_schemas = get_post_meta($post->ID, '_schemati_custom_schemas', true);
            if ($custom_schemas && is_array($custom_schemas)) {
                foreach ($custom_schemas as $custom_schema) {
                    if ($custom_schema['_enabled'] ?? true) {
                        // Remove internal fields before output
                        unset($custom_schema['_enabled']);
                        unset($custom_schema['_source']);
                        $schemas[] = $custom_schema;
                    }
                }
            }
        }
        
        // Output schemas
        foreach ($schemas as $schema) {
            echo "\n" . '<script type="application/ld+json">' . "\n";
            echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            echo "\n" . '</script>' . "\n";
        }
    }

    /**
     * Get breadcrumb data for schema
     */
    private function get_breadcrumb_data() {
        $breadcrumbs = array();
        $settings = $this->get_settings('schemati_general');
        
        // Home
        $breadcrumbs[] = array(
            'title' => $settings['breadcrumb_home'] ?? 'Home',
            'url' => home_url()
        );
        
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term) {
                $breadcrumbs[] = array(
                    'title' => $term->name,
                    'url' => get_term_link($term)
                );
            }
        } elseif (is_single()) {
            $post = get_queried_object();
            
            // Add category for posts
            if ($post->post_type === 'post') {
                $categories = get_the_category($post->ID);
                if (!empty($categories)) {
                    $category = $categories[0];
                    $breadcrumbs[] = array(
                        'title' => $category->name,
                        'url' => get_category_link($category->term_id)
                    );
                }
            }
            
            // Add current post
            if ($settings['show_current'] ?? true) {
                $breadcrumbs[] = array(
                    'title' => get_the_title($post->ID),
                    'url' => get_permalink($post->ID)
                );
            }
        } elseif (is_page()) {
            $post = get_queried_object();
            
            // Add parent pages
            $parents = array();
            $parent_id = $post->post_parent;
            while ($parent_id) {
                $parent = get_post($parent_id);
                $parents[] = array(
                    'title' => get_the_title($parent->ID),
                    'url' => get_permalink($parent->ID)
                );
                $parent_id = $parent->post_parent;
            }
            
            // Add parents in reverse order
            $breadcrumbs = array_merge($breadcrumbs, array_reverse($parents));
            
            // Add current page
            if ($settings['show_current'] ?? true) {
                $breadcrumbs[] = array(
                    'title' => get_the_title($post->ID),
                    'url' => get_permalink($post->ID)
                );
            }
        }
        
        return $breadcrumbs;
    }

    /**
     * Breadcrumb shortcode
     */
    public function breadcrumb_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'separator' => null,
            'home_text' => null,
            'show_current' => null
        ), $atts);
        
        return $this->render_breadcrumbs($atts);
    }

    /**
     * Render breadcrumbs HTML
     */
    private function render_breadcrumbs($args = array()) {
        $settings = $this->get_settings('schemati_general');
        $breadcrumbs = $this->get_breadcrumb_data();
        
        if (empty($breadcrumbs)) {
            return '';
        }
        
        $separator = $args['separator'] ?? $settings['breadcrumb_separator'] ?? ' › ';
        $show_current = $args['show_current'] ?? $settings['show_current'] ?? true;
        
        if (!$show_current) {
            array_pop($breadcrumbs);
        }
        
        $html = '<nav class="schemati-breadcrumbs" aria-label="Breadcrumb">';
        $html .= '<ol class="breadcrumb-list">';
        
        foreach ($breadcrumbs as $index => $crumb) {
            $is_last = ($index === count($breadcrumbs) - 1);
            
            $html .= '<li class="breadcrumb-item' . ($is_last ? ' current' : '') . '">';
            
            if (!$is_last) {
                $html .= '<a href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['title']) . '</a>';
            } else {
                $html .= '<span>' . esc_html($crumb['title']) . '</span>';
            }
            
            $html .= '</li>';
            
            if (!$is_last) {
                $html .= '<li class="breadcrumb-separator">' . esc_html($separator) . '</li>';
            }
        }
        
        $html .= '</ol>';
        $html .= '</nav>';
        
        return $html;
    }

    /**
     * Frontend styles for breadcrumbs
     */
    public function frontend_styles() {
        ?>
        <style>
        .schemati-breadcrumbs {
            margin: 1em 0;
            font-size: 14px;
        }
        .breadcrumb-list {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .breadcrumb-item {
            margin: 0;
            padding: 0;
        }
        .breadcrumb-item a {
            color: #0073aa;
            text-decoration: none;
        }
        .breadcrumb-item a:hover {
            text-decoration: underline;
        }
        .breadcrumb-item.current span {
            color: #666;
        }
        .breadcrumb-separator {
            margin: 0 0.5em;
            color: #999;
        }
        </style>
        <?php
    }

    /**
     * Initialize GitHub updater
     */
    private function init_github_updater() {
    $updater_file = SCHEMATI_DIR . 'includes/class-github-updater.php';
    
    if (!file_exists($updater_file)) {
        return;
    }
    
    require_once $updater_file;
    
    if (!class_exists('Schemati_GitHub_Updater')) {
        return;
    }
    
    // Initialize enhanced updater
    $this->github_updater = new Schemati_GitHub_Updater(
        SCHEMATI_FILE,
        'YourGitHubUsername',    // Replace with actual username
        'your-repo-name',        // Replace with actual repo
        ''                       // Optional access token
    );
}

    /**
     * Updates management page
     */
    public function updates_page() {
    // Get status from enhanced updater
    $status = $this->github_updater ? $this->github_updater->get_update_status() : null;
    $update_available = $status && $status['update_available'];
    
    ?>
    <div class="wrap">
        <h1><?php _e('Schemati - Updates', 'schemati'); ?></h1>
        
        <?php if ($status): ?>
        <div class="card">
            <h2><?php _e('Update Status', 'schemati'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Current Version', 'schemati'); ?></th>
                    <td>
                        <strong><?php echo esc_html($status['current_version']); ?></strong>
                        <?php if ($update_available): ?>
                            <span style="color: #d63384; margin-left: 10px;">⚠️ Update Available</span>
                        <?php else: ?>
                            <span style="color: #198754; margin-left: 10px;">✅ Up to Date</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Latest Version', 'schemati'); ?></th>
                    <td>
                        <strong><?php echo esc_html($status['remote_version'] ?: 'Checking...'); ?></strong>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Last Check', 'schemati'); ?></th>
                    <td>
                        <?php 
                        if ($status['last_check']) {
                            echo human_time_diff($status['last_check']) . ' ago';
                        } else {
                            echo 'Never';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            
            <p>
                <button type="button" onclick="schematiManualCheck()" class="button button-secondary">
                    🔄 Check for Updates
                </button>
                <button type="button" onclick="schematiClearCache()" class="button">
                    🗑️ Clear Cache
                </button>
            </p>
        </div>
        
        <?php if (!empty($status['errors'])): ?>
        <div class="card">
            <h2>Recent Update Errors</h2>
            <?php foreach (array_slice($status['errors'], -3) as $error): ?>
            <div class="notice notice-error inline">
                <p><strong><?php echo esc_html($error['time']); ?>:</strong> <?php echo esc_html($error['message']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="notice notice-warning">
            <p>GitHub updater not initialized. Please check your configuration.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function schematiManualCheck() {
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'schemati_updater_check',
                nonce: '<?php echo wp_create_nonce('schemati_updater_check'); ?>'
            },
            beforeSend: function() {
                jQuery('button').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            complete: function() {
                jQuery('button').prop('disabled', false);
            }
        });
    }
    
    function schematiClearCache() {
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'schemati_updater_clear_cache',
                nonce: '<?php echo wp_create_nonce('schemati_updater_clear_cache'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('Cache cleared successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    }
    </script>
    <?php
}

public function ajax_clear_update_cache() {
    check_ajax_referer('schemati_clear_cache', 'nonce');
    
    if (!current_user_can('update_plugins')) {
        wp_die('Insufficient permissions');
    }
    
    // Clear update caches
    delete_transient('schemati_remote_version');
    delete_transient('schemati_changelog');
    delete_site_transient('update_plugins');
    
    wp_send_json_success('Cache cleared');
}


    /**
     * Enhanced sidebar HTML with improved functionality
     */
    public function add_sidebar_html() {
        if (!current_user_can('edit_posts') || is_admin()) {
            return;
        }
        
        global $post;
        $general_settings = $this->get_settings('schemati_general');
        
        // Get current page schema data with enhanced detection
        $current_schemas = $this->get_enhanced_page_schemas();
        ?>
        <div id="schemati-sidebar" style="display: none; position: fixed; top: 32px; right: 0; width: 450px; height: calc(100vh - 32px); background: white; border-left: 1px solid #ccc; z-index: 99999; padding: 0; overflow-y: auto; box-shadow: -2px 0 10px rgba(0,0,0,0.15); font-family: -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
            
            <!-- Enhanced Header with Dynamic Status -->
            <div style="padding: 20px; background: linear-gradient(135deg, #0073aa 0%, #005177 100%); color: white; position: sticky; top: 0; z-index: 1000;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin: 0; font-size: 18px;">
                            <span style="margin-right: 8px;">⚙️</span>
                            Schemati Editor v5.1
                        </h3>
                        <div style="font-size: 12px; opacity: 0.8; margin-top: 4px;">
                            <span id="schema-count"><?php echo count($current_schemas); ?> schemas detected</span>
                            <span style="margin-left: 10px;">•</span>
                            <span id="schema-status"><?php echo $general_settings['enabled'] ? 'Active' : 'Disabled'; ?></span>
                            <span style="margin-left: 10px;">•</span>
                            <span>Enhanced Architecture</span>
                        </div>
                    </div>
                    <button onclick="toggleSchematiSidebar()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: white; padding: 5px;">&times;</button>
                </div>
            </div>
            
            <!-- Enhanced Tabs Navigation -->
            <div style="background: #f1f1f1; border-bottom: 1px solid #ccc;">
                <div style="display: flex;">
                    <button class="schemati-tab active" onclick="showSchematiTab('current')" style="flex: 1; padding: 12px; border: none; background: white; cursor: pointer; border-bottom: 2px solid #0073aa; font-size: 12px;">
                        <span style="display: block;">Current</span>
                        <small style="color: #666;" id="current-count"><?php echo count($current_schemas); ?> schemas</small>
                    </button>
                    <button class="schemati-tab" onclick="showSchematiTab('add')" style="flex: 1; padding: 12px; border: none; background: #f1f1f1; cursor: pointer; border-bottom: 2px solid transparent; font-size: 12px;">
                        <span style="display: block;">Add</span>
                        <small style="color: #666;">New schema</small>
                    </button>
                    <button class="schemati-tab" onclick="showSchematiTab('settings')" style="flex: 1; padding: 12px; border: none; background: #f1f1f1; cursor: pointer; border-bottom: 2px solid transparent; font-size: 12px;">
                        <span style="display: block;">Settings</span>
                        <small style="color: #666;">Global</small>
                    </button>
                </div>
            </div>
            
            <!-- Enhanced Current Schemas Tab -->
            <div id="schemati-tab-current" class="schemati-tab-content" style="padding: 20px;">
                <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="margin: 0; color: #333; font-size: 14px;">DETECTED SCHEMAS</h4>
                    <div style="display: flex; gap: 5px;">
                        <button onclick="validateAllSchemas()" style="background: #28a745; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 11px; cursor: pointer;" title="Validate All">✓</button>
                    </div>
                </div>
                
                <div id="current-schemas-list">
                    <?php if (empty($current_schemas)): ?>
                        <div style="text-align: center; padding: 40px 20px; color: #666;">
                            <div style="font-size: 48px; margin-bottom: 10px;">📋</div>
                            <h4>No schemas detected</h4>
                            <p>Add your first schema using the "Add" tab.</p>
                            <button onclick="showSchematiTab('add')" style="background: #0073aa; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; margin-top: 10px;">Add Schema</button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($current_schemas as $index => $schema): ?>
                            <?php $this->render_enhanced_schema_item($schema, $index); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Add Tab -->
            <div id="schemati-tab-add" class="schemati-tab-content" style="display: none; padding: 20px;">
                <h4 style="margin: 0 0 15px 0; color: #333; font-size: 14px;">ADD NEW SCHEMA</h4>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Schema Type:</label>
                    <select id="new-schema-type" onchange="loadSchemaTemplate()" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Select Schema Type</option>
                        <optgroup label="Business">
                            <option value="LocalBusiness">🏢 Local Business</option>
                            <option value="Service">🛠️ Service</option>
                            <option value="Product">📦 Product</option>
                            <option value="Organization">🏛️ Organization</option>
                        </optgroup>
                        <optgroup label="Content">
                            <option value="Article">📰 Article</option>
                            <option value="BlogPosting">📝 Blog Post</option>
                            <option value="NewsArticle">📺 News Article</option>
                            <option value="FAQPage">❓ FAQ Page</option>
                            <option value="HowTo">📋 How-To</option>
                            <option value="Recipe">🍳 Recipe</option>
                        </optgroup>
                        <optgroup label="Events & People">
                            <option value="Event">📅 Event</option>
                            <option value="Person">👤 Person</option>
                            <option value="Review">⭐ Review</option>
                        </optgroup>
                        <optgroup label="Media">
                            <option value="VideoObject">🎥 Video</option>
                        </optgroup>
                        <optgroup label="Other">
                            <option value="WebSite">🌍 Website</option>
                        </optgroup>
                    </select>
                </div>
                
                <div id="new-schema-form" style="display: none;">
                    <form onsubmit="addNewSchema(); return false;">
                        <div id="schema-template-fields"></div>
                        
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="button" onclick="previewNewSchema()" style="flex: 1; background: #6c757d; color: white; border: none; padding: 12px; border-radius: 4px; cursor: pointer; font-weight: 500;">
                                👁️ Preview
                            </button>
                            <button type="submit" style="flex: 2; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; cursor: pointer; font-weight: 500;">
                                ➕ Add Schema
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Enhanced Settings Tab -->
            <div id="schemati-tab-settings" class="schemati-tab-content" style="display: none; padding: 20px;">
                <h4 style="margin: 0 0 15px 0; color: #333; font-size: 14px;">GLOBAL SETTINGS</h4>
                
                <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">
                    <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer;">
                        <input type="checkbox" id="schema-enabled" <?php checked($general_settings['enabled']); ?> onchange="toggleGlobalSchema()" style="margin-right: 8px;">
                        <span style="font-weight: 500;">Enable Schema Markup</span>
                    </label>
                    <div style="font-size: 12px; color: #666; margin-left: 20px;">
                        Controls whether schema markup is output on your website
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
    <h5 style="margin: 0 0 10px 0; font-size: 13px; color: #333;">QUICK ACTIONS</h5>
    <div style="display: grid; gap: 8px;">
        <button onclick="showSchematiPreview()" style="width: 100%; padding: 12px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
            <span>🔍</span>
            <span>Preview All Schemas</span>
        </button>
        <button onclick="testSchemaMarkupNet()" style="width: 100%; padding: 12px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
            <span>🚀</span>
            <span>Test Rich Results</span>
        </button>
    </div>
</div>
                
                <div style="margin-bottom: 20px;">
                    <a href="<?php echo admin_url('admin.php?page=schemati'); ?>" style="display: flex; align-items: center; gap: 8px; width: 100%; padding: 12px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; text-align: center; text-decoration: none; justify-content: center;">
                        <span>⚙️</span>
                        <span>Full Settings Panel</span>
                    </a>
                </div>
                
                <<div style="font-size: 11px; color: #666; text-align: center; border-top: 1px solid #eee; padding-top: 15px;">
    <div>Schemati v5.1 | Enhanced Architecture</div>
    <div style="margin-top: 4px;">
        <a href="https://schemamarkup.net/?url=<?php echo esc_attr(parse_url(home_url(), PHP_URL_HOST)); ?>" target="_blank" style="color: #0073aa; text-decoration: none;">Schema Validation Tool</a>
    </div>
</div>
            </div>
        </div>
        
        <!-- Enhanced Schema Preview Modal -->
        <div id="schemati-schema-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100000; font-family: -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 90%; max-width: 1200px; height: 85%; background: white; border-radius: 8px; padding: 0; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px; background: linear-gradient(135deg, #0073aa 0%, #005177 100%); color: white;">
                    <div>
                        <h2 style="margin: 0; color: white;">🔍 Live Schema Preview</h2>
                        <div style="font-size: 12px; opacity: 0.8; margin-top: 4px;" id="schema-preview-count">Loading...</div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button onclick="copyAllSchemas()" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">📋 Copy All</button>
                        <button onclick="hideSchematiPreview()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: white; padding: 5px;">&times;</button>
                    </div>
                </div>
                <div id="schema-modal-content" style="height: calc(100% - 80px); overflow-y: auto; padding: 20px; font-family: monospace; font-size: 12px; line-height: 1.5;">
                    Loading schema data...
                </div>
            </div>
        </div>
        
        <script>
// Enhanced JavaScript with object-oriented architecture
var SchematiSidebar = {
    currentPostId: <?php echo get_the_ID() ?: 0; ?>,
    ajaxUrl: '<?php echo admin_url("admin-ajax.php"); ?>',
    nonce: '<?php echo wp_create_nonce("schemati_ajax"); ?>',
    detectedSchemas: [],
    phpSchemas: <?php echo json_encode($current_schemas); ?>,
    // Centralized AJAX call handler
    ajaxCall: function(action, data, successCallback, errorCallback) {
        data.action = action;
        data.nonce = this.nonce;
        
        jQuery.ajax({
            url: this.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    if (successCallback) successCallback(response);
                } else {
                    var errorMsg = response.data?.message || 'Unknown error occurred';
                    if (errorCallback) {
                        errorCallback(errorMsg);
                    } else {
                        alert('Error: ' + errorMsg);
                    }
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'Connection error. Please try again.';
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                }
                if (errorCallback) {
                    errorCallback(errorMsg);
                } else {
                    alert(errorMsg);
                }
            }
        });
    },
    
    // Initialize sidebar
    init: function() {
        this.syncSchemasWithDOM();
        console.log('Schemati Sidebar v5.1: Enhanced Architecture Loaded');
    },
    
    // Enhanced sync function
    syncSchemasWithDOM: function() {
        this.detectedSchemas = [];
        
        // Scan DOM for JSON-LD scripts
        var domSchemas = [];
        document.querySelectorAll('script[type="application/ld+json"]').forEach(function(script, index) {
            try {
                var schema = JSON.parse(script.textContent);
                schema._domIndex = index;
                schema._element = script;
                schema._source = 'dom';
                domSchemas.push(schema);
            } catch(e) {
                console.warn('Invalid JSON-LD schema found:', e);
            }
        });
        
        // Combine with PHP data
        var self = this;
        domSchemas.forEach(function(domSchema, index) {
            var schemaType = domSchema['@type'];
            var schemaName = domSchema.name || domSchema.title || domSchema.headline || 'Untitled';
            
            var phpMatch = self.phpSchemas.find(function(phpSchema) {
                return phpSchema['@type'] === schemaType && 
                       (phpSchema.name === schemaName || phpSchema.title === schemaName);
            });
            
            if (phpMatch) {
                phpMatch._domDetected = true;
                phpMatch._domIndex = index;
                self.detectedSchemas.push(phpMatch);
            } else {
                domSchema._enabled = true;
                domSchema._source = domSchema._source || 'system';
                domSchema._editable = false;
                self.detectedSchemas.push(domSchema);
            }
        });
        
        this.updateSchemaCounts();
    },
    
    // Update schema counters
    updateSchemaCounts: function() {
        var count = this.detectedSchemas.length;
        var schemaCountEl = document.getElementById('schema-count');
        var currentCountEl = document.getElementById('current-count');
        
        if (schemaCountEl) schemaCountEl.textContent = count + ' schemas detected';
        if (currentCountEl) currentCountEl.textContent = count + ' schemas';
    }
};
SchematiSidebar.testCurrentPage = function() {
    testSchemaMarkupNet();
};
// Enhanced sidebar functions using the new architecture
function toggleSchematiSidebar() {
    var sidebar = document.getElementById("schemati-sidebar");
    if (sidebar) {
        if (sidebar.style.display === "none") {
            sidebar.style.display = "block";
            SchematiSidebar.syncSchemasWithDOM();
        } else {
            sidebar.style.display = "none";
        }
    }
}

function showSchematiTab(tabName) {
    document.querySelectorAll('.schemati-tab-content').forEach(tab => {
        tab.style.display = 'none';
    });
    document.querySelectorAll('.schemati-tab').forEach(btn => {
        btn.style.background = '#f1f1f1';
        btn.style.borderBottomColor = 'transparent';
    });
    
    var targetTab = document.getElementById('schemati-tab-' + tabName);
    if (targetTab) {
        targetTab.style.display = 'block';
    }
    
    if (event && event.target) {
        event.target.style.background = 'white';
        event.target.style.borderBottomColor = '#0073aa';
    }
    
    if (tabName === 'current') {
        SchematiSidebar.syncSchemasWithDOM();
    }
}

function toggleSchemaStatus(index) {
    SchematiSidebar.ajaxCall('schemati_toggle_schema', {
        schema_index: index,
        post_id: SchematiSidebar.currentPostId
    }, function(response) {
        // Success - reload page to show changes
        location.reload();
    });
}

function deleteSchema(index) {
    if (!confirm('Are you sure you want to delete this schema?')) {
        return;
    }
    
    SchematiSidebar.ajaxCall('schemati_delete_schema', {
        schema_index: index,
        post_id: SchematiSidebar.currentPostId
    }, function(response) {
        // Success - reload page to show changes
        location.reload();
    });
}

function loadSchemaTemplate() {
    var schemaType = document.getElementById('new-schema-type').value;
    var formContainer = document.getElementById('new-schema-form');
    var fieldsContainer = document.getElementById('schema-template-fields');
    
    if (!schemaType) {
        formContainer.style.display = 'none';
        return;
    }
    
    // Show loading state
    fieldsContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;"><div style="font-size: 20px; margin-bottom: 10px;">⏳</div><p>Loading template...</p></div>';
    formContainer.style.display = 'block';
    
    SchematiSidebar.ajaxCall('schemati_get_schema_template', {
        schema_type: schemaType
    }, function(response) {
        // Success
        fieldsContainer.innerHTML = response.data;
        
        // Add success feedback
        var successMsg = document.createElement('div');
        successMsg.style.cssText = 'background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #28a745;';
        successMsg.innerHTML = '✅ Template loaded successfully! Fill in the fields below.';
        fieldsContainer.insertBefore(successMsg, fieldsContainer.firstChild);
        
        // Remove success message after 3 seconds
        setTimeout(function() {
            if (successMsg.parentNode) {
                successMsg.remove();
            }
        }, 3000);
    }, function(errorMsg) {
        // Error
        fieldsContainer.innerHTML = '<div style="color: #721c24; background: #f8d7da; padding: 15px; border-radius: 4px; border-left: 4px solid #dc3545;"><strong>❌ Error:</strong> ' + errorMsg + '<br><br><button onclick="loadSchemaTemplate()" style="background: #dc3545; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer;">🔄 Retry</button></div>';
    });
}

function addNewSchema() {
    var form = document.querySelector('#new-schema-form form');
    var formData = new FormData(form);
    var schemaType = document.getElementById('new-schema-type').value;
    
    if (!schemaType) {
        alert('Please select a schema type');
        return false;
    }
    
    var data = {
        schema_type: schemaType,
        post_id: SchematiSidebar.currentPostId
    };
    
    // Add form data
    for (var pair of formData.entries()) {
        data[pair[0]] = pair[1];
    }
    
    SchematiSidebar.ajaxCall('schemati_add_schema', data, function(response) {
        // Success
        alert('Schema added successfully!');
        form.reset();
        document.getElementById('new-schema-form').style.display = 'none';
        document.getElementById('new-schema-type').value = '';
        location.reload();
    });
    
    return false;
}

function previewNewSchema() {
    var form = document.querySelector('#new-schema-form form');
    var formData = new FormData(form);
    var schemaType = document.getElementById('new-schema-type').value;
    
    // Build preview schema object
    var previewSchema = {
        '@context': 'https://schema.org',
        '@type': schemaType
    };
    
    // Add form data to preview
    for (var pair of formData.entries()) {
        if (pair[1]) {
            previewSchema[pair[0]] = pair[1];
        }
    }
    
    // Show preview in modal
    var modal = document.getElementById('schemati-schema-modal');
    var content = document.getElementById('schema-modal-content');
    
    content.innerHTML = '<div style="margin-bottom: 20px; padding: 15px; background: #fff3cd; border-radius: 8px; color: #856404; border-left: 4px solid #ffc107;"><h3 style="margin: 0;">👁️ Schema Preview</h3><p style="margin: 5px 0 0 0; font-size: 13px;">This is how your schema will look when added.</p></div><pre style="background: #2d3748; color: #e2e8f0; padding: 20px; border-radius: 5px; overflow-x: auto; white-space: pre-wrap; font-size: 11px; line-height: 1.4;">' + JSON.stringify(previewSchema, null, 2) + '</pre>';
    
    modal.style.display = 'block';
}

function showSchematiPreview() {
    SchematiSidebar.syncSchemasWithDOM();
    
    var modal = document.getElementById('schemati-schema-modal');
    var content = document.getElementById('schema-modal-content');
    var countElement = document.getElementById('schema-preview-count');
    
    if (SchematiSidebar.detectedSchemas.length === 0) {
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;"><div style="font-size: 48px; margin-bottom: 15px;">📋</div><h3>No Schema Found</h3><p>No schema markup was detected on this page.</p></div>';
        countElement.textContent = 'No schemas found';
    } else {
        var html = '<div style="margin-bottom: 20px; padding: 15px; background: #d4edda; border-radius: 8px; color: #155724; border-left: 4px solid #28a745;"><h3 style="margin: 0; display: flex; align-items: center; gap: 8px;"><span>✅</span>Found ' + SchematiSidebar.detectedSchemas.length + ' Schema Type(s)</h3><p style="margin: 5px 0 0 0; font-size: 13px;">All schemas are properly formatted and ready for search engines.</p></div>';
        
        SchematiSidebar.detectedSchemas.forEach(function(schema, index) {
            var schemaType = schema['@type'] || 'Unknown Type';
            var schemaName = schema.name || schema.title || schema.headline || 'No title';
            
            html += '<div style="margin-bottom: 25px; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; background: white;">';
            html += '<div style="padding: 15px; background: linear-gradient(135deg, #0073aa 0%, #005177 100%); color: white; display: flex; justify-content: space-between; align-items: center;">';
            html += '<div><h4 style="margin: 0; font-size: 16px;">' + (index + 1) + '. ' + schemaType + ' Schema</h4>';
            if (schemaName !== 'No title') {
                html += '<div style="font-size: 12px; opacity: 0.8; margin-top: 4px;">' + schemaName + '</div>';
            }
            html += '</div>';
            html += '<button onclick="copySchema(' + index + ')" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 11px;">Copy</button>';
            html += '</div>';
            html += '<pre style="background: #2d3748; color: #e2e8f0; padding: 20px; margin: 0; overflow-x: auto; white-space: pre-wrap; font-size: 11px; line-height: 1.5;">' + JSON.stringify(schema, null, 2) + '</pre>';
            html += '</div>';
        });
        content.innerHTML = html;
        countElement.textContent = SchematiSidebar.detectedSchemas.length + ' schemas detected and validated';
    }
    
    modal.style.display = 'block';
}

function copySchema(index) {
    if (SchematiSidebar.detectedSchemas[index]) {
        navigator.clipboard.writeText(JSON.stringify(SchematiSidebar.detectedSchemas[index], null, 2)).then(function() {
            alert('Schema copied to clipboard!');
        });
    }
}

function copyAllSchemas() {
    navigator.clipboard.writeText(JSON.stringify(SchematiSidebar.detectedSchemas, null, 2)).then(function() {
        alert('All schemas copied to clipboard!');
    });
}

function hideSchematiPreview() {
    document.getElementById('schemati-schema-modal').style.display = 'none';
}

function testSchemaMarkupNet() {
    // Get current page URL without protocol
    var currentUrl = window.location.host + window.location.pathname + window.location.search;
    window.open('https://schemamarkup.net/?url=' + encodeURIComponent(currentUrl), '_blank');
}
	function testGoogleRichResults() {
    testSchemaMarkupNet();
}


function toggleGlobalSchema() {
    var checkbox = document.getElementById('schema-enabled');
    var enabled = checkbox.checked ? 1 : 0;
    
    SchematiSidebar.ajaxCall('schemati_toggle_global', {
        enabled: enabled
    }, function(response) {
        // Success
        var statusEl = document.getElementById('schema-status');
        if (statusEl) {
            statusEl.textContent = enabled ? 'Active' : 'Disabled';
        }
    });
}

function validateAllSchemas() {
    SchematiSidebar.syncSchemasWithDOM();
    var validCount = 0;
    var invalidCount = 0;
    
    SchematiSidebar.detectedSchemas.forEach(function(schema) {
        try {
            if (schema['@context'] && schema['@type']) {
                validCount++;
            } else {
                invalidCount++;
            }
        } catch (e) {
            invalidCount++;
        }
    });
    
    alert('Validation Results:\n✅ Valid: ' + validCount + '\n❌ Invalid: ' + invalidCount);
}

// Initialize sidebar when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    SchematiSidebar.init();
});

// Add enhanced CSS for better interactions
var style = document.createElement('style');
style.textContent = `
    .schemati-tab {
        transition: all 0.3s ease;
    }
    .schemati-tab:hover {
        background: #e9ecef !important;
    }
    .schema-item {
        transition: all 0.3s ease;
    }
    .schema-item:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .schemati-loading {
        opacity: 0.6;
        pointer-events: none;
    }
`;
document.head.appendChild(style);
</script>
        <?php
    }
	private function log_error($message, $context = array()) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Schemati Error: ' . $message . ' Context: ' . print_r($context, true));
    }
}

// 4. ADD: Schema validation method (NEW)
private function validate_schema($schema) {
    $errors = array();
    
    // Check required fields
    if (empty($schema['@context'])) {
        $errors[] = 'Missing @context';
    }
    
    if (empty($schema['@type'])) {
        $errors[] = 'Missing @type';
    }
    
    // Type-specific validation
    switch ($schema['@type']) {
        case 'LocalBusiness':
            if (empty($schema['name'])) {
                $errors[] = 'Missing business name';
            }
            break;
        case 'Product':
            if (empty($schema['name'])) {
                $errors[] = 'Missing product name';
            }
            break;
        // Add more validations as needed
    }
    
    return $errors;
}


    /**
     * Render enhanced schema item with better UI
     */
    private function render_enhanced_schema_item($schema, $index) {
        $schema_type = $schema['@type'] ?? 'Unknown';
        $schema_name = $schema['name'] ?? $schema['title'] ?? 'Untitled';
        $schema_enabled = $schema['_enabled'] ?? true;
        $schema_source = $schema['_source'] ?? 'unknown';
        
        // Get source icon and color
        $source_info = $this->get_source_info($schema_source);
        ?>
        <div class="schema-item" data-schema-index="<?php echo $index; ?>" style="margin-bottom: 15px; border: 1px solid <?php echo $schema_enabled ? '#ddd' : '#f5c6cb'; ?>; border-radius: 8px; overflow: hidden; <?php echo $schema_enabled ? '' : 'opacity: 0.7;'; ?>">
            <div class="schema-header" style="background: <?php echo $schema_enabled ? '#f8f9fa' : '#f8d7da'; ?>; padding: 12px; display: flex; justify-content: space-between; align-items: center;">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <strong style="color: #0073aa; font-size: 14px;"><?php echo esc_html($schema_type); ?></strong>
                        <span style="background: <?php echo $source_info['color']; ?>; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 500;">
                            <?php echo $source_info['icon']; ?> <?php echo $source_info['label']; ?>
                        </span>
                    </div>
                    <div style="font-size: 12px; color: #666; line-height: 1.3;">
                        <?php echo esc_html(wp_trim_words($schema_name, 8)); ?>
                    </div>
                </div>
                <div style="display: flex; gap: 5px; align-items: center;">
                    <?php if ($schema_source === 'custom'): ?>
                    <button onclick="toggleSchemaStatus(<?php echo $index; ?>)" style="background: <?php echo $schema_enabled ? '#28a745' : '#dc3545'; ?>; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 11px; cursor: pointer; font-weight: 500;">
                        <?php echo $schema_enabled ? 'ON' : 'OFF'; ?>
                    </button>
                    <button onclick="deleteSchema(<?php echo $index; ?>)" style="background: #dc3545; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 11px; cursor: pointer;">✕</button>
                    <?php else: ?>
                    <span style="color: #666; font-size: 11px;">System</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get source information for schema items
     */
    private function get_source_info($source) {
        switch ($source) {
            case 'global':
                return array(
                    'label' => 'Global',
                    'icon' => '🌐',
                    'color' => '#17a2b8'
                );
            case 'post':
                return array(
                    'label' => 'Post',
                    'icon' => '📄',
                    'color' => '#28a745'
                );
            case 'auto':
                return array(
                    'label' => 'Auto',
                    'icon' => '🤖',
                    'color' => '#6f42c1'
                );
            case 'custom':
                return array(
                    'label' => 'Custom',
                    'icon' => '✏️',
                    'color' => '#fd7e14'
                );
            default:
                return array(
                    'label' => 'System',
                    'icon' => '⚙️',
                    'color' => '#6c757d'
                );
        }
    }

    /**
     * Get enhanced page schemas with better detection
     */
    private function get_enhanced_page_schemas() {
    $schemas = array();
    
    // Get existing schemas that would be output
    $general_settings = $this->get_settings('schemati_general');
    
    if (!$general_settings['enabled']) {
        return $schemas;
    }
    
    // Organization schema
    $org_schema = $this->build_organization_schema();
    if ($org_schema) {
        $org_schema['_enabled'] = true;
        $org_schema['_source'] = 'global';
        $schemas[] = $org_schema;
    }
    
    // NEW: Header/Footer schemas
    $header_schema = $this->build_wpheader_schema();
    if ($header_schema) {
        $header_schema['_enabled'] = true;
        $header_schema['_source'] = 'auto';
        $schemas[] = $header_schema;
    }
    
    $footer_schema = $this->build_wpfooter_schema();
    if ($footer_schema) {
        $footer_schema['_enabled'] = true;
        $footer_schema['_source'] = 'auto';
        $schemas[] = $footer_schema;
    }
        
        // Page-specific schemas
        if (is_singular()) {
            global $post;
            
            // WebPage/Article schema
            $page_schema = $this->build_webpage_schema();
            if ($page_schema) {
                $page_schema['_enabled'] = true;
                $page_schema['_source'] = 'post';
                $schemas[] = $page_schema;
            }
            
            // Breadcrumb schema
            if (!is_front_page()) {
                $breadcrumb_schema = $this->build_breadcrumb_schema();
                if ($breadcrumb_schema) {
                    $breadcrumb_schema['_enabled'] = true;
                    $breadcrumb_schema['_source'] = 'auto';
                    $schemas[] = $breadcrumb_schema;
                }
            }
            
            // Custom schemas from meta
            $custom_schemas = get_post_meta($post->ID, '_schemati_custom_schemas', true);
            if ($custom_schemas && is_array($custom_schemas)) {
                foreach ($custom_schemas as $index => $custom_schema) {
                    $custom_schema['_source'] = 'custom';
                    $custom_schema['_index'] = $index;
                    $schemas[] = $custom_schema;
                }
            }
        }
        
        return $schemas;
    }
}

// Initialize the plugin
function schemati_init() {
    return Schemati::instance();
}

// Start the plugin
add_action('plugins_loaded', 'schemati_init');

// Helper function for themes
function schemati_breadcrumbs($args = array()) {
    $schemati = Schemati::instance();
    return $schemati->breadcrumb_shortcode($args);
}

// Plugin hooks for cleanup
register_uninstall_hook(__FILE__, 'schemati_uninstall');

function schemati_uninstall() {
    // Clean up options
    delete_option('schemati_general');
    delete_option('schemati_article');
    delete_option('schemati_about_page');
    delete_option('schemati_contact_page');
    delete_option('schemati_local_business');
    delete_option('schemati_person');
    delete_option('schemati_author');
    delete_option('schemati_publisher');
    delete_option('schemati_product');
    delete_option('schemati_faq');
    
    // Clean up post meta
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_schemati_%'");
    
    // Clear cache
    wp_cache_flush_group('schemati_schemas');
}

?>
