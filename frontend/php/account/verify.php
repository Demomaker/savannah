<?php
# Verify registration hash.
#
# Copyright (C) 1999-2000 The SourceForge Crew
# Copyright (C) 2002-2006 Mathieu Roy <yeupou--gnu.org>
# Copyright (C) 2017 Ineiev
#
# This file is part of Savane.
#
# Savane is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as
# published by the Free Software Foundation, either version 3 of the
# License, or (at your option) any later version.
#
# Savane is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

require_once('../include/init.php');
require_once('../include/dnsbl.php');
require_once('../include/spam.php');
require_once('../include/html.php');
require_once('../include/form.php');
require_once('../include/exit.php');

register_globals_off();

extract(sane_import('post',
  array('update', 'form_id',
    'form_loginname', 'form_pw', 'confirm_hash')));

# Block here potential robots.
dnsbl_check();

# Logged users have no business here.
if (user_isloggedin())
  session_redirect($GLOBALS['sys_home']."my/");

if (!empty($update))
  {
    # First check just confirmation hash.
    $res = db_execute('SELECT confirm_hash,status FROM user WHERE '
                      .'user_name=? and status<>"SQD"',
                      array($form_loginname));
    if (db_numrows($res) < 1)
      exit_error(_("Invalid username."));

    $usr = db_fetch_array($res);
    if ($confirm_hash != $usr['confirm_hash'])
# TRANSLATORS: confirmation hash is a secret code sent to the user.
      exit_error(_("Invalid confirmation hash"));

    # Then check valid login.
    if (session_login_valid($form_loginname,
                            $form_pw,
                            1, # accept not yet confirmed accounts
                            0, # not a cookie for a year
                            0, # not crypted
                            session_issecure()))
      {
        $res = db_execute("UPDATE user SET status='A' WHERE user_name=?",
                          array($form_loginname));
        session_redirect($GLOBALS['sys_home']."account/first.php");
      }
  }
site_header(array('title'=>_("Login")));
# TRANSLATORS: the argument is the name of the system (like "Savannah").
print '<h2> '.sprintf(_("%s Account Verification"),$GLOBALS['sys_name'])
      ."</h2>\n";
print '<p>'._("In order to complete your registration, login now. Your account
will then be activated for normal logins.")."</p>\n";

print form_header($_SERVER["PHP_SELF"], $form_id);
print '<p><span class="preinput">'._("Login Name").':</span><br />&nbsp;&nbsp;';
print form_input("text", "form_loginname");
print "</p>\n";

print '<p><span class="preinput">'._("Password").':</span><br />&nbsp;&nbsp;';
print form_input("password", "form_pw");
print "</p>\n";

# Must accept all ways of providing confirm_hash (POST & GET), because
# in the mail it is a POST but if the form fail (wrong password, etc), it will
# be a GET.
print form_input("hidden", "confirm_hash", sane_all('confirm_hash'));
print form_footer(_("Login"));

site_footer(array());
?>
