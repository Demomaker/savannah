<?php
# Database access wrappers, with quoting/escaping.
#
# Copyright (C) 1999-2000  The SourceForge Crew
# Copyright (C) 2004-2005  Elfyn McBratney <elfyn--emcb.co.uk>
# Copyright (C) 2004-2005  Mathieu Roy <yeupou--gnu.org>
# Copyright (C) 2000-2006  John Lim (ADOdb)
# Copyright (C) 2007  Cliss XXI (GCourrier)
# Copyright (C) 2006, 2007  Sylvain Beucler
# Copyright (C) 2017, 2019, 2020 Ineiev
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

define('DB_AUTOQUERY_INSERT', 1);
define('DB_AUTOQUERY_UPDATE', 2);

function db_connect()
{
  global $sys_dbhost,$sys_dbuser,$sys_dbpasswd,$conn,$sys_dbname;
  global $have_mysqli, $mysql_conn;

  $mysql_conn = NULL;
  $have_mysqli = false;

  # Test the presence of php-mysql - you get a puzzling blank page
  # when it's not installed.
  if (extension_loaded('mysqli'))
    $have_mysqli = true;
  elseif (!extension_loaded('mysql'))
    {
      echo "Please install the MySQL extension for PHP:
    <ul>
      <li>Debian-based: <code>aptitude install php4-mysql</code>
        or <code>aptitude install php5-mysql</code></li>
      <li>Fedora Core: <code>yum install php-mysql</code></li>
    </ul>";
      echo "Check the <a href='"
           .$GLOBALS['sys_url_topdir']."/testconfig.php'>configuration
          page</a> and the <a href='http://php.net/mysql'>PHP website</a> for
          more information.<br />";
      echo "Once the extension is installed, please restart Apache.";
      exit;
    }

  if ($have_mysqli)
    $conn = mysqli_connect ($sys_dbhost, $sys_dbuser, $sys_dbpasswd, $sys_dbname);
  else
    {
       $conn = @mysql_connect($sys_dbhost,$sys_dbuser,$sys_dbpasswd);
       if ($conn)
         if (!mysql_select_db($sys_dbname, $conn))
           $conn = false;
    }
  if (!$conn)
    {
      echo "Failed to connect to database: ";
      if ($have_mysqli)
        echo mysqli_connect_error();
      else
        echo db_error();
      echo "<br />\nPlease contact as soon as possible server administrators "
            .$GLOBALS['sys_email_adress'].".<br />\n";
      echo "Until this problem get fixed, you will not be able to use this site.";
      exit;
    }

  if ($have_mysqli)
    {
      mysqli_set_charset ($conn, 'utf8');
      $mysql_conn = $conn;
      return true;
    }
  if (version_compare(PHP_VERSION, '5.2.3', '>='))
    {
      mysql_set_charset('utf8', $conn);
      return true;
    }
  # Not available in Etch (5.2.0 < 5.2.3...) - using a work-around meanwhile.
  # Apparently this means mysql_real_escape_string() isn't aware of the charset,
  # hence why this isn't recommended.
  mysql_query('SET NAMES utf8');
  return true;
}

function db_real_escape_string ($string)
{
  global $have_mysqli, $mysql_conn;

  if ($have_mysqli)
    return mysqli_real_escape_string ($mysql_conn, $string);

  return mysql_real_escape_string ($string);
}

# sprinf-like function to auto-escape SQL strings.
# db_query_escape("SELECT * FROM user WHERE user_name='%s'", $_GET['myuser']);
function db_query_escape()
{
  $num_args = func_num_args();
  if ($num_args < 1)
    util_die(_("db_query_escape: Missing parameter"));
  $args = func_get_args();

  # Escape all params except the query itself.
  for ($i = 1; $i < $num_args; $i++)
    $args[$i] = db_real_escape_string($args[$i]);

  $query = call_user_func_array('sprintf', $args);
  return db_query($query);
}

# Substitute '?' with one of the values in the $inputarr array,
# properly escaped for inclusion in an SQL query.
function db_variable_binding($sql, $inputarr=null)
{
  $sql_expanded = $sql;

  if (!$inputarr)
    return $sql_expanded;

  if (!is_array($inputarr))
    util_die("db_variable_binding: \$inputarr is not an array. Query is: <code>"
        . htmlspecialchars($sql) . "</code>, \$inputarr is <code>"
        . print_r($inputarr, 1) . "</code>");

  $sql_exploded = explode('?', $sql);

  $i = 0;
  $sql_expanded = '';

  foreach($inputarr as $v)
    {
      $sql_expanded .= $sql_exploded[$i];
      # From Ron Baldwin <ron.baldwin#sourceprose.com>.
      # Only quote string types.
      $typ = gettype($v);
      if ($typ == 'string')
        $sql_expanded .= "'" . db_real_escape_string($v) . "'";
      elseif ($typ == 'double')
        # Locale fix so 1.1 doesn't get converted to 1,1.
        $sql_expanded .= str_replace(',','.',$v);
      elseif ($typ == 'boolean')
        $sql_expanded .= $v ? '1' : '0';
      elseif ($typ == 'object')
        util_die("Don't use db_execute with objects.");
      elseif ($v === null)
        $sql_expanded .= 'NULL';
      else
        $sql_expanded .= $v;
      $i += 1;
    }

  $match = true;
  if (isset($sql_exploded[$i]))
    {
      $sql_expanded .= $sql_exploded[$i];
      if ($i+1 != sizeof($sql_exploded))
        $match = false;
    }
  else
    $match = false;
  if (!$match)
    util_die("db_variable_binding: input array does not match query: <pre>"
         .htmlspecialchars($sql)
         ."<br />"
         .print_r($inputarr, true));
  return $sql_expanded;
}

/* Like ADOConnection->AutoExecute, without ignoring non-existing
 fields (you'll get a nice mysql_error() instead) and with a modified
 argument list to allow variable binding in the where clause.

This allows hopefully more reable lengthy INSERT and UPDATE queries.

Check http://phplens.com/adodb/reference.functions.getupdatesql.html ,
http://phplens.com/adodb/tutorial.generating.update.and.insert.sql.html
and adodb.inc.php.

E.g.:

$success = db_autoexecute('user', array('realname' => $newvalue),
                          DB_AUTOQUERY_UPDATE,
                          "user_id=?", array(user_getid())); */
function db_autoexecute($table, $dict, $mode=DB_AUTOQUERY_INSERT,
                        $where_condition=false, $where_inputarr=null)
{
  # Table name validation and quoting.
  $tables = preg_split('/[\s,]+/', $table);
  $tables_string = '';
  $first = true;
  foreach ($tables as $table)
    {
      if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]+$/', $table))
        util_die("db_autoexecute: invalid table name: "
                 .htmlspecialchars($table));
      if ($first)
        {
          $tables_string = "`$table`";
          $first = false;
        }
      else
        {
          $tables_string .= ",`$table`";
        }
    }

  switch((string) $mode)
    {
    case 'INSERT':
    case '1':
    # Quote fields to avoid problem with reserved words (bug #8898@gna).
    # TODO: do connections with ANSI_QUOTES mode and use the standard
    # "'" field delimiter.
      $first = true;
      foreach (array_keys($dict) as $field)
        {
          if ($first)
            {
              $fields = "`$field`";
              $first = false;
            }
          else
            $fields .= ",`$field`";
        }
      # $fields = `date`,`summary`,...
      $question_marks = implode(',', array_fill(0, count($dict), '?')); // ?,?,...
      return db_execute("INSERT INTO $tables_string ($fields) "
                         ."VALUES ($question_marks)", array_values($dict));
      break;
    case 'UPDATE':
    case '2':
      $sql_fields = '';
      $values = array();

      foreach ($dict as $field => $value)
        {
          $sql_fields .= "`$field`=?,";
          $values[] = $value;
        }
      $sql_fields = rtrim($sql_fields, ',');
      $values = array_merge($values, $where_inputarr);
      $where_sql = $where_condition ? "WHERE $where_condition" : '';
      return db_execute("UPDATE $tables_string SET $sql_fields $where_sql",
                        $values);
      break;
    default:
    }
  util_die("db_autoexecute: unknown mode=$mode");
}

/* Like ADOConnection->Execute, with variables binding emulation for
MySQL, but simpler (not 2D-array, namely). Example:

db_execute("SELECT * FROM utilisateur WHERE name=?", array("Gogol d'Algol"));

'db_autoexecute' replaces '?' with the matching parameter, taking its
type into account (int -> int, string -> quoted string, float ->
canonical representation, etc.)

Check http://phplens.com/adodb/reference.functions.execute.html and
adodb.inc.php. */
function db_execute($sql, $inputarr=null)
{
  $expanded_sql = db_variable_binding($sql, $inputarr);
  return db_query($expanded_sql);
}

function db_query($qstring,$print=0)
{
  global $have_mysqli, $mysql_conn;

  # Store query for recap display.
  if ($GLOBALS['sys_debug_on'])
    {
      $GLOBALS['debug_query_count']++;
      $backtrace = debug_backtrace();
      $outside = null;
      foreach ($backtrace as $step)
        {
          if ($step['file'] != __FILE__)
            {
              $outside = $step;
              break;
            }
        }
      # Strip installation prefix.
      $relative_path = str_replace($GLOBALS['sys_www_topdir'].'/',
                                   '', $outside['file']);
      $location = "$relative_path:{$outside['line']}";
      array_push($GLOBALS['debug_queries'], array($qstring, $location));
    }

  if ($GLOBALS['sys_debug_sqlprofiler'] && extension_loaded('XCache'))
    {
      $backtrace = debug_backtrace();
      $outside = null;
      foreach ($backtrace as $step)
        {
          if ($step['file'] != __FILE__)
            {
              $outside = $step;
              break;
            }
        }
      # Strip installation prefix.
      $relative_path = str_replace($GLOBALS['sys_www_topdir'].'/', '',
                                   $outside['file']);
      $location = "$relative_path:{$outside['line']}";
      xcache_inc($location);
    }

  if ($print)
    {
      print "<pre>[";
      print_r($qstring);
      print "]</pre>";
    }

  if ($have_mysqli)
    $GLOBALS['db_qhandle'] = mysqli_query ($mysql_conn, $qstring);
  else
    $GLOBALS['db_qhandle'] = mysql_query ($qstring);
  if (!$GLOBALS['db_qhandle'])
    {
      util_die('db_query: SQL query error ' .
               '<em>' . db_error() . '</em> in ['
               . htmlspecialchars($qstring) . ']');
    }
  return $GLOBALS['db_qhandle'];
}

function db_numrows($qhandle)
{
  global $have_mysqli;

  if (!$qhandle)
    return 0;

  if ($have_mysqli)
    return mysqli_num_rows ($qhandle);

  return mysql_numrows($qhandle);
}

function db_free_result($qhandle)
{
  global $have_mysqli;

  if ($have_mysqli)
    return mysqli_free_result ($qhandle);

  return mysql_free_result($qhandle);
}

function db_result($qhandle,$row,$field)
{
  global $have_mysqli;

  if (!$have_mysqli)
    return mysql_result($qhandle,$row,$field);

  if (!mysqli_data_seek ($qhandle, $row))
    return NULL;

  $row_data = mysqli_fetch_row ($qhandle);
  if ($row_data === false)
    return NULL;

  $field_num = mysqli_num_fields ($qhandle);
  if (gettype ($field) == 'integer')
    {
      if ($field >= $field_num)
        return NULL;
      return $row_data [$field];
    }

  $fields = mysqli_fetch_fields ($qhandle);
  if ($fields === false)
    return NULL;
  for ($i = 0; $i < $field_num; $i++)
    if ($fields[$i]->name == $field)
      return $row_data[$i];

  return NULL;
}

function db_numfields($lhandle)
{
  global $have_mysqli;

  if ($have_mysqli)
    return mysqli_num_fields ($lhandle);
  return mysql_numfields($lhandle);
}

function db_fieldname($lhandle,$fnumber)
{
  global $have_mysqli;

  if ($have_mysqli)
    return mysqli_fetch_field_direct ($lhandle, $fnumber)->name;
  return mysql_field_name($lhandle,$fnumber);
}

function db_affected_rows($qhandle)
{
  global $have_mysqli, $mysql_conn;

  if ($have_mysqli)
    return mysqli_affected_rows ($mysql_conn);
  return mysql_affected_rows();
}

function db_fetch_array($qhandle = 0)
{
  global $have_mysqli;

  if ($have_mysqli)
    {
      if ($qhandle)
        return mysqli_fetch_array($qhandle);
      if (isset($GLOBALS['db_qhandle']))
        return mysqli_fetch_array($GLOBALS['db_qhandle']);
      return (array());
    }

  if ($qhandle)
    return mysql_fetch_array($qhandle);
  if (isset($GLOBALS['db_qhandle']))
    return mysql_fetch_array($GLOBALS['db_qhandle']);
  return (array());
}

function db_insertid($qhandle)
{
  global $have_mysqli, $mysql_conn;

  if ($have_mysqli)
    return mysqli_insert_id ($mysql_conn);
  return mysql_insert_id();
}

function db_error()
{
  global $have_mysqli, $mysql_conn;

  if ($have_mysqli)
    return mysqli_error ($mysql_conn);
  return mysql_error();
}

# Return an sql insert command taking in input a qhandle:
# it is supposed to ease copy a a row into another, ignoring the autoincrement
# field + replacing another field value (like group_id).
function db_createinsertinto ($result, $table, $row, $autoincrement_fieldname,
                              $replace_fieldname='zxry', $replace_value='axa')
{
  $fields = array();
  for ($i = 0; $i < db_numfields($result); $i++)
    {
      $fieldname = db_fieldname($result, $i);
      # Create the sql by ignoring the autoincremental id.
      if ($fieldname != $autoincrement_fieldname)
        {
          // If the value is empty
          if (db_result($result, $row, $fieldname) != NULL)
            {
              // Replace another field
              if ($fieldname == $replace_fieldname)
                {
                  $fields[$fieldname] = $replace_value;
                }
              else
                {
                  $fields[$fieldname] = db_result($result, $row, $fieldname);
                }
            }
        }
    }
  # No fields? Ignore.
  if (count($fields) == 0)
    return 0;
  return db_autoexecute($table, $fields, DB_AUTOQUERY_INSERT);
}
?>
