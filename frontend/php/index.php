<?php
# Front page - news, latests projects, etc.
# Copyright (C) 1999-2000 The SourceForge Crew
# Copyright (C) 2002-2006 Mathieu Roy <yeupou--gnu.org>
# Copyright (C) 2006, 2007  Sylvain Beucler
# Copyright (C) 2017, 2018  Ineiev
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

require_once('include/init.php');
require_directory("people");
require_directory("news");
require_directory("stats");
require_once('include/features_boxes.php');

# Some messages make little sense for users as opposed to the admins;
# they are left untranslated.

function no_i18n($string)
{
  return $string;
}

# Check if the PHP Frontend is acceptably configured.
# Do progressive little checks, to avoid creating to much extra load.
# Not gettextized for now, already lot of more important strings to 
# translate.
if (empty($GLOBALS['sys_unix_group_name']))
{
  fb(no_i18n("Serious configuration problem: sys_unix_group_name is empty."), 1);
} 

# Check whether the local admin group exists. This is useful only during
# installation process.
if ($conn && empty($sys_group_id))
{
  if (!user_isloggedin()) 
    {
      # If there is no database, we will first found that no user is logged in
      # Check if there is a database.
      $result = db_query("SHOW TABLES LIKE 'groups'");
      if (!db_numrows($result))
	{
	  # No valid database
	  fb(sprintf(no_i18n(
"Installation incomplete: while the connection to the SQL server is
ok, the database '%s' was not found. Please, create it according to
the documentation shipped with your Savane package"),
             $GLOBALS['sys_dbname']), 1);
	}
      else if (db_result(db_query("SELECT count(*) AS count FROM user"),
                         0, 'count') < 2)
	{ // 2 = 1 default "None" user + 1 normal user
	  fb(no_i18n(
"Installation incomplete: you must now create for yourself a user
account. Once it is done, you will have to login and register the
local administration project"), 1);
	}
      else
	{
	  # Not logged-in, probably no user account
	  fb(sprintf(no_i18n(
"Installation incomplete: you have to login and register the local
administration project (or maybe '%s', from the
'sys_unix_group_name' configuration parameter, is not the right
project name?)"), $sys_unix_group_name), 1);
	}
    }
  else
    {    
      # No admin groups
      fb(no_i18n(
"Installation incomplete: you must now register the local
administration project, select 'Register New Project' in the left
menu"), 1);
    }
  # The string is a URL on localhost, e.g. http://127.0.0.1/testconfig.php
  fb(sprintf(
    no_i18n("By the way, have you checked the setup of your web server at %s?"),
             'http://127.0.0.1'.$GLOBALS['sys_home'].'testconfig.php'), 1);
}

$HTML->header(array('title'=>_("Welcome"), 'notopmenu'=>1));
html_feedback_top();

print '
   <div class="indexright">
';
print show_features_boxes();
print '
   </div><!-- end indexright --> 
';

print '   <div class="indexcenter">';

utils_get_content("homepage");

print "\n<p>&nbsp;</p>\n";

print $HTML->box_top('<a href="'.$GLOBALS['sys_home'].'news/" class="sortbutton">'
       ._("Latest News").'</a>');
print news_show_latest($GLOBALS['sys_group_id'],9, "true"); 
print $HTML->box_bottom();

print '
   </div><!-- end indexcenter -->
';

$HTML->footer(array());
