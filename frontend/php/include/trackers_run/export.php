<?php
# This file is part of the Savane project
# <http://gna.org/projects/savane/>
#
# $Id: download.php 4969 2005-11-15 10:32:43Z yeupou $
#
#  Copyright 2005-2006 (c) Mathieu Roy <yeupou--gnu.org>
#
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

register_globals_off();

# use the wording "export job" to distinguish the job from the task that
# will help users to follow the job.
# Yes, job and task can be considered as synonym. But as long as we havent
# got such jobs completely managed via the task tracker, we need to avoid
# confusions.
$group_id = sane_all("group_id");
$group = sane_all("group");
$group_name = $group;

if (!$group_id)
{ print exit_no_group(); }

$project=project_get_object($group_id);

if (!member_check(0, $group_id))
{
  exit_error(_("Data Export is currently restricted to projects members"));
}

trackers_init($group_id);

# Set $printer that may be used in later pages instead of PRINTER
if (defined('PRINTER'))
{ $printer = 1; }

# Set the limit of possible jobs per user
$max_export = 5;

# Get the list of current exports
$sql = "SELECT * FROM trackers_export WHERE user_name='".user_getname()."' AND unix_group_name='".$group_name."' AND status<>'I' ORDER BY export_id ASC";
$res_export = db_query($sql);
$export_count = db_numrows($res_export);



########################################################################
# GET/POST Update

if (sane_all("update"))
{
  # Create new item
  if (sane_post("create"))
    {
      $form_id = sane_all("form_id");
      if (!form_check($form_id))
	{ exit_error(_("Exiting")); }

      if ($export_count >= $max_export)
	{
	  # Already registered 5 exports? Kick out
	  form_clean($form_id);
	  exit_error(sprintf(ngettext("You have already registered %s export job for this project, which is the current limit. If more exports are required ask other project members.", "You have already registered %s export jobs for this project, which is the current limit. If more exports are required ask other project members.", $max_export), $max_export));
	}


      ##
      # Find out the sql to build up export
      $report_id = sane_all("report_id");
      if (!$report_id)
	{ $report_id = 100; }
      trackers_report_init($group_id, $report_id);

      $select = 'SELECT bug_id ';
      $from = 'FROM '.ARTIFACT.' ';
      $where = 'WHERE group_id='.$group_id.' ';

      #################### GRABBED FROM BROWSE
      # This should probably included in functions
      $url_params = trackers_extract_field_list();
      unset($url_params['group_id'], $url_params['history_date']);
      $advsrch = sane_get("advsrch");
      while (list($field,$value_id) = each($url_params))
	{
	  if (!is_array($value_id))
	    {
	      unset($url_params[$field]);
	      $url_params[$field][] = $value_id;
	    }
	  if (trackers_data_is_date_field($field))
	    {
	      if ($advsrch)
		{
		  $field_end = $field.'_end';
		  $url_params[$field_end] = sane_post($field_end);
		}
	      else
		{
		  $field_op = $field.'_op';
		  $url_params[$field_op] = sane_post($field_op);
		  if (!$url_params[$field_op])
		    { $url_params[$field_op] = '='; }
		}
	    }
	}

      while ($field = trackers_list_all_fields())
	{
	  if (trackers_data_is_showed_on_query($field) &&
	      trackers_data_is_select_box($field) )
	    {
	      if (!isset($url_params[$field]))
		{ $url_params[$field][] = 0; }
	    }
	}

      reset($url_params);
      while (list($field,$value_id) = each($url_params))
	{
	  # This break the sql, I dont now why. Apparently it returns false
	  # for the date fields.
	  #if (!trackers_data_is_showed_on_query($field))
	  #  { continue; }

	  if (trackers_data_is_select_box($field) && !trackers_isvarany($url_params[$field]) )
	    {
	      $where .= ' AND '.$field.' IN ('.implode(',',$url_params[$field]).') ';
	    }
	  else if (trackers_data_is_date_field($field) && $url_params[$field][0])
	    {
       	      list($time,$ok) = utils_date_to_unixtime($url_params[$field][0]);
	      preg_match("/\s*(\d+)-(\d+)-(\d+)/", $url_params[$field][0],$match);
	      list(,$year,$month,$day) = $match;

	      if ($advsrch)
		{
		  list($time_end,$ok_end) = utils_date_to_unixtime($url_params[$field.'_end'][0]);
		  if ($ok)
		    { $where .= ' AND '.$field.' >= '. $time; }

		  if ($ok_end)
		    { $where .= ' AND '.$field.' <= '. $time_end; }
		}
	      else
		{
		  $operator = $url_params[$field.'_op'][0];
          # '=' means that day between 00:00 and 23:59
		  if ($operator == '=')
		    {
		      $time_end = mktime(23, 59, 59, $month, $day, $year);
		      $where .= ' AND '.$field.' >= '.$time.' AND '.$field.' <= '.$time_end.' ';
		    }
		  else
		    {
		      $time = mktime(0,0,0, $month, ($day+1), $year);
		      $where .= ' AND '.$field." $operator= $time ";
		    }
		}

      # Always exclude undefined dates (0)
	      $where .= ' AND '.$field." <> 0 ";

	    }
	  elseif ((trackers_data_is_text_field($field) ||
		   trackers_data_is_text_area($field)) &&
		  $url_params[$field][0])
	    {
      # Buffer summary and original submission (details) to handle them later
      # in case we have an OR to do between the two, instead of the usual
      # AND
	      if ($sumORdet == 1 &&
		  ($field == 'summary' || $field == 'details'))
		{
		  if ($field == 'summary')
		    { $summary_search = 1; }
		  if ($field == 'details')
		    { $details_search = 1; }
		}
	      else
		{
          # It s a text field accept. Process INT or TEXT,VARCHAR fields differently
		  $where .= ' AND '.trackers_build_match_expression($field, $url_params[$field][0]);
		}
	    }
	}


      # Handle summary and/or original submission now, if a AND is required
      if ($sumORdet == 1)
	{
      # We will process the usual normal AND case: there was something for both
      # fields.
	  if ($details_search == 1 && $summary_search == 1)
	    {
	      $where .= ' AND ';
	      $where .= '( ( ';
	      $where .= trackers_build_match_expression('details', $url_params['details'][0]);
	      $where .= ' ) OR ( ';
	      $where .= trackers_build_match_expression('summary', $url_params['summary'][0]);
	      $where .= ') ) ';
	    }
	  else
	    {
      # Now we take care of the unusual, possible though, case where and
      # AND was asked but not both fields set.
      # Since the AND was asked, the fields havent been taken care of before
      # and we need to do it now.
      # We do that in two IF, in case something went very wrong. In such case
      # we will proceed with a usual AND.
	      if ($details_search == 1 && $url_params['details'][0])
		{
		  $where .= ' AND ';
		  $where .= trackers_build_match_expression('details', $url_params['details'][0]);
		}
	      if ($summary_search == 1 && $url_params['summary'][0])
		{
		  $where .= ' AND ';
		  $where .= trackers_build_match_expression('summary', $url_params['summary'][0]);
		}
	    }
	}
      #################### GRABBED FROM BROWSE
      $export_sql = "$select $from $where";


      ##
      # Find out the time arguments
      unset($timestamp, $requested_hour, $requested_day);

      # Use the time as it was while the form was printed to the user
      $current_time = sane_post("current_time");

      # Find out the relevant timestamp that will be used by the backend
      # to determine which job must be performed
      $mainchoice = sane_post("date_mainchoice");

      switch ($mainchoice)
	{
	case 'asap':
	  # Basic case where the user wants the export to be done as soon
	  # as possible: we provide current time as timestamp
	  $timestamp = mktime();
	  break;
	case 'next':
	  # Case where the user provide a date for a one time export
	  # In the form:
	  #    0 = today
	  #    1 = tomorrow
	  #    etc...
	  $current_day = strftime('%d', $current_time);
	  $current_month = strftime('%m', $current_time);
	  $day = ($current_day+sane_post("date_next_day"));
	  $hour =  sane_post("date_next_hour");
	  $timestamp = mktime($hour, 0, 0, $current_month, $day);
	  break;
	case 'frequent':
	  # Data export will be done on a weekly basis
	  # We store the timestamp of the next time it is expect
	  # and we save the request, so the backend now that he will have
	  # to update the timestamp afterwards
	  $current_day = strftime('%d', $current_time);
	  $current_month = strftime('%m', $current_time);
	  $hour =  sane_post("date_frequent_hour");
	  $requested_hour = $hour;
	  $requested_day = (sane_post("date_frequent_day")+1);

	  for ($day = $current_day; $day <= ($current_day+8); $day++)
	    {
	      # Test the next 8 days and find out which one match with
              # the requested day
	      $timestamp = mktime($hour, 0, 0, $current_month, $day);
	      if (strftime('%u', $timestamp) == $requested_day)
		{ break; }
	    }


	}


      ##
      # Insert the request into the database

      # First add an entry in trackers_export. Create the export with the
      # status invalid (I) so it wont be handled by the backend before
      # the next step is done on the frontend side
      $sql = "INSERT INTO trackers_export (task_id, artifact, unix_group_name , user_name, `sql`, status, date, frequency_day, frequency_hour) VALUES ('0', '".ARTIFACT."', '".$group_name."', '".user_getname()."', '".addslashes($export_sql)."', 'I', '".$timestamp."', '".$requested_day."', '".$requested_hour."')";
      $result = db_query($sql);
      if (!$result)
	{
	  exit_error(_("SQL insert error"));
	}
      $insert_id =  db_insertid($result);


      form_clean($form_id);

      # Second, create a task to make it easy for other project members
      # to follow the export and for the user to have the export in my items
      # as a task.
      # We could have imagined using a simple task to manage the whole
      # export stuff, but that would be probably overkill for now.
      # Maybe we wil reconsider this later.
      session_redirect($GLOBALS['sys_home']."task/export-createtask.php?group=".rawurlencode($group)."&export_id=$insert_id&from=".ARTIFACT);

    }

  # Delete item
  if (sane_get("delete"))
    {
      $export_id = sane_get("delete");

      # Obtain the relevant task number
      $task_id = db_result(db_query("SELECT task_id FROM trackers_export WHERE export_id='$export_id' LIMIT 1"), 0, 'task_id');

      # Delete the entry
      $result = db_query("DELETE FROM trackers_export WHERE export_id='$export_id' AND user_name='".user_getname()."' LIMIT 1");
      if (db_affected_rows($result))
	{
	  fb(sprintf(_("Export job #%s successfully removed"), $export_id));

	  session_redirect($GLOBALS['sys_home']."task/export-updatetask.php?group=".rawurlencode($group)."&export_id=$export_id&task_id=$task_id&from=".ARTIFACT);

	}
      else
	{
	  fb(sprintf(_("Unable to remove export job #%s"), $export_id), 1);
	}



      # Update the list of current exports
      $sql = "SELECT * FROM trackers_export WHERE user_name='".user_getname()."' AND unix_group_name='".$group_name."' AND status<>'I' ORDER BY export_id ASC";
      $res_export = db_query($sql);
      $export_count = db_numrows($res_export);



    }


}


########################################################################
# Print XHTML page
#
# If we have an export_id : we edit the pending export
# If we have no export_id :
#                        - we provide the list of pending exports
#                        - we provide the form to add a new pending export
#                        if the maximum was of 10 queues was not reached


unset($export_id); # Not implemented
if ($export_id)
{
  # Not implemented

}
else
{
  # allow additional feedback
  if (sane_get("feedback"))
    {  $feedback = sane_get("feedback"); }

  trackers_header(array('title'=>_("Data Export Jobs")));

  print '<p>'._("From here, you can select criteria for an XML export of the items of your project of the current tracker. Then your request will be queued and made available on an HTTP accessible URL. This way you can automate exports, using scripts, as you know the file URL in advance.").'</p>';


  ##
  # List of pending exports
  print '<h3>'.html_anchor(_("Pending Export Jobs"), "pending").'</h3>';

  if ($export_count > 0)
    {
      print $HTML->box_top(_("Queued Jobs"));

      for ($i = 0; $i < $export_count; $i++)
	{
	 if ($i > 0)
	    { print $HTML->box_nextitem(utils_get_alt_row_color($i+1)); }

	 print '<span class="trash">';
	 print utils_link($_SERVER['PHP_SELF'].'?update=1&amp;delete='.db_result($res_export, $i, 'export_id').'&amp;group='.$group_name,
			  '<img src="'.$GLOBALS['sys_home'].'images/'.SV_THEME.'.theme/misc/trash.png" border="0" alt="'._("Remove this job").'" />');
	 print '</span>';

	 $status = _("Pending");
	 if (db_result($res_export, $i, 'status') == 'D')
	   { $status = _("Done"); }

	 print utils_link($GLOBALS['sys_home'].'task/?func=detailitem&amp;item_id='.db_result($res_export, $i, 'task_id'),
			  # I18N
	   		  # The first two strings are export and task id;
	   		  # the last string is the status (pending, done)
			  sprintf(_("Job #%s, bound to task #%s, %s"),
				  db_result($res_export, $i, 'export_id'),
				  db_result($res_export, $i, 'task_id'),
				  $status));


	 $export_url = $GLOBALS['sys_https_url'].$GLOBALS['sys_home']."export/$group_name/".user_getname()."/".db_result($res_export, $i, 'export_id').".xml";
	 print '<br />'.sprintf(_("URL: %s"), utils_link($export_url, $export_url));

	 $type = utils_get_tracker_name(db_result($res_export, $i, 'artifact'));

	 if (db_result($res_export, $i, 'frequency_day'))
	   {
	     # I18N
	     # First string is 'every weekday', second the time of day
	     # Example: "every Wednesday at 16:45 hours"
	     $date = sprintf(_("%s at %s hours"),
			     calendar_every_weekday_name(db_result($res_export, $i, 'frequency_day')),
			     db_result($res_export, $i, 'frequency_hour'));
	   }
	 else
	   {
	     $date = utils_format_date(db_result($res_export, $i, 'date'));
	   }

	 print '<br /><span class="smaller">'.
	   # I18N
	   # First string is the type of export (e.g. recipes, bugs, tasks, ...)
	   # Second string is the date of the export
	   # Example: Exporting recipes on Fri, 2 Dec 2005
	   sprintf(_("Exporting %s on %s"),
		   $type,
		   $date).
	   '</span>';
	}

      print $HTML->box_bottom();

      print '<p>'._("Note that xml files will be removed after 2 weeks or if you remove the job from this list.").'</p>';
    }
  else
    {
      print _("You have no export job pending.");
    }

  ##
  # Query to build an export
  print '<br />';
  print '<h3>'.html_anchor(_("Creating a new Export Job"), "new").'</h3>';

  if ($export_count < $max_export)
    {
      ##
      # Query Form selection
      $report_id = sane_all("report_id");
      if (!$report_id)
	{ $report_id = 100; }
      trackers_report_init($group_id, $report_id);

      $multiple_selection = sane_all("advsrch");
      if ($multiple_selection)
	{
	  $advsrch_1 = ' selected="selected"';
	  # Use is_multiple to provide an array to the later display_field
	  # functions that use that to determine if they need to display
	  # simple or multiple select boxes
	  $is_multiple = array();
	}
      else
	{ $advsrch_0 = ' selected="selected"'; }


      $form .= sprintf(' '._("and %s selection."), '<select name="advsrch"><option value="0"'.$advsrch_0.'>'._("Simple").'</option><option value="1"'.$advsrch_1.'>'._("Multiple").'</option></select>');


      $res_report = trackers_data_get_reports($group_id,user_getid());

      print html_show_displayoptions(sprintf(_("Use the %s Query Form and %s selection for export criteria."),
					     html_build_select_box($res_report,
								   'report_id',
								   $report_id,
								   true,
								   'Basic'),
					     '<select name="advsrch"><option value="0"'.$advsrch_0.'>'._("Simple").'</option><option value="1"'.$advsrch_1.'>'._("Multiple").'</option></select>'),
				     form_header($_SERVER['PHP_SELF'].'#new', '').form_input("hidden", "group", $group_name),
				     form_submit(_("Apply")));

      ##
      # Display criteria
      print form_header($_SERVER['PHP_SELF'], '');
      print form_input("hidden", "group", $group_name);
      print form_input("hidden", "create", "1");
      $current_time = time();
      print form_input("hidden", "current_time", $current_time);

      print '<span class="preinput">'._("Export criteria:").'</span><br />';

      # FIXME: for some reasons, this does not show up on the cookbook

      #################### GRABBED FROM BROWSE
      # This should probably included in functions
      $ib=0;
      $is=0;
      $fields_per_line=5;
      $load_cal=false;

# Check if summary and original submission are criteria
      $summary_search = 0;
      $details_search = 0;

      while ($field = trackers_list_all_fields(cmp_place_query))
	{
# Skip unused field
	  if (!trackers_data_is_used($field))
	    { continue; }

# Skip fields not part of this query form
	  if (!trackers_data_is_showed_on_query($field))
	    { continue; }

# beginning of a new row
	  if ($ib % $fields_per_line == 0)
	    {
	      $align = ($printer ? "left" : "center");
	      $labels .= "\n".'<tr align="'.$align.'" valign="top">';
	      $boxes .= "\n".'<tr align="'.$align.'" valign="top">';
	    }

	  $labels .= '<td>'.trackers_field_label_display($field,$group_id,false,false).'</td>';
	  $boxes .= '<td><span class="smaller">';

	  if (trackers_data_is_select_box($field))
	    {
	      unset($value);
	      if (isset($is_multiple))
		{ $value = array(); }

	      # For Open/Closed, automatically select Open
	      if ($field == 'status_id')
		{
		  if (isset($is_multiple))
		    { $value = array(1); }
		  else
		    { $value = 1; }
		}

	      $boxes .=
		trackers_field_display($field,$group_id,$value,false,false,($printer?true:false),false,true,'None', true,'Any');

	    }
	  elseif (trackers_data_is_date_field($field))
	    {

	      if ($advsrch)
		{
		  $boxes .= trackers_multiple_field_date($field,$is_multiple,
							 $url_params[$field.'_end'][0],0,0,$printer);
		}
	      else
		{
		  $boxes .= trackers_field_date_operator($field,$is_multiple,$printer).
		    trackers_field_date($field,$url_params[$field][0],0,0,$printer);
		}

	    }
	  elseif (trackers_data_is_text_field($field) ||trackers_data_is_text_area($field))
	    {
	      if ($field == 'summary')
		{ $summary_search = 1; }
	      if ($field == 'details')
		{ $details_search = 1; }

	      $boxes .=
		($printer ? $url_params[$field][0] : trackers_field_text($field,$url_params[$field][0],15,80)) ;
	    }

	  $boxes .= "</span></td>\n";

	  $ib++;

# end of this row
	  if ($ib % $fields_per_line == 0)
	    {
	      $html_select .= $labels.'</tr>'.$boxes.'</tr>';
	      $labels = $boxes = '';
	    }

	}

# Make sure the last few cells are in the table
      if ($labels)
	{
	  $html_select .= $labels.'</tr>'.$boxes.'</tr>';
	}

      # [...]
      print '<table cellpadding="0" cellspacing="5">
        <tr><td colspan="'.$fields_per_line.'" nowrap="nowrap">';
      print $html_select;
      print '</table>';

      #################### GRABBED FROM BROWSE


      ##
      # Time selection
      print '<br />';
      print '<span class="preinput">'._("The export should be generated:").'</span><br />';

      # ASAP case
      print '&nbsp;&nbsp;&nbsp;'.form_input("radio", "date_mainchoice", "asap\" checked=\"checked").' '._("as soon as possible").'<br />';

      # Today at xHour
      # (this wont be ready for alternative calendars, but currently the
      # priority is to have this working)
      $timezone = strftime('%Z', $current_time);
      $current_hour = strftime('%H', $current_time);
      $current_day = strftime('%d', $current_time);
      $current_month = strftime('%m', $current_time);

      $valid_hours = array();
      for ($hour = 0; $hour <= 24; $hour++)
	{ $valid_hours[] = $hour; }

      $valid_days = array();
      $valid_days = array(_("Today"), _("Tomorrow"));
      unset($count);
      for ($day = ($current_day+2); $count <= 31; $day++)
	{
	  $count++;
	  $day_time = mktime(0, 0, 0, $current_month, $day);
	  # use format minimal because the hour is meaningless here
	  $valid_days[] = utils_format_date($day_time, 'minimal');
	}

      $valid_hours = array();
      for ($hour = 0; $hour <= 23; $hour++)
	{ $valid_hours[] = $hour; }

      print '&nbsp;&nbsp;&nbsp;'.form_input("radio", "date_mainchoice", "next").' '.
        # I18N
	# First %s: the day (e.g. today, tomorrow, Fri 2. Dec 2005, ...)
	# Second %s: the time (e.g. 16:37)
	# Third %s: the timezone (e.g. GMT)
	# Note that the GMT string may not be set, so dont put it beween 
	# parenthesis.
	sprintf(_("%s at %s hours %s"),
		html_build_select_box_from_array($valid_days,
						 "date_next_day"),
		html_build_select_box_from_array($valid_hours,
						 "date_next_hour",
						 ($current_hour+1)),
		$timezone).'<br />';


      # Next/Every xDay at xHour
      # (this wont be ready for alternative calendars, but currently the
      # priority is to have this working)
      $valid_days = array();
      for ($day = 1; $day <= 7; $day++)
	{ $valid_days[] = calendar_every_weekday_name($day); }

      print '&nbsp;&nbsp;&nbsp;'.form_input("radio", "date_mainchoice", "frequent").' '.
        # I18N
        # First string is 'every weekday', second the time of day,
	# third is the timezone
        # Example: "every Wednesday at 16:45 hours GMT"
	# Note that the GMT string may not be set, so dont put it beween 
	# parenthesis.
	sprintf(_("%s at %s hours %s"),
		html_build_select_box_from_array($valid_days,
						 "date_frequent_day"),
		html_build_select_box_from_array($valid_hours,
						 "date_frequent_hour",
						 ($current_hour+1)),
		$timezone
	).'<br />';


      print '<p align="center">'.form_submit().'</p>';
    }
  else
    {
      print sprintf(ngettext("You have already registered %s export job for this project, which is the current limit. If more exports are required ask other project members.", "You have already registered %s export jobs for this project, which is the current limit. If more exports are required ask other project members.", $max_export), $max_export);
    }

  trackers_footer(array());
}



?>