<?php
#==============================================================================
# LTB Self Service Password
#
# Copyright (C) 2009 Clement OUDOT
# Copyright (C) 2009 LTB-project.org
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# GPL License: http://www.gnu.org/licenses/gpl.txt
#
#==============================================================================

# This page is called to reset a password trusting question/anwser

#==============================================================================
# POST parameters
#==============================================================================
# Initiate vars
$result = "";
$login = $presetLogin;
$question = "";
$answer = "";
$newpassword = "";
$confirmpassword = "";
$ldap = "";
$userdn = "";
if (!isset($pwd_forbidden_chars)) { $pwd_forbidden_chars=""; }
$mail = "";
$extended_error_msg = "";

if (isset($_POST["confirmpassword"]) and $_POST["confirmpassword"]) { $confirmpassword = strval($_POST["confirmpassword"]); }
 else { $result = "confirmpasswordrequired"; }
if (isset($_POST["newpassword"]) and $_POST["newpassword"]) { $newpassword = strval($_POST["newpassword"]); }
  else { $result = "newpasswordrequired"; }
if (isset($_POST["answer"]) and $_POST["answer"]) { $answer = strval($_POST["answer"]); }
 else { $result = "answerrequired"; }
if (isset($_POST["question"]) and $_POST["question"]) { $question = strval($_POST["question"]); }
 else { $result = "questionrequired"; }
if (isset($_REQUEST["login"]) and $_REQUEST["login"]) { $login = strval($_REQUEST["login"]); }
 else { $result = "loginrequired"; }
if (! isset($_POST["confirmpassword"]) and ! isset($_POST["newpassword"]) and ! isset($_POST["answer"]) and ! isset($_POST["question"]) and ! isset($_REQUEST["login"]))
 { $result = "emptyresetbyquestionsform"; }

# Check the entered username for characters that our installation doesn't support
if ( $result === "" ) {
    $result = check_username_validity($login,$login_forbidden_chars);
}

#==============================================================================
# Check reCAPTCHA
#==============================================================================
if ( $result === "" && $use_recaptcha ) {
    $result = check_recaptcha($recaptcha_privatekey, $recaptcha_request_method, $_POST['g-recaptcha-response'], $login);
}

#==============================================================================
# Check question/answer
#==============================================================================
if ( $result === "" ) {

    # Connect to LDAP
    $ldap = ldap_connect($ldap_url);
    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    if ( $ldap_starttls && !ldap_start_tls($ldap) ) {
        $result = "ldaperror";
        error_log("LDAP - Unable to use StartTLS");
    } else {

    # Bind
    if ( isset($ldap_binddn) && isset($ldap_bindpw) ) {
        $bind = ldap_bind($ldap, $ldap_binddn, $ldap_bindpw);
    } else {
        $bind = ldap_bind($ldap);
    }

    if ( !$bind ) {
        $result = "ldaperror";
        $errno = ldap_errno($ldap);
        if ( $errno ) {
            error_log("LDAP - Bind error $errno  (".ldap_error($ldap).")");
        }
    } else {

    # Search for user
    $ldap_filter = str_replace("{login}", $login, $ldap_filter);
    $search = ldap_search($ldap, $ldap_base, $ldap_filter);

    $errno = ldap_errno($ldap);
    if ( $errno ) {
        $result = "ldaperror";
        error_log("LDAP - Search error $errno (".ldap_error($ldap).")");
    } else {

    # Get user DN
    $entry = ldap_first_entry($ldap, $search);
    $userdn = ldap_get_dn($ldap, $entry);

    if( !$userdn ) {
        $result = "badcredentials";
        error_log("LDAP - User $login not found");
    } else {

    # Check objectClass to allow samba and shadow updates
    $ocValues = ldap_get_values($ldap, $entry, 'objectClass');
    if ( !in_array( 'sambaSamAccount', $ocValues ) and !in_array( 'sambaSAMAccount', $ocValues ) ) {
        $samba_mode = false;
    }
    if ( !in_array( 'shadowAccount', $ocValues ) ) {
        $shadow_options['update_shadowLastChange'] = false;
        $shadow_options['update_shadowExpire'] = false;
    }

    # Get user email for notification
    if ( $notify_on_change ) {
        $mailValues = ldap_get_values($ldap, $entry, $mail_attribute);
        if ( $mailValues["count"] > 0 ) {
            $mail = $mailValues[0];
        }
    }

    # Get question/answer values
    $questionValues = ldap_get_values($ldap, $entry, $answer_attribute);
    unset($questionValues["count"]);

    $match = false;

    $question = preg_quote($question,'/');
    $answer   = preg_quote($answer,'/');
    $pattern  = "/^\{$question\}$answer$/i";

    # Match with user submitted values
    foreach ($questionValues as $questionValue) {
        $value = $crypt_answers ? decrypt($questionValue, $keyphrase) : $questionValue;
        if (preg_match($pattern, $value)) {
            $match = true;
        }
    }

    if (!$match) {
        $result = "answernomatch";
        error_log("Answer does not match question for user $login");
    }

    $entry = ldap_get_attributes($ldap, $entry);
    $entry['dn'] = $userdn;

}}}}}

#==============================================================================
# Check and register new passord
#==============================================================================
# Match new and confirm password
if ( $result === "" ) {
    if ( $newpassword != $confirmpassword ) { $result="nomatch"; }
}

# Check password strength
if ( $result === "" ) {
    $result = check_password_strength( $newpassword, "", $pwd_policy_config, $login, $entry );
}

# Change password
if ($result === "") {
    if ( isset($prehook) ) {
        $command = hook_command($prehook, $login, $newpassword, null, $prehook_password_encodebase64);
        exec($command, $prehook_output, $prehook_return);
    }
    if ( ! isset($prehook_return) || $prehook_return === 0 || $ignore_prehook_error ) {
	$result = change_password($ldap, $userdn, $newpassword, $ad_mode, $ad_options, $samba_mode, $samba_options, $shadow_options, $hash, $hash_options, "", "", $ldap_use_exop_passwd);
	if ( $result === "passwordchanged" && isset($posthook) ) {
	    $command = hook_command($posthook, $login, $newpassword, null, $posthook_password_encodebase64);
	    exec($command, $posthook_output, $posthook_return);
	}
	if ( $result !== "passwordchanged" ) {
	    if ( $show_extended_error ) {
		ldap_get_option($ldap, 0x0032, $extended_error_msg);
	    }
	}
    }
}

#==============================================================================
# Notify password change
#==============================================================================
if ($mail and $notify_on_change and $result === 'paswordchanged') {
    $data = array( "login" => $login, "mail" => $mail, "password" => $newpassword);
    if ( !send_mail($mailer, $mail, $mail_from, $mail_from_name, $messages["changesubject"], $messages["changemessage"].$mail_signature, $data) ) {
        error_log("Error while sending change email to $mail (user $login)");
    }
}
