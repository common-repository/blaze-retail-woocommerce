<?php
/**
 * WooCommerce BLAZE User Sync
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit; // Exit if accessed directly
if (!function_exists('wp_new_user_notification')) :

    function wp_new_user_notification($user_id, $plaintext_pass = '') {
        WooBlaze_Retail()->userSync->set_user_symfony_hashes($user_id, $plaintext_pass); // create symfony hash
        $user = get_userdata($user_id);

        // The blogname option is escaped with esc_html on the way into the database in sanitize_option
        // we want to reverse this for the plain text arena of emails.
        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        $message = sprintf(__('New user registration on your site %s:'), $blogname) . "\r\n\r\n";
        $message .= sprintf(__('Username: %s'), $user->user_login) . "\r\n\r\n";
        $message .= sprintf(__('E-mail: %s'), $user->user_email) . "\r\n";

        @wp_mail(get_option('admin_email'), sprintf(__('[%s] New User Registration'), $blogname), $message);

        if (empty($plaintext_pass)) {
            return;
        }

        $message = sprintf(__('Username: %s'), $user->user_login) . "\r\n";
        $message .= sprintf(__('Password: %s'), $plaintext_pass) . "\r\n";
        $message .= wp_login_url() . "\r\n";

        wp_mail($user->user_email, sprintf(__('[%s] Your username and password'), $blogname), $message);
    }

endif;

class Blaze_woo_user_sync {

    function set_user_symfony_hashes($user_id, $password) {
        $user = get_userdata($user_id);

        if (!$password && 0 == strlen($password)) {
            return;
        }

        $salt = md5(rand(100000, 999999) . $user->get('display_name'));
        $hash = sha1($salt . $password);
        update_user_meta($user_id, 'Blaze_symfony_salt', $salt);
        update_user_meta($user_id, 'Blaze_symfony_hash', $hash);
    }

    function __construct($render_registration=false) {
        $this->domain = get_option('Blaze_api_domain');
        $this->apikey = get_option('Blaze_api_key');
        add_action('user_profile_update_errors', array(&$this, 'user_profile_update_errors'), 10, 3);

        // registration customer
        if($render_registration != null && $render_registration) {
            add_action('woocommerce_register_form', array(&$this, 'woocommerce_register_form'), 10, 0);
            add_action('woocommerce_registration_errors', array(&$this, 'woocommerce_registration_errors'), 10, 3);
            add_action('woocommerce_created_customer', array(&$this, 'woocommerce_created_customer'), 10, 3);
        }

        // Fix strenght password check
        add_filter('send_password_change_email', '__return_false');
        add_action('wp_print_scripts', array(&$this, 'woocommerce_ninja_remove_password_strength'), 100);
        add_action('wp_authenticate', array(&$this, 'login_function'), 10, 2);
        add_action('password_reset', array(&$this, 'my_password_reset'), 10, 2);
        add_filter('lostpassword_post', array(&$this, 'woo_blaze_change_password_mail_message'), 2, 1);
    }

    function woo_blaze_change_password_mail_message($errors) {

        global $wpdb;
        $exists = email_exists($_POST['user_login']);

        if ($exists == '' || $exists == NULL) {
            $email = $_POST['user_login'];
            $apidomain = get_option('Blaze_api_domain');
            $apikey = get_option('Blaze_api_key');

            // get consumer profile to create a user in woo if it doesn't exist.. send a forgot password email otherwise            
            $url = $apidomain . "/api/v1/store/auth/createFromProfile?api_key=" . $apikey;
            $data = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode(array("email" => $email)),
                'method' => 'POST'
            ));
            $response = json_decode($data['body']);
            $message = $response->message;
            if ($message == '') {
                $blazeuserid = $response->id;
                $firstname = $response->firstName;
                $lastname = $response->lastName;
                $phone = $response->primaryPhone;

                // explode email to get nickname to use in the database.
                $emaildata = explode("@", $email);
                $nickname = username_exists($emaildata[0]) ? $emaildata[0] . rand(1000, 9999) : $emaildata[0];
                $password = "12345";

                // set role before saving the user in the database.
                $role = array('customer' => 1);

                // set cart object to null.
                $cart = array('cart' => Array());
                $user_id = wp_create_user($nickname, $password, $email);
                update_user_meta($user_id, 'first_name', $firstname);
                update_user_meta($user_id, 'last_name', $lastname);
                update_user_meta($user_id, 'nickname', $nickname);
                update_user_meta($user_id, 'wp_capabilities', $role);
                update_user_meta($user_id, 'Blaze_woo_user_id', $blazeuserid);
                update_user_meta($user_id, 'shipping_first_name', $firstname);
                update_user_meta($user_id, 'billing_first_name', $firstname);
                update_user_meta($user_id, 'shipping_last_name', $lastname);
                update_user_meta($user_id, 'billing_last_name', $lastname);
                update_user_meta($user_id, 'shipping_phone', $phone);
                update_user_meta($user_id, 'billing_phone', $phone);
                update_user_meta($user_id, 'shipping__email', $email);
                update_user_meta($user_id, 'billing_email', $email);
                update_user_meta($user_id, '_woocommerce_persistent_cart_1', $cart);
                $user_data = get_user_by('email', trim(wp_unslash($email)));

                $user_login = $user_data->user_login;
                $ID = $user_data->ID;
                $user_email = $user_data->user_email;
                $key = get_password_reset_key($user_data);
                if (is_multisite()) {
                    $site_name = get_network()->site_name;
                } else {
                    $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
                }

                $message = __('Someone has requested a password reset for the following account:') . "\r\n\r\n";
                /* translators: %s: site name */
                $message .= sprintf(__('Site Name: %s'), $site_name) . "\r\n\r\n";
                /* translators: %s: user login */
                $message .= sprintf(__('Username: %s'), $user_login) . "\r\n\r\n";
                $message .= __('If this was a mistake, just ignore this email and nothing will happen.') . "\r\n\r\n";
                $message .= __('To reset your password, visit the following address:') . "\r\n\r\n";
                $message .= '<' . network_site_url("my-account/lost-password/?key=$key&id=" . $ID, 'login') . ">\r\n";

                /* translators: Password reset email subject. %s: Site name */
                $stitle = sprintf(__('[%s] Password Reset'), $site_name);
                $title = apply_filters('retrieve_password_title', $stitle, $user_login, $user_data);
                $message = apply_filters('retrieve_password_message', $message, $key, $user_login, $user_data);
                if (wp_mail($user_email, wp_specialchars_decode($title), $message)) {
                    $errors->add('invalid_captcha', '<p>Password reset email has been sent.</p>');
                }
            }
        }
    }

    function my_password_reset($user, $new_pass) {
        global $wpdb;
        $useremail = $user->data->user_email;
        $userid = $user->data->ID;
        $balzeuserid = get_user_meta($userid, 'Blaze_woo_user_id', true);
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');

        //Has blaze ID
        if($balzeuserid && $balzeuserid != "") {
            //Reset Pass
            $url = $apidomain . "/api/v1/store/auth/resetPassword?api_key=" . $apikey;
            $userdata = array('consumerId' => $balzeuserid, 'email' => $useremail, 'password' => $new_pass);
            $data = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($userdata),
                'method' => 'POST'
            ));
            //$server_output = json_decode($data['body']);
        }
        else {
            //Import consumer/member information from blaze(fill in blaze consumerID too(NEEDED FOR RESET PASS!))
            $urlGetMember = $apidomain . "/api/v1/store/auth/createFromProfile?api_key=" . $apikey;
            $memberData = wp_remote_post($urlGetMember, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode(array("email" => $useremail)),
                'method' => 'POST'
            ));
            $response = json_decode($memberData['body']);

            $blazeuserid = $response->id;
            $firstname = $response->firstName;
            $lastname = $response->lastName;
            $phone = $response->primaryPhone;

            // explode email to get nickname to use in the database.
            $emaildata = explode("@", $useremail);
            $password = "12345";
            $dob = $response->dob;

            // set role before saving the user in the database.
            $role = array('customer' => 1);

            // set cart object to null.
            $cart = array('cart' => Array());
            update_user_meta($userid, 'first_name', $firstname);
            update_user_meta($userid, 'last_name', $lastname);
            update_user_meta($userid, 'wp_capabilities', $role);
            update_user_meta($userid, 'Blaze_woo_user_id', $blazeuserid);
            update_user_meta($userid, 'shipping_first_name', $firstname);
            update_user_meta($userid, 'billing_first_name', $firstname);
            update_user_meta($userid, 'shipping_last_name', $lastname);
            update_user_meta($userid, 'billing_last_name', $lastname);
            update_user_meta($userid, 'shipping_phone', $phone);
            update_user_meta($userid, 'billing_phone', $phone);
            update_user_meta($userid, 'shipping__email', $useremail);
            update_user_meta($userid, 'billing_email', $useremail);
            update_user_meta($userid, '_woocommerce_persistent_cart_1', $cart);
            update_user_meta($userid, 'Blaze_dob', date('m/d/Y', $dob/1000));


            //Reset PASS
            $url = $apidomain . "/api/v1/store/auth/resetPassword?api_key=" . $apikey;
            $userdata = array('consumerId' => $balzeuserid, 'email' => $useremail, 'password' => $new_pass);
            $data = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($userdata),
                'method' => 'POST'
            ));
        }
        return true;
    }

// add the action 

    public function authenticate_existing_user($user) {
        //Authenticate
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        
        //if not, register account just like register form(fill in incorect data because its admin account)
        $errors = "";
        $user_meta = get_user_meta( $user->ID );

        $email = $user->data->user_email;

        if($email == "" || $email == null) {
            $errors = $errors . "User needs an email to authorize. ";
        }
        $password = $user->data->user_pass;
        $firstName = $user_meta['first_name'][0];
        if($firstName == "" || $firstName == null) {
            $errors = $errors . "User needs a first name to authorize. ";
        }
        $lastName = $user_meta['last_name'][0];
        if($lastName == "" || $lastName == null) {
            $errors = $errors . "User needs a last name to authorize. ";
        }
        $dob = $user_meta['Blaze_dob'][0];
        if($dob == null || $dob == '') {
            $errors = $errors . "User needs a dob to authorize. Edit account details <a style='color: white' href='../my-account/edit-account'>here.</a>";
        }
        $date = strtotime('+1 day', strtotime($dob)) * 1000;
        

        $textOptIn = false;
        $emailOptIn = false;
        $medical = false;
        $sex = '0';
        
        //$errors = $errors . $dob . " STOPP";
        $userdata = array(
            'email' => $email,
            'password' => $password,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phoneNumber' => "858 111 1111",
            'dob' => $date,
            'textOptIn' => $textOptIn,
            'emailOptIn' => $emailOptIn,
            'medical' => $medical,
            'sex' => $sex
        );
        if($errors != "") {
            return $errors;
        }
        else {
            $urlRegister = $apidomain . "/api/v1/store/auth/register?api_key=" . $apikey;
            $registerData = wp_remote_post($urlRegister, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($userdata),
                'method' => 'POST'
            ));
            $registerResult = json_decode($registerData['body']);
            $registerMessage = $registerResult->message;

            //Error in the registration process
            if ($registerMessage != '') {
                

                // Member/Email already exists in blaze, pull in data from blaze and authenticate user
                if($registerMessage == "Email is in use.") {
                    

                    //Pull in data from blaze
                    $urlProfile = $apidomain . "/api/v1/store/auth/createFromProfile?api_key=" . $apikey;
                    $profileData = wp_remote_post($urlProfile, array(
                        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                        'body' => json_encode(array("email" => $email)),
                        'method' => 'POST'
                    ));

                   
                    $profileBody = json_decode($profileData['body']);
                    $blazeuserid = $profileBody->id;
                    $firstname = $profileBody->firstName;
                    $lastname = $profileBody->lastName;
                    $phone = $profileBody->primaryPhone;

                    if($profileBody->message == "") {
                        update_user_meta($user->ID, 'first_name', $firstname);
                        update_user_meta($user->ID, 'last_name', $lastname);
                        update_user_meta($user->ID, 'Blaze_woo_user_id', $blazeuserid);
                        update_user_meta($user->ID, 'shipping_first_name', $firstname);
                        update_user_meta($user->ID, 'billing_first_name', $firstname);
                        update_user_meta($user->ID, 'shipping_last_name', $lastname);
                        update_user_meta($user->ID, 'billing_last_name', $lastname);
                        update_user_meta($user->ID, 'shipping_phone', $phone);
                        update_user_meta($user->ID, 'billing_phone', $phone);
                        update_user_meta($user->ID, 'shipping__email', $email);
                        update_user_meta($user->ID, 'billing_email', $email);
                    }
                    else {
                        //Return error from create from profile api
                        return "Profile API:" . $profileBody->message;
                    }
                    
                    //Set password to wordpress password
                    $passUrl = $apidomain . "/api/v1/store/auth/resetPassword?api_key=" . $apikey;
                    $passUserData = array('consumerId' => $blazeuserid, 'email' => $email, 'password' => $password);
                    $passData = wp_remote_post($passUrl, array(
                        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                        'body' => json_encode($passUserData),
                        'method' => 'POST'
                    ));
                    $passResult = json_decode($passData['body']);

                    if($passResult->message != "") {
                        return  "Reset Password API:". $passResult->message;
                    }

                    //Authenticate User
                    $authUrl = $apidomain . "/api/v1/store/auth/login?api_key=" . $apikey;
                    $authenticateSubmittedData = array('email' => $email, 'password' => $password);            
                    
                    $authenticateData = wp_remote_post($authUrl, array(
                        'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                        'body' => json_encode($authenticateSubmittedData),
                        'method' => 'POST'
                    ));
                    $authenticateResult = json_decode($authenticateData['body']);

                    if($authenticateResult->message != "") {
                        return  "Auth API:". $authenticateResult->message;
                    }
                    
                    $userid = $authenticateResult->user->id;
                    $accessToken = $authenticateResult->accessToken;
                    update_user_meta($user->ID, 'blaze_userId', $userid);
                    update_user_meta($user->ID, 'first_name', $firstName);
                    update_user_meta($user->ID, 'last_name', $lastName);
                    update_user_meta($user->ID, 'Blaze_woo_user_id', $authenticateResult->id);
                    update_user_meta($user->ID, 'token', $accessToken);
                    return "";
                }

                //Return error from registration api
                return "Register API:". $registerMessage;
            }
            else {
                //Member registered successfully, assign data
                if ($registerResult->accessToken != '') {
                
                    $_SESSION["user_data"] = $registerResult;
    
                    $userid = $registerResult->user->id;
                    $accessToken = $registerResult->accessToken;
                    update_user_meta($user->ID, 'blaze_userId', $userid);
                    update_user_meta($user->ID, 'first_name', $firstName);
                    update_user_meta($user->ID, 'last_name', $lastName);
                    update_user_meta($user->ID, 'Blaze_woo_user_id', $registerResult->id);
                    update_user_meta($user->ID, 'token', $accessToken);
                }
            }
        }

        

        /*
        $userdata = array(
            'email' => sanitize_email($_POST['email']),
            'password' => esc_html($_POST['password']),
            'firstName' => esc_html($_POST['Blaze_fname']),
            'lastName' => esc_html($_POST['Blaze_lname']),
            'phoneNumber' => esc_html($_POST['Blaze_phone']),
            'dob' => $date,
            'marketingSource' => esc_html($_POST['marketingSource']),
            'textOptIn' => $textOptIn,
            'emailOptIn' => $emailOptIn,
            'medical' => esc_html($_POST['accounttype']),
            'sex' => esc_html($_POST['Blaze_sex']),
            'contractId' => esc_html($_POST['contractid'])
        );

        $data = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => json_encode($userdata),
            'method' => 'POST'
        ));
        $result = json_decode($data['body']);
        $message = $result->message;
        if ($message != '') {
            $errors->add('agreement_required', __('Email already exists! Please use your existing credentials to login.', 'blaze-woo-integration'));
        }
        if ($result->accessToken != '') {
            $_SESSION["user_data"] = $result;
        }
    */
    }
    public function login_function($user) {
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        // initiate API call
        $url = $apidomain . "/api/v1/store/auth/login?api_key=" . $apikey;
        $data1 = array('email' => sanitize_email($_POST['username']), 'password' => esc_html($_POST['password']));

        if (isset($_POST['username'])) {

            // initiate API call
            $data = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($data1),
                'method' => 'POST'
            ));
            $result = json_decode($data['body']);
            if ($result->message == 'Invalid user credentials.' || $result->message == 'password may not be empty,
email not a well-formed email address' || $result->message == 'email not a well-formed email address' || $result->message == 'email may not be empty') {
                $useremail = sanitize_email($_POST['username']);
                $exists = email_exists($useremail);
                if ($exists) {
                    $user_meta = get_userdata($exists);
                    $user_roles = $user_meta->roles;
                    $roles_to_exclude = array('administrator', 'subscriber');
                    if (!in_array('administrator', $user_roles) && !in_array('subscriber', $user_roles)) {
                        $user_id = wp_update_user(array('ID' => $exists, 'user_pass' => 'donotrememberblazepassword'));
                    }
                }
                return null;
            } else {
                $useremail = $result->user->email;
                $exists = email_exists($useremail);
                if ($exists) {
                    wp_update_user(array('ID' => $exists, 'user_pass' => esc_html($_POST['password'])));
                    $user_id = $exists;
                    update_user_meta($user_id, 'token', $result->accessToken);
                    update_user_meta($user_id, 'first_name', $result->user->firstName);
                    update_user_meta($user_id, 'last_name', $result->user->lastName);
                    update_user_meta($user_id, 'Blaze_woo_user_id', $result->user->id);
                    update_user_meta($user_id, 'billing_phone', $result->user->primaryPhone);
                    update_user_meta($user_id, 'billing_email', $result->user->email);
                } else {
                    $userdata = array('user_login' => $useremail, 'user_email' => $useremail, 'user_pass' => esc_html($_POST['password']),);
                    $user = wp_insert_user($userdata);
                    update_user_meta($user, 'first_name', $result->user->firstName);
                    update_user_meta($user, 'last_name', $result->user->lastName);
                    update_user_meta($user, 'Blaze_woo_user_id', $result->user->id);
                    update_user_meta($user, 'token', $result->accessToken);
                    update_user_meta($user, 'billing_phone', $result->user->primaryPhone);
                    update_user_meta($user, 'billing_email', $result->user->email);
                }
            }
        }
    }

    // customer update his profile
    function user_profile_update_errors(&$errors, $update, $user) {
        
    }

    // registration customer
    function woocommerce_register_form() {
        if (get_option('Blaze_company_country') == 'Canada') {
            $zip_label = 'City/Province/Postal Code';
            $zip_placeholder = 'Postal';
            $state_code = 'Province';
            $postal_code = 'Postal Code';
            $state_id_label = 'Government Issues ID #';
            $medibook_number_label = 'If Applicable, Please Enter Your 16-digits Number of Your Verification420.com Recommendation';
            $ca_verif_rec_label = 'Physician\'s Recommendation/Diagnosis';
            $state_issue_label = 'Government Issued ID';
        } else {
            $zip_label = 'City/State/Zip';
            $zip_placeholder = 'ZIP';
            $state_code = 'State';
            $postal_code = 'ZIP';
            $state_id_label = 'Drivers License';
            $medibook_number_label = 'Please Enter 16-digits Number of Your Verification420.com Recommendation';
            $ca_verif_rec_label = 'Physician\'s Statement & Recommendation';
            $state_issue_label = 'Add  a Government Issued ID';
        }
        ?>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.3.0/css/datepicker.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.3.0/js/bootstrap-datepicker.js">"></script>
        <script src="https://cdn.jsdelivr.net/jquery.validation/1.15.1/jquery.validate.min.js"></script>
        <script language="JavaScript">
            jQuery(function () {
                jQuery("form.register").validate({
                    rules: {
                        Blaze_fname: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        },
                        Blaze_lname: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        },
                        dlNo: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        },
                        dlState: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        },
                        is_agreed_third: "required",
                        Blaze_dmv_scan: "required",
                        Blaze_phone: {
                            required: true,
                        },
                        email: {
                            required: true,
                            email: true
                        },
                        password: {
                            required: true,
                        },
                        Blaze_dob: {
                            required: true,
                        },
                        Blaze_dmv_address: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        },
                        Blaze_dmv_city: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        },
                        Blaze_dmv_zipcode: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        },
                        Blaze_dmv_postalcode: {
                            required: true,
                            normalizer: function (value) {
                                return jQuery.trim(value);
                            }
                        }
                    },
                    messages: {
                        Blaze_fname: "Please enter your first name",
                        Blaze_lname: "Please enter your last name",
                        password: {
                            required: "Please provide a password",
                        },
                        Blaze_phone: {
                            required: "Please provide a phone number",
                        },
                        email: "Please enter a valid email address",
                        Blaze_dob: "Please enter date of birth",
                        dlNo: "Please enter your DL No",
                        dlState: "Please select your state/province",
                        Blaze_dmv_address: "Please enter your address",
                        Blaze_dmv_city: "Please enter your city",
                        Blaze_dmv_zipcode: "Please enter your zip code/postal code",
                        is_agreed_third: "Please accept agreement",
                        Blaze_dmv_scan: "Please upload your government issued ID"
                    },
                    submitHandler: function (form) {
                        form.submit();
                    }
                });
            });
        </script>   
        <style type="text/css">
            .woocommerce-page form .form-row label.error {
                color: #ff0000;
                font-weight: 100;
                font-size: 12px;
                position: absolute;
                bottom: -20px;
                width: 100%;
                margin: 0;
            }
            .woocommerce form .form-row-wide, .woocommerce-page form .form-row-wide {
                position: relative;
            }
        </style>
        <script language="JavaScript">
            jQuery(function () {
                jQuery("form.register").attr('enctype', 'multipart/form-data');

                jQuery.mask.definitions['~'] = '[0-9]';
                jQuery(".Blaze_phone").mask("(~~~) ~~~-~~~~");
                jQuery("#Blaze_rec_num").mask("9999 9999 9999 9999");
                jQuery('.datepicker').datepicker({
                    autoclose: true,
                    endDate: '+0d'
                });
                var date = new Date();
                date.setDate(date.getDate() + 1)
                jQuery('.datepicker1').datepicker({
                    startDate: date,
                    autoclose: true
                });
            });
        </script>
        <script type="text/javascript">
            jQuery(function () {
                jQuery("input[name='accounttype']").click(function () {
                    if (jQuery("#chkYes").is(":checked")) {
                        jQuery(".medicinal").show();
                    } else {
                        jQuery(".medicinal").hide();
                    }
                });
            });
        </script>
        <p class="form-row form-row-wide blaze-woo-r-account-type">
            <label for="Blaze_accounttype"><?php _e('Which type of account would you like to create?', 'blaze-woo-integration'); ?>
                <span class="required">*</span>
            </label>
            <span class="rec-radio-btn"><input type="radio" name="accounttype" class="recreationalclass" value="false" checked> Recreational</span><br>
            <span class="med-radio-btn"><input type="radio" name="accounttype" value="true" id="chkYes" class="medicinalclass"> Medicinal</span><br>
        </p>
        <p class="form-row form-row-wide blaze-woo-r-first-name">
            <label for="Blaze_fname"><?php _e('First Name', 'blaze-woo-integration'); ?>
                <span class="required">*</span>
            </label>
            <input type="text" class="input-text" name="Blaze_fname" id="Blaze_fname"
                   value="<?php if (!empty($_POST['Blaze_fname'])) echo esc_attr($_POST['Blaze_fname']); ?>"/>
        </p>

        <p class="form-row form-row-wide blaze-woo-r-last-name">
            <label for="Blaze_lname"><?php _e('Last Name', 'blaze-woo-integration'); ?>
                <span class="required">*</span>
            </label>
            <input type="text" class="input-text" name="Blaze_lname" id="Blaze_lname"
                   value="<?php if (!empty($_POST['Blaze_lname'])) echo esc_attr($_POST['Blaze_lname']); ?>"/>
        </p>

        <p class="form-row form-row-wide blaze-woo-r-sex">
            <label for="Blaze_sex"><?php _e('Sex', 'blaze-woo-integration'); ?>
                <span class="required">*</span>
            </label>
            <select id="Blaze_sex" name="Blaze_sex">
                <option value="0" <?php echo $_POST['Blaze_sex'] == '0' ? ' selected' : ''; ?>>Male</option>
                <option value="1" <?php echo $_POST['Blaze_sex'] == '1' ? ' selected' : ''; ?>>Female</option>
                <option value="2" <?php echo $_POST['Blaze_sex'] == '2' ? ' selected' : ''; ?>>Others</option>
            </select>
        </p>

        <p class="form-row form-row-wide blaze-woo-r-dob">
            <label for="Blaze_dob"><?php _e('Date Of Birth (mm/dd/yyyy)', 'blaze-woo-integration'); ?><span class="required">*</span>
            </label>
            <input type="text" class="input-text datepicker" name="Blaze_dob" id="Blaze_dob"
                   value="<?php if (!empty($_POST['Blaze_dob'])) echo esc_attr($_POST['Blaze_dob']); ?>"/>
        </p>
        <p class="form-row form-row-wide blaze-woo-r-phone">
            <label for="Blaze_phone">
                <?php _e('Phone', 'blaze-woo-integration'); ?> <span class="required">*</span>
            </label>
            <input type="tel" class="input-text Blaze_phone" name="Blaze_phone" id="Blaze_phone"
                   value="<?php if (!empty($_POST['Blaze_phone'])) echo esc_attr($_POST['Blaze_phone']); ?>"/>
        </p>

        <p class="form-row form-row-wide blaze-woo-r-dl">
            <label for="Blaze_dmv"><?php _e('DL No', 'blaze-woo-integration'); ?> <span class="required">*</span>
            </label>
            <input type="text" class="input-text" name="dlNo" id="Blaze_dmv"
                   value="<?php if (!empty($_POST['dlNo'])) echo esc_attr($_POST['dlNo']); ?>"/>
        </p>

        <p class="form-row form-row-wide blaze-woo-r-dl-expiry">
            <label for="Blaze_dl_expiration"><?php _e('DL Expiration Date - MM/DD/YYYY', 'blaze-woo-integration'); ?>
            </label>
            <input type="text" class="input-text datepicker1" name="dl_expiration"
                   value=""/>
        </p>
        <script>
            jQuery(document).ready(function () {
                jQuery(".chk").change(function () {
                    if (jQuery(this).attr("checked"))
                    {
                        jQuery('.chk').not(this).prop('checked', false);
                        var val = this.checked ? this.value : '';
                        if (val == 'Other') {
                            jQuery(".other").show();
                        } else {
                            jQuery(".other").hide();

                        }


                    }
                });
            });
        </script>
        <?php
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $url = $apidomain . "/api/v1/store?api_key=" . $apikey;
        $data = wp_remote_get($url);
        $response = json_decode($data['body']);
        $contractid = $response->contract->id;
        $marketingSources = $response->shop->marketingSources;
        $countrycode = $response->shop->address->country;
        $addressregistration = get_option('Blaze_address_registration');
        $agreementLink = get_option('Blaze_agreement_link');
        $agreementText = "";
        if(trim($agreementLink) != "") {
            $agreementText = "I accept <a href=".$agreementLink." target=\"_blank\">agreement</a>";
        }

        ?>
        <input type="text" value="<?php echo $contractid; ?>" name="contractid" style="display:none"/>
        <p class="form-row form-row-wide blaze-woo-r-marketing-source">
            <label for="Blaze_marketing_source"><?php _e('What led you to sign up?', 'blaze-woo-integration'); ?>
               <!--  <span class="required">*</span> -->
            </label>
            <?php foreach ($marketingSources as $value) {?>
                <input type="checkbox"  value="<?php echo $value; ?>" class="chk" name="marketingSource" /><?php echo $value; ?>
            <?php } ?>
            <?php if($response->shop->onlineStoreInfo->enableOtherMarketingSource) { ?>
                <input type="checkbox"  value="Other" class="chk" name="marketingSource" />Other
            <?php } ?>
            <input type="text"   value="" class="other" placeholder="Other"  name="marketingSource1" style="display:none"/>
        </p>


        <!--Medicinal section start-->
        <div class="medicinal" style="display:none;"><h4>Medicinal section</h4>
            <p class="form-row form-row-wide blaze-woo-rm-state">
                <label for="Blaze_address"><?php _e('State', 'blaze-woo-integration'); ?>
                    <span class="required">*</span></label>
                <select class="FormInput FormSelect" name="consumerType"><option value="MedicinalThirdParty">Medicinal - Non-State Card</option><option value="MedicinalState">Medicinal - State Card</option></select>
            </p>
            <p class="form-row form-row-wide blaze-woo-rm-recno">
                <label for="Blaze_rec_no"><?php _e('Rec #', 'blaze-woo-integration'); ?> </label>
                <input type="text" name="recNo" value="" class="FormInput themeBorderColor">
            </p>
            <p class="form-row form-row-wide blaze-woo-expiration-date">
                <label for="Blaze_expiration_date"><?php _e('Rec Expiration Date', 'blaze-woo-integration'); ?> </label>
                <input type="text" class="FormInput datepicker1" value="" name="expiration_date" >
            </p>
            <p class="form-row form-row-wide blaze-woo-rm-doctor-fn">
                <label for="Blaze_doctor_firstname"><?php _e('Doctor First Name', 'blaze-woo-integration'); ?> </label>
                <input type="text" name="doctorFirstName" value="" class="FormInput themeBorderColor">
            </p>
            <p class="form-row form-row-wide blaze-woo-rm-doctor-ln">
                <label for="Blaze_doctor_lastname"><?php _e('Doctor Last Name', 'blaze-woo-integration'); ?> </label>
                <input type="text" name="doctorLastName" value="" class="FormInput themeBorderColor">
            </p>
            <p class="form-row form-row-wide blaze-woo-rm-verify-website">
                <label for="Blaze_rec_verify_website"><?php _e('Rec Verify Website', 'blaze-woo-integration'); ?> </label>
                <input type="text" name="verificationWebsite" value="" class="FormInput themeBorderColor">
            </p>
            <p class="form-row form-row-wide blaze-woo-rm-rec-phone">
                <label for="Blaze_rec_verify_phone"><?php _e('Rec Verify Phone #', 'blaze-woo-integration'); ?> </label>
                <input type="tel" class="FormInput themeBorderColor Blaze_phone" name="verifyPhone" value="" >
            </p>
            <p class="form-row form-row-wide blaze-woo-rm-medical-license">
                <label for="Blaze_doctor_license"><?php _e('Medical License #', 'blaze-woo-integration'); ?> </label>
                <input type="text" name="doctorLicense" value="" class="FormInput themeBorderColor">
            </p>
            <p class="form-row form-row-wide blaze-woo-rm-rec-id">
                <label for="Blaze_rec_scan"><?php _e('Add Recommendation ID', 'blaze-woo-integration'); ?>     
                </label>
                <input type="file" class="input-text" name="Blaze_rec_scan" id="Blaze_rec_scan"/>
            </p>
        </div><!--Medicinal section end-->
        <?php if ($addressregistration == 'yes') { ?>
            <p class="form-row form-row-wide blaze-woo-r-address">
                <label
                    for="Blaze_dmv_address"><?php _e('Address', 'blaze-woo-integration'); ?> <span class="required">*</span>
                </label>
                <input type="text" class="input-text" name="Blaze_dmv_address" id="Blaze_dmv_address"/>
            </p>
            <p class="form-row form-row-wide blaze-woo-r-city">
                <label
                    for="Blaze_dmv_city"><?php _e('City', 'blaze-woo-integration'); ?> <span class="required">*</span>
                </label>
                <input type="text" class="input-text" name="Blaze_dmv_city" id="Blaze_dmv_city"/>
            </p>
            <p class="form-row form-row-wide blaze-woo-r-zipcode">
                <?php if ($countrycode != "US") { ?>
                    <label
                        for="Blaze_dmv_zipcode"><?php _e('Postal Code', 'blaze-woo-integration'); ?> <span class="required">*</span>
                    </label>
                    <input type="text" class="input-text" name="Blaze_dmv_zipcode" id="Blaze_dmv_zipcode"/>
                <?php } else { ?>
                    <label
                        for="Blaze_dmv_zipcode"><?php _e('Zip Code', 'blaze-woo-integration'); ?> <span class="required">*</span>
                    </label>
                    <input type="text" class="input-text" name="Blaze_dmv_zipcode" id="Blaze_dmv_zipcode"/>
                <?php } ?>
            </p>
        <?php } ?>
        <p class="form-row form-row-wide blaze-woo-r-state">

            <?php if ($countrycode != "US") { ?>
                <label for="Blaze_dl_States"><?php _e('Select Province', 'blaze-woo-integration'); ?>
                    <span class="required">*</span>
                </label>
                <select class="FormInput FormSelect" type="text" placeholder="state" name="dlState">
                    <option value="">Select Province</option>
                    <option value="SK">AB</option>
                    <option value="BC">BC</option>
                    <option value="MB">MB</option>
                    <option value="NB">NB</option>
                    <option value="NL">NL</option>
                    <option value="NS">NS</option> 
                    <option value="PE">PE</option>
                    <option value="QC">QC</option>
                    <option value="QN">QN</option>
                    <option value="DE">SK</option>
                </select>
            <?php } else { ?>
                <label for="Blaze_dl_States"><?php _e('Select State', 'blaze-woo-integration'); ?>
                    <span class="required">*</span>
                </label>
                <select class="FormInput FormSelect" type="text" placeholder="state" name="dlState">
                    <option value="">Select State</option>
                    <option value="AL">AL</option>
                    <option value="AK">AK</option>
                    <option value="AZ">AZ</option>
                    <option value="AR">AR</option>
                    <option value="CA">CA</option>
                    <option value="CO">CO</option>
                    <option value="CT">CT</option>
                    <option value="DE">DE</option>
                    <option value="FL">FL</option>
                    <option value="GA">GA</option>
                    <option value="HI">HI</option>
                    <option value="ID">ID</option>
                    <option value="IL">IL</option>
                    <option value="IN">IN</option>
                    <option value="IA">IA</option>
                    <option value="KS">KS</option>
                    <option value="KY">KY</option>
                    <option value="LA">LA</option>
                    <option value="ME">ME</option>
                    <option value="MD">MD</option>
                    <option value="MA">MA</option>
                    <option value="MI">MI</option>
                    <option value="MN">MN</option>
                    <option value="MS">MS</option>
                    <option value="MO">MO</option>
                    <option value="MT">MT</option>
                    <option value="NE">NE</option>
                    <option value="NV">NV</option>
                    <option value="NH">NH</option>
                    <option value="NJ">NJ</option>
                    <option value="NM">NM</option>
                    <option value="NY">NY</option>
                    <option value="NC">NC</option>
                    <option value="ND">ND</option>
                    <option value="OH">OH</option>
                    <option value="OK">OK</option>
                    <option value="OR">OR</option>
                    <option value="PA">PA</option>
                    <option value="RI">RI</option>
                    <option value="SC">SC</option>
                    <option value="SD">SD</option>
                    <option value="TN">TN</option>
                    <option value="TX">TX</option>
                    <option value="UT">UT</option>
                    <option value="VT">VT</option>
                    <option value="VA">VA</option>
                    <option value="WA">WA</option>
                    <option value="WV">WV</option>
                    <option value="WI">WI</option>
                    <option value="WY">WY</option>
                </select>
            <?php } ?>

        </p>
        <p class="form-row form-row-wide blaze-woo-r-dl-file">
            <label
                for="Blaze_dmv_scan"><?php _e($state_issue_label, 'blaze-woo-integration'); ?>

                <span class="required">*</span>

            </label>
            <input type="file" class="input-text" name="Blaze_dmv_scan" id="Blaze_dmv_scan"/>
        </p>


        <p class="form-row form-row-wide blaze-woo-r-promo-check">
            <label for="is_agreed" class="inline">
                <input name="emailOptIn" type="checkbox" id="is_agreed" value="1">
                <?php _e('Receive email promotions and updates</a>', 'blaze-woo-integration'); ?>
            </label>
        </p>

        <p class="form-row form-row-wide blaze-woo-r-sms-check">
            <label for="is_agreed_second" class="inline">
                <input name="textOptIn" type="checkbox" id="is_agreed_second" value="1">
                <!-- <span class="required">*</span> -->
                <?php _e('Receive SMS marketing and updates</a>', 'blaze-woo-integration'); ?>
            </label>
        </p>
        <?php
        if($agreementText != "") {
            echo '
            <p class="form-row form-row-wide blaze-woo-r-agreement-check">
                <label for="is_agreed_third" class="inline">
                    <input name="is_agreed_third" type="checkbox" id="is_agreed_third" value="1">
                     '.$agreementText.'</a>
                    <span class="required">*</span>
                </label>
            </p>
            ';
        }
        ?>

        <input type="hidden" class="input-text" name="Blaze_dmv_country" value=<?php echo $countrycode; ?> id="Blaze_dmv_country"/>
        <?php
    }

    function woocommerce_registration_errors($errors, $username, $email) {
        global $Blaze_user_validation_off;

        if ($errors->get_error_code() || $Blaze_user_validation_off) {
            $Blaze_user_validation_off = false;
            return $errors;
        }

        // validate dob
        $dob = $_POST['Blaze_dob'];
        $dmv = $_POST['Blaze_dmv'];
        $phone = $_POST['Blaze_phone'];
        $rec_num = isset($_POST['Blaze_rec_num']) ? $_POST['Blaze_rec_num'] : false;
        $fname = $_POST['Blaze_fname'];
        $lname = $_POST['Blaze_lname'];
        $is_agreed = $_POST['is_agreed'];
        $is_agreed_second = $_POST['is_agreed_second'];
        $is_agreed_third = $_POST['is_agreed_third'];

        $is_Canada = get_option('Blaze_company_country') == 'Canada';

        if (!preg_match('|^\\d{2}/\\d{2}/\\d{4}$|i', $dob)) {
            $errors->add('dob_invalid', __(' Date Of Birth is incorrect', 'blaze-woo-integration'));
        }
        if (!$dmv && get_option('Blaze_id_required') == 'yes') {
            if ($is_Canada) {
                $errors->add('dmv_required', __(' Drivers License is required', 'blaze-woo-integration'));
            } else {
                /* $errors->add('dmv_required', __(' DMV is required', 'blaze-woo-integration')); */
            }
        }
        if (!$fname) {
            $errors->add('fname_required', __(' First Name is required', 'blaze-woo-integration'));
        }
        if (!$lname) {
            $errors->add('lname_required', __(' Last Name is required', 'blaze-woo-integration'));
        }


        if (!$is_agreed_third && (trim(get_option('Blaze_agreement_link')) != "")) {
            $errors->add('agreement_required', __(' Please Accpt term', 'blaze-woo-integration'));
        }
        if (!preg_match('|^\(\d{3}\) \d{3}-\d{4}$|i', $phone)) {
            $errors->add('phone_invalid', __(' Phone is incorrect', 'blaze-woo-integration'));
        }

        if ($_POST['email']) {
            global $wpdb;
            $apidomain = get_option('Blaze_api_domain');
            $apikey = get_option('Blaze_api_key');
            $url = $apidomain . "/api/v1/store/auth/register?api_key=" . $apikey;
            $emailOptIn = $_POST['emailOptIn'];
            if ($emailOptIn) {
                $emailOptIn = true;
            } else {
                $emailOptIn = false;
            }
            $textOptIn = $_POST['textOptIn'];
            if ($textOptIn) {
                $textOptIn = true;
            } else {
                $textOptIn = false;
            }
            $date = strtotime($_POST['Blaze_dob']) * 1000;
            $userdata = array(
                'email' => sanitize_email($_POST['email']),
                'password' => esc_html($_POST['password']),
                'firstName' => esc_html($_POST['Blaze_fname']),
                'lastName' => esc_html($_POST['Blaze_lname']),
                'phoneNumber' => esc_html($_POST['Blaze_phone']),
                'dob' => $date,
                'marketingSource' => esc_html($_POST['marketingSource']),
                'textOptIn' => $textOptIn,
                'emailOptIn' => $emailOptIn,
                'medical' => esc_html($_POST['accounttype']),
                'sex' => esc_html($_POST['Blaze_sex']),
                'contractId' => esc_html($_POST['contractid'])
            );

            $data = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($userdata),
                'method' => 'POST'
            ));
            $result = json_decode($data['body']);
            $message = $result->message;
            if ($message != '') {
                $errors->add('agreement_required', __('Email already exists! Please use your existing credentials to login.', 'blaze-woo-integration'));
            }
            if ($result->accessToken != '') {
                $_SESSION["user_data"] = $result;
            }
        }

        return $errors;
    }

    function woocommerce_created_customer($customer_id, $new_customer_data, $password_generated) {
        global $wpdb;
        $apidomain = get_option('Blaze_api_domain');
        $apikey = get_option('Blaze_api_key');
        $emailregistration = get_option('Blaze_email_registration');
        $result = $_SESSION["user_data"];
        unset($_SESSION["user_data"]);
        $authorization = $result->accessToken;
        $uploads = wp_upload_dir($time);
        $target_file = $uploads['path'] . '/' . basename($_FILES["Blaze_dmv_scan"]["name"]);
        $govtidd = $uploads['url'] . '/' . basename($_FILES["Blaze_dmv_scan"]["name"]);
        move_uploaded_file($_FILES["Blaze_dmv_scan"]["tmp_name"], $target_file);
        update_user_meta($customer_id, 'govtID', $target_file);
        $url = $apidomain . '/api/v1/store/user/dlPhoto?api_key=' . $apikey;

        $img_url = $target_file;
        if (function_exists('curl_file_create')) {
            $cFile = curl_file_create($img_url);
        }

        $photo_array = array('Authorization' => $authorization, 'file' => $cFile, 'name' => $_POST['Blaze_fname'], 'Content-Type' => 'image/jpeg', 'public' => 1);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apidomain . "/api/v1/store/user/dlPhoto?api_key=" . $apikey);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $photo_array);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization:$authorization"));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $assetsresult = curl_exec($ch);
        curl_close($ch);
        $uploads = wp_upload_dir($time);
        $target_file1 = $uploads['path'] . '/' . basename($_FILES["Blaze_rec_scan"]["name"]);
        $recscanid = $uploads['url'] . '/' . basename($_FILES["Blaze_rec_scan"]["name"]);
        move_uploaded_file($_FILES["Blaze_rec_scan"]["tmp_name"], $target_file1);
        update_user_meta($customer_id, 'recommendationID', $target_file1);
        $urlrec = $apidomain . "/api/v1/store/user/recPhoto?api_key=" . $apikey;
        $ch = curl_init($urlrec);

        $img_url = $target_file1;
        if (function_exists('curl_file_create')) {
            $rFile = curl_file_create($img_url);
        }

        $photo_array_rec = array('Authorization' => $authorization, 'file' => $rFile, 'name' => $_POST['Blaze_fname'], 'Content-Type' => 'image/jpeg', 'public' => 1);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apidomain . "/api/v1/store/user/recPhoto?api_key=" . $apikey);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $photo_array_rec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization:$authorization"));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $assetsresultrec = curl_exec($ch);
        curl_close($ch);
        $updateurl = $apidomain . "/api/v1/store/user?api_key=" . $apikey;
        $ch = curl_init($updateurl);
        $assetsresult = json_decode($assetsresult);
        $assetsresultrec = json_decode($assetsresultrec);
        $dlPhoto = $assetsresult->dlPhoto;
        $recPhoto = $assetsresultrec->recPhoto;
        $accessToken = $result->accessToken;
        $assetAccessToken = $result->assetAccessToken;
        $sourceCompanyId = $result->user->sourceCompanyId;
        $userid = $result->user->id;
        $email = $result->user->email;
        $firstName = $result->user->firstName;
        $lastName = $result->user->lastName;
        $sessionId = $result->sessionId;
        $expirationTime = $result->expirationTime;
        $loginTime = $result->loginTime;
        $created = $result->user->created;
        $modified = $result->user->modified;
        $phoneNumber = $result->user->primaryPhone;
        $sex = $result->user->sex;
        $accounttype = $result->user->medical;
        $dob = $result->user->dob;
        $marketingSource = $result->user->marketingSource;
        if ($accounttype) {
            $accounttype1 = $_POST['consumerType'];
        } else {
            $accounttype1 = 'AdultUse';
        }
        $dlExpiration = strtotime($_POST['dl_expiration']) * 1000;
        $expirationdate = strtotime($_POST['expiration_date']) * 1000; 

        if ($dlExpiration) {
            $dlExpiration = $dlExpiration + 43200 * 1000; 
        }

        if ($expirationdate) {
            $expirationdate = $expirationdate + 43200 * 1000; 
        }

        $emailOptIn = $_POST['emailOptIn'];
        if ($emailOptIn) {
            $emailOptIn = true;
        } else {
            $emailOptIn = false;
        }
        $textOptIn = $_POST['textOptIn'];
        if ($textOptIn) {
            $textOptIn = true;
        } else {
            $textOptIn = false;
        }
        //update api
        $updatedata = array(
            'id' => $userid,
            'created' => $created,
            'modified' => $modified,
            'deleted' => false,
            'updated' => false,
            'email' => $email,
            'password' => NULL,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'middleName' => NULL,
            'address' => array(
                "address" => $_POST['Blaze_dmv_address'] != ' ' ? $_POST['Blaze_dmv_address'] : " ",
                "zipCode" => $_POST['Blaze_dmv_zipcode'] != ' ' ? $_POST['Blaze_dmv_zipcode'] : " ",
                "city" => $_POST['Blaze_dmv_city'] != ' ' ? $_POST['Blaze_dmv_city'] : " ",
                "state" => $_POST['dlState']
            ),
            'dob' => $dob + 43200 * 1000,
            'primaryPhone' => $phoneNumber,
            'cpn' => $_POST['verifyPhone'],
            'textOptIn' => $textOptIn,
            'emailOptIn' => $emailOptIn,
            'medical' => $accounttype,
            'searchText' => NULL,
            'sex' => $sex,
            'marketingSource' => $marketingSource,
            'sourceCompanyId' => $sourceCompanyId,
            'verifyMethod' => 'Website',
            'verificationWebsite' => $_POST['verificationWebsite'],
            'verificationPhone' => $_POST['verifyPhone'],
            'doctorFirstName' => $_POST['doctorFirstName'],
            'doctorLastName' => $_POST['doctorLastName'],
            'doctorLicense' => $_POST['doctorLicense'],
            'dlNo' => $_POST['dlNo'],
            'dlExpiration' => $dlExpiration,
            'dlState' => $_POST['dlState'],
            'dlPhoto' => $dlPhoto,
            'recPhoto' => $recPhoto,
            'recNo' => $_POST['recNo'],
            'recExpiration' => $expirationdate,
            'notificationType' => 'Email',
            'consumerType' => $accounttype1,
            'verified' => false,
            'active' => true,
            'memberIds' =>
            array(
            ),
            'memberStatuses' =>
            array(
            ),
            'signedContracts' =>
            array(
            ),
        );

        $data = wp_remote_post($updateurl, array(
            'headers' => array('Authorization' => $authorization, 'Content-Type' => 'application/json; charset=utf-8'),
            'body' => json_encode($updatedata),
            'method' => 'POST',
            'timeout' => 600,
            'redirection' => 5,
            'blocking' => true
        ));
        $result = json_decode($data['body']);
        update_user_meta($customer_id, 'blaze_userId', $userid);
        update_user_meta($customer_id, 'first_name', $firstName);
        update_user_meta($customer_id, 'last_name', $lastName);
        update_user_meta($customer_id, 'Blaze_woo_user_id', $result->id);
        update_user_meta($customer_id, 'token', $accessToken);
        update_user_meta($customer_id, 'wp_capabilities', array('customer' => 1));
        update_user_meta($customer_id, 'billing_address_1', $_POST['Blaze_dmv_address'] != ' ' ? $_POST['Blaze_dmv_address'] : " ");
        update_user_meta($customer_id, 'shipping_address_1', $_POST['Blaze_dmv_address'] != ' ' ? $_POST['Blaze_dmv_address'] : " ");
        update_user_meta($customer_id, 'billing_city', $_POST['Blaze_dmv_city'] != ' ' ? $_POST['Blaze_dmv_city'] : " ");
        update_user_meta($customer_id, 'shipping_city', $_POST['Blaze_dmv_city'] != ' ' ? $_POST['Blaze_dmv_city'] : " ");
        update_user_meta($customer_id, 'billing_postcode', $_POST['Blaze_dmv_zipcode'] != ' ' ? $_POST['Blaze_dmv_zipcode'] : " ");
		update_user_meta($customer_id, 'shipping_postcode', $_POST['Blaze_dmv_zipcode'] != ' ' ? $_POST['Blaze_dmv_zipcode'] : " ");
        update_user_meta($customer_id, 'billing_country', $_POST['Blaze_dmv_country'] != ' ' ? $_POST['Blaze_dmv_country'] : "US");
        update_user_meta($customer_id, 'shipping_country', $_POST['Blaze_dmv_country'] != ' ' ? $_POST['Blaze_dmv_country'] : "US");
        update_user_meta($customer_id, 'billing_state', $_POST['dlState']);
        update_user_meta($customer_id, 'Blaze_dob', date('m/d/Y', $dob/1000));
        update_user_meta($customer_id, 'shipping_first_name', $firstName);
        update_user_meta($customer_id, 'billing_first_name', $firstName);
        update_user_meta($customer_id, 'shipping_last_name', $lastName);
        update_user_meta($customer_id, 'billing_last_name', $lastName);
        update_user_meta($customer_id, 'shipping_phone', $phoneNumber);
        update_user_meta($customer_id, 'billing_phone', $phoneNumber);
        update_user_meta($customer_id, 'shipping__email', $email);
        update_user_meta($customer_id, 'billing_email', $email);
        curl_close($ch);
        if ($emailregistration == 'yes') {
            $emailOptIn = $result->emailOptIn == 1 ? "Yes" : "No";
            $textOptIn = $result->textOptIn == 1 ? "Yes" : "No";
            $sex = $result->sex == 1 ? "Female" : ($result->sex == 0 ? "Male" : "Other");
            $typeaccount = $_POST['accounttype'] == 'true' ? "Medicinal" : "Recreational";
            $adminemail = get_bloginfo('admin_email');
            $sitename = get_bloginfo('name');
            $subject = $sitename . " - New user registration!";
            $txt = 'Hi Administrator,<br/><br/>New user registered! Here below are the user details: <br/><br/> Email: ' . $result->email . '<br/>First Name: ' . $result->firstName . '<br/>Last Name: ' . $result->lastName . '<br/>Sex: ' . $sex . '<br/>DOB: ' . $_POST['Blaze_dob'] . '<br/>Primary Phone: ' . $result->primaryPhone . '<br/>DL No: ' . $result->dlNo . '<br/>DL Expiration Date: ' . $_POST['dl_expiration'] . '<br/>Address: ' . $_POST['Blaze_dmv_address'] . '<br/>City: ' . $_POST['Blaze_dmv_city'] . '<br/>Zip Code: ' . $_POST['Blaze_dmv_zipcode'] . '<br/>State: ' . $result->dlState . '<br/>Receive Email Promotions: ' . $emailOptIn . '<br/>Receive SMS Marketing: ' . $textOptIn . '<br/>Marketing Source: ' . $result->marketingSource . '<br/>Type Of Account: ' . $typeaccount . '<br/>Government Issued ID: <a target="_blank" href=' . $govtidd . '> Click here to download</a><br/>';
            if ($typeaccount == 'Medicinal') {
                $text = 'Rec #: ' . $_POST['recNo'] . '<br/>Rec Expiration Date: ' . $_POST['expiration_date'] . '<br/>Doctor First Name: ' . $_POST['doctorFirstName'] . '<br/>Doctor Last Name: ' . $_POST['doctorLastName'] . '<br/>Rec Verify Website: ' . $_POST['verificationWebsite'] . '<br/>Rec Verify Phone: ' . $_POST['verifyPhone'] . '<br/>Medical License: ' . $_POST['doctorLicense'] . '<br/> Recommendation ID: <a target="_blank" href=' . $recscanid . '> Click here to download</a><br/>';
                $txt .= $text;
            }
            $txt .= "<br/>Thanks,<br/>Webmaster ";
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: $sitename <" . $adminemail . ">";
            wp_mail($adminemail, $subject, $txt, $headers);
        }
    }

    public function woocommerce_ninja_remove_password_strength() {
        if (wp_script_is('wc-password-strength-meter', 'enqueued')) {
            wp_dequeue_script('wc-password-strength-meter');
        }
    }

}
