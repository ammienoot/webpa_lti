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
 *    1.1.00  10-Feb-13  Updated for LTI_Tool_Provider 2.3 class
 *                       Renamed "upload data" option to "sync data"
 *                       Added group synchronisation option
*/

###
###  Page to update list of enrolled users
###

  require_once('../../../../includes/inc_global.php');
  require_once(DOC__ROOT . 'includes/functions/lib_string_functions.php');

  function __autoload($class_name) {
    include_once('../../lib/LTI_Tool_Provider.php');
  }

#
### Option only available for tutors
#
  if (!check_user($_user, APP__USER_TYPE_TUTOR)){
    header('Location:'. APP__WWW .'/logout.php?msg=denied');
    exit;
  }
#
### Set the page information
#
  $UI->page_title = APP__NAME . " synchronise data";
  $UI->menu_selected = 'sync data';
  $UI->breadcrumbs = array ('home' => '../../../../admin/', 'sync data'=>null);
  $UI->help_link = '?q=node/237';
#
### Display page
#
  $UI->head();
  $UI->body();
  $UI->content_start();
?>
<div class="content_box">
<?php
#
### Check the LTI resource link supports the memberships service
#
  $consumer = new LTI_Tool_Consumer($_user_source_id, APP__DB_TABLE_PREFIX);
  $resource_link = new LTI_Resource_Link($consumer, $_SESSION['_user_context_id']);
  if ($resource_link->hasMembershipsService()) {
    $group_handler = new GroupHandler();
/*
    Structure for mapping Tool Consumer IDs to WebPA IDs for groups
      [tc_set_id] - pa_set_id, groups(tc_group_id, pa_group_id)
*/
    $settings = unserialize($resource_link->getSetting('ext_ims_lti_tool_setting'));
    $groups_map = $settings['groups_map'];
    if (is_null($groups_map)) {
      $groups_map = array();
    }
    $group_changes = array();
#
### Remove any WebPA IDs in map which no longer exist
#
    $collections = $group_handler->get_module_collections($_module_id);
    foreach ($groups_map as $set_id => $set) {
      $exists = FALSE;
      foreach ($collections as $collection) {
        if ($collection['collection_id'] == $set['id']) {
          $exists = TRUE;
          break;
        }
      }
      if (!$exists) {
        unset($groups_map[$set_id]);
      } else {
        $collection = $group_handler->get_collection($set['id']);
        $groups = $collection->get_groups_array();
        foreach ($set['groups'] as $tc_group_id => $pa_group_id) {
          $exists = FALSE;
          foreach ($groups as $pa_group) {
            if ($pa_group['group_id'] == $pa_group_id) {
              $exists = TRUE;
              break;
            }
          }
          if (!$exists) {
            unset($groups_map[$set_id]['groups'][$tc_group_id]);
          }
        }
      }
    }
#
### Check if update has been confirmed
#
    if (fetch_POST('do')) {
      if (isset($_SESSION['_group_changes'])) {
        $group_changes = $_SESSION['_group_changes'];
      }
#
### Check for users to add
#
      if (isset($_SESSION['_to_add'])) {
        $users = $_SESSION['_to_add'];
        foreach ($users as $user) {
          $new_user = new User();
          $user_row = $CIS->get_user_for_username($user->getId(LTI_Tool_Provider::ID_SCOPE_ID_ONLY), $_user_source_id);
          if ($user_row) {
            $exists = TRUE;
            $new_user->load_from_row($user_row);
          } else {
            $exists = FALSE;
            $new_user->update_username($user->getId(LTI_Tool_Provider::ID_SCOPE_ID_ONLY));
            $new_user->update_source_id($_user_source_id);
          }
          $new_user->forename = $user->firstname;
          $new_user->lastname = $user->lastname;
          $new_user->email = $user->email;
          $new_user->update_password(md5(str_random()));
          $new_user->set_dao_object($DB);
          if ($exists) {
            $new_user->save_user();
            $user_id = $new_user->id;
          } else {
            $user_id = $new_user->add_user();
          }
// Set role in module
          if ($user->isStaff()) {
            $role = APP__USER_TYPE_TUTOR;
          } else {
            $role = APP__USER_TYPE_STUDENT;
          }
          $sql = 'INSERT INTO ' . APP__DB_TABLE_PREFIX . 'user_module (user_id, module_id, user_type) ' .
                 "VALUES ({$user_id}, {$_module_id}, '{$role}')";
          $DB->execute($sql);
// Update any pending group memberships with user ID
          if (isset($group_changes['member']['add'])) {
            for ($i = 0; $i < count($group_changes['member']['add']); $i++) {
              if ($group_changes['member']['add'][$i]['tc_user_id'] == $user->getId()) {
                $group_changes['member']['add'][$i]['pa_user_id'] = $user_id;
              }
            }
          }
        }
        unset($_SESSION['_to_add']);
      }
#
### Check for users to update
#
      if (isset($_SESSION['_to_update'])) {
        $users = $_SESSION['_to_update'];
        foreach ($users as $id => $user) {
          $user_row = $CIS->get_user($id);
          $edit_user = new User();
          $edit_user->load_from_row($user_row);
          $edit_user->forename = $user->firstname;
          $edit_user->lastname = $user->lastname;
          $edit_user->email = $user->email;
          $edit_user->set_dao_object($DB);
          $edit_user->save_user();
        }
        unset($_SESSION['_to_update']);
      }
#
### Check for users with a new role
#
      if (isset($_SESSION['_to_update_role'])) {
        $users = $_SESSION['_to_update_role'];
        foreach ($users as $id => $user) {
          if ($user->isStaff()) {
            $role = APP__USER_TYPE_TUTOR;
          } else {
            $role = APP__USER_TYPE_STUDENT;
          }
          $sql = 'UPDATE ' . APP__DB_TABLE_PREFIX . "user_module SET user_type = '{$role}' " .
                 "WHERE (user_id = {$id}) AND (module_id = {$_module_id})";
          $DB->execute($sql);
        }
        unset($_SESSION['_to_update_role']);
      }
#
### Check for users to unenrol
#
      if (isset($_SESSION['_to_delete'])) {
        if (strpos(fetch_POST('do'), 'without') === FALSE) {
          $users = $_SESSION['_to_delete'];
          foreach ($users as $user) {
            $sql = "DELETE FROM " . APP__DB_TABLE_PREFIX . "user_module WHERE user_id = {$user['user_id']} " .
                   "AND module_id = {$_module_id}";
            $DB->execute($sql);
          }
        }
        unset($_SESSION['_to_delete']);
      }
#
### Check for group changes
#
      if (isset($group_changes['set']['delete'])) {
        foreach ($group_changes['set']['delete'] as $set_id) {
          $collection = $group_handler->get_collection($groups_map[$set_id]['id']);
          $collection->delete();
          unset($groups_map[$set_id]);
        }
      }
      if (isset($group_changes['set']['add'])) {
        foreach ($group_changes['set']['add'] as $set) {
          if (isset($_REQUEST["add_{$set['id']}"])) {
            $collection = $group_handler->create_collection();
            $collection->module_id = $_module_id;
            $collection->name = $set['title'];
            $collection->save();
            $groups_map[$set['id']] = array('id' => $collection->id, 'groups' => array());
          }
        }
      }
      if (isset($group_changes['set']['update'])) {
        foreach ($group_changes['set']['update'] as $set) {
          $collection = $group_handler->get_collection($groups_map[$set['id']]['id']);
          if ($set['title'] != $collection->name) {
            $collection->name = $set['title'];
            $collection->save();
          }
        }
      }
      if (isset($group_changes['group']['delete'])) {
        foreach ($group_changes['group']['delete'] as $group) {
          $collection = $group_handler->get_collection($groups_map[$group['set_id']]['id']);
          $pa_group = $collection->get_group_object($group['id']);
          $pa_group->delete();
          foreach ($groups_map[$group['set_id']]['groups'] as $id => $pa_group_id) {
            if ($pa_group_id == $group['id']) {
              unset($groups_map[$group['set_id']]['groups'][$id]);
              break;
            }
          }
        }
      }
      if (isset($group_changes['group']['add'])) {
        foreach ($group_changes['group']['add'] as $group) {
          if (isset($groups_map[$group['set_id']])) {  // check group is not for a new group set which was not selected for sync
            $collection = $group_handler->get_collection($groups_map[$group['set_id']]['id']);
            if ($collection) {
              $pa_group = $collection->new_group($group['title']);
              $pa_group->save();
              $groups_map[$group['set_id']]['groups'][$group['id']] = $pa_group->id;
            }
          }
        }
      }
      if (isset($group_changes['group']['update'])) {
        foreach ($group_changes['group']['update'] as $group) {
          $collection = $group_handler->get_collection($groups_map[$group['set_id']]['id']);
          $pa_group = $collection->get_group_object($groups_map[$group['set_id']]['groups'][$group['id']]);
          $pa_group->name = $group['title'];
          $pa_group->save();
        }
      }
      if (isset($group_changes['member']['delete'])) {
        foreach ($group_changes['member']['delete'] as $member) {
         $DB->execute("DELETE FROM " . APP__DB_TABLE_PREFIX . "user_group_member WHERE (user_id = '{$member['pa_user_id']}') AND " .
              "(group_id = '{$member['pa_group_id']}')");
        }
      }
      if (isset($group_changes['member']['add'])) {
        foreach ($group_changes['member']['add'] as $member) {
          if (isset($groups_map[$member['set_id']])) {  // check group is not for a new group set was not selected for sync
            $DB->do_insert('INSERT INTO ' . APP__DB_TABLE_PREFIX . "user_group_member ({fields}) VALUES ({values})",
               array('user_id' => $member['pa_user_id'], 'group_id' => $groups_map[$member['set_id']]['groups'][$member['group_id']]));
          }
        }
      }
      $settings['groups_map'] = $groups_map;
#
### Update time of synchronisation
#
      $date = date('j F Y \a\t g:i a');
      $settings['members'] = $date;
      $resource_link->doSettingService(LTI_Resource_Link::EXT_WRITE, serialize($settings));

      echo "<div class=\"success_box\">Updates completed.</div>\n";

    } else if (fetch_POST('continue')) {
#
### Fetch latest enrolment list from source
#
      $consumer_instance->defaultEmail = DEFAULT_EMAIL;
      $members = $resource_link->doMembershipsService(TRUE);
      if ($members !== FALSE) {
        $query = 'SELECT u.username, u.user_id, u.forename, u.lastname, u.email, um.user_type FROM ' .
                 APP__DB_TABLE_PREFIX . 'user u INNER JOIN ' . APP__DB_TABLE_PREFIX . 'user_module um ON u.user_id = um.user_id ' .
                 "WHERE (um.module_id = {$_module_id}) AND (source_id = '{$_user_source_id}')";
        $users = $DB->fetch_assoc($query);
#
### Check for new, changed and deleted group sets
#
/*
  Structure for capturing changes to group set data:
    ['set']
      ['add'] - id, title
      ['update'] - id, title
      ['delete'] - id
    ['group']
      ['add'] - set_id, id, title
      ['update'] - set_id, id, title
      ['delete'] - set_id, id
    ['member']
      ['add'] - set_id, group_id, tc_user_id, pa_user_id
      ['delete'] - set_id, pa_group_id, pa_user_id
*/
        $group_changes['set']['delete'] = array_keys($groups_map);

        foreach ($resource_link->group_sets as $set_id => $set) {
          if (!isset($groups_map[$set_id])) {
            $group_changes['set']['add'][] = array('id' => $set_id, 'title' => $set['title']);
            foreach ($set['groups'] as $group_id) {
              $group_changes['group']['add'][] = array('set_id' => $set_id, 'id' => $group_id, 'title' => $resource_link->groups[$group_id]['title']);
            }
            foreach ($members as $user) {
              $user_id = NULL;
              if (isset($users[$user->getId()])) {
                $user_id = $users[$user->getId()]['user_id'];
              }
              foreach ($user->groups as $group_id) {
                if (in_array($group_id, $set['groups']) && $user->isLearner()) {
                  $group_changes['member']['add'][] = array('set_id' => $set_id, 'group_id' => $group_id, 'tc_user_id' => $user->getId(), 'pa_user_id' => $user_id);
                }
              }
            }
          } else if (!empty($groups_map[$set_id])) {
            $current_groups = $resource_link->group_sets[$set_id]['groups'];
            $collection = $group_handler->get_collection($groups_map[$set_id]['id']);
            if ($collection->name != $set['title']) {
              $group_changes['set']['update'][] = array('id' => $set_id, 'title' => $set['title']);
            }
            $pa_groups = $collection->get_groups_array();
            foreach ($groups_map[$set_id]['groups'] as $tc_group_id => $pa_group_id) {
              $i = 0;
              foreach ($pa_groups as $pa_group) {
                if ($pa_group['group_id'] == $pa_group_id) {
                  if (isset($resource_link->groups[$tc_group_id]) && ($pa_group['group_name'] != $resource_link->groups[$tc_group_id]['title'])) {
                    $group_changes['group']['update'][] = array('set_id' => $set_id, 'id' => $tc_group_id, 'title' => $resource_link->groups[$tc_group_id]['title']);
                  }
                  unset($current_groups[array_search($tc_group_id, $current_groups)]);
                  unset($pa_groups[$i]);
                  $pa_groups = array_values($pa_groups);
                  break;
                }
                $i++;
              }
            }
            foreach ($current_groups as $group_id) {
              $group_changes['group']['add'][] = array('set_id' => $set_id, 'id' => $group_id, 'title' => $resource_link->groups[$group_id]['title']);
            }
            foreach ($pa_groups as $pa_group) {
              $group_changes['group']['delete'][] = array('set_id' => $set_id, 'id' => $pa_group['group_id']);
            }
// check memberships
            $collection_member_rows = $collection->get_member_rows();
            foreach ($members as $user) {
              if ($user->isLearner()) {
                $user_id = NULL;
                if (isset($users[$user->getId()])) {
                  $user_id = $users[$user->getId()]['user_id'];
                }
                foreach ($user->groups as $group) {
                  if (isset($resource_link->groups[$group]['set'])) {
                    $in_group = NULL;
                    if (!is_null($user_id) && isset($groups_map[$set_id]['groups'][$group]) && is_array($collection_member_rows)) {
                      $pa_group_id = $groups_map[$set_id]['groups'][$group];
                      $i = 0;
                      foreach ($collection_member_rows as $row) {
                        if ($row['user_id'] == $user_id) {
                          $in_group = $row['group_id'];
                          unset($collection_member_rows[$i]);
                          $collection_member_rows = array_values($collection_member_rows);
                          break;
                        }
                        $i++;
                      }
                    }
                    $change = array('set_id' => $set_id, 'group_id' => $group, 'tc_user_id' => $user->getId(), 'pa_user_id' => $user_id);
                    if (is_null($in_group)) {
                      $group_changes['member']['add'][] = $change;
                    } else if ($in_group != $groups_map[$set_id]['groups'][$group]) {
                      $group_changes['member']['delete'][] = array('set_id' => $set_id, 'pa_group_id' => $in_group, 'pa_user_id' => $user_id);
                      $group_changes['member']['add'][] = $change;
                    }
                  }
                }
              }
            }
            unset($group_changes['set']['delete'][array_search($set_id, $group_changes['set']['delete'])]);
            foreach ($collection_member_rows as $row) {
              $deleted = FALSE;
              if (isset($group_changes['group']['delete'])) {
                foreach ($group_changes['group']['delete'] as $group) {
                  if ($group['id'] == $row['group_id']) {
                    $deleted = TRUE;
                    break;
                  }
                }
              }
              if (!$deleted) {
                $group_changes['member']['delete'][] = array('set_id' => $set_id, 'pa_group_id' => $row['group_id'], 'pa_user_id' => $row['user_id']);
              }
            }
          } else {
            unset($group_changes['set']['delete'][array_search($set_id, $group_changes['set']['delete'])]);
          }
        }
#
### Check for new, changed and deleted users
#
        $to_add = array();
        $to_update = array();
        $to_update_role = array();
        foreach ($members as $user) {
          if (!isset($users[$user->getId(LTI_Tool_Provider::ID_SCOPE_ID_ONLY)])) {
            $to_add[] = $user;
          } else {
            $old_user = $users[$user->getId(LTI_Tool_Provider::ID_SCOPE_ID_ONLY)];
            $changed = (($old_user['forename'] != $user->firstname) ||
                        ($old_user['lastname'] != $user->lastname) ||
                        ($old_user['email'] != $user->email));
            if ($changed) {
              $to_update[$old_user['user_id']] = $user;
            }
            $changed = (($old_user['user_type'] == APP__USER_TYPE_TUTOR) && (!$user->isStaff()) ||
                        ($old_user['user_type'] == APP__USER_TYPE_STUDENT) && (!$user->isLearner()));
            if ($changed) {
              $to_update_role[$old_user['user_id']] = $user;
            }
            unset($users[$user->getId(LTI_Tool_Provider::ID_SCOPE_ID_ONLY)]);
          }
        }
?>
<form action="" method="post">
<?php
#
### Initialise set change arrays for display
#
        $sets_add = array();
        $sets_update = array();
        $sets_delete = array();
        if (isset($group_changes['set']['add'])) {
          foreach ($group_changes['set']['add'] as $set) {
            $sets_add[$set['id']] = $set['id'];
          }
        }
        if (isset($group_changes['set']['delete'])) {
          foreach ($group_changes['set']['delete'] as $set_id) {
            $sets_delete[$set_id] = $groups_map[$set_id]['id'];
          }
        }
        if (isset($group_changes['set']['update'])) {
          foreach ($group_changes['set']['update'] as $set) {
            $sets_update[$set['id']] = $set['id'];
          }
        }
        if (isset($group_changes['group']['add'])) {
          foreach ($group_changes['group']['add'] as $group) {
            if (!isset($sets_add[$group['set_id']])) {
              $sets_update[$group['set_id']] = $group['set_id'];
            }
          }
        }
        if (isset($group_changes['group']['update'])) {
          foreach ($group_changes['group']['update'] as $group) {
            if (!isset($sets_add[$group['set_id']])) {
              $sets_update[$group['set_id']] = $group['set_id'];
            }
          }
        }
        if (isset($group_changes['group']['delete'])) {
          foreach ($group_changes['group']['delete'] as $group) {
            if (!isset($sets_add[$group['set_id']])) {
              $sets_update[$group['set_id']] = $group['set_id'];
            }
          }
        }
        if (isset($group_changes['member']['add'])) {
          foreach ($group_changes['member']['add'] as $member) {
            if (!isset($sets_add[$member['set_id']])) {
              $sets_update[$member['set_id']] = $member['set_id'];
            }
          }
        }
        if (isset($group_changes['member']['delete'])) {
          foreach ($group_changes['member']['delete'] as $member) {
            if (!isset($sets_add[$member['set_id']])) {
              $sets_update[$member['set_id']] = $member['set_id'];
            }
          }
        }
#
### Display list of changes found
#
        if ((count($to_add) > 0) || (count($to_update) > 0) || (count($to_update_role) > 0) || (count($users) > 0) ||
            (count($sets_add) > 0) || (count($sets_update) > 0) || (count($sets_delete) > 0)) {
          displayUsers('Users to be added', $to_add);
          displayUsers('Users to be updated', $to_update);
          displayUsers('Users changing role', $to_update_role);
          displayUsers('Users to be deleted', $users, FALSE);
          displaySets('Select any new collections to be added', $resource_link, $group_handler, $sets_add, 'add');
          displaySets('Existing collections to be updated', $resource_link, $group_handler, $sets_update, 'update');
          displaySets('Existing collections to be deleted', $resource_link, $group_handler, $sets_delete, 'delete');
?>
<p>
&nbsp;&nbsp;&nbsp;<input type="submit" name="do" value="Update <?php echo APP__NAME; ?>" />
<?php
          if ((count($users) > 0) &&
              ((count($to_add) > 0) || (count($to_update) > 0) || (count($to_update_role) > 0))) {
?>
&nbsp;&nbsp;&nbsp;<input type="submit" name="do" value="Update <?php echo APP__NAME; ?> without deletions" />
<?php
          }
?>
</p>
</form>
<?php
#
### Save changes to the user session pending confirmation of update
#
          $_SESSION['_to_add'] = $to_add;
          $_SESSION['_to_update'] = $to_update;
          $_SESSION['_to_update_role'] = $to_update_role;
          $_SESSION['_to_delete'] = $users;
          $_SESSION['_group_changes'] = $group_changes;

        } else {
          echo '<p>' . APP__NAME . " is up-to-date; no changes found to process.</p>\n";
        }

      } else {

        echo "<p>Unable to access memberships list from source, please try again later.</p>\n";

      }

    } else {
#
### Ensure any pending changes are removed from session
#
      unset($_SESSION['_to_add']);
      unset($_SESSION['_to_update']);
      unset($_SESSION['_to_update_role']);
      unset($_SESSION['_to_delete']);
      unset($_SESSION['_group_changes']);
?>

<p>
This page allows you to update <?php echo APP__NAME; ?> with any changes to the enrolments in the
course which is the source for this module.  These updates may include:
</p>
<ul>
  <li>new users</li>
  <li>changes to the names and/or email addresses of existing users</li>
  <li>changes to the type (tutor or student) of an existing user</li>
  <li>deletion of users which no longer exist in the source course</li>
  <li>memberships of groups in the VLE which are part of a set (added as a collection)</li>
</ul>
<p>
Click on the <em>Continue</em> button to obtain a list of the changes to be processed.  The updates
will not be made until you confirm them.
</p>

<?php
#
### Get details of last enrolment synchronisation
#
      $last = $settings['members'];
      if ($last) {
        echo "<p>\n";
        echo "The last update was performed on {$last}.\n";
        echo "</p>\n";
      }
?>

<form action="" method="post">
<p>
&nbsp;&nbsp;&nbsp;<input type="submit" name="continue" value="Continue" />
</p>
</form>

<?php

    }

  } else {

?>

<p>
Sorry, this link does not support the memberships service and so no synchronisation is possible.
</p>

<?php
  }
?>

</div>
<?php
$UI->content_end();

#
### Function to display a list of users
#
  function displayUsers($title, $users, $isObject = TRUE) {

    if (count($users) > 0) {
      echo "<h2>{$title}</h2>\n\n";

      echo '<div class="obj">';
      echo '<table class="obj" cellpadding="2" cellspacing="2">';
      echo "<tr><th>username</th><th>Name</th><th>type</th></tr>\n";
      if ($isObject) {
        foreach ($users as $user) {
          if ($user->isStaff()) {
            $role = 'tutor';
          } else {
            $role = 'student';
          }
          echo '<tr>';
          echo '<td class="obj_info_text" style="width: 10em;">' . $user->getId(LTI_Tool_Provider::ID_SCOPE_ID_ONLY) . '</td>';
          echo '<td class="obj_info_text">' . $user->fullname . '</td>';
          echo '<td class="obj_info_text" style="width: 10em;">' . $role . '</td>';
          echo "</tr>\n";
        }
      } else {
        foreach ($users as $username => $user) {
          if ($user['user_type'] == APP__USER_TYPE_TUTOR) {
            $role = 'tutor';
          } else {
            $role = 'student';
          }
          echo '<tr>';
          echo '<td class="obj_info_text" style="width: 10em;">' . $username . '</td>';
          echo '<td class="obj_info_text">' . $user['forename'] . ' ' . $user['lastname'] . '</td>';
          echo '<td class="obj_info_text" style="width: 10em;">' . $role . '</td>';
          echo "</tr>\n";
        }
      }
      echo "</table>\n";
      echo "</div>\n";
    }

  }

#
### Function to display a list of group sets
#
  function displaySets($title, $resource_link, $group_handler, $sets, $action) {

    if (count($sets) > 0) {
      echo "<h2>{$title}</h2>\n\n";

      echo '<div class="obj">';
      echo '<table class="obj" cellpadding="2" cellspacing="2">';
      echo "<tr><th>&nbsp;</th><th>Name</th><th style=\"text-align: right\">Groups</th><th style=\"text-align: right\">Members</th></tr>\n";
      foreach ($sets as $set => $set_id) {
        if (isset($resource_link->group_sets[$set_id])) {
          $title = $resource_link->group_sets[$set_id]['title'];
          $num_groups = count($resource_link->group_sets[$set_id]['groups']);
          $num_members = $resource_link->group_sets[$set_id]['num_learners'];
        } else {
          $collection = $group_handler->get_collection($set_id);
          if ($collection) {
            $title = $collection->name;
            $groups = $collection->get_groups_array();
            $num_groups = count($groups);
            $collection_member_rows = $collection->get_member_rows();
            $num_members = count($collection_member_rows);
          } else {
            $title = 'NA';
            $num_groups = 'NA';
            $num_members = 'NA';
          }
        }
        echo '<tr>';
        if ($action == 'add') {
          echo '<td class="obj_info_text" style="width: 10em; text-align: right;">';
          echo "<input type=\"checkbox\" name=\"{$action}_{$set_id}\" value=\"true\" />&nbsp;";
          echo '</td>';
        } else {
          echo '<td class="obj_info_text" style="width: 10em;">&nbsp;</td>';
        }
        echo '<td class="obj_info_text">' . $title . '</td>';
        echo '<td class="obj_info_text" style="text-align: right">' . $num_groups . '</td>';
        echo '<td class="obj_info_text" style="text-align: right">' . $num_members . '</td>';
        echo "</tr>\n";
      }
      echo "</table>\n";
      echo "</div>\n";
    }

  }

?>
