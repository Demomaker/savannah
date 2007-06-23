<?php
# This file is part of the Savane project
# <http://gna.org/projects/savane/>
#
# $Id: index.php 148 2007-05-27 21:48:12Z Beuc $
#
#  Copyright 2005      (c) Mathieu Roy <yeupou--gnu.org>
# Copyright (C) 2007  Sylvain Beucler
#
# The Savane project is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# The Savane project is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with the Savane project; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

require_once('../include/init.php');

#input_is_safe();
#mysql_is_safe();

if (!$group_id)
{
  exit_no_group();
}

$project = project_get_object($group_id);

if (!$project->Uses("git") && !$project->UsesForHomepage("git"))
{
  exit_error(_("This project has turned off this tool"));
}


site_project_header(array('group'=>$group_id,'context'=>'git'));



# ####################### Git Browsing


$type = "Git";
if (($project->Uses("git") &&
     $project->getUrl("git_viewcvs") != 'http://' &&
     $project->getUrl("git_viewcvs") != '') ||
    ($project->UsesForHomepage("git") &&
     $project->getUrl("cvs_viewcvs_homepage") != 'http://' &&
     $project->getUrl("cvs_viewcvs_homepage") != ''))
{
  print '<h2>'.sprintf(_("Browsing the %s Repository"), $type).'</h2>';
  print '<p>'.sprintf(_("You can Browse the %s repository of this project with your web browser. This gives you a good picture of the current status of the source files. You may also view the complete histories of any file in the repository as well as differences among two versions."), $type).'</p>';
  
  print '<ul>';
  if ($project->Uses("git") &&
      $project->getUrl("git_viewcvs") != 'http://' &&
      $project->getUrl("git_viewcvs") != '')
    {
      print '<li><a href="'.$project->getUrl("git_viewcvs").'">'._("Browse Sources Repository").'</a></li>';
    }
  if ($project->UsesForHomepage("git") &&
      $project->getUrl("cvs_viewcvs_homepage") != 'http://' &&
      $project->getUrl("cvs_viewcvs_homepage") != ''
      )
    {
      print '<li><a href="'.$project->getUrl("cvs_viewcvs_homepage").'">'._("Browse Web Pages Repository").'</a></li>';
    }
  print '</ul><p>&nbsp;</p>';
  
}

print '<h2>'.sprintf(_("Getting a Copy of the %s Repository"),$type).'</h2>';

utils_get_content("git/index");

site_project_footer(array());