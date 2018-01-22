<?php
/*
 *  webpa-lti - WebPA module to add LTI support
 *  Copyright (C) 2013  Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Contact: stephen@spvsoftwareproducts.com
 *
 *  Version history:
 *    1.0.00   4-Jul-12  Initial release
 *    1.1.00  10-Feb-13  Added check for modules before changing sources
*/

###
###  Page to allow the active LTI source to be changed
###

  require_once("../../../includes/inc_global.php");

#
### Option only available for administrators
#
  if (!check_user($_user, APP__USER_TYPE_ADMIN)){
    header('Location:'. APP__WWW .'/logout.php?msg=denied');
    exit;
  }
#
### Check is a source has been selected
#
  $sScreenMsg = '';
  $source_id = fetch_POST('source_id', NULL);
  if (!is_null($source_id)) {
    $modules = $CIS->get_user_modules(NULL, NULL, 'name', $source_id);
    if (is_array($modules)) {
      $_SESSION['_source_id'] = $source_id;
      $_SESSION['_user_source_id'] = $source_id;

      $ids = array_keys($modules);
      $_SESSION['_module_id'] = $ids[0];
      $_SESSION['_user_context_id'] = $modules[$ids[0]]['module_code'];

      header('Location: ' . APP__WWW . "/");
      exit;
    } else {
      $sScreenMsg = "No modules exist for this source";
    }
  }
#
### Set the page information
#
  $UI->page_title = 'Change Source';
  $UI->menu_selected = 'change source';
  $UI->breadcrumbs = array ('home' => '../../../', 'change source' => null);
  $UI->help_link = '?q=node/237';
  $page_intro = 'Use this page to change the currently selected source.';
#
### Display page
#
  $UI->head();
  $UI->body();
  $UI->content_start();
?>

<p><?php echo $page_intro; ?></p>

<div class="content_box">
<?php
  if(!empty($sScreenMsg)){
    echo "  <div class=\"success_box\">{$sScreenMsg}</div>\n";
  }
?>
  <form action="" method="post" name="select_source">
  <table class="option_list" style="width: 100%;">
<?php
#
### Get list of sources
#
  $tool_provider = new LTI_Tool_Provider(NULL, APP__DB_TABLE_PREFIX);
  $sources = $tool_provider->getConsumers();
#
### Display table of sources
#
  if (count($sources) > 0) {
    $checked_str = (isset($_source_id) && ($_source_id == '')) ? ' checked="checked"' : '' ;
    echo("  <tr>\n");
    echo("    <td><input type=\"radio\" name=\"source_id\" id=\"source\" value=\"\"{$checked_str} /></td>\n");
    echo("    <td><label style=\"font-weight: normal;\" for=\"source_\">&lt;" . APP__NAME . "&gt;</label></td>\n");
    echo("  </tr>\n");
    $i = 0;
    foreach ($sources as $source) {
      $i++;
      $checked_str = (isset($_source_id) && ($_source_id == $source->getKey())) ? ' checked="checked"' : '' ;
      echo("  <tr>\n");
      echo("    <td><input type=\"radio\" name=\"source_id\" id=\"source_{$i}\" value=\"{$source->getKey()}\"{$checked_str} /></td>\n");
      echo("    <td><label style=\"font-weight: normal;\" for=\"source_{$i}\">");
      echo("{$source->name} ({$source->getKey()})");
      echo("</label></td>\n  </tr>\n");
    }
  } else {
    echo("<tr>\n");
    echo("  <td colspan=\"2\">No sources</td>\n");
    echo("</tr>\n");
  }
?>
  </table>
<?php
  if (count($sources) > 0) {
?>
<p>
<input type="submit" value="Select source" />
</p>
<?php
  }
?>
  </form>
</div>
<?php
  $UI->content_end();
?>