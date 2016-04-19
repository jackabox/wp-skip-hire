<?php
/**
 * Plugin Name:       Skip Hire
 * Plugin URI:        http://plugins.adtrakdev.com/skiphire
 * Description:       Adding the ability to hire skips and process payments within areas.
 * Version:           0.2.1
 * Author:            Adtrak
 * Author URI:        http://adtrak.co.uk/
 * GitLab Theme URI:  https://gitlab.com/adtrak/skip-hire.git
 */

# composer
require plugin_dir_path( __FILE__ )  . '/vendor/autoload.php';

# If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) die;

# require cmb2  
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/cmb2/init.php' ) ) 
    require_once plugin_dir_path( __FILE__ ) . 'vendor/cmb2/init.php';

# include google maps field, if not already declared
if(!class_exists( 'PW_CMB2_Field_Google_Maps' ) && file_exists( plugin_dir_path( __FILE__ ) . 'vendor/cmb_field_map/cmb-field-map.php' ) ) 
    require_once plugin_dir_path( __FILE__ ) . 'vendor/cmb_field_map/cmb-field-map.php';

if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/classes/SkipHire.php' ) ) 
    require plugin_dir_path( __FILE__ ) . 'includes/classes/SkipHire.php';

$plugin = new ad_skip_hire( );

class ad_skip_hire
{
    protected $plugin_name;
    protected $version;
    protected $prefix;
    protected $settings;
    protected $settings_slug;

    protected $coupons;
    protected $locations;
    protected $permits;
    protected $orders;
    protected $skips;
    protected $paypal;

    /**
     * contruct
     */
    public function __construct()
    {
        $this->plugin_name = 'ad_skip_hire';
        $this->version = '0.1';
        $this->prefix = 'ash_';
        $this->settings_slug = "ad_skip_hire_options";
        $this->sections = $this->get_sections();

        # activation
        register_activation_hook( __FILE__ , [$this, 'activate'] );

        # dependanices
        $this->load_dependencies();

        # init
        add_action('init', [$this, 'session_start']);

        # menu pages
        add_action( 'admin_menu', [$this, 'skip_admin_pages'] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        # shortcodes
        add_shortcode( 'ash_postcode_form', [$this, 'build_postcode_form'] );
        add_shortcode( 'ash_booking_form', [$this, 'booking_form_director'] );
        add_shortcode( 'ash_booking_confirmation', [$this, 'booking_form_process'] );

        # javascript
        add_action( 'wp_enqueue_scripts', [$this, 'load_javascript'] );
        add_filter( 'cmb2_meta_box_url', [$this, 'update_cmb2_meta_box_url'] );
    }


    public function activate() 
    {
        $booking_form = [
            'post_title'    => 'Booking',
            'post_content'  => '[ash_booking_form]',
            'post_status'   => 'publish',
            'post_type'     => 'page'
        ];

        $page_exists = get_page_by_title( $booking_form['post_title'] );

        if ( $page_exists == null ) {
            $post_id = wp_insert_post( $booking_form );
        
            wp_insert_post([
                'post_title'    => 'Confirmation',
                'post_content'  => '[ash_booking_confirmation]',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_parent'   => $post_id,
            ]);
        }
    }

    /**
     * load in dependancies, we may not need all the extra calls here
     */
    public function load_dependencies()
    {
        // the class responsible for managing the orders
        require_once plugin_dir_path( __FILE__ ) . 'includes/classes/Locations.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/classes/Permits.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/classes/Coupons.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/classes/Skips.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/classes/Orders.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/classes/WpGeoQuery.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/classes/PayPal.php';

        $this->locations = new ad_skip_hire_locations();
        $this->permits = new ad_skip_hire_permits();
        $this->coupons = new ad_skip_hire_coupons();
        $this->skips = new ad_skip_hire_skips();
        $this->orders = new ad_skip_hire_orders();
        $this->paypal = new ad_paypal_interface();
    }

    /**
     * update cmb2 meta box url so it loads assets
     */
    public function update_cmb2_meta_box_url()
    {
        $url = plugins_url( 'vendor/cmb2', __FILE__ );
        return $url;
    }

    /**
     * Inject session_start before the headers are called.
     */
    function session_start() 
    {
        if( ! session_id() ) 
            session_start();
    }

    /**
     * load the javascript
     */
    public function load_javascript()
    {
        wp_enqueue_script( 'jquery', plugins_url( 'js/jquery-2.2.3.min.js', __FILE__ ), '', '2.2.3');
        wp_enqueue_script( 'google_maps_api', 'https://maps.googleapis.com/maps/api/js', '', '', true );
        wp_enqueue_script( 'ash_custom', plugins_url( 'js/custom.min.js', __FILE__ ), ['jquery', 'google_maps_api'], '1.0.1', true);
    }

    /**
     * create the required menu pages in the admin
     */
    public function skip_admin_pages() 
    {
        add_menu_page( 
            'Skip Hire Options', 
            'Skip Hire', 
            'read', 
            $this->plugin_name,
            [$this, 'skip_create_admin_page'], 
            '', 
            20
        );

        add_submenu_page( 
            $this->plugin_name,
            'Skip Hire Settings', 
            'Settings', 
            'manage_options', 
            $this->plugin_name . '_options',
            [$this, 'skip_create_settings_page']
        );
    }

    public function get_sections() 
    {
        $sections = [];

        $sections[$this->prefix . '_delivery'] = [
            'id'         => $this->prefix . '_delivery',
            'title'      => 'Delivery',
            'callback'   => [$this, 'render_section'],
            'page'       => 'delivery_page'
        ];

        $sections[$this->prefix . '_payment'] = [
            'id'         => $this->prefix . '_payment',
            'title'      => 'Payment',
            'callback'   => [$this, 'render_section'],
            'page'       => 'payment_page'
        ];

        return $sections;
    }

    public function render_section($desc)
    {
        echo "<p></p>";
    }

    public function get_fields() 
    {
        $fields = [];

        $fields[] = [
            'id'             => $this->prefix . '_delivery_radius',
            'title'          => 'Delivery Radius',
            'callback'       => [$this, 'render_field'],
            'page'           => 'delivery_page',
            'section'        => $this->prefix . '_delivery',
            'desc'           => 'Specify a radius from locations to deliver to.',
            'type'           => 'text',
            'default_value'  => '10',
            'class'          => ''
        ];
        
        $fields[] = [
            'id'             => $this->prefix . '_paypal_client_id',
            'title'          => 'PayPal Client ID',
            'callback'       => [$this, 'render_field'],
            'page'           => 'payment_page',
            'section'        => $this->prefix . '_payment',
            'desc'           => 'Provide the PayPal Client ID.',
            'type'           => 'text',
            'default_value'  => '',
            'class'          => ''
        ];

        $fields[] = [
            'id'             => $this->prefix . '_paypal_client_secret',
            'title'          => 'PayPal Client Secret',
            'callback'       => [$this, 'render_field'],
            'page'           => 'payment_page',
            'section'        => $this->prefix . '_payment',
            'desc'           => 'Provide the PayPal Client Secret.',
            'type'           => 'text',
            'default_value'  => '',
            'class'          => ''
        ];

        $fields[] = [
            'id'             => $this->prefix . '_payment_description',
            'title'          => 'Payment Description',
            'callback'       => [$this, 'render_field'],
            'page'           => 'payment_page',
            'section'        => $this->prefix . '_payment',
            'desc'           => 'Provide the payment description (will appear on invoices).',
            'type'           => 'text',
            'default_value'  => '',
            'class'          => ''
        ];

        return $fields;
    }

    public function create_field($field)
    {
        extract ($field);

        $field_args = [
            'id' => $id,
            'page' => $page,
            'type' => $type,
            'desc' => $desc,
            'default_value' => $default_value,
            'class' => $class
        ];

        add_settings_field($id, $title, [$this, 'render_field'], $page, $section, $field_args);
    }

    public function render_field ($field_args = [])
    {        
        extract ($field_args);
        $options = get_option($page);

        $html = '<div class="' . $class . '">';

        switch ($type) {
            case 'text':
                $value = isset($options[$id]) ? $options[$id] : '';
                $html .= '<input type="text" id="' . $id . '" name="' . $page . '[' . $id . ']" value="' . $value . '" />';
                $html .= '<br/><span class="field-desc">' . $desc . '</span>';
                break;
            case 'checkbox':
                $html .= '<input type="checkbox" id="' . $id . '" name="' . $page . '[' . $id . ']" value="1" ' . checked (1, isset ($options[$id]) ? $options[$id] : 0, false) . '/>';
                $html .= '<label for="' . $id . '">&nbsp;'  . $desc . '</label>';
                break;
            case 'button':
                break;
            default:
                break;
        }

        $html .= '</div>';
        echo $html;
    }

    public function register_settings()
    {
        foreach($this->sections as $section) {
            add_settings_section($section['id'], $section['title'], $section['callback'], $section['page']);
        }

        $fields = $this->get_fields();

        foreach($fields as $field) {
            $this->create_field($field);
        }

        foreach($this->sections as $section) {
            register_setting($section['page'], $section['page'], [$this, 'validate_options']);
        }
    }

    public function validate_options($input)
    {
        $output = [];

        foreach ($input as $key => $value) {
            if ( isset( $input[$key] ) )
                $output[$key] = strip_tags(stripslashes($input[$key]));
        }
        
        return apply_filters('validate_options', $output, $input);
    }

    /**
     * create the admin page for managing settings
     */
    public function skip_create_settings_page( )
    {
        include_once plugin_dir_path(__FILE__) . 'includes/views/admin/skips.php';
    }

    /**
     * send the right forms onto the page, process and transfer details
     */
    public function booking_form_director ( $args = [] )
    {
        $postcode = (isset($_REQUEST['ash_postcode'])) ? $_REQUEST['ash_postcode'] : NULL; 
        $lat = (isset($_REQUEST['ash_lat'])) ? $_REQUEST['ash_lat'] : NULL; 
        $lng = (isset($_REQUEST['ash_lng'])) ? $_REQUEST['ash_lng'] : NULL; 
        $skip = (isset($_REQUEST['ash_skip_id'])) ? $_REQUEST['ash_skip_id'] : NULL; 

        # set sessions
        if($skip != null)
            $_SESSION['ash_skip_id'] = $skip;
        
        if($postcode != null)
            $_SESSION['ash_postcode'] = $postcode;

        # load forms       
        if ( $skip == null && $lat != null ) {
            # run the geo query
            $locations = new ASH_WP_Query_Geo([
                'post_status'       => 'publish',
                'post_type'         => 'ash_locations',
                'posts_per_page'    => -1,
                'lat'               => $lat,
                'lng'               =>  $lng,
                'distance'          => 10
            ]);

            if( $locations->found_posts > 0 ) {
                $this->build_skip_form( $postcode );
            } else {
                echo "<p>We don't deliver skips to your location sorry.</p>";
            }
        } elseif ( $skip != null ) {
            if( isset( $_POST['ash_submit'] ) ) {
                foreach( $_POST as $key => $entry ) {
                    if(
                        $key == 'ash_forename' ||
                        $key == 'ash_surname' ||
                        $key == 'ash_email' ||
                        $key == 'ash_phone' ||
                        $key == 'ash_delivery_address_1' ||
                        $key == 'ash_delivery_city' ||
                        $key == 'ash_delivery_county' ||
                        $key == 'ash_delivery_postcode' ||
                        $key == 'ash_delivery_date' ||
                        $key == 'ash_delivery_time' ||
                        $key == 'ash_skip_id'
                    ) {
                        if( ( $entry == NULL ) || empty( $entry ) ):
                            echo "<p>There were errors with the form. Please fix them to proceed.</p>";
                            $this->build_booking_form();
                            $pass = false;
                            break;
                        endif;
                    } else { 
                        $pass = true;
                    }
                }

                if( $pass == true ) {
                    $this->build_confirmation_form();
                }

            } else {
                $this->build_booking_form();
            }
        } else {
            echo "<p>Please enter a post code to see if we deliver to your area.</p>";
            $this->build_postcode_form();
        }
    }

    /**
     * the shortcode contents for the postcode form (looking up lat and lang)
     */
    public function build_postcode_form( )
    { 
        include_once plugin_dir_path( __FILE__ ) . 'includes/views/postcodeForm.php';
    }

    /**
     * build the skips form so we can pick which skip we want
     */
    public function build_skip_form( $postcode = null )
    {
        include_once plugin_dir_path( __FILE__ ) . 'includes/views/skipChoiceForm.php';
    }

    /**
     * build the booking form so that we can fill out user details
     */
    public function build_booking_form( $skip = NULL, $postcode = NULL )
    { 
        include_once plugin_dir_path( __FILE__ ) . 'includes/views/bookingForm.php';
    }

    /**
     * build the confirmation form, which links off to payment method or displays success with payment.
     */
    public function build_confirmation_form( $args = [] )
    {
        if(!isset($_GET['success'])) {
            # Skips
            $skips = new WP_Query([
                'post_type'         => 'ash_skips',
                'posts_per_page'    => 1,
                'post_id'           => $_SESSION['ash_skip_id']
            ]);

            if ( $skips->have_posts() ): 
                while ( $skips->have_posts() ): $skips->the_post();
                    $skip['title'] = get_the_title();
                    $skip['id'] =  get_the_ID();
                    $skip['price'] = get_post_meta( get_the_ID(), 'ash_skips_price', true );
                endwhile; 
            endif;

            # Permits
            $permits = new WP_Query([
                'post_type'         => 'ash_permits',
                'posts_per_page'    => 1,
                'post_id'           => $_POST['ash_permit_id']
            ]);

            if ( $permits->have_posts() ): 
                while ( $permits->have_posts() ): $permits->the_post();
                    $permit['title'] = get_the_title();
                    $permit['id'] =  get_the_ID();
                    $permit['price'] = get_post_meta( get_the_ID(), 'ash_permits_price', true );
                endwhile; 
            else:
                $permit['title'] = '';
                $permit['price'] = 0.00;
            endif;

            $subTotal = $permit['price'] + $skip['price'];

            # Coupon
            if( isset($_POST['ash_coupon'] ) ) {

                $coupons = new WP_Query([
                    'post_type'         => 'ash_coupons',
                    's'                 => $_POST['ash_coupon'],
                    'posts_per_page'    => 1,
                ]);

                if ( $coupons->have_posts() ):
                    while ( $coupons->have_posts() ): $coupons->the_post();
                        $couponType = get_post_meta(get_the_ID(), 'ash_coupons_type', true);
                        $couponAmount = get_post_meta(get_the_ID(), 'ash_coupons_amount', true);

                        $coupon['title'] = get_the_title();
                        $coupon['id'] =  get_the_ID();

                        if( $couponType == 'flat' ) {
                            $coupon['price'] = $couponAmount;
                        } elseif ( $couponType == 'percent' ) {
                            $coupon['price'] = $subTotal * ($couponAmount / 100);
                        }
                    endwhile; 
                else:
                    $coupon['title'] = '';
                    $coupon['price'] = 0.00;
                endif; 

            } else {
                $coupon['title'] = '';
                $coupon['price'] = 0.00;
            }

            $total = $subTotal - $coupon['price'];
            $_SESSION['ash_order_total'] = $total;

            # paypal link
            $paymentLink = $this->paypal->generate_payment_link($_POST, $skip, $permit, $coupon, $total);

            # create the form.
            $this->create_order_from_form();

            # Include Template
            include_once plugin_dir_path( __FILE__ ) . 'includes/views/orderConfirmationForm.php';
        } else {
            include_once plugin_dir_path( __FILE__ ) . 'includes/views/orderConfirmation.php';
        }
    }

    /**
     * create the order and add it to the database
     */
    public function create_order_from_form () 
    {
        # create the post
        $createPost = [
            'post_title'    => wp_strip_all_tags( $_POST['ash_forename'] . ' ' . $_POST['ash_surname'] ),
            'post_content'  => '',
            'post_status'   => 'publish',
            'post_type'     => 'ash_orders',
        ];

        # get the post id
        $postID = wp_insert_post( $createPost );
        $_SESSION['ash_order_id'] = $postID;


        # session
        $_SESSION['ash_user'] = [
            'id'    => $postID,
            'name'  => $_POST['ash_forename'] . ' ' . $_POST['ash_surname'],
        ];

        $deliveryAddress = [
            'address_1' => $_POST['ash_delivery_address_1'],
            'address_2' => $_POST['ash_delivery_address_2'],
            'city'      => $_POST['ash_delivery_city'],
            'county'    => $_POST['ash_delivery_county'],
            'postcode'  => strtoupper($_SESSION['ash_postcode']),
        ];

        # posted data - meta
        add_post_meta( $postID, 'ash_orders_email', $_POST['ash_email'] );
        add_post_meta( $postID, 'ash_orders_phone', $_POST['ash_phone'] );
        add_post_meta( $postID, 'ash_orders_delivery_address', $deliveryAddress );
        add_post_meta( $postID, 'ash_orders_delivery_date', $_POST['ash_delivery_date']);
        add_post_meta( $postID, 'ash_orders_delivery_time', $_POST['ash_delivery_time'][0]);
        add_post_meta( $postID, 'ash_orders_skip_id', $_SESSION['ash_skip_id']);
        add_post_meta( $postID, 'ash_orders_permit_id', $_POST['ash_permit_id']);
        add_post_meta( $postID, 'ash_orders_waste', $_POST['ash_waste']);
        add_post_meta( $postID, 'ash_orders_notes', $_POST['ash_notes']);
        add_post_meta( $postID, 'ash_orders_status', 'pending');
        add_post_meta( $postID, 'ash_orders_total', $_SESSION['ash_order_total']);
    }

    /**
     * update from the paypal return
     */
    public function booking_form_process ()
    {
        $this->paypal->authorised_payment_check();

        add_post_meta( $_SESSION['ash_order_id'], 'ash_orders_paypal_id', $_GET['paymentId']);
        add_post_meta( $_SESSION['ash_order_id'], 'ash_orders_paypal_payer_id', $_GET['PayerID']);
    }

    /**
     * validate a string/text field
     */
    public function validateString ($val) 
    {
        return sanitize_text_field($val);
    }

    /**
     * validate a url that's passed
     */
    public function validateUrl ($url) 
    {
        return esc_url($url);
    }

    /**
     * validate a number that's passed
     */
    public function validateNumber ($val) 
    {
        return intval($val);
    }
}