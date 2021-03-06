<?php

class EMPS_Auth
{
    public $AUTH_R = -4;
    public $USER_ID = 0;

    public $login;

    public function __construct()
    {
        $this->login = array();
    }

    public function get_num_ip($ip)
    {
        $nip = 0;
        $pp = explode(".", $ip);
        $i = 0;
        while (list($name, $value) = each($pp)) {
            $nip += $value << ((3 - $i) * 8);
            $i++;
        }
        return $nip;
    }

    public function credentials($groups)
    {
        return $this->user_credentials($this->USER_ID, $groups);
    }

    public function login_error($code)
    {
        $_SESSION['login']['error'] = $code;
    }

    public function no_login_error()
    {
        unset($_SESSION['login']['error']);
    }

    function create_session($username, $password, $mode)
    {
        global $SET, $emps;

        if (mb_substr($username, 0, 1) == '8') {
            $username = '+7' . mb_substr($username, 1);
        }

        $user = $emps->db->get_row('e_users', "username='$username'");
        if (!$user) {
            $this->login_error("no_user");
            return false;
        }

        $user = $this->ensure_fullname($user);

        if (!$mode) {
            if ($user['password'] != md5($password)) {
                $this->login_error("wrong_password");
                return false;
            }
        }

        if ($user['status'] == 0) {
            $this->login_error("no_activation");
            return false;
        }

        $user_id = $user['id'];

        $SET = array();
        $SET['user_id'] = $user_id;
        $SET['ip'] = $_SERVER['REMOTE_ADDR'];
        $SET['browser_id'] = $emps->ensure_browser($_SERVER['HTTP_USER_AGENT']);
        $SET['dt'] = time();
        $emps->db->sql_insert("e_sessions");

        $_SESSION['session_id'] = $emps->db->last_insert();

        return true;
    }

    function check_session()
    {
        global $emps;
        $ssid = "";
        if (isset($_SESSION['session_id'])) {
            $ssid = $_SESSION['session_id'];
        }

        if (!$ssid) {
            return false;
        }

        $session = $emps->db->get_row("e_sessions", "id = $ssid");
        if (!$session) {
            unset($this->USER_ID);
            unset($_SESSION['session_id']);
            return false;
        } else {
            if ($session['dt'] < (time() - 10 * 60)) {
                $browser_id = $emps->ensure_browser($_SERVER['HTTP_USER_AGENT']);
                if ($browser_id != $session['browser_id']) {
                    $browser = ", browser_id = " . $browser_id . " ";
                }
                $emps->db->query("update " . TP . "e_sessions set dt = " . time() . " where id = " . $session['id']);
            }
        }

        $this->USER_ID = $session['user_id'];

        $this->login = $_SESSION['login'];

        $user = $this->load_user($this->USER_ID);
        $this->login['user'] = $user;

        $this->AUTH_R = 1;
        return true;
    }

    function close_session()
    {
        global $emps;
        $ssid = $_SESSION['session_id'];
        if (!$ssid) {
            return false;
        }

        $emps->db->query('delete from ' . TP . "e_sessions where id=$ssid");

        unset($this->USER_ID);
        unset($_SESSION['session_id']);

        $this->AUTH_R = -4;
    }

    function user_credentials_context($user_id, $lst, $context_id)
    {
        global $emps;

        if (!$user_id) return false;
        if (!$lst) return false;
        $user_id += 0;
        $p = explode(",", $lst);
        if ($lst == "users") {
            $user = $emps->db->get_row_cache("e_users", "id=$user_id");
            if ($user['status'] == 1) return true;
        }

        while (list($n, $v) = each($p)) {
            $v = trim($v);
            $context_id += 0;
            $ug = $emps->db->get_row("e_users_groups", "user_id=$user_id and group_id='$v' and context_id=$context_id");
            if ($ug) return true;
        }
        return false;
    }

    function user_credentials($user_id, $lst)
    {
        global $emps;

        $rv = $this->user_credentials_context($user_id, $lst, $emps->website_ctx);
        if ($rv) {
            return $rv;
        }
        return $this->user_credentials_context($user_id, $lst, $emps->default_ctx);
    }

    public function handle_logon()
    {
        global $smarty, $emps;
        $this->no_login_error();

        if (isset($_POST['post_login'])) {
            if ($_POST['post_login'] == 1) {
                $this->AUTH_R = $this->create_session($_POST['login_username'], $_POST['login_password'], 0);
            }
        }

        if (isset($_POST['post_oauth'])) {
            if ($_POST['post_oauth'] == 1) {
                $target = $_POST['post_oauth_target'];
                $this->do_oauth_login($target, 'start');
            }
        }

        if (isset($_GET['provider'])) {
            $this->do_oauth_login($_GET['provider'], 'finish');
        }

        if (isset($_GET['logout'])) {
            if ($_GET['logout'] == 1) {
                $this->close_session();
            }
        }

        $this->check_session();

        if ($this->credentials("users")) {
            $_SESSION['login']['status'] = 1;
        } else {
            $_SESSION['login']['status'] = 0;
        }

        if (isset($smarty)) {
            if (is_array($_SESSION['login'])) {
                $this->login = array_merge($this->login, $_SESSION['login']);
            }
            $smarty->assign("login", $this->login);
        }
    }

    public function base64Decode_jwt($data)
    {
        $decoded = str_pad($data, 4 - (strlen($data) % 4), '=');
        return base64_decode(strtr($decoded, '-_', '+/'));
    }

    public function do_oauth_login($target, $mode)
    {
        global $emps;

        require 'oauth/http.php';
        require 'oauth/oauth_client.php';


        $client = new oauth_client_class;
        //$client->debug = 1;
        //$client->debug_http = 1;
        $config_file = EMPS_SCRIPT_PATH . '/modules/oauth/oauth_configuration.json';
        if (!file_exists($config_file)) {
            $config_file = $emps->common_module("oauth/oauth_configuration.json");
        }

        $client->configuration_file = $config_file;

        $proto = "http";

        switch ($target) {
            case 'twitter':
                $client->client_id = OAUTH_TWITTER_KEY;
                $client->client_secret = OAUTH_TWITTER_SECRET;
                $client->server = 'Twitter';
                break;
            case 'vk':
                $client->client_id = OAUTH_VK_ID;
                $client->client_secret = OAUTH_VK_SECRET;
                $client->server = 'VK';
                $client->scope = '';
                break;
            case 'ok':
                $client->client_id = OAUTH_OK_ID;
                $client->client_secret = OAUTH_OK_SECRET;
                $client->server = 'OK';
                $client->scope = '';
                $proto = "https";
                break;
            case 'mailru':
                $client->client_id = OAUTH_MAILRU_ID;
                $client->client_secret = OAUTH_MAILRU_SECRET;
                $client->server = 'MailRu';
                $client->scope = '';
                $proto = "https";
                break;
            case 'facebook':
                $client->client_id = OAUTH_FB_ID;
                $client->client_secret = OAUTH_FB_SECRET;
                $client->server = 'Facebook';
                $client->scope = '';
                break;
            case 'yandex':
                $client->client_id = OAUTH_YANDEX_ID;
                $client->client_secret = OAUTH_YANDEX_SECRET;
                $client->server = 'Yandex';
                $client->scope = '';
                $proto = "https";
                break;
            case 'google':
                $client->client_id = OAUTH_GOOGLE_ID;
                $client->client_secret = OAUTH_GOOGLE_SECRET;
                $client->server = 'Google2';
                $client->scope = 'openid profile';
                $client->store_access_token_response = true;
                $proto = "https";
                if (defined('OAUTH_GOOGLE_PROTO')) {
                    $proto = OAUTH_GOOGLE_PROTO;
                }
                break;
            default:
                return false;
        }

        $host = $_SERVER['SERVER_NAME'];
        $x = explode("?", $_SERVER['REQUEST_URI'], 2);
        $path = $x[0];
        $url = $proto . "://" . $host . $path . "?provider=" . $target;

        if (($target == 'ok' || $target == 'google' || $target == 'facebook' || $target == 'yandex') && $mode == 'start') {
            $_SESSION['oauth_back_redirect'] = $path;
            $url = $proto . "://" . $host . "/" . "?provider=" . $target;
        }

        $client->redirect_uri = $url;

        if ($mode == 'start') {
            unset($_SESSION['OAUTH_ACCESS_TOKEN']);

            $client->ResetAccessToken();

            if (($success = $client->Initialize())) {
                if (($success = $client->Process())) {
                    $success = $client->Finalize($success);
                }
            }
        }

        if ($mode == 'finish') {
//            error_log("OAUTH: mode: finish");
            if (($success = $client->Initialize())) {
//                error_log("OAUTH: Initialize = true");
                if (($success = $client->Process())) {
//                    error_log("OAUTH: Process = true");
                    if (strlen($client->access_token)) {
//                        error_log("OAUTH: access_token = true");
                        $data = $this->oauth_user_data($client, $target);

                        if ($data['user_id']) {
                            $userword = $target . '-' . $data['user_id'];

                            if ($_SESSION['oauth_back_redirect']) {
                                $path = $_SESSION['oauth_back_redirect'];
                                unset($_SESSION['oauth_back_redirect']);
                            }

                            $oauth_id = $this->oauth_id($userword);
                            if ($oauth_id) {
                                $user = $this->load_user($oauth_id['user_id']);
                                if ($user) {
                                    $this->create_session($user['username'], '', 1);
                                    $emps->redirect_page($path);
                                    exit();
                                }

                            } else {

                                if (!$this->taken_user($userword)) {

                                    $password = $this->generate_password();

                                    $data['no_activation'] = true;

                                    $user_id = $this->register_user($userword, $password, $data);

                                    if ($user_id) {

                                        $emps->db->query("update " . TP . "e_users set site=1 where id=" . $user_id);
                                        if ($target == "twitter") {
                                            $emps->db->query("update " . TP . "e_users set twitter_id = " . $data['user_id'] . ", profile_name='" . $data['twitter'] . "' where id=" . $user_id);
                                        }

                                        $this->new_identity($userword, $user_id, $target, $data);

                                        $this->activate_account($user_id);
                                        $this->create_session($userword, '', 1);
                                        $emps->redirect_page($path);
                                        exit();

                                    }

                                } else {
                                    $this->create_session($userword, '', 1);
                                    $emps->redirect_page($path);
                                    exit();
                                }
                            }
                        }
                    }
                    $success = $client->Finalize($success);
                }
            }
        }

        if ($client->exit) {
            exit;
        }
        return true;
    }

    public function identity_link($ra)
    {
        global $emps;

        return $ra['data']['link'];
    }

    public function list_identities($user_id)
    {
        global $emps;

        $r = $emps->db->query("select * from " . TP . "e_identities where user_id = " . $user_id);
        $lst = array();
        while ($ra = $emps->db->fetch_named($r)) {
            $ra['data'] = unserialize($ra['data']);
            $ra['name'] = $ra['firstname'] . " " . $ra['lastname'];
            $ra['link'] = $this->identity_link($ra);
            if (isset($ra['data']['orig_user'])) {
                $ra['orig_user'] = json_decode($ra['data']['orig_user'], true);
            }
            $lst[$ra['provider']] = $ra;
        }
        return $lst;
    }

    public function remove_identity($id)
    {
        global $emps;

        $id = intval($id);

        $emps->db->query("delete from " . TP . "e_identities where id = " . $id);
    }

    public function oauth_id($userword)
    {
        global $emps;

        $row = $emps->db->get_row("e_identities", "lcase(identity) = lcase('" . $emps->db->sql_escape($userword) . "')");
        if ($row) {
            return $row;
        }

        return false;
    }

    public function oauth_user_data(&$client, $target)
    {
        global $emps;

        $user = array();

        if ($target == 'twitter') {
            $success = $client->CallAPI(
                'https://api.twitter.com/1.1/account/verify_credentials.json',
                'GET', array(), array('FailOnAccessError' => true), $user);
        }

        if ($target == 'vk') {
            $success = $client->CallAPI(
                'https://api.vk.com/method/users.get',
                'GET', array("fields" => "id,first_name,last_name,sex,photo_50"), array('FailOnAccessError' => true), $user);
        }

        if ($target == 'facebook') {
            $success = $client->CallAPI(
                'https://graph.facebook.com/me',
                'GET', array("fields" => "id,first_name,last_name,gender,picture,email,link"), array('FailOnAccessError' => true), $user);
        }

        if ($target == 'google') {
            $success = $client->CallAPI(
                'https://www.googleapis.com/oauth2/v3/userinfo',
                'GET', array(), array('FailOnAccessError' => true), $user);
        }

        if ($target == 'yandex') {
            $success = $client->CallAPI(
                'https://login.yandex.ru/info',
                'GET', array("format" => "json"), array('FailOnAccessError' => true), $user);
        }

        if ($target == 'ok') {

            $params = array(
                "application_key=" . OAUTH_OK_PUBLIC,
                "format=json",
                "method=users.getCurrentUser"
            );

            $sigq = implode("", $params);
            $query = implode("&", $params) . "&" .
                "access_token=" . $client->access_token;

            $s2 = md5($client->access_token . OAUTH_OK_SECRET);
            $sig = md5($sigq . $s2);

            $result = file_get_contents("http://api.odnoklassniki.ru/fb.do?" . $query . "&sig=" . $sig);

            $user = json_decode($result);
            if (isset($user->uid)) {
                $success = true;
            }

        }

        if ($target == 'mailru') {
            //error_log("TARGET: Mail.ru");

            $params = array(
                "app_id=" . OAUTH_MAILRU_ID,
                "method=users.getInfo",
                "secure=1",
                "session_key=" . $client->access_token
            );

            $sigq = implode("", $params);
            $query = implode("&", $params);

            $sig = md5($sigq . OAUTH_MAILRU_SECRET);

            $url = "http://www.appsmail.ru/platform/api?" . $query . "&sig=" . $sig;
            //error_log($url);

            $result = file_get_contents($url);

            //error_log($result);

            $user = json_decode($result)[0];
            if (isset($user->uid)) {
                $success = true;
            }

        }

        $data = array();
        if ($success) {
            if ($target == 'twitter') {
                $data['profile_image'] = $user->profile_image_url;
                $name = $user->name;
                $x = explode(" ", $name, 2);
                $data['firstname'] = $x[0];
                $data['lastname'] = $x[1];
                if ($target == 'twitter') {
                    $data['twitter'] = $user->screen_name;
                }
                $data['link'] = "https://twitter.com/" . $user->screen_name;
                $data['user_id'] = $user->id;
            }
            if ($target == 'vk') {
//				dump($user);exit();
                $resp = $user->response[0];
                $data['user_id'] = $resp->uid;
                $data['firstname'] = $resp->first_name;
                $data['lastname'] = $resp->last_name;
                $data['gender'] = $resp->sex;
                $data['profile_image'] = $resp->photo_50;
                $data['link'] = "https://vk.com/id" . $resp->uid;
            }
            if ($target == 'ok') {
//				dump($user);exit();				
                $data['user_id'] = $user->uid;
                $data['firstname'] = $user->first_name;
                $data['lastname'] = $user->last_name;
                $data['link'] = "http://odnoklassniki.ru/profile/" . $user->uid;
                $data['profile_image'] = $user->pic_1;
            }
            if ($target == 'mailru') {
//				dump($user);exit();
                $data['user_id'] = $user->uid;
                $data['firstname'] = $user->first_name;
                $data['lastname'] = $user->last_name;
                $data['email'] = $user->email;
                $data['link'] = $user->link;
                $data['profile_image'] = $user->pic_big;
            }
            if ($target == 'facebook') {

                $data['user_id'] = $user->id;
                $data['firstname'] = $user->first_name;
                $data['lastname'] = $user->last_name;
                $data['email'] = $user->email;
                $data['gender'] = $user->gender;
                $data['profile_image'] = $user->picture->data->url;
                $data['link'] = "https://www.facebook.com/app_scoped_user_id/" . $user->id . "/";
            }
            if ($target == 'google') {
                $data['user_id'] = $user->sub;
                $data['firstname'] = $user->given_name;
                $data['lastname'] = $user->family_name;
                $data['link'] = $user->link;
                $data['email'] = $user->email;
                $data['gender'] = $user->gender;
                $data['profile_image'] = $user->picture;
            }
            if ($target == 'yandex') {
                $data['user_id'] = $user->id;
                $data['firstname'] = $user->first_name;
                $data['lastname'] = $user->last_name;
                $data['link'] = $user->login;
                $data['email'] = $user->default_email;
                $data['gender'] = $user->sex;
                if(!$user->is_avatar_empty) {
                    $data['profile_image'] = "https://avatars.yandex.net/get-yapic/" . $user->default_avatar_id . "/islands-200";
                }
            }

            $data['orig_user'] = json_encode($user);

            return $data;
        }

        return false;
    }

    public function new_identity($userword, $user_id, $target, $data)
    {
        global $emps, $SET;

        $SET = array();
        $SET['identity'] = $userword;
        $SET['firstname'] = $data['firstname'];
        $SET['lastname'] = $data['lastname'];
        $SET['provider'] = $target;
        $SET['user_id'] = $user_id;
        $SET['photo'] = $data['profile_image'];
        $SET['data'] = serialize($data);
        $row = $emps->db->get_row("e_identities", "identity = '" . $userword . "'");
        if ($row) {
            $emps->db->sql_update("e_identities", "id = " . $row['id']);
        } else {
            $emps->db->sql_insert("e_identities");
        }
    }

    public function list_user_groups($user_id)
    {
        global $emps;

        $r = $emps->db->query("select * from " . TP . "e_users_groups where user_id = " . $user_id . " and context_id in (" . $emps->website_ctx . "," .
            $emps->default_ctx . ")");

        $groups = array();
        while ($ra = $emps->db->fetch_named($r)) {
            $groups[$ra['group_id']] = true;
        }
        $gl = array();
        foreach ($groups as $n => $v) {
            $gl[] = $n;
        }
        return $gl;
    }

    public function delete_from_group($user_id, $group_id, $context_id)
    {
        global $emps;
        $context_id += 0;
        $user_id += 0;
        $emps->db->query("delete from " . TP . "e_users_groups where user_id=$user_id and group_id='$group_id' and context_id=$context_id");
    }

    public function add_to_group_context($user_id, $group_id, $context_id)
    {
        global $emps, $SET;
        $group_id = trim($group_id);
        if (!$group_id) {
            return false;
        }
        if ($this->user_credentials_context($user_id, $group_id, $context_id)) return;
        $this->delete_from_group($user_id, $group_id, $context_id);
        $SET = array();
        $r = $_REQUEST;
        $_REQUEST = array();
        $_REQUEST['user_id'] = $user_id + 0;
        $_REQUEST['group_id'] = $group_id;
        $_REQUEST['context_id'] = $context_id + 0;
        $emps->db->sql_insert("e_users_groups");
        $_REQUEST = $r;
    }

    public function add_to_group($user_id, $group_id)
    {
        global $emps;
        $this->add_to_group_context($user_id, $group_id, $emps->website_ctx);
    }

    public function clear_activations($user_id)
    {
        global $emps;
        $emps->db->query("delete from " . TP . "e_actkeys where user_id=$user_id");
    }

    public function pick_activation_key($uid)
    {
        global $emps;
        $bt = md5(uniqid(rand() . time(), true));
        $dt = time();
        $emps->db->query("delete from " . TP . "e_actkeys where pin = '" . $bt . "'");
        $emps->db->query("insert into " . TP . "e_actkeys (pin, user_id, dt) values ('$bt',$uid,$dt)");
        return $bt;
    }

    public function activate_account($uid)
    {
        global $emps;
        $emps->db->query("update " . TP . "e_users set status='1' where id=$uid");
        $this->clear_activations($uid);
    }

    public function create_activation($user_id)
    {
        global $smarty, $pp, $key, $emps;

        $dt = time() - 12 * 60 * 60;
        $emps->db->query("delete from " . TP . "e_actkeys where dt<$dt");

        $emps->clearvars();
        $smarty->assign("udata", $this->load_user($user_id));
        $key = $this->pick_activation_key($user_id);
        $pp = "activate";
        $link = EMPS_SCRIPT_WEB . $emps->elink();
        $emps->loadvars();

        $smarty->assign("url", $link);

        require_once($emps->common_module("mail/mail.class.php"));

        $mail = new EMPS_Mail;

        return $mail->queue_message($user_id, "db:msg/activate", $mail->encode_string($smarty->fetch("db:msg/actheader"), "utf-8"));
    }

    public function register_user($userword, $password, $data)
    {
        global $SET, $emps;

        $user = $emps->db->get_row("e_users", "lcase(username)=lcase('$userword') and status>0");
        if ($user) return -1;

        $emps->db->query("delete from " . TP . "e_users where lcase(username)=lcase('$userword')");

        $SET = array();
        $SET['username'] = $userword;
        $SET['password'] = md5($password);
        $SET['context_id'] = $emps->website_ctx;
        $SET['status'] = 0;
        $emps->db->sql_insert("e_users");
        $user_id = $emps->db->last_insert();

        $emps->p->save_properties($data, $emps->p->get_context(DT_USER, 1, $user_id), P_USER);

        if ($data['email'] && !$data['no_activation']) {
            $r = $this->create_activation($user_id);
            if ($r < 0) {
                return -10;
            }
        }
        return $user_id;
    }

    public function taken_user($username)
    {
        global $emps;
        $row = $emps->db->get_row("e_users", "lcase(username)=lcase('" . $username . "') and status>0");
        if ($row) {
            return $row;
        }
        return false;
    }

    public function load_user($user_id)
    {
        global $emps;
        $user_id += 0;
        if (!$user_id) {
            return false;
        }
        $user = $emps->db->get_row("e_users", "id=$user_id");
        if (!$user) return false;
        $user = $emps->p->read_properties($user, $emps->p->get_context(DT_USER, 1, $user['id']));

        if (!$user['fullname']) {
            $user['fullname'] = $this->form_fullname($user);
        }

        if (!$user['display_name']) {
            $user['display_name'] = $user['fullname'];
        }
        return $user;
    }

    public function form_fullname($ra)
    {
        $parts = array();
        if ($ra['firstname']) {
            $parts[] = $ra['firstname'];
        }
        if ($ra['lastname']) {
            $parts[] = $ra['lastname'];
        }

        $ra['fullname'] = implode(" ", $parts);
        return $ra;
    }

    public function ensure_fullname($ra)
    {
        global $emps;
        $ra = $this->load_user($ra['id']);
        $ofullname = $ra['fullname'];
        $ra = $this->form_fullname($ra);
        if ($ofullname != $ra['fullname']) {
            $fullname = $emps->db->sql_escape($ra['fullname']);
            $emps->db->query("update " . TP . "e_users set fullname='" . $fullname . "' where id=" . $ra['id']);
        }
        return $ra;
    }

    public function generate_password()
    {
        $line = "1qazxsw23edcvfr45tgbnhy67ujmki890olp0PLMKO9IJNBHU87YGVCFT65RDXZSE43WASQ21";
        $len = strlen($line) - 1;
        $cnt = mt_rand(5, 8);
        $pwd = "";
        for ($i = 0; $i < $cnt; $i++) {
            $ic = mt_rand(0, $len);
            $c = $line{$ic};
            $pwd .= $c;
        }
        return $pwd;
    }

    public function plain_phone($phone)
    {
        $s = preg_replace('/\D/', '', $phone);
        return $s;
    }

    public function birth_date($date)
    {
        global $emps;

        $time = $date . " 12:00";

        $dt = $emps->parse_time($time);

        return $dt;
    }
}

